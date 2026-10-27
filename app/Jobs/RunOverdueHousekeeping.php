<?php

namespace App\Jobs;

use App\Support\Housekeeping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queue a run of every housekeeping task that is overdue. Queued by traffic; see
 * App\Support\Housekeeping. Each task runs as its own job, so a slow or failing one does
 * not hold up or take down the others.
 */
class RunOverdueHousekeeping implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // A failed check is simply made again by the next request after the check interval.
    public int $tries = 1;

    public function handle(Housekeeping $housekeeping): void
    {
        foreach ($housekeeping->overdue() as $task) {
            RunHousekeepingTask::dispatch($task);
        }
    }
}
