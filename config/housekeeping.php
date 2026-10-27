<?php

/*
|--------------------------------------------------------------------------
| Housekeeping
|--------------------------------------------------------------------------
|
| Periodic maintenance that has to happen whether or not anything runs the
| scheduler. A deployment that runs `schedule:run` gets these from the entries
| in routes/console.php. A deployment that does not, such as one that scales
| to zero between visits, gets them from traffic: once a task falls due, and
| at most once per check interval, a request queues a small job, after its
| response has gone, that runs whichever of these tasks is overdue. While
| nothing is due, requests queue nothing.
|
| Both paths record when each task last succeeded, in the housekeeping_runs
| table, so a task the scheduler has just run is not run again by traffic.
| They also record each attempt, so traffic waits before trying a failed task
| again instead of trying it at every check.
|
*/

return [

    // Queue the overdue-task check from incoming requests. Turn it off on a deployment
    // that runs the scheduler and would rather rely on it alone.
    'on_traffic' => (bool) env('HOUSEKEEPING_ON_TRAFFIC', true),

    // The least time between two checks queued by traffic, however many requests arrive.
    // A check is queued only once a task is due, so this matters only while one is.
    'check_every_seconds' => (int) env('HOUSEKEEPING_CHECK_EVERY_SECONDS', 60),

    // How long a task's run is presumed alive. A run that dies without releasing its lock
    // (a worker killed mid-task) holds the task for this long and no longer.
    'lock_minutes' => (int) env('HOUSEKEEPING_LOCK_MINUTES', 60),

    // How long traffic waits after a failed run before trying the task again, counted from
    // when the run failed. Each further failure in a row doubles the wait, up to the task's
    // own interval, and the grace below is added to it. A run that dies without finishing
    // counts as a failure from when it started, and is not tried again before its lock
    // expires.
    'retry_after_minutes' => (int) env('HOUSEKEEPING_RETRY_AFTER_MINUTES', 15),

    // How late a task may be before traffic runs it. A scheduled run starts on the minute
    // its interval comes round, so without this a check landing in that same minute would
    // find the task due and start a second run alongside the scheduler's. It is added to
    // the wait after a failure as well: once that wait has grown to the interval, a failed
    // scheduled run's wait ends on the minute the next scheduled run starts.
    'grace_minutes' => 5,

    /*
     * The tasks, keyed by the name their Schedule entry carries. Each is an Artisan
     * command and how often it should run. Adding a task is one line here, plus a
     * Schedule entry in routes/console.php for deployments that run the scheduler.
     */
    'tasks' => [
        'sync-known-assets' => ['command' => 'assets:sync-registry', 'every_minutes' => (int) env('HOUSEKEEPING_ASSET_SYNC_EVERY_MINUTES', 360)],
        'prune-qr-exports' => ['command' => 'qr:prune-exports', 'every_minutes' => (int) env('HOUSEKEEPING_QR_PRUNE_EVERY_MINUTES', 1440)],
    ],

];
