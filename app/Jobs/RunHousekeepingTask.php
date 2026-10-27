<?php

namespace App\Jobs;

use App\Support\Housekeeping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Run one housekeeping task. See App\Support\Housekeeping. */
class RunHousekeepingTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Not retried by the queue: a failed run leaves the task overdue, and a check queued by
    // traffic runs it again once the wait after the failure has passed.
    public int $tries = 1;

    /**
     * Killed if it runs this long, for a task whose entry in config/housekeeping.php names one. The
     * queue's own limit applies to the rest.
     */
    public ?int $timeout = null;

    public function __construct(public string $task)
    {
        $timeout = config("housekeeping.tasks.{$task}.timeout");
        $this->timeout = $timeout === null ? null : (int) $timeout;
    }

    public function handle(Housekeeping $housekeeping): void
    {
        $housekeeping->run($this->task);
    }
}
