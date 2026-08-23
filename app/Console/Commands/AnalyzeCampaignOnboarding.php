<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeCampaignOnboarding as AnalyzeJob;
use App\Models\Campaign;
use App\Models\CampaignWalletInsight;
use App\Services\OnboardingAnalysisService;
use Illuminate\Console\Command;

/**
 * Run a campaign's onboarding analysis from the command line.
 *
 * The campaign page does the same thing through a queued job. This exists for two cases
 * the page cannot cover: a self-hosted install with no queue worker running, and marking
 * operator test claims, which needs a list of stake keys rather than a button.
 *
 *   php artisan onboard:analyze {campaign} [--operator=stake1...] [--queue]
 */
class AnalyzeCampaignOnboarding extends Command
{
    protected $signature = 'onboard:analyze
        {campaign : Campaign ID, or a unique fragment of its name}
        {--operator=* : Stake key of an operator/test wallet to exclude from the genuine population}
        {--queue : Dispatch to the queue instead of running inline}';

    protected $description = 'Classify a campaign\'s claimant wallets as new or established and measure activation';

    public function handle(OnboardingAnalysisService $service): int
    {
        $campaign = $this->resolveCampaign($this->argument('campaign'));

        if (! $campaign) {
            return self::FAILURE;
        }

        $this->flagOperators($campaign, $this->option('operator'));

        if ($this->option('queue')) {
            AnalyzeJob::dispatch($campaign->id);
            $this->info("Queued onboarding analysis for {$campaign->name}.");

            return self::SUCCESS;
        }

        $eligible = $campaign->claims()->whereNotNull('transaction_hash')->count();
        $this->info("Analyzing {$eligible} confirmed claim(s) for {$campaign->name} on {$campaign->network}...");
        $this->line('This makes roughly one Koios query per distinct wallet, so it takes a while.');

        $analysis = $service->analyze($campaign);

        $this->report($campaign, $analysis->summary ?? []);

        return self::SUCCESS;
    }

    private function resolveCampaign(string $needle): ?Campaign
    {
        $campaign = Campaign::find($needle) ?? Campaign::where('name', 'like', "%{$needle}%")->first();

        if (! $campaign) {
            $this->error("No campaign matched '{$needle}'.");

            return null;
        }

        return $campaign;
    }

    /**
     * @param  array<int, string>  $stakeKeys
     */
    private function flagOperators(Campaign $campaign, array $stakeKeys): void
    {
        if (! $stakeKeys) {
            return;
        }

        // Recorded up front so the flag is in place before the analysis writes its rows,
        // and so re-running with the same flags is idempotent.
        foreach ($stakeKeys as $stakeKey) {
            CampaignWalletInsight::updateOrCreate(
                ['campaign_id' => $campaign->id, 'stake_key' => $stakeKey],
                ['is_operator' => true]
            );
        }

        $this->line('Flagged '.count($stakeKeys).' operator/test wallet(s), excluded from the genuine population.');
    }

    private function report(Campaign $campaign, array $s): void
    {
        if (! $s) {
            $this->warn('No results.');

            return;
        }

        $this->newLine();
        $this->line("=== {$campaign->name} — onboarding efficacy ({$s['network']}) ===");

        $this->table(['Metric', 'Value'], [
            ['Claimant wallets', $s['claimants_total']],
            ['Operator/test wallets excluded', $s['operator_wallets']],
            ['Genuine claimant wallets', $s['genuine_wallets']],
            ['Newly onboarded (no prior tx)', "{$s['new_wallets']} ({$s['new_pct']}%)"],
            ['Established (had prior tx)', "{$s['established_wallets']} ({$s['established_pct']}%)"],
            ['NEW wallets that self-initiated a tx', "{$s['new_activated']} of {$s['new_wallets']} ({$s['new_activated_pct']}%)"],
            ['Established that self-initiated', "{$s['established_activated']} of {$s['established_wallets']} ({$s['established_activated_pct']}%)"],
            ['Delegated to a stake pool', "{$s['delegated']} ({$s['delegated_pct']}%)"],
            ['NEW wallets delegated', "{$s['new_delegated']} of {$s['new_wallets']} ({$s['new_delegated_pct']}%)"],
            ['Interacted with a contract', "{$s['script_interactors']} ({$s['script_interactors_pct']}%)"],
            ['Observation window (avg days)', $s['observation_days_avg'] ?? 'n/a'],
        ]);

        // Activation and retention mean nothing without the window they were measured
        // over, so it is printed with the numbers rather than left for the reader to work out.
        $this->line('Activation is measured over ~'.($s['observation_days_avg'] ?? 0).' days since claim on average.');
    }
}
