<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClaimStatusChecker;
use App\Services\PhyrhoseBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A transaction backend that stops answering.
 *
 * Requests to it give up after a bounded time, and a status check that meets one stops and
 * leaves its claims waiting for the next check instead of failing the job.
 */
class PhyrhoseTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_clients_carry_the_configured_timeouts(): void
    {
        config([
            'cardano.phyrhose.timeout_seconds' => 7,
            'cardano.phyrhose.connect_timeout_seconds' => 3,
        ]);

        foreach (['mainnet_phyrhose', 'preprod_phyrhose'] as $macro) {
            $options = Http::$macro()->getOptions();

            $this->assertSame(7, $options['timeout'] ?? null, "$macro has no request timeout");
            $this->assertSame(3, $options['connect_timeout'] ?? null, "$macro has no connect timeout");
        }
    }

    public function test_the_timeouts_default_to_ten_five_and_sixty_seconds(): void
    {
        $phyrhose = (require config_path('cardano.php'))['phyrhose'];

        $this->assertSame(10, $phyrhose['timeout_seconds']);
        $this->assertSame(5, $phyrhose['connect_timeout_seconds']);
        $this->assertSame(60, $phyrhose['submit_timeout_seconds']);
    }

    /**
     * The claim id a recipient carries is for backends that pay claim by claim. The payload the
     * custodian receives is the recipient as it always was.
     */
    public function test_the_custodians_payload_does_not_carry_the_claim_id(): void
    {
        $sent = null;
        Http::fake(function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response(['status' => 'ok', 'data' => [null, ['items' => []]]]);
        });

        (new PhyrhoseBackend)->submitPayment('bucket', [['claimId' => 7, 'pooCode' => 'CODE', 'address' => 'addr_test1', 'lovelace' => 2_000_000, 'tokens' => []]], 'preprod');

        $this->assertSame([['pooCode' => 'CODE', 'address' => 'addr_test1', 'lovelace' => 2_000_000, 'tokens' => []]], $sent['recipients']);
    }

    /**
     * Sending claims gets the longer limit, as the request actually goes out; asking about a
     * transaction keeps the short one.
     */
    public function test_a_send_uses_the_submit_timeout_and_a_status_call_does_not(): void
    {
        config([
            'cardano.phyrhose.timeout_seconds' => 10,
            'cardano.phyrhose.submit_timeout_seconds' => 60,
        ]);

        $timeouts = [];
        Http::fake(function ($request, array $options) use (&$timeouts) {
            $timeouts[str_contains($request->url(), 'pooPurchaseOrder') ? 'send' : 'status'] = $options['timeout'] ?? null;

            return Http::response(['status' => 'ok', 'data' => [null, ['items' => [], 'status' => 'processing']]]);
        });

        $backend = new PhyrhoseBackend;
        $backend->submitPayment('bucket', [['pooCode' => 'CODE', 'address' => 'addr_test1', 'lovelace' => 2_000_000, 'tokens' => []]], 'preprod');
        $backend->checkStatus('1001', 'preprod');

        $this->assertSame(['send' => 60, 'status' => 10], $timeouts);
    }

    /**
     * The first answer lands, then the backend goes away. The claim that was answered is
     * recorded, the rest stay exactly as they were, the job finishes, and the backend is not
     * asked about anything after the connection failed.
     */
    public function test_a_status_pass_that_loses_the_backend_ends_cleanly_and_leaves_claims_waiting(): void
    {
        $campaign = Campaign::factory()->for(User::factory())->create(['network' => 'preprod']);
        Wallet::factory()->for($campaign)->create(['backend' => 'phyrhose']);

        $claims = collect(range(3, 1))->map(function (int $minutesAgo) use ($campaign) {
            $claim = Claim::factory()
                ->for(Code::factory()->for($campaign))
                ->withTransaction()
                ->create(['status' => 'pending']);
            $claim->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

            return $claim;
        });

        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;

            if ($requests === 1) {
                return Http::response(['status' => 'ok', 'data' => [null, ['status' => 'completed', 'txId' => 'firsthash']]]);
            }

            return Http::failedConnection()($request);
        });

        (new CheckClaims($campaign->id))->handle();

        $this->assertSame(2, $requests, 'nothing should be asked after the connection failed');

        [$oldest, $middle, $newest] = $claims->map->fresh()->all();

        $this->assertSame('firsthash', $oldest->transaction_hash);
        foreach ([$middle, $newest] as $waiting) {
            $this->assertNull($waiting->transaction_hash);
            $this->assertSame('pending', $waiting->status);
            $this->assertSame(0, (int) $waiting->retry_count);
            $this->assertNotNull($waiting->transaction_id);
            $this->assertTrue($waiting->isAwaitingConfirmation());
        }

        $stats = app(ClaimStatusChecker::class)->check($campaign);
        $this->assertTrue($stats['unreachable']);
        $this->assertSame(0, $stats['checked']);
    }

    /**
     * Phyrhose purchase ids are integers and it answers any other string with a parse error,
     * so an id that is not all digits is never sent. The demo campaign seeder writes UUIDs.
     */
    public function test_a_purchase_id_that_is_not_an_integer_is_never_sent(): void
    {
        Http::fake();

        $backend = new PhyrhoseBackend;

        foreach (['3f2b8c1e-5d4a-4e7b-9a6c-1b2d3e4f5a6b', '', '-5', '12a', ' 12'] as $id) {
            $this->assertSame(['status' => 'unknown', 'txHash' => null], $backend->checkStatus($id, 'preprod'));
        }

        Http::assertNothingSent();
    }

    public function test_an_integer_purchase_id_is_sent_once_and_its_answer_returned(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['status' => 'completed', 'txId' => 'abc123hash']],
        ])]);

        $result = (new PhyrhoseBackend)->checkStatus('48213', 'preprod');

        $this->assertSame(['status' => 'completed', 'txHash' => 'abc123hash'], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'firehose/purchaseStatus?purchaseId=48213'));
    }

    /**
     * A claim holding a UUID as its transaction id, as the demo seeder leaves, stays
     * awaiting confirmation and costs no request on a pass.
     */
    public function test_a_status_pass_does_not_ask_about_a_uuid_transaction_id(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->for(User::factory())->create(['network' => 'preprod']);
        Wallet::factory()->for($campaign)->create(['backend' => 'phyrhose']);

        $claim = Claim::factory()
            ->for(Code::factory()->for($campaign))
            ->withTransaction()
            ->create(['status' => 'pending', 'transaction_id' => '3f2b8c1e-5d4a-4e7b-9a6c-1b2d3e4f5a6b']);
        $before = $claim->fresh()->getAttributes();

        app(ClaimStatusChecker::class)->check($campaign);

        Http::assertNothingSent();
        $this->assertSame($before, $claim->fresh()->getAttributes());
        $this->assertTrue($claim->fresh()->isAwaitingConfirmation());
    }
}
