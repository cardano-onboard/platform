<?php

namespace Tests\Feature;

use App\Cardano\ChainLease;
use App\Cardano\ChainRetention;
use App\Jobs\CheckClaims;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use Cardano\Transaction\Codec\TransactionDecoder;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NativeCampaigns;
use Tests\TestCase;

/**
 * The claim pipeline driven through the native backend, end to end.
 *
 * The claims job and the status check are the production code and are not changed for this
 * backend; the backend conforms to them. So these tests run the job, the claim check and the
 * status check as they run in production, against real signed transactions and a chain that
 * decodes them, and assert what happened to the claims and to the money.
 */
class NativeClaimPipelineTest extends TestCase
{
    use NativeCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpNativeChain();
    }

    private function fundedCampaign(int $lovelace = 100_000_000): Campaign
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, $lovelace);

        return $campaign;
    }

    private function processClaims(Campaign $campaign): void
    {
        (new ProcessClaims($campaign->id))->handle();
    }

    private function tick(Campaign $campaign): void
    {
        (new CheckClaims($campaign->id))->handle();
    }

    private function validityEnd(string $hash): int
    {
        return (int) DB::table('campaign_transactions')->where('tx_hash', $hash)->value('validity_end');
    }

    private function transaction(string $hash)
    {
        return TransactionDecoder::decode(
            ChainRetention::signedBytes(DB::table('campaign_transactions')->where('tx_hash', $hash)->first())
        );
    }

    /**
     * Every claim in a batch comes back from the backend under its code and address, so the
     * claims job records the transaction that pays it. The record of what the transaction paid is
     * written from this path too, keyed the same way, with the claim found.
     */
    public function test_every_claim_in_a_batch_gains_the_transaction_that_pays_it(): void
    {
        $campaign = $this->fundedCampaign();
        $claims = collect([1, 2, 3])->map(fn (int $n): Claim => $this->claim($campaign, $n));

        $this->processClaims($campaign);

        [$hash] = $this->chain->pending();

        foreach ($claims as $claim) {
            $claim->refresh();
            $this->assertSame($hash, $claim->transaction_id, 'A claim in the batch has no transaction.');
            $this->assertSame(2_000_000, $claim->reward_lovelace);

            $this->assertDatabaseHas('campaign_transaction_claims', [
                'tx_hash' => $hash,
                'claim_id' => $claim->id,
            ]);
        }

        // One transaction, paying the three of them.
        $this->assertSame(1, DB::table('campaign_transactions')->count());
        $this->assertCount(4, $this->transaction($hash)->body->outputs());
    }

    /**
     * A queue that does not fit in one transaction is paid across runs. Each transaction is under
     * the size limit, the leftovers go out on the run the claims job queues for them without
     * another claim arriving, each builds on the change of the one before, and nobody is paid twice.
     */
    public function test_a_queue_larger_than_one_transaction_is_paid_across_runs(): void
    {
        $campaign = $this->fundedCampaign(5_000_000_000);
        $claims = collect(range(1, 400))->map(fn (int $n): Claim => $this->claim($campaign, $n));

        $this->processClaims($campaign);

        $hashes = $this->chain->pending();

        $this->assertGreaterThan(1, count($hashes));
        $this->assertSame(0, Claim::whereNull('transaction_id')->count());
        $this->assertSame(400, DB::table('campaign_transaction_claims')->count());

        foreach ($hashes as $hash) {
            $this->assertLessThanOrEqual(16384, strlen(ChainRetention::signedBytes(DB::table('campaign_transactions')->where('tx_hash', $hash)->first())));
        }

        $this->assertSame(count($hashes), DB::table('campaign_transactions')->count());
        $this->assertSame($claims->count(), Claim::whereIn('transaction_id', $hashes)->count());
    }

    /**
     * One call pays a whole queue that needs several transactions, each built on the change of the
     * one before. Left to the claims job, every transaction after the first would wait out a
     * backoff that doubles with each run, which for a queue of a thousand claims is minutes.
     */
    public function test_one_call_pays_a_queue_that_needs_several_transactions(): void
    {
        $campaign = $this->fundedCampaign(5_000_000_000);
        $recipients = collect(range(1, 400))->map(fn (int $n): array => $this->recipient($this->claim($campaign, $n)))->all();

        $ids = $this->backend()->submitPayment($campaign->id, $recipients, 'preprod')['claimIds'];

        $this->assertCount(400, $ids);
        $this->assertGreaterThan(1, count($this->chain->pending()));
    }

    /**
     * A chain that cannot be read does not end the job. The batch is answered with no payment, the
     * claims job queues another run after its backoff, and the run after the chain is back pays it.
     */
    public function test_a_chain_that_cannot_be_read_leaves_the_batch_to_the_next_run(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);
        $this->chain->utxosDown = true;

        Queue::fake();
        $this->processClaims($campaign);

        $this->assertNull($claim->fresh()->transaction_id);
        Queue::assertPushed(ProcessClaims::class, fn (ProcessClaims $job): bool => $job->campaign_id === $campaign->id && $job->delay > 0);

        $this->chain->utxosDown = false;
        $this->processClaims($campaign);

        $this->assertNotNull($claim->fresh()->transaction_id);
    }

    /**
     * A reward below the live minimum is paid at exactly the minimum, and the record of what the
     * transaction paid says so.
     */
    public function test_a_reward_under_the_minimum_is_paid_at_the_minimum_and_recorded_as_paid(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1, 100);

        $this->processClaims($campaign);

        $claim->refresh();
        $this->assertNotNull($claim->transaction_id);

        $paid = $this->transaction($claim->transaction_id)->body->outputs()[0]->value->coin->toInt();

        $this->assertGreaterThan(100, $paid);
        $this->assertLessThan(1_500_000, $paid);
        $this->assertSame($paid, (int) DB::table('campaign_transaction_claims')->value('lovelace'));
    }

    /**
     * The campaign's transaction message reaches the chain as CIP-20 metadata.
     */
    public function test_the_campaigns_message_reaches_the_chain(): void
    {
        $campaign = $this->fundedCampaign();
        $campaign->update(['txn_msg' => 'Thanks for coming']);
        $claim = $this->claim($campaign, 1);

        $this->processClaims($campaign);

        $this->assertSame(
            bin2hex(hex2bin('a11902a2a1636d736781')."\x71Thanks for coming"),
            bin2hex($this->transaction($claim->fresh()->transaction_id)->auxiliaryData->encode()),
        );
    }

    /**
     * A batch that finds the campaign's chain held by another worker is not lost. The claims job
     * queues another run for it, and the run after the lease is free pays it. Thrown instead, the
     * job would end and the claims would wait for another claim to arrive.
     */
    public function test_a_batch_that_finds_the_lease_held_is_queued_again_and_paid_later(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);
        $other = new ChainLease('another-host:1:0000000000000000');
        $other->take($campaign);

        Queue::fake();
        $this->processClaims($campaign);

        $this->assertNull($claim->fresh()->transaction_id);
        $this->assertSame(0, DB::table('campaign_transactions')->count());
        Queue::assertPushed(ProcessClaims::class, fn (ProcessClaims $job): bool => $job->campaign_id === $campaign->id && $job->delay > 0);

        $other->release($campaign);
        $this->processClaims($campaign);

        $this->assertNotNull($claim->fresh()->transaction_id);
    }

    /**
     * Past its authorisation a campaign cannot pay. The claim is left unsent and nothing is
     * recorded or offered, and the job's failure says why.
     */
    public function test_a_campaign_past_its_authorisation_pays_nothing_and_says_why(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);
        $this->chain->slot = (int) $campaign->expiry_slot + 1;

        try {
            $this->processClaims($campaign);
            $this->fail('A campaign past its authorisation was paid from.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('authorisation has expired', $e->getMessage());
        }

        $this->assertNull($claim->fresh()->transaction_id);
        $this->assertSame([], $this->chain->offered);
        $this->assertSame(0, DB::table('campaign_transactions')->count());
    }

    /**
     * A payment that landed and is only now seen past its validity window is not paid again.
     *
     * This is the double-payment path: the claim waits while the transaction confirms, the window
     * closes, and the status check must ask the chain for depth rather than read a passed window
     * as failure. The claim check reconciles and checks, and nothing is ever rebuilt.
     */
    public function test_a_landed_payment_past_its_validity_is_not_rebuilt(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);

        $this->processClaims($campaign);
        [$hash] = $this->chain->pending();

        // In a block, but not yet deep enough, and the chain has since moved past the window.
        $this->chain->land($hash, depth: 1);
        $this->chain->slot = $this->validityEnd($hash) + 600;

        $this->tick($campaign);

        $claim->refresh();
        $this->assertSame($hash, $claim->transaction_id);
        $this->assertSame(0, (int) $claim->retry_count);
        $this->assertNull($claim->transaction_hash);

        // Deep enough now.
        $this->chain->depths[$hash] = 5;
        $this->tick($campaign);

        $claim->refresh();
        $this->assertSame('completed', $claim->status);
        $this->assertSame($hash, $claim->transaction_hash);
        $this->assertNotNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('confirmed_at'));

        // Offered once, when it was built. Nothing was rebuilt and nothing else was offered.
        $this->assertSame([$hash], array_values(array_unique($this->chain->offered)));
        $this->assertSame(1, DB::table('campaign_transactions')->count());
    }

    /**
     * Silence is not failure. With the window passed and the chain unable to answer, the claim is
     * left exactly as it was, and nothing is rebuilt.
     */
    public function test_a_passed_window_with_no_answer_from_the_chain_changes_nothing(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);

        $this->processClaims($campaign);
        [$hash] = $this->chain->pending();

        $this->chain->slot = $this->validityEnd($hash) + 600;
        $this->chain->statusDown = true;

        $this->tick($campaign);

        $claim->refresh();
        $this->assertSame($hash, $claim->transaction_id);
        $this->assertSame(0, (int) $claim->retry_count);
        $this->assertNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('failed_at'));
        $this->assertSame(1, DB::table('campaign_transactions')->count());
    }

    /**
     * A worker that submitted and died before the claims were marked leaves them unmarked. The
     * retry, even after the window has passed, finds the transaction by code and address, asks the
     * chain, sees it landed, and answers with its hash instead of paying again.
     */
    public function test_a_retry_after_a_crash_does_not_pay_a_landed_batch_again(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);

        // The backend's half of the job, with the claims never updated afterwards.
        $this->backend()->submitPayment($campaign->id, [$this->recipient($claim)], 'preprod');
        [$hash] = $this->chain->pending();
        $this->chain->land($hash, depth: 2);
        $this->chain->slot = $this->validityEnd($hash) + 600;

        $this->processClaims($campaign);

        $this->assertSame($hash, $claim->fresh()->transaction_id);
        $this->assertSame(1, DB::table('campaign_transactions')->count());
        $this->assertSame([$hash], $this->chain->offered);
    }

    /**
     * A payment that provably never landed is rebuilt, once, and pays the claim.
     *
     * The chain is asked, has not seen it, and is past its window: that is the one answer on
     * which the claim is paid again, and the old transaction is recorded as finished so nothing
     * builds on it.
     */
    public function test_a_payment_that_never_landed_is_rebuilt_once(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);

        $this->processClaims($campaign);
        [$first] = $this->chain->pending();

        // Dropped from every mempool, never in a block.
        $this->chain->mempool = [];
        $this->chain->slot = $this->validityEnd($first) + 600;

        // Times out, and the claim waits out its retry backoff before it is sent again.
        $this->tick($campaign);
        $this->travel(10)->minutes();
        $this->processClaims($campaign);

        $claim->refresh();
        $this->assertSame(1, (int) $claim->retry_count);
        $this->assertSame('expired', DB::table('campaign_transactions')->where('tx_hash', $first)->value('failure'));

        [$second] = $this->chain->pending();
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $claim->transaction_id);

        // The rebuild spends what the first would have, not the first one's change.
        $inputs = array_map(
            static fn ($input): string => $input->transactionIdHex(),
            $this->transaction($second)->body->inputs(),
        );
        $this->assertNotContains($first, $inputs);
    }

    /**
     * A second batch while the first is pending is built on the first one's change, pays exactly
     * its own claims, and is accepted without waiting for the first to confirm.
     */
    public function test_a_second_batch_while_the_first_is_pending_is_built_on_its_change(): void
    {
        $campaign = $this->fundedCampaign();
        $early = collect([1, 2, 3])->map(fn (int $n): Claim => $this->claim($campaign, $n));

        $this->processClaims($campaign);
        [$first] = $this->chain->pending();

        $late = collect([4, 5])->map(fn (int $n): Claim => $this->claim($campaign, $n, 3_000_000));

        $this->processClaims($campaign);
        $pending = $this->chain->pending();

        $this->assertCount(2, $pending);
        $second = $pending[1];
        $this->assertNotSame($first, $second);

        foreach ($early as $claim) {
            $this->assertSame($first, $claim->fresh()->transaction_id);
        }

        foreach ($late as $claim) {
            $this->assertSame($second, $claim->fresh()->transaction_id);
        }

        // It spends the first transaction's change, which is its last output.
        $firstOutputs = count($this->transaction($first)->body->outputs());
        $spent = array_map(
            static fn ($input): string => $input->transactionIdHex().'#'.$input->index->toInt(),
            $this->transaction($second)->body->inputs(),
        );
        $this->assertSame([$first.'#'.($firstOutputs - 1)], $spent);

        // And pays the two new claimants and nobody else.
        $paid = [];

        foreach ($this->transaction($second)->body->outputs() as $output) {
            $paid[] = \Cardano\Transaction\Address\Address::fromBytes($output->address)->toBech32();
        }

        $this->assertSame([$this->claimant(4), $this->claimant(5), $campaign->wallet->address], $paid);

        // Both land, in order, and each claimant holds what the code promised.
        $this->chain->land($first);
        $this->chain->land($second);

        foreach ([1, 2, 3] as $n) {
            $this->assertSame(2_000_000, $this->chain->balance($this->claimant($n)));
        }

        foreach ([4, 5] as $n) {
            $this->assertSame(3_000_000, $this->chain->balance($this->claimant($n)));
        }
    }

    /**
     * The same batch offered again before it lands is the same transaction offered again, not a
     * second one.
     */
    public function test_retrying_a_batch_in_flight_offers_the_same_transaction(): void
    {
        $campaign = $this->fundedCampaign();
        $claims = [$this->claim($campaign, 1), $this->claim($campaign, 2)];
        $recipients = array_map(fn (Claim $claim): array => $this->recipient($claim), $claims);

        $first = $this->backend()->submitPayment($campaign->id, $recipients, 'preprod');
        $again = $this->backend()->submitPayment($campaign->id, $recipients, 'preprod');

        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('campaign_transactions')->count());
        $this->assertCount(2, $this->chain->offered);
        $this->assertSame(1, count(array_unique($this->chain->offered)));
    }

    /**
     * A batch mixing recipients already in flight with new ones is answered with the existing
     * transaction for the first and a new transaction paying only the second.
     */
    public function test_a_batch_with_some_recipients_in_flight_pays_only_the_others(): void
    {
        $campaign = $this->fundedCampaign();
        [$one, $two, $three] = [$this->claim($campaign, 1), $this->claim($campaign, 2), $this->claim($campaign, 3)];

        $this->backend()->submitPayment($campaign->id, [$this->recipient($one), $this->recipient($two)], 'preprod');
        [$first] = $this->chain->pending();

        $ids = $this->backend()->submitPayment(
            $campaign->id,
            [$this->recipient($one), $this->recipient($two), $this->recipient($three)],
            'preprod',
        )['claimIds'];

        $second = $ids[$three->id];

        $this->assertSame($first, $ids[$one->id]);
        $this->assertSame($first, $ids[$two->id]);
        $this->assertNotSame($first, $second);

        $paid = [];

        foreach ($this->transaction($second)->body->outputs() as $output) {
            $paid[] = \Cardano\Transaction\Address\Address::fromBytes($output->address)->toBech32();
        }

        $this->assertSame([$this->claimant(3), $campaign->wallet->address], $paid);
    }

    /**
     * A transaction the node refuses the first time it is offered is recorded as finished, and
     * nothing builds on its outputs: the next batch, paying the refused claim and a new one, spends
     * what the campaign actually holds.
     */
    public function test_a_refused_payment_is_not_built_on(): void
    {
        $campaign = $this->fundedCampaign();
        $first = $this->claim($campaign, 1);
        $refusals = 1;

        $this->chain->refuse = function () use (&$refusals) {
            if ($refusals-- > 0) {
                $factory = new HttpFactory;

                return $factory->createResponse(400)->withBody($factory->createStream('ConwayUtxowFailure (ValueNotConservedUTxO ...)'));
            }

            return null;
        };

        // Answered with nothing sent: the job queues another run rather than ending.
        Queue::fake();
        $this->processClaims($campaign);
        Queue::assertPushed(ProcessClaims::class);

        $refused = DB::table('campaign_transactions')->value('tx_hash');
        $this->assertSame('rejected', DB::table('campaign_transactions')->where('tx_hash', $refused)->value('failure'));
        $this->assertNull($first->fresh()->transaction_id);

        $second = $this->claim($campaign, 2);
        $this->processClaims($campaign);

        [$paid] = $this->chain->pending();
        $this->assertNotSame($refused, $paid);
        $this->assertSame($paid, $first->fresh()->transaction_id);
        $this->assertSame($paid, $second->fresh()->transaction_id);

        $spent = array_map(
            static fn ($input): string => $input->transactionIdHex(),
            $this->transaction($paid)->body->inputs(),
        );
        $this->assertNotContains($refused, $spent);
    }

    /**
     * The same batch refused once and built again from the same outputs is the same bytes and the
     * same hash. Offered again and taken, it is no longer marked finished, so the next batch builds
     * on its change instead of spending its inputs a second time.
     */
    public function test_a_refused_payment_built_again_identically_is_live_again(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);
        $refusals = 1;

        $this->chain->refuse = function () use (&$refusals) {
            if ($refusals-- > 0) {
                $factory = new HttpFactory;

                return $factory->createResponse(400)->withBody($factory->createStream('ConwayUtxowFailure (...)'));
            }

            return null;
        };

        try {
            $this->processClaims($campaign);
        } catch (\App\Cardano\ChainRejected) {
            // Expected.
        }

        $this->processClaims($campaign);
        [$hash] = $this->chain->pending();

        $this->assertSame($hash, $claim->fresh()->transaction_id);
        $this->assertNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('failed_at'));

        $next = $this->claim($campaign, 2);
        $this->processClaims($campaign);

        $this->assertCount(2, $this->chain->pending());
        $this->assertNotSame($hash, $next->fresh()->transaction_id);
    }

    /**
     * What is left is not swept while a payment is still in flight. Settlement pays, confirms, and
     * only then returns the rest, so a claim being paid is never raced by the sweep.
     */
    public function test_a_campaign_is_not_swept_while_a_payment_is_in_flight(): void
    {
        $campaign = $this->fundedCampaign();
        $this->claim($campaign, 1);
        $this->processClaims($campaign);
        $offered = array_unique($this->chain->offered);

        try {
            $this->backend()->refund($campaign->id, $this->claimant(9), 'preprod');
            $this->fail('A campaign was swept with a payment in flight.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not confirmed yet', $e->getMessage());
        }

        $this->assertSame($offered, array_unique($this->chain->offered));
    }

    /**
     * A claim still waiting to be paid blocks the sweep: returning the balance would leave it owed
     * with nothing to pay it, and the operator told the refund went through.
     */
    public function test_a_campaign_is_not_swept_while_a_claim_waits_to_be_paid(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);

        try {
            $this->backend()->refund($campaign->id, $this->claimant(9), 'preprod');
            $this->fail('A campaign was swept with a claim still owed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not been paid yet', $e->getMessage());
        }

        $this->assertSame([], $this->chain->offered);
        $this->assertNull($claim->fresh()->transaction_id);
    }

    /**
     * A campaign holding more outputs than one transaction can spend is swept in several, each
     * under the size limit, until the address is empty. Everything lands at the destination.
     */
    public function test_a_sweep_of_more_outputs_than_one_transaction_holds_is_chained(): void
    {
        $campaign = $this->fundedCampaign(5_000_000);

        foreach (range(1, 699) as $unused) {
            $this->chain->fund($campaign->wallet->address, 5_000_000);
        }

        $destination = $this->claimant(9);

        $this->assertTrue($this->backend()->refund($campaign->id, $destination, 'preprod'));

        $hashes = $this->chain->pending();
        $this->assertGreaterThan(1, count($hashes));
        $fees = 0;

        foreach ($hashes as $hash) {
            $this->assertLessThanOrEqual(16384, strlen($this->chain->mempool[$hash]));
            $fees += TransactionDecoder::decode($this->chain->mempool[$hash])->body->fee()->toInt();
        }

        foreach ($hashes as $hash) {
            $this->chain->land($hash);
        }

        $this->assertSame([], $this->chain->utxos[$campaign->wallet->address]);
        $this->assertSame(700 * 5_000_000 - $fees, $this->chain->balance($destination));
    }

    /**
     * A failure after the first sweep leaves what was sent, and the next refund takes the rest.
     */
    public function test_a_sweep_that_fails_part_way_is_finished_by_the_next_refund(): void
    {
        $campaign = $this->fundedCampaign(5_000_000);

        foreach (range(1, 699) as $unused) {
            $this->chain->fund($campaign->wallet->address, 5_000_000);
        }

        $seen = [];
        $this->chain->refuse = function (string $hash) use (&$seen) {
            $seen[$hash] = true;

            return count($seen) === 2 ? (new HttpFactory)->createResponse(503)->withBody((new HttpFactory)->createStream('down')) : null;
        };

        $this->assertTrue($this->backend()->refund($campaign->id, $this->claimant(9), 'preprod'));
        $this->assertCount(1, $this->chain->pending());

        $this->chain->land($this->chain->pending()[0]);
        $this->chain->refuse = null;
        DB::table('campaign_transactions')->whereNull('confirmed_at')->update(['failed_at' => now(), 'failure' => 'rejected']);

        $this->assertTrue($this->backend()->refund($campaign->id, $this->claimant(9), 'preprod'));

        foreach ($this->chain->pending() as $hash) {
            $this->chain->land($hash);
        }

        $this->assertSame([], $this->chain->utxos[$campaign->wallet->address]);
    }

    /**
     * A sweep the node refuses is recorded as finished. Left in flight it would block every payment
     * and refund until its validity passed.
     */
    public function test_a_refused_sweep_does_not_block_what_follows(): void
    {
        $campaign = $this->fundedCampaign();
        $this->chain->refuse = fn () => (new HttpFactory)->createResponse(400)->withBody((new HttpFactory)->createStream('ConwayUtxowFailure (...)'));

        try {
            $this->backend()->refund($campaign->id, $this->claimant(9), 'preprod');
            $this->fail('The refusal was not reported.');
        } catch (\App\Cardano\ChainRejected) {
            $this->assertSame('rejected', DB::table('campaign_transactions')->value('failure'));
        }

        $this->chain->refuse = null;
        $this->assertTrue($this->backend()->refund($campaign->id, $this->claimant(9), 'preprod'));
    }

    /**
     * Nothing runs on a schedule here, so nothing else marks a landed payment confirmed. The refund settles
     * the chain itself before it refuses for payments in flight.
     */
    public function test_a_refund_settles_a_landed_payment_before_it_refuses(): void
    {
        $campaign = $this->fundedCampaign();
        $this->claim($campaign, 1);
        $this->processClaims($campaign);
        [$hash] = $this->chain->pending();
        $this->chain->land($hash, depth: 5);

        $this->assertTrue($this->backend()->refund($campaign->id, $this->claimant(9), 'preprod'));
        $this->assertNotNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('confirmed_at'));
    }

    /**
     * The operator asking for a refund the backend cannot make is told why, not shown an error
     * page. Here, because a payment is still confirming.
     */
    public function test_a_refused_refund_is_explained_to_the_operator(): void
    {
        $campaign = $this->fundedCampaign();
        $this->claim($campaign, 1);
        $this->processClaims($campaign);

        $this->actingAs($campaign->user)
            ->post(route('campaigns.refund', $campaign->id), ['address' => $this->claimant(9)])
            ->assertRedirect()
            ->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'not confirmed yet'));
    }

    /**
     * The status check acts on completed, processing, timeout and unknown, and on nothing else. An
     * answer in any other word leaves the claim waiting for ever, so each state a payment passes
     * through is checked for the word it is given.
     */
    public function test_the_status_answers_in_the_words_the_status_check_acts_on(): void
    {
        $campaign = $this->fundedCampaign();
        $this->claim($campaign, 1);
        $this->processClaims($campaign);
        [$hash] = $this->chain->pending();

        // Offered and inside its window.
        $this->assertSame(['status' => 'processing', 'txHash' => $hash], $this->backend()->checkStatus($hash, 'preprod'));

        // In a block, not yet deep enough.
        $this->chain->land($hash, depth: 1);
        $this->assertSame(['status' => 'processing', 'txHash' => $hash], $this->backend()->checkStatus($hash, 'preprod'));

        // Deep enough, once the campaign's record has been brought up to date, which the claim
        // check does for the whole campaign before it asks about any claim.
        $this->chain->depths[$hash] = 3;
        $this->assertSame(['status' => 'processing', 'txHash' => $hash], $this->backend()->checkStatus($hash, 'preprod'));
        app(\App\Cardano\ChainConfirmation::class)->reconcile($campaign, 'preprod');
        $this->assertSame(['status' => 'completed', 'txHash' => $hash], $this->backend()->checkStatus($hash, 'preprod'));

        // Something this backend never built.
        $this->assertSame('unknown', $this->backend()->checkStatus(str_repeat('f', 64), 'preprod')['status']);
    }

    /**
     * Past its window while the chain cannot answer, the payment is left as it was. Timeout is the
     * answer on which the claim is paid again, and a chain that did not answer has not said the
     * payment never landed. The status check reads the record and cannot ask the chain at all.
     */
    public function test_a_chain_that_cannot_be_asked_does_not_time_a_payment_out(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);
        $this->processClaims($campaign);
        [$hash] = $this->chain->pending();

        $this->chain->slot = $this->validityEnd($hash) + 600;
        $this->chain->statusDown = true;

        $this->tick($campaign);

        $this->assertSame(['status' => 'processing', 'txHash' => $hash], $this->backend()->checkStatus($hash, 'preprod'));
        $this->assertNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('failed_at'));
        $this->assertSame($hash, $claim->fresh()->transaction_id);
    }

    /**
     * A server error on submission does not say whether the node took the transaction, so it is
     * not recorded as refused. The payment stays in flight, and the next run offers the same bytes
     * rather than building a second payment for the same claims.
     */
    public function test_a_server_error_on_submission_leaves_the_payment_in_flight(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);
        $errors = 1;

        $this->chain->refuse = function (string $hash, string $bytes) use (&$errors) {
            if ($errors-- > 0) {
                // Forwarded to a node that took it, and the answer lost on the way back.
                $this->chain->mempool[$hash] = $bytes;
                $factory = new HttpFactory;

                return $factory->createResponse(502)->withBody($factory->createStream('Bad Gateway'));
            }

            return null;
        };

        try {
            $this->processClaims($campaign);
            $this->fail('A submission with no answer was reported as sent.');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(\App\Cardano\ChainRejected::class, $e);
        }

        $hash = DB::table('campaign_transactions')->value('tx_hash');
        $this->assertNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('failed_at'));

        $this->processClaims($campaign);

        $this->assertSame($hash, $claim->fresh()->transaction_id);
        $this->assertSame([$hash], array_values(array_unique($this->chain->offered)));
        $this->assertSame(1, DB::table('campaign_transactions')->count());
    }

    /**
     * A worker that submitted and died, with the payment then dropped and its window passed. The
     * retry asks the chain, which has not seen it, records it as finished, and pays the claim with a
     * new transaction. Recording it matters: a row left unfinished is asked about for ever and is
     * counted as waiting by every scheduled pass.
     */
    public function test_a_retry_after_a_crash_records_a_payment_that_never_landed_as_finished(): void
    {
        $campaign = $this->fundedCampaign();
        $claim = $this->claim($campaign, 1);

        $this->backend()->submitPayment($campaign->id, [$this->recipient($claim)], 'preprod');
        [$first] = $this->chain->pending();
        $this->chain->mempool = [];
        $this->chain->slot = $this->validityEnd($first) + 600;

        $this->processClaims($campaign);

        $this->assertSame('expired', DB::table('campaign_transactions')->where('tx_hash', $first)->value('failure'));
        [$second] = $this->chain->pending();
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $claim->fresh()->transaction_id);
    }

    /**
     * A payment in flight whose input has been spent by something else, the recovery keys say,
     * will never land. Its change will never exist, so the next batch is not built on it.
     */
    public function test_a_payment_whose_inputs_were_spent_elsewhere_is_not_built_on(): void
    {
        $campaign = $this->fundedCampaign();
        $address = $campaign->wallet->address;
        $this->claim($campaign, 1);
        $this->processClaims($campaign);
        [$orphan] = $this->chain->pending();

        // Its input spent by another transaction, and something smaller paid in since.
        $this->chain->mempool = [];
        $this->chain->utxos[$address] = [];
        $this->chain->fund($address, 10_000_000);

        $this->claim($campaign, 2);
        $this->processClaims($campaign);

        $paid = collect($this->chain->pending())->last();
        $spent = array_map(
            static fn ($input): string => $input->transactionIdHex(),
            $this->transaction($paid)->body->inputs(),
        );

        $this->assertNotContains($orphan, $spent);
    }
}
