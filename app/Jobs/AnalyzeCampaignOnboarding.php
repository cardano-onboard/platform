<?php

namespace App\Jobs;

use App\Contracts\ReportsTaskProgress;
use App\Jobs\Concerns\TracksCampaignTask;
use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignTask;
use App\Services\ClaimStatusChecker;
use App\Services\OnboardingAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the onboarding analysis for one campaign.
 *
 * This is queued rather than done in the request because it makes roughly one Koios
 * query per distinct claimant wallet: a few hundred claimants is a couple of minutes of
 * paced requests. Confirming outstanding claims first adds one backend call per
 * unconfirmed claim, which is the same reason again: a venue's worth of claims checked
 * inside an HTTP request is a request that times out.
 *
 * Minutes of silence is exactly what an operator cannot read, so the run reports itself
 * through a campaign task row: which phase it is in, how many wallets it has read, and why
 * it stopped when it could not finish. The page's shared poller carries that to the
 * onboarding panel, so the panel needs no clock of its own.
 */
class AnalyzeCampaignOnboarding implements ReportsTaskProgress, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksCampaignTask;

    /** What this run is called on the campaign page. */
    public const TASK_TYPE = 'onboarding-analysis';

    /**
     * The phase name the confirmation pass reports.
     *
     * Every other phase is named by the service, which is what knows where the minutes go.
     * This one is the job's own work, so the job names it. The onboarding panel matches on
     * this exact string to describe what is being confirmed rather than the chain reads
     * that have not started yet, which is why it is a constant a test can pin.
     */
    public const STAGE_CONFIRMING = 'Confirming outstanding claims';

    public int $tries = 2;

    public int $timeout = 900;

    /** How long to wait for a status check already running before giving up on it. */
    private const CONFIRMATION_WAIT_SECONDS = 60;

    /**
     * One run per campaign.
     *
     * The codes import keys on the file it was handed, because two different files are two
     * separate imports. An analysis has nothing to key on: it measures the whole campaign,
     * so a second one while the first is in flight would repeat every Koios query to arrive
     * at the same answer. The default key says that, and says it early enough that the
     * request which asked for the second run can tell the operator why nothing happened.
     */
    public static function dedupeKey(): string
    {
        return CampaignTask::DEFAULT_KEY;
    }

    /**
     * What a finished analysis changes on the campaign page.
     *
     * The run writes campaign_analyses and campaign_wallet_insights, and the page reads
     * both of those through the onboarding prop and nowhere else. The codes table, the
     * charts and the wallet balance are untouched by it, so naming them here would make the
     * page fetch all of that, balance call included, to redraw one panel.
     *
     * The confirmation pass writes claims too, but the only thing the page says about them
     * is the count still awaiting confirmation, which the onboarding prop already carries.
     *
     * @return list<string>
     */
    public static function reloads(): array
    {
        return ['onboarding'];
    }

    public function __construct(public string $campaign_id, public ?string $task_id = null) {}

    public function handle(OnboardingAnalysisService $analysis, ClaimStatusChecker $checker): void
    {
        $this->beginTask();

        // The wallet is loaded with the campaign because the status check reaches the
        // transaction backend through it.
        $campaign = Campaign::with('wallet')->find($this->campaign_id);

        if (! $campaign) {
            Log::warning('Onboarding analysis skipped: campaign not found.', [
                'campaign_id' => $this->campaign_id,
            ]);

            $this->failTask('That campaign no longer exists.');

            return;
        }

        $this->confirmOutstandingClaims($campaign, $checker);

        $result = $analysis->analyze($campaign, $this);

        $this->succeed(['wallets' => (int) $result->wallets_analyzed]);
    }

    /**
     * Close the gap before measuring, rather than reporting around it.
     *
     * A claim gains its transaction hash when its status is checked, and status checking
     * is deliberately not automatic. The analysis can only see claims that carry a hash,
     * so without this pass its denominator is "claims somebody happened to have checked"
     * and nothing on the page says so. Claims that still have no hash afterwards had
     * nothing to report: they are absent from the result, and the coverage figure the
     * analysis records says how many that was.
     *
     * A failure here fails the run. Producing a number that silently omits claims is the
     * failure this feature exists to prevent, so it is not worth carrying on for.
     *
     * This is a phase like the six the service reports, so it is announced on the task row
     * the panel is already reading rather than through a column of its own. The count is
     * the denominator, because one backend call per unconfirmed claim is a number somebody
     * watching the panel can make sense of.
     */
    private function confirmOutstandingClaims(Campaign $campaign, ClaimStatusChecker $checker): void
    {
        $pending = $campaign->claims()->awaitingConfirmation()->count();

        if ($pending === 0) {
            return;
        }

        $this->stage(self::STAGE_CONFIRMING);
        $this->progress(0, $pending);

        CampaignAnalysis::updateOrCreate(
            ['campaign_id' => $campaign->id],
            [
                'status' => CampaignAnalysis::STATUS_RUNNING,
                'started_at' => now(),
                'completed_at' => null,
                'error' => null,
            ]
        );

        Log::info('Onboarding analysis: confirming outstanding claims first.', [
            'campaign_id' => $campaign->id,
            'pending_claims' => $pending,
        ]);

        // Waits on a scheduled pass already in flight rather than racing it, because
        // proceeding without those answers is the thing being avoided.
        $checker->check($campaign, waitSeconds: self::CONFIRMATION_WAIT_SECONDS);

        $this->progress($pending, $pending);
    }

    public function middleware(): array
    {
        return [
            new RateLimited('AnalyzeCampaignOnboarding'),
            // A second run while one is in flight would duplicate every Koios call for no
            // new information, so overlapping runs are dropped rather than queued.
            (new WithoutOverlapping($this->campaign_id))->dontRelease()
                ->expireAfter(900),
        ];
    }

    /**
     * The queue's own hook, called after the last attempt fails.
     *
     * Both rows are written, because two different readers need the answer. The task row is
     * what the page's poller reads while somebody is watching, and the analysis row is what
     * the panel shows to anyone who opens the page afterwards, when the run is long over and
     * no task row is being polled.
     *
     * Declaring this in the class silently replaces the trait's version, which is the whole
     * reason it calls failTask() itself: without that line a crashed run would leave a task
     * row saying "running" until the stale sweep caught it, and the operator would watch a
     * progress bar that was never going to move again.
     */
    public function failed(\Throwable $e): void
    {
        CampaignAnalysis::where('campaign_id', $this->campaign_id)->update([
            'status' => CampaignAnalysis::STATUS_FAILED,
            'completed_at' => now(),
            'error' => $e->getMessage(),
        ]);

        $this->failTask($e->getMessage());
    }
}
