<?php

namespace App\Jobs\Concerns;

use App\Models\CampaignTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a job needs to report itself to the campaign page.
 *
 * The job keeps its own constructor and its own shape; all this adds is a nullable
 * `$task_id` the dispatcher passes in. Where there is no task id every method here is a
 * no-op, so a job can still be run straight from a test or a console command.
 *
 * Every write sets `heartbeat_at` explicitly. Liveness is not `updated_at`: a job that has
 * been working on one long step for ten minutes has changed nothing and is perfectly
 * healthy, and reclaiming it as stale would start a second copy of it.
 */
trait TracksCampaignTask
{
    /**
     * The numerator at the last progress write, or null before the first one. Progress is
     * written every so many items rather than every item: a ten thousand code import would
     * otherwise be ten thousand extra writes to say what fifty of them already say.
     */
    private ?int $taskProgressWritten = null;

    /** The task row this run belongs to, or null when the job was run without one. */
    public function task(): ?CampaignTask
    {
        $id = $this->taskId();

        return $id === null ? null : CampaignTask::find($id);
    }

    /** Mark the run started. Called once, at the top of handle(). */
    public function beginTask(?string $stage = null, ?int $total = null): void
    {
        $this->writeTask(array_filter([
            'status' => CampaignTask::STATUS_RUNNING,
            'stage' => $stage,
            'progress_total' => $total,
            'started_at' => now(),
        ], static fn ($value) => $value !== null));
    }

    /** The short label the panel shows while this step runs. */
    public function stage(string $stage): void
    {
        $this->writeTask(['stage' => Str::limit($stage, 48, '')]);
    }

    /**
     * Report how far along the run is.
     *
     * Pass a total where one is known. Where it is not, leave it null: the page then shows
     * an indeterminate bar rather than a percentage of a number nobody counted.
     *
     * $force writes the number whatever the throttle says. For the moments where the
     * numerator is not just a bar position: a run that is about to stop and be picked up
     * later has to leave behind the count it actually reached, because the operator will be
     * looking at it for as long as the pause lasts, and rounding it down to the last
     * throttled write would show a run going backwards each time it resumes.
     */
    public function progress(int $done, ?int $total = null, bool $force = false): void
    {
        $step = max(25, (int) ceil(($total ?? 0) / 50));
        $finished = $total !== null && $done >= $total;

        if (! $force
            && $this->taskProgressWritten !== null
            && ! $finished
            && $done < $this->taskProgressWritten + $step) {
            return;
        }

        $this->taskProgressWritten = $done;

        $this->writeTask(array_filter([
            'progress_done' => max(0, $done),
            'progress_total' => $total,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Where this run got to, for the attempt that picks it up next.
     *
     * Read straight from the row rather than from anything held in memory, because the
     * attempt that wrote it is a different process from the one reading it: that is the
     * entire situation this exists for.
     */
    public function checkpoint(): ?array
    {
        $checkpoint = $this->task()?->checkpoint;

        return is_array($checkpoint) ? $checkpoint : null;
    }

    /**
     * Record where the run has got to.
     *
     * Only ever called once the work it describes is durable. A checkpoint written before
     * its output was stored would send the next attempt past work that no longer exists,
     * and the gap would show up as an archive quietly missing a few hundred stickers.
     */
    public function saveCheckpoint(array $checkpoint): void
    {
        $this->writeTask(['checkpoint' => json_encode($checkpoint)]);
    }

    /** Forget where the run got to, either because it finished or because it cannot be trusted. */
    public function clearCheckpoint(): void
    {
        $this->writeTask(['checkpoint' => null]);
    }

    /** The run finished. Anything the page needs from it goes in $result. */
    public function succeed(array $result = []): void
    {
        $this->writeTask([
            'status' => CampaignTask::STATUS_COMPLETE,
            'stage' => null,
            'result' => $result === [] ? null : json_encode($result),
            'error' => null,
            // The run is over; a cursor into it would only describe work nobody will redo.
            'checkpoint' => null,
            'completed_at' => now(),
        ]);
    }

    /**
     * The run did not finish, and the job knew why.
     *
     * For a refusal the job handles itself, such as a file that is too large to import.
     * A thrown exception arrives at failed() below instead.
     */
    public function failTask(string $message): void
    {
        $this->writeTask([
            'status' => CampaignTask::STATUS_FAILED,
            'stage' => null,
            // The operator reads this. A message longer than a line is a stack trace that
            // escaped, and the log is where that belongs.
            'error' => Str::limit($message, 500),
            'completed_at' => now(),
        ]);
    }

    /**
     * The queue's own hook, called after the last attempt fails.
     *
     * Without this a crashed run leaves the row saying "running" until the stale sweep
     * catches it, and the operator watches a progress bar that will never move.
     */
    public function failed(Throwable $e): void
    {
        $this->failTask($e->getMessage());
    }

    /** The task row id this run was dispatched with, where it was dispatched with one. */
    protected function taskId(): ?string
    {
        return property_exists($this, 'task_id') ? $this->task_id : null;
    }

    /**
     * One write against the task row, through the query builder.
     *
     * Not through the model: these fire several times a run, no listener wants to hear
     * about them, and the row may well have been reclaimed by a newer run in the meantime,
     * in which case updating a stale in-memory copy would undo it.
     */
    private function writeTask(array $attributes): void
    {
        $id = $this->taskId();

        if ($id === null || $attributes === []) {
            return;
        }

        DB::table('campaign_tasks')
            ->where('id', $id)
            ->update($attributes + [
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
