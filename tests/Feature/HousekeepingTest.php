<?php

namespace Tests\Feature;

use App\Jobs\RunHousekeepingTask;
use App\Jobs\RunOverdueHousekeeping;
use App\Models\HousekeepingRun;
use App\Support\Housekeeping;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Housekeeping on a deployment with no scheduler, and on one with a scheduler.
 *
 * Traffic queues a check only once a task is due, and then at most once per interval; the
 * check queues each overdue task; a task stamps its last run only when it succeeds,
 * whichever way it was started; a failed task, including one whose run died, waits before
 * it is tried again, twice as long after each further failure in a row; and a task already
 * running, no longer due, or changed by another run since it was read is not started again.
 * The prune task is the one exercised end to end, because it can be made to fail for real by
 * giving it an age it cannot read.
 */
class HousekeepingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // The traffic check stands aside on the sync driver, which is what the suite runs
        // on, so the tests that exercise it name a real queue driver. Bus::fake keeps the
        // queued jobs from going anywhere.
        config()->set('queue.default', 'database');
        config()->set('housekeeping.on_traffic', true);
        config()->set('housekeeping.check_every_seconds', 60);
        config()->set('housekeeping.retry_after_minutes', 15);
        config()->set('housekeeping.grace_minutes', 5);

        // Stamps hold whole seconds, and many tests compare one against the time they took
        // before the run. A clock left running could cross a second between the two.
        $this->freezeSecond();
    }

    public function test_many_requests_within_the_interval_queue_one_check(): void
    {
        Bus::fake([RunOverdueHousekeeping::class]);

        for ($i = 0; $i < 5; $i++) {
            $this->get('/up')->assertOk();
        }

        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 1);

        $this->travel(30)->seconds();
        $this->get('/up');
        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 1);

        $this->travel(31)->seconds();
        $this->get('/up');
        $this->get('/up');
        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 2);
    }

    public function test_web_api_and_claim_subdomain_requests_all_count_as_traffic(): void
    {
        config()->set('cardano.claim_domain', 'claim.onbd.test');
        Route::middleware('api')->domain('claim.onbd.test')->group(base_path('routes/claim.php'));

        Bus::fake([RunOverdueHousekeeping::class]);

        $this->get('/login');
        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 1);

        cache()->flush();
        $this->postJson('/api/claim/v1/01J00000000000000000000000', []);
        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 2);

        cache()->flush();
        $this->postJson('http://claim.onbd.test/v1/01J00000000000000000000000', []);
        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 3);
    }

    public function test_the_off_switch_stops_traffic_queueing_checks(): void
    {
        config()->set('housekeeping.on_traffic', false);
        Bus::fake([RunOverdueHousekeeping::class]);

        $this->get('/up')->assertOk();

        Bus::assertNotDispatched(RunOverdueHousekeeping::class);
    }

    public function test_traffic_queues_nothing_on_the_sync_driver(): void
    {
        config()->set('queue.default', 'sync');
        Bus::fake([RunOverdueHousekeeping::class]);

        $this->get('/up')->assertOk();

        Bus::assertNotDispatched(RunOverdueHousekeeping::class);
    }

    public function test_the_check_queues_overdue_tasks_and_leaves_fresh_ones(): void
    {
        config()->set('housekeeping.grace_minutes', 5);
        Bus::fake([RunHousekeepingTask::class]);

        // Never run: overdue.
        (new RunOverdueHousekeeping)->handle(app(Housekeeping::class));
        Bus::assertDispatchedTimes(RunHousekeepingTask::class, 2);

        Bus::fake([RunHousekeepingTask::class]);
        $this->stamp('sync-known-assets', now()->subMinutes(360 + 6));
        $this->stamp('prune-qr-exports', now()->subHours(3));

        (new RunOverdueHousekeeping)->handle(app(Housekeeping::class));

        Bus::assertDispatched(RunHousekeepingTask::class, fn ($job) => $job->task === 'sync-known-assets');
        Bus::assertNotDispatched(RunHousekeepingTask::class, fn ($job) => $job->task === 'prune-qr-exports');

        // Due, but inside the grace a scheduled run is given: left alone.
        Bus::fake([RunHousekeepingTask::class]);
        $this->stamp('sync-known-assets', now()->subMinutes(360 + 2));

        (new RunOverdueHousekeeping)->handle(app(Housekeeping::class));

        Bus::assertNotDispatched(RunHousekeepingTask::class);
    }

    public function test_the_interval_comes_from_config(): void
    {
        config()->set('housekeeping.grace_minutes', 0);
        config()->set('housekeeping.tasks.prune-qr-exports.every_minutes', 60);
        $this->stamp('sync-known-assets', now());
        $this->stamp('prune-qr-exports', now()->subMinutes(61));

        $this->assertSame(['prune-qr-exports'], app(Housekeeping::class)->overdue());
    }

    public function test_a_successful_run_stamps_when_it_started_and_releases_its_lock(): void
    {
        $started = now()->startOfSecond();

        (new RunHousekeepingTask('prune-qr-exports'))->handle(app(Housekeeping::class));

        $row = HousekeepingRun::where('task', 'prune-qr-exports')->sole();
        $this->assertTrue($row->last_run_at->equalTo($started));
        $this->assertNull($row->locked_by);
        $this->assertNull($row->locked_until);
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());
    }

    public function test_a_failed_run_stamps_nothing_and_a_check_retries_it_after_the_wait(): void
    {
        // An age the command cannot read: it refuses to prune and exits as a failure.
        config()->set('cardano.qr_storage.ttl_days', 'soon');

        $this->runFailing('prune-qr-exports');

        $row = $this->row('prune-qr-exports');
        $this->assertNull($row->last_run_at);
        $this->assertNull($row->locked_by, 'a failed run must release its lock');
        $this->assertSame(1, $row->failures);
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());

        // The fifteen-minute wait, plus the five-minute grace.
        $this->travel(20)->minutes();
        $this->assertContains('prune-qr-exports', app(Housekeeping::class)->overdue());

        config()->set('cardano.qr_storage.ttl_days', 7);
        (new RunHousekeepingTask('prune-qr-exports'))->handle(app(Housekeeping::class));

        $this->assertNotNull($row->fresh()->last_run_at);
    }

    public function test_a_failed_task_is_not_queued_again_at_the_next_check(): void
    {
        $this->stamp('sync-known-assets', now());
        config()->set('cardano.qr_storage.ttl_days', 'soon');

        $this->runFailing('prune-qr-exports');

        Bus::fake([RunHousekeepingTask::class]);

        $this->travel(1)->minutes();
        (new RunOverdueHousekeeping)->handle(app(Housekeeping::class));
        $this->travel(18)->minutes();
        (new RunOverdueHousekeeping)->handle(app(Housekeeping::class));

        Bus::assertNotDispatched(RunHousekeepingTask::class);

        // Twenty minutes after the failure: the fifteen-minute wait, plus the grace.
        $this->travel(1)->minutes();
        (new RunOverdueHousekeeping)->handle(app(Housekeeping::class));

        Bus::assertDispatchedTimes(RunHousekeepingTask::class, 1);
        Bus::assertDispatched(RunHousekeepingTask::class, fn ($job) => $job->task === 'prune-qr-exports');
    }

    public function test_the_wait_doubles_with_each_failure_in_a_row_and_stops_at_the_interval_plus_the_grace(): void
    {
        config()->set('housekeeping.tasks.prune-qr-exports.every_minutes', 60);
        config()->set('cardano.qr_storage.ttl_days', 'soon');
        $housekeeping = app(Housekeeping::class);

        // Each wait plus the five-minute grace. Without the grace, a wait that has reached
        // the interval would end on the minute a scheduled run of the task starts.
        foreach ([1 => 20, 2 => 35, 3 => 65, 4 => 65, 5 => 65] as $failures => $wait) {
            $this->runFailing('prune-qr-exports');
            $this->assertSame($failures, $this->row('prune-qr-exports')->failures);

            $this->travel($wait - 1)->minutes();
            $this->assertNotContains('prune-qr-exports', $housekeeping->overdue(), "due less than {$wait} minutes after failure {$failures}");
            $this->assertFalse($housekeeping->run('prune-qr-exports'), "run less than {$wait} minutes after failure {$failures}");

            $this->travel(1)->minutes();
            $this->assertContains('prune-qr-exports', $housekeeping->overdue(), "not due {$wait} minutes after failure {$failures}");
        }
    }

    public function test_the_wait_runs_from_when_the_run_failed(): void
    {
        // The command runs with itself bound as $this, so the test is passed in by name.
        $test = $this;
        $this->task('slow-failure', function () use ($test) {
            // The command fails twenty minutes after it starts.
            $test->travel(20)->minutes();

            return 1;
        });
        $started = now();

        $this->runFailing('slow-failure');

        $row = $this->row('slow-failure');
        $this->assertTrue($row->last_attempt_at->equalTo($started));
        $this->assertTrue($row->last_failed_at->equalTo($started->copy()->addMinutes(20)));

        // Counted from the start, the wait would already be over.
        $this->assertNotContains('slow-failure', app(Housekeeping::class)->overdue());

        $this->travel(19)->minutes();
        $this->assertNotContains('slow-failure', app(Housekeeping::class)->overdue());
        $this->travel(1)->minutes();
        $this->assertContains('slow-failure', app(Housekeeping::class)->overdue());
    }

    public function test_a_run_that_took_the_lock_and_never_finished_counts_as_a_failure(): void
    {
        config()->set('housekeeping.lock_minutes', 5);
        $started = now();

        // A process started the command and died before it finished: it never reports.
        (new Housekeeping)->commandStarting('qr:prune-exports');

        $row = $this->row('prune-qr-exports');
        $this->assertSame(1, $row->failures);
        $this->assertTrue($row->last_attempt_at->equalTo($started));
        $this->assertNotNull($row->locked_by);
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue(), 'a task whose lock is held is not due');

        // The lock has expired, but the run that held it counts as a failure, so the task
        // waits out the retry time and grace from when that run started, not the lock alone.
        $this->travel(6)->minutes();
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());
        $this->assertFalse(app(Housekeeping::class)->run('prune-qr-exports'));

        $this->travel(13)->minutes();
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());
        $this->travel(1)->minutes();
        $this->assertContains('prune-qr-exports', app(Housekeeping::class)->overdue());
    }

    public function test_a_lock_is_held_through_the_last_second_of_its_time(): void
    {
        $this->stamp('sync-known-assets', now());
        // The clock is frozen on a whole second, the precision the column holds.
        HousekeepingRun::create([
            'task' => 'prune-qr-exports',
            'locked_until' => now(),
            'locked_by' => 'someone-else',
        ]);
        Bus::fake([RunOverdueHousekeeping::class]);

        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());
        $this->assertFalse(app(Housekeeping::class)->run('prune-qr-exports'));
        $this->get('/up')->assertOk();
        Bus::assertNotDispatched(RunOverdueHousekeeping::class);

        $this->travel(1)->seconds();

        $this->assertContains('prune-qr-exports', app(Housekeeping::class)->overdue());
        $this->get('/up')->assertOk();
        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 1);
        $this->assertTrue(app(Housekeeping::class)->run('prune-qr-exports'));
    }

    public function test_a_queued_run_counts_as_a_failure_from_the_moment_it_takes_the_lock(): void
    {
        $seen = null;
        $this->task('probe', function () use (&$seen) {
            $seen = HousekeepingRun::where('task', 'probe')->sole();
        });
        $started = now();

        $this->assertTrue(app(Housekeeping::class)->run('probe'));

        // What a worker killed at this point would have left behind.
        $this->assertSame(1, $seen->failures);
        $this->assertTrue($seen->last_attempt_at->equalTo($started));
        $this->assertNotNull($seen->locked_by);

        $this->assertSame(0, $this->row('probe')->failures);
    }

    public function test_a_success_clears_the_failures(): void
    {
        config()->set('cardano.qr_storage.ttl_days', 'soon');

        $this->runFailing('prune-qr-exports');
        $this->travel(15 + 5)->minutes();
        $this->runFailing('prune-qr-exports');
        $this->assertSame(2, $this->row('prune-qr-exports')->failures);

        $this->travel(30 + 5)->minutes();
        $started = now();
        config()->set('cardano.qr_storage.ttl_days', 7);
        $this->assertTrue(app(Housekeeping::class)->run('prune-qr-exports'));

        $row = $this->row('prune-qr-exports');
        $this->assertSame(0, $row->failures);
        $this->assertTrue($row->last_run_at->equalTo($started));

        // The next failure, a day later, waits the first wait again rather than a longer one.
        $this->travel(1440 + 5)->minutes();
        config()->set('cardano.qr_storage.ttl_days', 'soon');
        $this->runFailing('prune-qr-exports');
        $this->assertSame(1, $this->row('prune-qr-exports')->failures);

        $this->travel(15 + 5 - 1)->minutes();
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());
        $this->travel(1)->minutes();
        $this->assertContains('prune-qr-exports', app(Housekeeping::class)->overdue());
    }

    public function test_a_run_skips_a_task_that_is_no_longer_due(): void
    {
        // Two checks queued the same task. The first run succeeds; the second finds it done.
        $this->assertTrue((new Housekeeping)->run('prune-qr-exports'));
        $before = $this->row('prune-qr-exports');

        $this->travel(5)->seconds();
        $this->assertFalse((new Housekeeping)->run('prune-qr-exports'));

        $after = $this->row('prune-qr-exports');
        $this->assertTrue($after->last_run_at->equalTo($before->last_run_at));
        $this->assertTrue($after->last_attempt_at->equalTo($before->last_attempt_at));
        $this->assertSame(0, $after->failures);
        $this->assertNull($after->locked_by);

        // The first run fails; the second waits out the failure rather than adding to it.
        $this->travel(1440 + 5)->minutes();
        config()->set('cardano.qr_storage.ttl_days', 'soon');
        $this->runFailing('prune-qr-exports');

        $this->travel(5)->seconds();
        $this->assertFalse((new Housekeeping)->run('prune-qr-exports'));
        $this->assertSame(1, $this->row('prune-qr-exports')->failures);
        $this->assertNull($this->row('prune-qr-exports')->locked_by);
    }

    public function test_a_run_skips_a_task_another_run_finished_after_it_read_the_row(): void
    {
        // Another worker runs the task to the end in the moment between this run reading the
        // row and taking the lock. The lock is free again, so only the row having changed
        // since the read can stop this run.
        $this->betweenReadAndLock('prune-qr-exports', function () {
            $this->assertTrue((new Housekeeping)->run('prune-qr-exports'));
        });

        $this->assertFalse((new Housekeeping)->run('prune-qr-exports'));

        $row = $this->row('prune-qr-exports');
        $this->assertTrue($row->last_run_at->equalTo(now()));
        $this->assertSame(0, $row->failures);
        $this->assertNull($row->locked_by);
    }

    public function test_a_run_skips_a_task_another_run_failed_after_it_read_the_row(): void
    {
        config()->set('cardano.qr_storage.ttl_days', 'soon');

        $this->betweenReadAndLock('prune-qr-exports', function () {
            $this->runFailing('prune-qr-exports');
        });

        $this->assertFalse((new Housekeeping)->run('prune-qr-exports'));

        $row = $this->row('prune-qr-exports');
        $this->assertSame(1, $row->failures, 'the skipped run must not add a failure of its own');
        $this->assertNull($row->locked_by);
    }

    public function test_a_run_skips_a_task_whose_slow_run_reported_a_failure_after_it_read_the_row(): void
    {
        config()->set('housekeeping.lock_minutes', 5);

        // A run started and is still going long after its lock expired.
        $slow = new Housekeeping;
        $slow->commandStarting('qr:prune-exports');

        // Past its lock and past the wait counted from its start, so the task reads as due.
        $this->travel(15 + 5 + 1)->minutes();

        // It reports its failure between this run reading the row and taking the lock. Only
        // the failure time changes: the attempt and the count were set when it started.
        $this->betweenReadAndLock('prune-qr-exports', function () use ($slow) {
            $slow->commandFinished('qr:prune-exports', 1);
        });

        $this->assertFalse((new Housekeeping)->run('prune-qr-exports'));

        $row = $this->row('prune-qr-exports');
        $this->assertTrue($row->last_failed_at->equalTo(now()));
        $this->assertSame(1, $row->failures);
        $this->assertNull($row->locked_by);
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue(), 'the wait now runs from the reported failure');
    }

    public function test_a_success_in_the_same_second_as_the_last_one_still_clears_the_failures(): void
    {
        // Three runs inside one second, with the clock frozen: a success, a failure and a
        // success. Runs the scheduler or an operator starts go ahead whether or not the task
        // is due, so the second and third are started that way.
        $this->assertTrue((new Housekeeping)->run('prune-qr-exports'));

        $failing = new Housekeeping;
        $failing->commandStarting('qr:prune-exports');
        $failing->commandFinished('qr:prune-exports', 1);
        $this->assertSame(1, $this->row('prune-qr-exports')->failures);

        $succeeding = new Housekeeping;
        $succeeding->commandStarting('qr:prune-exports');
        $succeeding->commandFinished('qr:prune-exports', 0);

        $row = $this->row('prune-qr-exports');
        $this->assertSame(0, $row->failures, 'a success must clear the failures even when the last success has the same stamp');
        $this->assertTrue($row->last_run_at->equalTo(now()));
    }

    public function test_a_request_with_nothing_due_queues_nothing_and_reads_the_table_once(): void
    {
        $this->stamp('sync-known-assets', now());
        $this->stamp('prune-qr-exports', now());
        Bus::fake([RunOverdueHousekeeping::class]);
        $reads = $this->countQueriesOn('housekeeping_runs');

        $this->get('/up')->assertOk();
        $this->assertSame(1, $reads(), 'the first request works out when the next task is due');

        for ($i = 0; $i < 5; $i++) {
            $this->travel(10)->minutes();
            $this->get('/up')->assertOk();
        }

        $this->assertSame(1, $reads(), 'later requests read the cached time');
        Bus::assertNotDispatched(RunOverdueHousekeeping::class);
    }

    public function test_a_request_once_a_task_falls_due_queues_exactly_one_check(): void
    {
        config()->set('housekeeping.grace_minutes', 5);
        // Due ten minutes from now, interval and grace included.
        $this->stamp('sync-known-assets', now()->subMinutes(360 + 5 - 10));
        $this->stamp('prune-qr-exports', now());
        Bus::fake([RunOverdueHousekeeping::class]);

        $this->get('/up');
        $this->travel(9)->minutes();
        $this->get('/up');

        Bus::assertNotDispatched(RunOverdueHousekeeping::class);

        $this->travel(1)->minutes();

        for ($i = 0; $i < 5; $i++) {
            $this->get('/up')->assertOk();
        }

        Bus::assertDispatchedTimes(RunOverdueHousekeeping::class, 1);
    }

    public function test_a_run_finishing_makes_the_next_request_look_again(): void
    {
        $this->stamp('sync-known-assets', now());
        $this->stamp('prune-qr-exports', now()->subDay());
        Bus::fake([RunOverdueHousekeeping::class]);

        // The scheduler is running the prune, so traffic finds nothing it may start.
        app(Housekeeping::class)->commandStarting('qr:prune-exports');
        $this->get('/up')->assertOk();
        Bus::assertNotDispatched(RunOverdueHousekeeping::class);

        $reads = $this->countQueriesOn('housekeeping_runs');
        app(Housekeeping::class)->commandFinished('qr:prune-exports', 0);
        $afterRun = $reads();

        $this->get('/up')->assertOk();
        $this->assertGreaterThan($afterRun, $reads(), 'the next request should read the table again');
        Bus::assertNotDispatched(RunOverdueHousekeeping::class);
    }

    public function test_a_task_already_running_is_not_started_again(): void
    {
        HousekeepingRun::create([
            'task' => 'prune-qr-exports',
            'locked_until' => now()->addMinutes(30),
            'locked_by' => 'someone-else',
        ]);

        $this->assertFalse(app(Housekeeping::class)->run('prune-qr-exports'));
        $this->assertNull(HousekeepingRun::where('task', 'prune-qr-exports')->sole()->last_run_at);
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());

        // A lock past its time was left by a run that died, and is taken over.
        HousekeepingRun::where('task', 'prune-qr-exports')->update(['locked_until' => now()->subMinute()]);

        $this->assertTrue(app(Housekeeping::class)->run('prune-qr-exports'));
        $this->assertNotNull(HousekeepingRun::where('task', 'prune-qr-exports')->sole()->last_run_at);
    }

    public function test_a_scheduled_run_stamps_the_same_last_run(): void
    {
        $started = now()->startOfSecond();
        $this->withConsoleEventsAsOutsideTests();

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $row = HousekeepingRun::where('task', 'prune-qr-exports')->sole();
        $this->assertTrue($row->last_run_at->equalTo($started));
        $this->assertNull($row->locked_by);
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());
    }

    public function test_a_failed_scheduled_run_stamps_nothing_and_traffic_waits_before_retrying(): void
    {
        config()->set('cardano.qr_storage.ttl_days', 'soon');
        $this->withConsoleEventsAsOutsideTests();

        $this->artisan('qr:prune-exports')->assertFailed();

        $row = $this->row('prune-qr-exports');
        $this->assertNull($row->last_run_at);
        $this->assertSame(1, $row->failures);
        $this->assertNotContains('prune-qr-exports', app(Housekeeping::class)->overdue());

        $this->travel(15 + 5)->minutes();
        $this->assertContains('prune-qr-exports', app(Housekeeping::class)->overdue());
    }

    public function test_traffic_does_not_start_a_task_the_scheduler_is_running(): void
    {
        // The scheduler's process has started the command and not yet finished it.
        app(Housekeeping::class)->commandStarting('qr:prune-exports');

        // A queue worker is another process, with its own instance.
        $this->assertFalse((new Housekeeping)->run('prune-qr-exports'));

        app(Housekeeping::class)->commandFinished('qr:prune-exports', 0);

        $this->assertNull(HousekeepingRun::where('task', 'prune-qr-exports')->value('locked_by'));
        $this->assertNotNull(HousekeepingRun::where('task', 'prune-qr-exports')->value('last_run_at'));
    }

    public function test_every_task_has_a_schedule_entry_that_runs_its_command(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy('description');

        foreach (config('housekeeping.tasks') as $name => $task) {
            // A task marked unscheduled runs from traffic only, on a host that has no scheduler.
            if (($task['scheduled'] ?? true) === false) {
                continue;
            }

            $this->assertTrue($events->has($name), "no Schedule entry is named $name");
            $this->assertStringEndsWith($task['command'], trim(str_replace("'", '', $events[$name]->command)));
        }
    }

    /**
     * Outside tests the console kernel turns every command's start and finish into the
     * CommandStarting and CommandFinished events, which is how a run the scheduler starts is
     * recorded. Under tests the framework leaves that wiring out, so it is put back here.
     */
    private function withConsoleEventsAsOutsideTests(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
    }

    private function stamp(string $task, $at): void
    {
        HousekeepingRun::updateOrCreate(['task' => $task], ['last_run_at' => $at]);
    }

    private function row(string $task): HousekeepingRun
    {
        return HousekeepingRun::where('task', $task)->sole();
    }

    /**
     * Add a task whose command is $body, as housekeeping:{$task}, due once a day. Artisan is
     * built once and keeps the commands it was built with, so it is built again to include
     * the new one.
     */
    private function task(string $task, Closure $body): void
    {
        Artisan::command("housekeeping:{$task}", $body);
        $this->app->make(ConsoleKernel::class)->setArtisan(null);
        config()->set("housekeeping.tasks.{$task}", ['command' => "housekeeping:{$task}", 'every_minutes' => 1440]);
    }

    /**
     * Call $between once, straight after run() reads $task's row to judge whether it is due,
     * and before it takes the lock. Query listeners run as soon as each query has, so this
     * is the moment a second worker would act in.
     */
    private function betweenReadAndLock(string $task, Closure $between): void
    {
        $called = false;

        DB::listen(function ($query) use ($task, $between, &$called) {
            $isTheRead = str_starts_with($query->sql, 'select')
                && str_contains($query->sql, 'housekeeping_runs')
                && str_ends_with($query->sql, 'limit 1')
                && $query->bindings === [$task];

            if ($called || ! $isTheRead) {
                return;
            }

            $called = true;
            $between();
        });
    }

    /** Run a task through its queued job, and require the run to fail. */
    private function runFailing(string $task): void
    {
        try {
            (new RunHousekeepingTask($task))->handle(app(Housekeeping::class));
            $this->fail("a failed run of {$task} should fail its job");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($task, $e->getMessage());
        }
    }

    /**
     * Count the queries that touch $table from here on. Returns a function that reads the
     * count so far.
     */
    private function countQueriesOn(string $table): Closure
    {
        $count = 0;

        DB::listen(function ($query) use ($table, &$count) {
            if (str_contains($query->sql, $table)) {
                $count++;
            }
        });

        return function () use (&$count) {
            return $count;
        };
    }
}
