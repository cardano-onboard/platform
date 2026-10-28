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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks the transaction backend about every claim on one campaign still awaiting an answer.
 *
 * The Check claims button sends one with a task row, and an operator is watching the page
 * for the answer, so that run reports itself through the row like every other job on the
 * page and the page reloads the claims when it finishes.
 *
 * Every other run has no task row and nobody watching it. A status request about a claim
 * still waiting sends one through requestSoon(), at most once per campaign per debounce
 * window however many attendees are polling. The scheduler, where one runs, sends one
 * every few minutes. Neither queues anything after itself.
 *
 * Sending claims to the backend starts a chain through afterSubmission(): a check a little
 * later that queues the next one after itself, waiting longer each time, for as long as the
 * campaign has claims awaiting confirmation, up to CHAIN_LIFETIME_SECONDS. That is what
 * confirms claims nobody is watching on a deployment with no scheduler. A campaign has at
 * most one chain. A submission while one is running queues a check of that chain for its
 * own claims, a little later, and restarts the chain's backoff from there rather than
 * starting a second chain. A queue that runs jobs inline and ignores delays (the sync
 * driver) cannot wait, so a submission on it starts no chain.
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

    /** The longest wait between two checks in a chain. */
    public const CHAIN_MAX_INTERVAL_SECONDS = 300;

    /** How long a chain keeps going after it starts, or after the last submission restarted it. */
    public const CHAIN_LIFETIME_SECONDS = 1800;

    /**
     * @param  string|null  $chain_id  set on a check in a chain started by a submission; the
     *                                 chain is the campaign's only while the chain key holds
     *                                 this id
     * @param  int|null  $chain_started_at  when the chain started, or was last restarted by a
     *                                      submission, as a Unix timestamp
     * @param  int  $chain_step  which check in the chain this is, counting from 0
     * @param  string|null  $chain_token  this check's own id. Only the check the chain key
     *                                    names may queue the next one, so a chain with two
     *                                    checks queued goes on from one of them
     */
    public function __construct(
        public string $campaign_id,
        public ?string $task_id = null,
        public ?string $chain_id = null,
        public ?int $chain_started_at = null,
        public int $chain_step = 0,
        public ?string $chain_token = null,
    ) {
        //
    }

    /**
     * Queue a check for this campaign unless one was asked for within the debounce window.
     *
     * Called from status requests, which many attendees may be making at once about the
     * same campaign. The window is claimed with an atomic add, so only the first request in
     * it dispatches, and the rest return at once with whatever the claim says now. The
     * request never waits on the backend: the check runs on a worker, and the next poll
     * after it finishes sees the hash.
     *
     * @return bool whether this call was the one that dispatched
     */
    public static function requestSoon(string $campaignId): bool
    {
        $window = (int) config('cardano.claim_check.debounce_seconds', 15);

        if (! Cache::add('claim-check-requested:'.$campaignId, true, $window)) {
            return false;
        }

        try {
            // Released straight away so the job is queued here, while the request is still
            // standing, rather than whenever the pending dispatch happens to be destroyed.
            $pending = self::dispatch($campaignId);
            unset($pending);
        } catch (Throwable $e) {
            Log::error('CheckClaims: could not queue a requested check', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Start the campaign's chain of checks a little after claims were sent, or, when one is
     * already running, queue a check of that chain for these claims.
     *
     * The chain is owned through one cache key per campaign, claimed with an atomic add, so
     * two submissions at once start one chain. The key names the chain and the one check in
     * it that may queue the next. A submission during a chain queues a check at the usual
     * first wait and names it in the key, so the check that was already queued still asks
     * when it runs but leaves going on to this one. Each chain check has a unique key of its
     * own, so a check already queued for the campaign, which may run before this transaction
     * reaches the chain, cannot swallow it.
     */
    public static function afterSubmission(string $campaignId): void
    {
        if (self::queueCannotWait()) {
            if (Cache::add('claim-check-chain-skipped-warning', true, 3600)) {
                Log::warning('CheckClaims: no checks are queued after claims are sent because the queue connection runs jobs at once. Claims are confirmed only by status requests and the scheduler, so run php artisan schedule:run every minute or use a queue with a worker.', [
                    'queue' => config('queue.default'),
                ]);
            }

            return;
        }

        try {
            Cache::put(self::chainSubmittedKey($campaignId), self::preciseNow(), self::CHAIN_LIFETIME_SECONDS);

            if (! self::startChain($campaignId)) {
                self::joinChain($campaignId);
            }
        } catch (Throwable $e) {
            Log::error('CheckClaims: could not start the checks after submission', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the default queue runs a job without honouring its delay.
     *
     * The sync, deferred and background drivers all run the job in the process that queued
     * it, straight away. A chain on one cannot wait: each check would queue the next one
     * inside itself for as long as claims were outstanding. Claims are then confirmed and
     * retried only by status requests and by the scheduler, so a deployment with neither
     * leaves a claim pending once its recipient has left the page.
     */
    private static function queueCannotWait(): bool
    {
        $connection = config('queue.default');

        return in_array(config("queue.connections.{$connection}.driver"), ['sync', 'deferred', 'background'], true);
    }

    /**
     * Claim the campaign's chain and queue its first check, unless a chain already holds it.
     */
    private static function startChain(string $campaignId): bool
    {
        $chainId = (string) Str::ulid();
        $token = (string) Str::ulid();
        $delay = self::chainDelay(0);

        if (! Cache::add(self::chainKey($campaignId), self::chainHolder($chainId, $token), $delay + self::CHAIN_MAX_INTERVAL_SECONDS)) {
            return false;
        }

        $pending = self::dispatch($campaignId, null, $chainId, now()->getTimestamp(), 0, $token)->delay($delay);
        unset($pending);

        return true;
    }

    /**
     * Queue a check of the running chain at the first wait, for claims sent while it runs,
     * and make it the check that carries the chain on.
     */
    private static function joinChain(string $campaignId): void
    {
        $holder = Cache::get(self::chainKey($campaignId));

        if (! is_string($holder)) {
            // The chain ended between the add above and this read.
            self::startChain($campaignId);

            return;
        }

        [$chainId] = explode('|', $holder, 2);
        $token = (string) Str::ulid();
        $delay = self::chainDelay(0);

        Cache::put(self::chainKey($campaignId), self::chainHolder($chainId, $token), $delay + self::CHAIN_MAX_INTERVAL_SECONDS);

        $pending = self::dispatch($campaignId, null, $chainId, now()->getTimestamp(), 0, $token)->delay($delay);
        unset($pending);
    }

    /**
     * The clock to the microsecond, as seconds. The submission stamp and the moment a pass
     * began are compared to tell whether a submission came after the pass, and at whole
     * seconds a submission just before a pass looked the same as one just after it.
     */
    private static function preciseNow(): float
    {
        return (float) now()->format('U.u');
    }

    /** What the chain key holds: the chain, and the one check in it that may queue the next. */
    private static function chainHolder(string $chainId, ?string $token): string
    {
        return $chainId.'|'.$token;
    }

    /**
     * Seconds before check number $step in a chain: the after-submission wait, doubled for
     * each step, never more than CHAIN_MAX_INTERVAL_SECONDS. 40, 80, 160, then 300 at the
     * default.
     */
    public static function chainDelay(int $step): int
    {
        $base = max(1, (int) config('cardano.claim_check.after_submit_seconds', 40));

        return (int) min(self::CHAIN_MAX_INTERVAL_SECONDS, $base * 2 ** max(0, $step));
    }

    public static function chainKey(string $campaignId): string
    {
        return 'claim-check-chain:'.$campaignId;
    }

    private static function chainSubmittedKey(string $campaignId): string
    {
        return 'claim-check-chain-submitted:'.$campaignId;
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
     * job behind it. A check in a chain is keyed on its own token for the same reason, and
     * so that the check queueing the next one is not holding that one's key itself.
     */
    public function uniqueId(): string
    {
        if ($this->task_id !== null) {
            return $this->campaign_id.':'.$this->task_id;
        }

        if ($this->chain_id !== null) {
            return $this->campaign_id.':chain:'.$this->chain_id.':'.$this->chain_token;
        }

        return $this->campaign_id;
    }

    /**
     * How long the unique lock may outlive a job that never finishes.
     *
     * A job that finishes releases it straight away; this only bounds a job whose worker
     * died. For the unwatched runs that is the debounce window, so a lost check holds off
     * the next one no longer than the window a waiting attendee is already told to expect.
     * A run somebody asked for is keyed on its own task row, and holds it for as long as it
     * could possibly run.
     */
    public function uniqueFor(): int
    {
        if ($this->task_id !== null) {
            return $this->timeout;
        }

        return max(1, (int) config('cardano.claim_check.debounce_seconds', 15));
    }

    public function handle(?ClaimStatusChecker $checker = null): void
    {
        Log::info('CheckClaims: starting', [
            'campaign_id' => $this->campaign_id,
            'task_id' => $this->task_id,
            'chain_id' => $this->chain_id,
            'chain_step' => $this->chain_step,
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
            // An unwatched run does not wait on a pass already running: another comes round,
            // and a worker held open for one is a worker doing nothing. It asks about a
            // bounded number of claims, oldest first, so a campaign with a long backlog
            // cannot hold a worker or the campaign for the length of that backlog.
            $passStartedAt = self::preciseNow();

            $checker->check($campaign, limit: (int) config('cardano.claim_check.max_per_pass', 100));

            if ($this->chain_id !== null) {
                $this->continueChain($campaign, $passStartedAt);
            }

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
     * Queue the next check in this chain, or end the chain.
     *
     * The chain goes on while the campaign has claims awaiting confirmation, whether this
     * pass asked about them or not: a pass skipped because another was running, cut short
     * by an unreachable backend, or bounded by its cap leaves them outstanding. It ends when
     * nothing is outstanding or CHAIN_LIFETIME_SECONDS after it started. A submission since
     * it started restarts both the backoff and the lifetime from that submission.
     */
    private function continueChain(Campaign $campaign, float $passStartedAt): void
    {
        $key = self::chainKey($campaign->id);

        if (Cache::get($key) !== self::chainHolder($this->chain_id, $this->chain_token)) {
            // A later check of this chain, queued for claims sent since, carries it on; or
            // another chain holds the campaign, which happens only after this one's key
            // expired with its worker gone. Either way, queueing from here as well would
            // double every question from now on.
            Log::info('CheckClaims: another check carries the chain on', [
                'campaign_id' => $campaign->id,
                'chain_id' => $this->chain_id,
            ]);

            return;
        }

        $startedAt = (int) $this->chain_started_at;
        $nextStep = $this->chain_step + 1;

        $submittedAt = (float) Cache::get(self::chainSubmittedKey($campaign->id), 0);
        if ((int) $submittedAt > $startedAt) {
            $startedAt = (int) $submittedAt;
            $nextStep = 0;
        }

        $outstanding = $campaign->claims()->awaitingConfirmation()->exists();
        $expired = now()->getTimestamp() - $startedAt >= self::CHAIN_LIFETIME_SECONDS;

        if (! $outstanding || $expired) {
            Cache::forget($key);

            Log::info('CheckClaims: chain ended', [
                'campaign_id' => $campaign->id,
                'chain_id' => $this->chain_id,
                'reason' => $outstanding ? 'lifetime' : 'nothing outstanding',
            ]);

            // A submission after this pass began found the key still held and left its
            // claims to this chain, which is ending. Start a fresh chain for them.
            if ((float) Cache::get(self::chainSubmittedKey($campaign->id), 0) > $passStartedAt) {
                self::startChain($campaign->id);
            }

            return;
        }

        $delay = self::chainDelay($nextStep);
        $token = (string) Str::ulid();

        Cache::put($key, self::chainHolder($this->chain_id, $token), $delay + self::CHAIN_MAX_INTERVAL_SECONDS);

        self::dispatch($campaign->id, null, $this->chain_id, $startedAt, $nextStep, $token)->delay($delay);
    }

    /**
     * An unwatched run is dropped while another is working on the same campaign.
     *
     * A run somebody asked for is not, because a job the queue drops never reaches failed()
     * and its task row would sit at "queued" until the stale sweep. It waits on the
     * checker's own per-campaign lock instead, which keeps two passes apart just the same.
     *
     * Nor is a check in a chain, because a dropped one would end the chain with claims
     * still outstanding. It meets the checker's lock instead, which skips the pass, and
     * queues the next check as though the pass had found them waiting.
     */
    public function middleware(): array
    {
        if ($this->task_id !== null || $this->chain_id !== null) {
            return [];
        }

        return [
            (new WithoutOverlapping($this->campaign_id))->dontRelease()
                ->expireAfter(180),
        ];
    }
}
