<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClaimStatusChecker;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A claim whose transaction timed out is sent again after a backoff that doubles with each
 * timeout, and no send run brought round by other claims sends it before its time.
 */
class ClaimRetryBackoffTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cardano.claim_retry_backoff_base_seconds' => 60, 'cardano.push_delay_seconds' => 20]);
        $this->freezeSecond();

        $this->campaign = Campaign::factory()->for(User::factory())->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['key' => 'bucket', 'backend' => 'phyrhose']);
    }

    private function claim(array $attributes = []): Claim
    {
        return Claim::factory()
            ->for(Code::factory()->for($this->campaign)->create(['lovelace' => 2_000_000]))
            ->create($attributes);
    }

    private function runSendAsWorker(): void
    {
        $job = new ProcessClaims($this->campaign->id);
        (new UniqueLock(app(CacheRepository::class)))->release($job);
        $job->handle();
    }

    /**
     * Four timeouts in a row wait 1, 2, 4 and 8 minutes, both on the claim and on the send
     * run queued for it. The fifth marks it failed and queues nothing.
     */
    public function test_each_timeout_doubles_the_wait_until_the_claim_fails(): void
    {
        Queue::fake();
        Http::fake(['*purchaseStatus*' => Http::response(['status' => 'ok', 'data' => [null, ['status' => 'timeout']]])]);

        $claim = $this->claim(['transaction_id' => '1001', 'status' => 'pending']);

        foreach ([1 => 60, 2 => 120, 3 => 240, 4 => 480] as $timeouts => $wait) {
            (new UniqueLock(app(CacheRepository::class)))->release(new ProcessClaims($this->campaign->id));

            (new CheckClaims($this->campaign->id))->handle();

            $claim->refresh();
            $this->assertSame($timeouts, (int) $claim->retry_count);
            $this->assertNull($claim->transaction_id);
            $this->assertSame('pending', $claim->status);
            $this->assertTrue($claim->retry_after->equalTo(now()->addSeconds($wait)), "timeout $timeouts should wait $wait seconds");
            $this->assertSame($wait, Queue::pushed(ProcessClaims::class)->last()->delay);

            // Sent again once its wait is over, which gives it a transaction id to time out.
            $claim->forceFill(['transaction_id' => '100'.($timeouts + 1)])->save();
        }

        $pushed = Queue::pushed(ProcessClaims::class)->count();

        (new CheckClaims($this->campaign->id))->handle();

        $claim->refresh();
        $this->assertSame('failed', $claim->status);
        $this->assertSame(5, (int) $claim->retry_count);
        $this->assertSame($pushed, Queue::pushed(ProcessClaims::class)->count());
    }

    public function test_the_backoff_doubles_from_the_configured_base(): void
    {
        $this->assertSame([60, 120, 240, 480, 960], array_map(ClaimStatusChecker::retryBackoffSeconds(...), [1, 2, 3, 4, 5]));

        config(['cardano.claim_retry_backoff_base_seconds' => 10]);
        $this->assertSame(40, ClaimStatusChecker::retryBackoffSeconds(3));
    }

    public function test_the_base_defaults_to_sixty_seconds(): void
    {
        $this->assertSame(60, (require config_path('cardano.php'))['claim_retry_backoff_base_seconds']);
    }

    /**
     * A send run that a new claim brings round sends the new claim and leaves the one still
     * waiting out its backoff, then queues a run for when that wait ends. When it has, the
     * claim goes.
     */
    public function test_a_send_run_for_other_claims_leaves_a_claim_waiting_out_its_backoff(): void
    {
        Queue::fake();

        $waiting = $this->claim(['retry_count' => 1]);
        $waiting->forceFill(['retry_after' => now()->addSeconds(120)])->save();
        $fresh = $this->claim();

        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $items = [];
            foreach ($request->data()['recipients'] as $recipient) {
                $sent[] = $recipient['pooCode'];
                $items[] = [
                    'pooCode' => $recipient['pooCode'],
                    'recipientAddress' => $recipient['address'],
                    'purchaseId' => 'purchase-'.$recipient['pooCode'],
                ];
            }

            return Http::response(['status' => 'ok', 'data' => [null, ['items' => $items]]]);
        });

        $this->runSendAsWorker();

        $this->assertSame([$fresh->code->code], $sent);
        $this->assertNull($waiting->fresh()->transaction_id);
        $this->assertSame(120, Queue::pushed(ProcessClaims::class)->last()->delay);

        $this->travel(121)->seconds();
        $this->runSendAsWorker();

        $this->assertSame([$fresh->code->code, $waiting->code->code], $sent);
        $this->assertNotNull($waiting->fresh()->transaction_id);
    }

    /**
     * A wait longer than a queue can delay a job gets a run at that limit, which finds the
     * claim still not due and queues the next for the time that is left.
     */
    public function test_a_wait_past_the_queue_limit_is_covered_in_steps(): void
    {
        Queue::fake();
        Http::fake();

        $waiting = $this->claim(['retry_count' => 5]);
        $waiting->forceFill(['retry_after' => now()->addSeconds(960)])->save();

        $this->runSendAsWorker();
        $this->assertSame(900, Queue::pushed(ProcessClaims::class)->last()->delay);

        $this->travel(900)->seconds();
        $this->runSendAsWorker();
        $this->assertSame(60, Queue::pushed(ProcessClaims::class)->last()->delay);

        Http::assertNothingSent();
    }
}
