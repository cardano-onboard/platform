<?php

namespace Tests\Feature;

use App\Cardano\ChainData;
use App\Cardano\ChainStatusReader;
use App\Jobs\CheckClaims;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Reward;
use App\Services\NativeBackend;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Codec\TransactionDecoder;
use CardanoPhp\DataClient\DTOs\Epoch\ProtocolParams\ProtocolParams;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\Support\NativeCampaigns;
use Tests\TestCase;

/**
 * The ways a run of the native backend can go wrong part way, and what each leaves behind.
 *
 * Each of these is a claim paid twice, recorded as paid and not paid, or left waiting for something
 * that would never come. They run the claims job and the claim check against real signed
 * transactions and a chain that decodes them.
 */
class NativeBackendFailureModesTest extends TestCase
{
    use NativeCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpNativeChain();
    }

    private function funded(int $lovelace = 100_000_000): Campaign
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, $lovelace);

        return $campaign;
    }

    private function unsent(Code $code, string $address): Claim
    {
        return Claim::factory()->create(['code_id' => $code->id, 'address' => $address, 'transaction_id' => null, 'transaction_hash' => null]);
    }

    /**
     * The same wallet may claim one code twice. Each claim is paid by an output of its own, and the
     * second is not answered with the first claim's transaction.
     */
    public function test_a_second_claim_of_the_same_code_and_address_is_paid_by_its_own_output(): void
    {
        $campaign = $this->funded();
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => 2_000_000, 'uses' => 5, 'perWallet' => 2]);
        $address = $this->claimant(1);
        Queue::fake();

        $first = $this->unsent($code, $address);
        (new ProcessClaims($campaign->id))->handle();
        [$tx1] = $this->chain->pending();
        $this->chain->land($tx1, 5);
        (new CheckClaims($campaign->id))->handle();
        $this->assertSame($tx1, $first->fresh()->transaction_hash);

        $second = $this->unsent($code, $address);
        (new ProcessClaims($campaign->id))->handle();
        [$tx2] = $this->chain->pending();
        $this->chain->land($tx2, 5);
        (new CheckClaims($campaign->id))->handle();

        $second->refresh();
        $this->assertNotSame($tx1, $second->transaction_id);
        $this->assertSame($tx2, $second->transaction_hash);
        $this->assertSame(4_000_000, $this->chain->balance($address));
    }

    private function outputsTo(string $address): int
    {
        $outputs = 0;

        foreach ($this->chain->mempool as $bytes) {
            foreach (TransactionDecoder::decode($bytes)->body->outputs() as $output) {
                $outputs += Address::fromBytes($output->address)->toBech32() === $address ? 1 : 0;
            }
        }

        return $outputs;
    }

    /**
     * Two due claims of one code and address are two recipients: each has an output of its own and
     * an answer of its own.
     */
    public function test_two_due_claims_of_one_code_and_address_are_both_paid(): void
    {
        $campaign = $this->funded();
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => 2_000_000, 'uses' => 5, 'perWallet' => 2]);
        $address = $this->claimant(1);
        $first = $this->unsent($code, $address);
        $second = $this->unsent($code, $address);
        Queue::fake();

        (new ProcessClaims($campaign->id))->handle();

        $this->assertNotNull($first->fresh()->transaction_id);
        $this->assertNotNull($second->fresh()->transaction_id);
        $this->assertSame(2, $this->outputsTo($address));
    }

    /**
     * A twin paid by a run whose answer was lost keeps that payment. The twin that claimed after
     * it is paid on its own, and the pair is not answered with one output.
     */
    public function test_a_twin_that_claims_after_a_lost_answer_is_paid_on_its_own(): void
    {
        $campaign = $this->funded();
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => 2_000_000, 'uses' => 5, 'perWallet' => 2]);
        $address = $this->claimant(1);
        $first = $this->unsent($code, $address);

        // The run that paid the first died before the claims job saved its answer.
        $this->backend()->submitPayment($campaign->id, [$this->recipient($first)], 'preprod');
        [$tx1] = $this->chain->pending();
        $second = $this->unsent($code, $address);
        Queue::fake();

        (new ProcessClaims($campaign->id))->handle();

        $this->assertSame($tx1, $first->fresh()->transaction_id);
        $this->assertNotNull($second->fresh()->transaction_id);
        $this->assertNotSame($tx1, $second->fresh()->transaction_id);
        $this->assertSame(2, $this->outputsTo($address));
    }

    /**
     * A twin that failed for good keeps the id of its finished transaction, and that does not
     * count against the payment of the twin that is owed one.
     */
    public function test_a_twin_that_failed_does_not_hide_the_payment_the_other_is_owed(): void
    {
        $campaign = $this->funded();
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => 2_000_000, 'uses' => 5, 'perWallet' => 2]);
        $address = $this->claimant(1);
        $this->unsent($code, $address)->forceFill(['transaction_id' => str_repeat('ee', 32), 'status' => 'failed', 'retry_count' => 5])->save();
        $owed = $this->unsent($code, $address);

        // Paid by a run whose answer was lost.
        $this->backend()->submitPayment($campaign->id, [$this->recipient($owed)], 'preprod');
        Queue::fake();

        (new ProcessClaims($campaign->id))->handle();

        $this->assertNotNull($owed->fresh()->transaction_id);
        $this->assertSame(1, $this->outputsTo($address));
    }

    /**
     * Twins at the head of the queue and a balance for one: one is paid and answered, the other is
     * not answered and waits.
     */
    public function test_a_balance_for_one_of_two_twins_pays_one_and_answers_one(): void
    {
        $campaign = $this->funded(3_500_000);
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => 2_000_000, 'uses' => 5, 'perWallet' => 2]);
        $address = $this->claimant(1);
        $first = $this->unsent($code, $address);
        $second = $this->unsent($code, $address);
        Queue::fake();

        (new ProcessClaims($campaign->id))->handle();

        $this->assertNotNull($first->fresh()->transaction_id);
        $this->assertNull($second->fresh()->transaction_id);
        $this->assertSame(1, $this->outputsTo($address));
        Queue::assertPushed(ProcessClaims::class);
    }

    /**
     * Twins with a token reward and one token left: one is paid and answered, the other waits.
     */
    public function test_one_token_left_for_two_twins_pays_one_and_answers_one(): void
    {
        $policy = str_repeat('cd', 28);
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 50_000_000, [[$policy, '4142', '1']]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => 2_000_000, 'uses' => 5, 'perWallet' => 2]);
        Reward::create(['code_id' => $code->id, 'policy_hex' => $policy, 'asset_hex' => '4142', 'quantity' => 1]);
        $address = $this->claimant(1);
        $first = $this->unsent($code, $address);
        $second = $this->unsent($code, $address);
        Queue::fake();

        (new ProcessClaims($campaign->id))->handle();

        $this->assertNotNull($first->fresh()->transaction_id);
        $this->assertNull($second->fresh()->transaction_id);
        $this->assertSame(1, $this->outputsTo($address));
    }

    /**
     * A status endpoint that is down does not stop payments. The record is as the last check left
     * it, and a claim that arrives meanwhile is paid, without paying the earlier one again.
     */
    public function test_a_status_endpoint_that_is_down_does_not_stop_payments(): void
    {
        $campaign = $this->funded();
        $first = $this->claim($campaign, 1);
        Queue::fake();
        (new ProcessClaims($campaign->id))->handle();
        $second = $this->claim($campaign, 2);

        $this->chain->statusDown = true;
        (new ProcessClaims($campaign->id))->handle();

        $this->assertNotNull($second->fresh()->transaction_id);
        $this->assertSame(1, $this->outputsTo($first->address));
    }

    /**
     * A run that outlives its lease does not keep paying a queue another worker has taken over.
     * The second worker takes the expired lease in the middle of the first one's call, and the
     * first finds it cannot renew and stops with what it has sent.
     */
    public function test_a_run_that_lost_its_lease_stops_instead_of_paying_the_same_claims_again(): void
    {
        $campaign = $this->funded(5_000_000_000);

        foreach (range(1, 400) as $n) {
            $this->claim($campaign, $n);
        }

        $holder = new ReflectionProperty(NativeBackend::class, 'holder');
        $calls = 0;

        $data = new class($this->chain, function () use (&$calls, $holder, $campaign) {
            if (++$calls !== 2) {
                return;
            }

            // Worker 1 has submitted its first transaction and is about to build the second. Its
            // lease has run out, and worker 2 starts and takes it.
            $first = $holder->getValue();
            DB::table('campaign_chain_leases')->update(['expires_at' => now()->subSecond()]);
            $holder->setValue(null, 'worker-2');
            (new ProcessClaims($campaign->id))->handle();
            $holder->setValue(null, $first);
        }) extends ChainData
        {

            public function __construct(private $chain, private $hook) {}

            public function tip(string $network): array
            {
                return ['slot' => $this->chain->slot, 'time' => $this->chain->time];
            }

            public function parameters(string $network): ProtocolParams
            {
                return $this->chain->parameters();
            }

            public function utxos(string $network, string $address): array
            {
                ($this->hook)();

                return array_values($this->chain->utxos[$address] ?? []);
            }
        };
        $this->app->instance(ChainData::class, $data);

        Queue::fake();
        (new ProcessClaims($campaign->id))->handle();

        $paid = [];

        foreach ($this->chain->pending() as $hash) {
            foreach (TransactionDecoder::decode($this->chain->mempool[$hash])->body->outputs() as $output) {
                $address = Address::fromBytes($output->address)->toBech32();

                if ($address !== $campaign->wallet->address) {
                    $paid[$address] = ($paid[$address] ?? 0) + 1;
                }
            }
        }

        $this->assertCount(400, $paid);
        $this->assertSame([], array_filter($paid, fn (int $outputs): bool => $outputs > 1));
    }

    /**
     * One pass of the claim check asks the chain about the campaign's transactions once, and offers
     * a transaction the chain has not seen once, however many claims it pays.
     */
    public function test_a_claim_check_costs_one_chain_question_per_campaign_not_one_per_claim(): void
    {
        $questions = new class
        {
            public int $count = 0;
        };
        $inner = $this->chain->statusReader();
        $this->app->instance(ChainStatusReader::class, new class($inner, $questions) extends ChainStatusReader
        {
            public function __construct(private ChainStatusReader $inner, private object $questions) {}

            public function depths(string $network, array $hashes): array
            {
                $this->questions->count++;

                return $this->inner->depths($network, $hashes);
            }
        });

        $campaign = $this->funded(1_000_000_000);

        foreach (range(1, 50) as $n) {
            $this->claim($campaign, $n);
        }

        Queue::fake();
        (new ProcessClaims($campaign->id))->handle();
        [$hash] = $this->chain->pending();

        $offered = count(array_keys($this->chain->offered, $hash, true));
        $questions->count = 0;
        (new CheckClaims($campaign->id))->handle();

        $this->assertSame(1, $questions->count);
        $this->assertSame(1, count(array_keys($this->chain->offered, $hash, true)) - $offered);
    }

    /**
     * A failure on the second transaction of a call does not lose the answer for the first. The
     * claims it pays are marked, and the claims job queues another run for the rest.
     */
    public function test_a_failure_after_the_first_transaction_still_answers_what_was_sent(): void
    {
        $campaign = $this->funded(5_000_000_000);

        foreach (range(1, 400) as $n) {
            $this->claim($campaign, $n);
        }

        $seen = [];
        $this->chain->refuse = function (string $hash) use (&$seen) {
            $seen[$hash] = true;

            if (count($seen) === 2) {
                $factory = new HttpFactory;

                return $factory->createResponse(503)->withBody($factory->createStream('Service Unavailable'));
            }

            return null;
        };

        Queue::fake();
        (new ProcessClaims($campaign->id))->handle();

        $paid = Claim::whereNotNull('transaction_id')->count();

        $this->assertGreaterThan(0, $paid);
        $this->assertLessThan(400, $paid);
        Queue::assertPushed(ProcessClaims::class);
    }

    /**
     * A campaign that cannot cover the whole batch pays the recipients its balance covers, and the
     * rest wait for a top-up under the claims job's backoff.
     */
    public function test_a_shortfall_pays_what_the_balance_covers(): void
    {
        $campaign = $this->funded(9_000_000);

        foreach (range(1, 5) as $n) {
            $this->claim($campaign, $n);
        }

        Queue::fake();
        (new ProcessClaims($campaign->id))->handle();

        $this->assertSame(4, Claim::whereNotNull('transaction_id')->count());
        Queue::assertPushed(ProcessClaims::class);
    }

    /**
     * A reward naming a token that cannot exist is left unpaid, and the claims on either side of it
     * are paid.
     */
    public function test_a_malformed_reward_does_not_stop_the_claims_around_it(): void
    {
        $campaign = $this->funded();
        $good = $this->claim($campaign, 1);
        $bad = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => 2_000_000]);
        Reward::create(['code_id' => $bad->id, 'policy_hex' => str_repeat('ab', 27), 'asset_hex' => '4142', 'quantity' => 1]);
        $badClaim = $this->unsent($bad, $this->claimant(2));
        $later = $this->claim($campaign, 3);

        Queue::fake();
        (new ProcessClaims($campaign->id))->handle();

        $this->assertNotNull($good->fresh()->transaction_id);
        $this->assertNotNull($later->fresh()->transaction_id);
        $this->assertNull($badClaim->fresh()->transaction_id);
    }
}
