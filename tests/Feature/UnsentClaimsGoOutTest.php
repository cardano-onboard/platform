<?php

namespace Tests\Feature;

use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A claim that has not been sent always gets another run to send it.
 *
 * Before, a claim taken while a run was going had its dispatch swallowed by the running
 * job's unique lock, and a claim the backend refused waited until some other claim
 * happened to arrive. Neither now depends on another claim.
 */
class UnsentClaimsGoOutTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cardano.push_delay_seconds' => 20]);

        $this->campaign = Campaign::factory()->for(User::factory())->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['key' => 'bucket', 'backend' => 'phyrhose']);
    }

    private function unsentClaim(): Claim
    {
        return Claim::factory()
            ->for(Code::factory()->for($this->campaign)->create(['lovelace' => 2_000_000]))
            ->create(['transaction_id' => null]);
    }

    /** @var list<Claim>|null what the fake backend takes; null refuses the submission */
    private ?array $takes = null;

    /** @var (callable(): void)|null run inside the submission, as though during the run */
    private $during = null;

    private bool $faked = false;

    /**
     * One fake for the whole test, reading what to answer from the properties above, so a
     * test can switch the backend between refusing and taking claims.
     */
    private function fakeBackend(): void
    {
        if ($this->faked) {
            return;
        }

        $this->faked = true;

        Http::fake(function () {
            if ($this->during !== null) {
                ($this->during)();
            }

            if ($this->takes === null) {
                return Http::response(['status' => 'error', 'message' => 'no funds']);
            }

            return Http::response([
                'status' => 'ok',
                'data' => [null, ['items' => array_map(fn (Claim $claim) => [
                    'pooCode' => $claim->code->code,
                    'recipientAddress' => $claim->address,
                    'purchaseId' => 'purchase-'.$claim->id,
                ], $this->takes)]],
            ]);
        });
    }

    /**
     * @param  list<Claim>  $claims  the claims the backend takes; any others it skips
     */
    private function backendTakes(array $claims, ?callable $during = null): void
    {
        $this->takes = $claims;
        $this->during = $during;
        $this->fakeBackend();
    }

    private function backendRefuses(): void
    {
        $this->takes = null;
        $this->during = null;
        $this->fakeBackend();
    }

    /**
     * Run the job the way a worker picks it up: its unique lock is let go as processing
     * starts, which is what lets the run queue its own successor.
     */
    private function runAsWorker(): void
    {
        $job = new ProcessClaims($this->campaign->id);
        (new UniqueLock(app(CacheRepository::class)))->release($job);
        $job->handle();
    }

    /**
     * On a real queue: while one run is sending, a claim arrives and dispatches a run of its
     * own, as the claim endpoint does. That dispatch has to reach the queue. Held by the
     * running job's unique lock, it used to be dropped.
     */
    public function test_a_claim_dispatched_during_a_run_is_queued_rather_than_swallowed(): void
    {
        config(['queue.default' => 'database']);

        $first = $this->unsentClaim();
        $queuedDuringTheRun = null;

        $this->backendTakes([$first], function () use (&$queuedDuringTheRun) {
            if ($queuedDuringTheRun !== null) {
                return;
            }

            $this->unsentClaim();
            $pending = ProcessClaims::dispatch($this->campaign->id);
            unset($pending);

            $queuedDuringTheRun = DB::table('jobs')->whereNull('reserved_at')->count();
        });

        ProcessClaims::dispatch($this->campaign->id);
        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertSame(1, $queuedDuringTheRun, 'the dispatch made during the run did not reach the queue');
        $this->assertSame('purchase-'.$first->id, $first->fresh()->transaction_id);
    }

    /**
     * On a real queue with one try per job, a run that finds another running is dropped
     * rather than released, so it never comes back to fail as having used its attempts.
     * The run already going is what sends the claim.
     */
    public function test_an_overlapping_run_is_dropped_and_leaves_no_failed_job(): void
    {
        config(['queue.default' => 'database', 'cardano.push_delay_seconds' => 0]);
        Http::fake();

        $this->unsentClaim();

        $running = Cache::lock('laravel-queue-overlap:'.ProcessClaims::class.':'.$this->campaign->id);
        $this->assertTrue($running->get());

        ProcessClaims::dispatch($this->campaign->id);

        foreach (range(1, 2) as $attempt) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
        }

        $running->release();

        $this->assertSame(0, DB::table('jobs')->count(), 'the overlapping run should be gone from the queue');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'the overlapping run should not have failed');
        Http::assertNothingSent();
    }

    /**
     * A run whose worker died holds the campaign's overlap lock with nobody to release it.
     * Once the lock has lapsed, the next run sends. Before it has, the next run is dropped.
     */
    public function test_a_run_left_holding_the_lock_by_a_dead_worker_stops_blocking_once_it_lapses(): void
    {
        config(['queue.default' => 'database', 'cardano.phyrhose.submit_timeout_seconds' => 60]);

        $claim = $this->unsentClaim();
        $this->backendTakes([$claim]);

        // Taken exactly as the middleware takes it, then abandoned.
        $job = new ProcessClaims($this->campaign->id);
        $overlap = $job->middleware()[0];
        $this->assertSame(180, $overlap->expiresAfter);
        $this->assertTrue(Cache::lock($overlap->getLockKey($job), $overlap->expiresAfter)->get());

        ProcessClaims::dispatch($this->campaign->id);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->assertNull($claim->fresh()->transaction_id, 'the lock has not lapsed yet');

        $this->travel(181)->seconds();

        ProcessClaims::dispatch($this->campaign->id);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertSame('purchase-'.$claim->id, $claim->fresh()->transaction_id);
    }

    public function test_the_overlap_lock_follows_the_send_timeout(): void
    {
        config(['cardano.phyrhose.submit_timeout_seconds' => 100]);

        $this->assertSame(300, (new ProcessClaims($this->campaign->id))->middleware()[0]->expiresAfter);
    }

    /**
     * A claim that arrived during a run, which the run could not have sent, gets a run of
     * its own after the ordinary push delay: nothing went wrong, so there is no backoff.
     */
    public function test_a_run_that_sent_everything_it_tried_queues_the_next_after_the_push_delay(): void
    {
        Queue::fake();

        $first = $this->unsentClaim();
        $this->backendTakes([$first], fn () => $this->unsentClaim());

        (new ProcessClaims($this->campaign->id))->handle();

        Queue::assertPushed(ProcessClaims::class, 1);
        Queue::assertPushed(ProcessClaims::class, fn (ProcessClaims $job) => $job->delay === 20);
    }

    public function test_a_run_that_leaves_nothing_unsent_queues_nothing(): void
    {
        Queue::fake();

        $claim = $this->unsentClaim();
        $this->backendTakes([$claim]);

        (new ProcessClaims($this->campaign->id))->handle();

        Queue::assertNotPushed(ProcessClaims::class);
    }

    /**
     * Held, failed and completed claims are not waiting to be sent, so none of them keeps
     * a run coming back.
     */
    public function test_claims_not_waiting_to_be_sent_do_not_bring_another_run(): void
    {
        Queue::fake();
        Http::fake();

        $held = $this->unsentClaim();
        $held->forceFill(['held_reason' => 'no_credit'])->save();
        $this->unsentClaim()->forceFill(['status' => 'failed'])->save();
        $this->unsentClaim()->forceFill(['status' => 'completed'])->save();

        (new ProcessClaims($this->campaign->id))->handle();

        Http::assertNothingSent();
        Queue::assertNotPushed(ProcessClaims::class);
    }

    /**
     * A backend that keeps refusing is asked again after 30, 60, 120, 240 seconds, never
     * in a tight loop, and a run that goes through starts the count again.
     */
    public function test_runs_that_keep_failing_back_off_and_a_success_resets_it(): void
    {
        Queue::fake();

        $claim = $this->unsentClaim();
        $this->backendRefuses();

        $delays = [];
        foreach (range(1, 4) as $run) {
            $this->runAsWorker();
            $delays[] = Queue::pushed(ProcessClaims::class)->last()->delay;
        }

        $this->assertSame([30, 60, 120, 240], $delays);

        $this->backendTakes([$claim]);
        $this->runAsWorker();
        $this->assertSame('purchase-'.$claim->id, $claim->fresh()->transaction_id);

        $another = $this->unsentClaim();
        $this->backendRefuses();
        $this->runAsWorker();

        $this->assertSame(30, Queue::pushed(ProcessClaims::class)->last()->delay);
        $this->assertNull($another->fresh()->transaction_id);
    }

    /**
     * A backend that takes some claims and skips others has left claims unsent too, and is
     * backed off the same way rather than asked again at the push delay forever.
     */
    public function test_a_run_the_backend_only_partly_took_backs_off(): void
    {
        Queue::fake();

        $taken = $this->unsentClaim();
        $this->unsentClaim();
        $this->backendTakes([$taken]);

        (new ProcessClaims($this->campaign->id))->handle();

        Queue::assertPushed(ProcessClaims::class, fn (ProcessClaims $job) => $job->delay === 30);
    }

    public function test_the_backoff_never_passes_the_longest_delay_a_queue_accepts(): void
    {
        $this->assertSame(900, ProcessClaims::backoffAfterFailedRuns(20));
        $this->assertSame(30, ProcessClaims::backoffAfterFailedRuns(1));

        config(['cardano.push_delay_seconds' => 0]);
        $this->assertSame(30, ProcessClaims::backoffAfterFailedRuns(1));
    }
}
