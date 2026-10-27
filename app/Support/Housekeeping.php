<?php

namespace App\Support;

use App\Jobs\RunOverdueHousekeeping;
use App\Models\HousekeepingRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Periodic maintenance that runs whether or not a scheduler does.
 *
 * The tasks are listed in config/housekeeping.php. There are two ways one runs. A deployment
 * that runs the scheduler runs each from its entry in routes/console.php. A deployment that
 * does not gets them from traffic: once a task is due, a request queues a check at most once
 * per check interval, and the check queues a run of each task that is overdue. While no task
 * is due, a request reads one cached time and queues nothing, so the queue worker is not
 * woken for tasks that are due a few times a day.
 *
 * Both ways share one record per task in housekeeping_runs. A successful run stamps when it
 * started, however it was started, so traffic does not repeat a task the scheduler has just
 * done. A run holds a lock on that row while it works, so a check does not start a second
 * run of a task that is already running.
 *
 * Taking the lock also counts the run as a failure, in the same update, and a success clears
 * the count. A run whose worker is killed before it can report therefore counts as a failure
 * like one that reports it. Traffic waits after a failure before trying the task again, and
 * each further failure in a row doubles the wait, up to the task's own interval. The wait
 * runs from when the run failed, or from when it started if it died without reporting, and
 * the grace is added to it as it is to the interval. Without the wait, a task that keeps
 * failing would run at every check for as long as requests arrive.
 *
 * Bound as a singleton: the runs this process has open are remembered between the command
 * starting and the command finishing.
 */
class Housekeeping
{
    private const CHECK_KEY = 'housekeeping:check';

    private const NEXT_DUE_KEY = 'housekeeping:next-due';

    // The longest the next due time is trusted without reading the table again. It bounds
    // how late traffic notices a change made outside a run, such as a row edited by hand.
    private const NEXT_DUE_MAX_SECONDS = 3600;

    /**
     * Runs open in this process, by task.
     *
     * @var array<string, array{token: ?string, started_at: Carbon, owner: string}>
     */
    private array $open = [];

    /** @return array<string, array{command: string, every_minutes: int}> */
    public function tasks(): array
    {
        return config('housekeeping.tasks', []);
    }

    /**
     * Queue a check for overdue tasks, if a task is due and no check was queued within the
     * check interval.
     *
     * Called by every request once its response has been sent. The time the next task falls
     * due is cached, so a request while nothing is due costs one cache read. When the cached
     * time is missing it is worked out again from the table, with one query, and kept until
     * that time comes, or for an hour at most. Once it has come, it is kept for one check
     * interval: the task stays due until a run takes its lock, and every run forgets the
     * cached time when it finishes, so the next request works it out afresh.
     *
     * Cache::add is atomic, so however many requests find a task due together, one of them
     * queues the check and the others do nothing but fail to add a key that is already there.
     *
     * Skipped on the sync queue driver, which would run the tasks inside the web process
     * that is serving the request. A deployment on that driver runs them from the
     * scheduler.
     */
    public function checkFromTraffic(): void
    {
        if (! config('housekeeping.on_traffic') || config('queue.default') === 'sync') {
            return;
        }

        $seconds = max(1, (int) config('housekeeping.check_every_seconds', 60));
        $dueAt = Cache::get(self::NEXT_DUE_KEY);

        if ($dueAt === null) {
            $dueAt = $this->nextDueAt()->getTimestamp();
            $wait = $dueAt - now()->getTimestamp();

            Cache::put(self::NEXT_DUE_KEY, $dueAt, $wait > 0 ? min($wait, self::NEXT_DUE_MAX_SECONDS) : $seconds);
        }

        if ((int) $dueAt > now()->getTimestamp()) {
            return;
        }

        if (Cache::add(self::CHECK_KEY, true, $seconds)) {
            RunOverdueHousekeeping::dispatch();
        }
    }

    /**
     * The tasks traffic should run now.
     *
     * @return list<string>
     */
    public function overdue(): array
    {
        $now = now();
        $rows = $this->rows();
        $due = [];

        foreach (array_keys($this->tasks()) as $name) {
            $from = $this->dueFrom($name, $rows->get($name));

            if ($from === null || $from->lte($now)) {
                $due[] = $name;
            }
        }

        return $due;
    }

    /**
     * Run a task now, if it is due and nothing else is running it.
     *
     * Returns false when the task is already running elsewhere, or is no longer due: two
     * checks can each queue a run of the same task, and the second to reach a worker finds
     * that the first has already succeeded, or has failed and left the task waiting. Throws
     * when the command fails, after releasing the lock and without stamping a success, so
     * the queued run is recorded as failed.
     */
    public function run(string $task): bool
    {
        $command = $this->tasks()[$task]['command'] ?? null;

        if ($command === null) {
            Log::warning('Housekeeping: unknown task', ['task' => $task]);

            return false;
        }

        if (! $this->open($task, 'runner', requireLock: true, onlyIfDue: true)) {
            Log::info('Housekeeping: task already running or no longer due, skipped', ['task' => $task]);

            return false;
        }

        $exitCode = null;

        try {
            $exitCode = Artisan::call($command);
        } finally {
            $this->close($task, 'runner', $exitCode === 0);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException("Housekeeping task {$task} ({$command}) exited with code {$exitCode}");
        }

        Log::info('Housekeeping: task completed', ['task' => $task]);

        return true;
    }

    /**
     * A command started. If it is a task's command and this process is not already running
     * that task through run(), this is the scheduler or an operator running it directly:
     * take the lock so traffic leaves it alone, and remember when it started.
     *
     * The run goes ahead whether or not the lock was free, and whether or not the task is
     * due; a command cannot be refused from here, and the scheduler already keeps its own
     * runs from overlapping.
     */
    public function commandStarting(string $command): void
    {
        $task = $this->taskFor($command);

        if ($task !== null && ! isset($this->open[$task])) {
            $this->open($task, 'observer', requireLock: false, onlyIfDue: false);
        }
    }

    /** A command finished. Close the run commandStarting opened for it, if it opened one. */
    public function commandFinished(string $command, int $exitCode): void
    {
        $task = $this->taskFor($command);

        if ($task !== null) {
            $this->close($task, 'observer', $exitCode === 0);
        }
    }

    private function taskFor(string $command): ?string
    {
        foreach ($this->tasks() as $name => $task) {
            if ($task['command'] === $command) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The earliest time any task falls due for traffic, from one query. Now, if one already
     * has.
     */
    private function nextDueAt(): Carbon
    {
        $now = now();
        $rows = $this->rows();
        $earliest = null;

        foreach (array_keys($this->tasks()) as $name) {
            $from = $this->dueFrom($name, $rows->get($name)) ?? $now;

            if ($earliest === null || $from->lt($earliest)) {
                $earliest = $from;
            }
        }

        return $earliest ?? $now->copy()->addSeconds(self::NEXT_DUE_MAX_SECONDS);
    }

    /** @return Collection<string, HousekeepingRun> */
    private function rows(): Collection
    {
        return HousekeepingRun::query()
            ->whereIn('task', array_keys($this->tasks()))
            ->get(['task', 'last_run_at', 'last_attempt_at', 'last_failed_at', 'failures', 'locked_until'])
            ->keyBy('task');
    }

    /**
     * When a task next falls due for traffic, or null when nothing holds it back.
     *
     * It is due once all three of these have passed: its interval plus the grace since its
     * last success, the wait plus the grace since its last failure, and the lock of a run in
     * progress. A task that has never succeeded has no interval to wait out, and one whose
     * last run succeeded has no failure to wait after.
     */
    private function dueFrom(string $task, ?HousekeepingRun $row): ?Carbon
    {
        if ($row === null) {
            return null;
        }

        $every = max(1, (int) ($this->tasks()[$task]['every_minutes'] ?? 0));
        $grace = (int) config('housekeeping.grace_minutes', 0);
        $bounds = [];

        if ($row->last_run_at !== null) {
            $bounds[] = $row->last_run_at->copy()->addMinutes($every + $grace);
        }

        // The wait runs from the failure a run reported, or, for a run that died without
        // reporting, from when it took the lock: whichever is later. The grace is added for
        // the same reason as to the interval. Once the wait has grown to the interval, a
        // failed scheduled run's wait ends on the minute the next scheduled run starts.
        $failedAt = collect([$row->last_attempt_at, $row->last_failed_at])->filter()->max();

        if ($row->failures > 0 && $failedAt !== null) {
            $bounds[] = $failedAt->copy()->addMinutes($this->retryAfterMinutes($row->failures, $every) + $grace);
        }

        if ($row->locked_until !== null) {
            // A lock is free only once its time has passed, and the column holds whole
            // seconds, so the first moment it can be taken is the second after.
            $bounds[] = $row->locked_until->copy()->addSecond();
        }

        return $bounds === [] ? null : max($bounds);
    }

    /**
     * How long traffic waits after the latest of $failures failed runs in a row: the
     * configured wait, doubled for each failure after the first, and never longer than the
     * task's own interval, so a task that keeps failing is still tried once per interval.
     */
    private function retryAfterMinutes(int $failures, int $everyMinutes): int
    {
        $base = max(1, (int) config('housekeeping.retry_after_minutes', 15));

        // Twenty doublings is far past any interval, and keeps the product inside an integer.
        return min($everyMinutes, $base * 2 ** min($failures - 1, 20));
    }

    private function open(string $task, string $owner, bool $requireLock, bool $onlyIfDue): bool
    {
        if (isset($this->open[$task])) {
            return false;
        }

        $now = now();
        $token = $this->takeLock($task, $now, $onlyIfDue);

        if ($token === null && $requireLock) {
            return false;
        }

        $this->open[$task] = ['token' => $token, 'started_at' => $now, 'owner' => $owner];

        return true;
    }

    private function close(string $task, string $owner, bool $succeeded): void
    {
        $run = $this->open[$task] ?? null;

        if ($run === null || $run['owner'] !== $owner) {
            return;
        }

        unset($this->open[$task]);

        if ($succeeded) {
            // Stamped whether or not this run still holds the lock, and never moved back:
            // a run that took longer than the lock still did the work it was asked for.
            // Clearing the failures here undoes the count taking the lock added, including
            // for a run that started in the same second as the stamp already there.
            HousekeepingRun::query()
                ->where('task', $task)
                ->where(fn ($query) => $query->whereNull('last_run_at')->orWhere('last_run_at', '<=', $run['started_at']))
                ->update(['last_run_at' => $run['started_at'], 'failures' => 0]);
        } else {
            // The wait runs from when the run failed rather than from when it started, so a
            // run that fails after twenty minutes still waits the full time. The failure was
            // already counted when the run took the lock. Never moved back.
            $failedAt = now();

            HousekeepingRun::query()
                ->where('task', $task)
                ->where(fn ($query) => $query->whereNull('last_failed_at')->orWhere('last_failed_at', '<', $failedAt))
                ->update(['last_failed_at' => $failedAt]);
        }

        if ($run['token'] !== null) {
            HousekeepingRun::query()
                ->where('task', $task)
                ->where('locked_by', $run['token'])
                ->update(['locked_until' => null, 'locked_by' => null]);
        }

        // The task's next due time has moved, later on a success and to the end of the wait
        // on a failure, so the next request works it out again.
        Cache::forget(self::NEXT_DUE_KEY);
    }

    /**
     * Take the task's lock if it is free or abandoned. Returns the token that holds it, or
     * null if another run does. One conditional update, so two processes racing for the
     * same lock cannot both see themselves win.
     *
     * The same update stamps the attempt and counts it as a failure, which a success clears
     * when it closes. A run whose process dies before it can close has then already been
     * counted, and traffic waits before trying the task again rather than starting it at the
     * first check after the lock expires.
     *
     * With $onlyIfDue the lock is taken only if the task is due. The row is read and judged
     * first, and the update then matches only if the row is still as it was read, so a run
     * that succeeded or failed in between leaves this one without the lock. Judging after
     * taking the lock would not work: by then this update has overwritten the attempt time
     * and the failure count that the wait is worked out from.
     */
    private function takeLock(string $task, Carbon $now, bool $onlyIfDue): ?string
    {
        HousekeepingRun::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'task' => $task,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $query = HousekeepingRun::query()
            ->where('task', $task)
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', $now));

        if ($onlyIfDue) {
            $row = HousekeepingRun::query()->where('task', $task)->first();

            if ($row === null) {
                return null;
            }

            $from = $this->dueFrom($task, $row);

            if ($from !== null && $from->gt($now)) {
                return null;
            }

            $this->whereUnchanged($query, $row);
        }

        $token = (string) Str::ulid();

        $taken = $query->increment('failures', 1, [
            'locked_until' => $now->copy()->addMinutes(max(1, (int) config('housekeeping.lock_minutes', 60))),
            'locked_by' => $token,
            'last_attempt_at' => $now,
        ]);

        return $taken === 1 ? $token : null;
    }

    /**
     * Match the row only while its success, attempt, failure and failure count are those
     * $row was read with. Taking the lock changes the attempt and the count, a success
     * changes the last run, and a failure changes the last failure, so any run that started
     * or finished since the read is seen.
     */
    private function whereUnchanged(Builder $query, HousekeepingRun $row): void
    {
        foreach (['last_run_at', 'last_attempt_at', 'last_failed_at'] as $column) {
            $row->{$column} === null
                ? $query->whereNull($column)
                : $query->where($column, $row->{$column});
        }

        $query->where('failures', $row->failures);
    }
}
