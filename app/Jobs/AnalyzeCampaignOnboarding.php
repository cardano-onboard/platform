<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignAnalysis;
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
 * paced requests.
 */
class AnalyzeCampaignOnboarding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public string $campaign_id) {}

    public function handle(OnboardingAnalysisService $analysis): void
    {
        $campaign = Campaign::find($this->campaign_id);

        if (! $campaign) {
            Log::warning('Onboarding analysis skipped: campaign not found.', [
                'campaign_id' => $this->campaign_id,
            ]);

            return;
        }

        $analysis->analyze($campaign);
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

    public function failed(\Throwable $e): void
    {
        CampaignAnalysis::where('campaign_id', $this->campaign_id)->update([
            'status' => CampaignAnalysis::STATUS_FAILED,
            'completed_at' => now(),
            'error' => $e->getMessage(),
        ]);
    }
}
