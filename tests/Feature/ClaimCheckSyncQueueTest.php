<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The check chain on the sync queue, which is the default in .env.example.
 *
 * A sync queue runs a job inline and ignores its delay, so a chain cannot wait on it. A
 * submission must not make the request repeat the check until the clock moves on.
 */
class ClaimCheckSyncQueueTest extends TestCase
{
    use RefreshDatabase;

    private int $runs = 0;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'cardano.claim_check.after_submit_seconds' => 40]);

        $this->campaign = Campaign::factory()->for(User::factory())->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['backend' => 'phyrhose']);

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if ($event->job->resolveName() === CheckClaims::class && ++$this->runs > 20) {
                // Stops a chain that restarts itself without end, so it fails here rather
                // than running until the process gives out.
                throw new \RuntimeException('CheckClaims ran more than twenty times for one submission');
            }
        });
    }

    private function fakeBackend(string $status): void
    {
        Http::fake(fn () => Http::response(['status' => 'ok', 'data' => [null, $status === 'completed'
            ? ['status' => 'completed', 'txId' => 'landedhash']
            : ['status' => $status]]]));
    }

    private function waitingClaim(): void
    {
        Claim::factory()
            ->for(Code::factory()->for($this->campaign))
            ->withTransaction()
            ->create(['status' => 'pending']);
    }

    public function test_a_submission_queues_no_chain_on_the_sync_queue(): void
    {
        $this->fakeBackend('completed');
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);

        $this->assertSame(0, $this->runs);
    }

    public function test_a_submission_still_processing_does_not_loop_on_a_queue_that_cannot_wait(): void
    {
        $this->fakeBackend('processing');
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);

        $this->assertSame(0, $this->runs);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers_that_ignore_a_delay(): array
    {
        return ['deferred' => ['deferred'], 'background' => ['background']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('drivers_that_ignore_a_delay')]
    public function test_the_other_drivers_that_run_jobs_at_once_start_no_chain_either(string $driver): void
    {
        config(['queue.default' => $driver, "queue.connections.{$driver}" => ['driver' => $driver]]);
        Queue::fake();
        $this->fakeBackend('processing');
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);

        Queue::assertNothingPushed();
    }

    public function test_skipping_the_chain_is_logged_once_an_hour(): void
    {
        Cache::flush();
        Log::spy();
        $this->fakeBackend('processing');
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        CheckClaims::afterSubmission($this->campaign->id);

        Log::shouldHaveReceived('warning')->once();
    }
}
