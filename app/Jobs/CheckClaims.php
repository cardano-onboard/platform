<?php

namespace App\Jobs;

use App\Contracts\ReportsTaskProgress;
use App\Jobs\Concerns\TracksCampaignTask;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Services\ClaimStatusChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Asks the transaction backend about every claim on one campaign still awaiting an answer.
 *
 * Two callers dispatch this. The scheduler sends one every few minutes with no task row,
 * and nobody is watching it. The Check claims button sends one with a task row, and an
 * operator is watching the page for the answer, so that run reports itself through the
 * row like every other job on the page and the page reloads the claims when it finishes.
 */
class CheckClaims implements ReportsTaskProgress, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksCampaignTask;

    /** What this run is called on the campaign page. */
    public const TASK_TYPE = 'check-claims';

    /**
     * Kept as a class constant because it is the ceiling callers and tests refer to; the
     * retry logic itself lives with the pass in App\Services\ClaimStatusChecker.
     */
    public const MAX_RETRIES = ClaimStatusChecker::MAX_RETRIES;

    /**
     * How long a run somebody asked for waits on a pass already in flight.
     *
     * The scheduler's own pass may be holding the campaign when the button is pressed.
     * Returning at once would finish the operator's run having checked nothing, so it waits
     * for that pass to end and then asks about whatever is still outstanding.
     */
    public const WAIT_SECONDS = 60;

    /**
     * The wait above plus the checker's own lock window, which is the longest a pass is
     * allowed to hold the campaign. Well inside the task framework's stale window, so a
     * run that is still working is never reclaimed as dead.
     */
    public int $timeout = 360;

    public function __construct(public string $campaign_id, public ?string $task_id = null)
    {
        //
    }

    /**
     * One run per campaign.
     *
     * The status check covers every outstanding claim on the campaign, so there is nothing
     * narrower to key on, and a second run while the first is going would ask the backend
     * the same questions again.
     */
    public static function dedupeKey(): string
    {
        return CampaignTask::DEFAULT_KEY;
    }

    /**
     * What a finished check changes on the campaign page.
     *
     * Claim statuses and hashes reach the page inside the campaign prop, and the onboarding
     * panel carries the count still awaiting confirmation. The charts count claims by
     * creation date and the costs read recorded fees, neither of which a status check
     * changes, so they are left alone.
     *
     * @return list<string>
     */
    public static function reloads(): array
    {
        return ['campaign', 'onboarding'];
    }

    /**
     * Unique key per campaign, so one campaign's queued check does not hold off another's.
     *
     * A run somebody asked for is keyed on its task row as well. The row already stops a
     * second request from the page, and sharing the scheduler's key would let a queued
     * scheduled run swallow the dispatch silently, leaving the row saying "queued" with no
     * job behind it.
     */
    public function uniqueId(): string
    {
        return $this->task_id === null
            ? $this->campaign_id
            : $this->campaign_id.':'.$this->task_id;
    }

    /**
     * Auto-release the unique lock after 2x the configured push delay
     * so a stuck or crashed job can never permanently block future status checks.
     * Minimum of 60 seconds to ensure the lock outlives normal job execution.
     */
    public function uniqueFor(): int
    {
        return max(60, ((int) config('cardano.push_delay', 5)) * 60 * 2);
    }

    public function handle(?ClaimStatusChecker $checker = null): void
    {
        Log::info('CheckClaims: starting', [
            'campaign_id' => $this->campaign_id,
            'task_id' => $this->task_id,
        ]);

        $this->beginTask();

        $campaign = Campaign::with('wallet')
            ->find($this->campaign_id);

        if (! $campaign) {
            Log::error('CheckClaims: campaign not found', ['campaign_id' => $this->campaign_id]);

            $this->failTask('That campaign no longer exists.');

            return;
        }

        $checker ??= app(ClaimStatusChecker::class);

        if ($this->task_id === null) {
            // The scheduler does not wait on a pass already running: it comes round again,
            // and a worker held open for one is a worker doing nothing.
            $checker->check($campaign);

            return;
        }

        $stats = $checker->check($campaign, waitSeconds: self::WAIT_SECONDS, progress: $this);

        if ($stats['skipped']) {
            $this->failTask('Another status check for this campaign was still running. Try again in a few minutes.');

            return;
        }

        $this->succeed(array_intersect_key($stats, array_flip([
            'checked', 'completed', 'retried', 'failed', 'processing', 'unknown',
        ])));
    }

    /**
     * The scheduler's runs are dropped while another is working on the same campaign.
     *
     * A run somebody asked for is not, because a job the queue drops never reaches failed()
     * and its task row would sit at "queued" until the stale sweep. It waits on the
     * checker's own per-campaign lock instead, which keeps two passes apart just the same.
     */
    public function middleware(): array
    {
        if ($this->task_id !== null) {
            return [];
        }

        return [
            (new WithoutOverlapping($this->campaign_id))->dontRelease()
                ->expireAfter(180),
        ];
    }
}
