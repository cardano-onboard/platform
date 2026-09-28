<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeCampaignOnboarding as AnalyzeJob;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\CampaignWalletInsight;
use App\Services\OnboardingAnalysisService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Run a campaign's onboarding analysis from the command line.
 *
 * The campaign page does the same thing through a queued job. This exists for two cases
 * the page cannot cover: a self-hosted install with no queue worker running, and marking
 * operator test claims, which needs a list of stake keys rather than a button.
 *
 *   php artisan onboard:analyze {campaign} [--operator=stake1...] [--queue]
 *   php artisan onboard:analyze {campaign} --from=2026-09-01 --to=2026-09-03 --scope-only
 */
class AnalyzeCampaignOnboarding extends Command
{
    protected $signature = 'onboard:analyze
        {campaign : Campaign ID, or a unique fragment of its name}
        {--operator=* : Stake key of an operator/test wallet to exclude from the genuine population}
        {--from= : Report on claims from this date onwards (YYYY-MM-DD), alongside the whole campaign}
        {--to= : Report on claims up to and including this date (YYYY-MM-DD)}
        {--scope-only : Report the range from stored results without running the analysis again}
        {--queue : Dispatch to the queue instead of running inline}';

    protected $description = 'Classify a campaign\'s claimant wallets as new or established and measure what they did next';

    public function handle(OnboardingAnalysisService $service): int
    {
        $campaign = $this->resolveCampaign($this->argument('campaign'));

        if (! $campaign) {
            return self::FAILURE;
        }

        $this->flagOperators($campaign, $this->option('operator'));

        try {
            $from = $this->rangeEnd('from')?->startOfDay();
            $to = $this->rangeEnd('to')?->endOfDay();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Re-scoping an analysis that has already run reads the stored per-wallet rows
        // and nothing else, so it costs no Koios queries and can be done as often as the
        // question changes.
        if ($this->option('scope-only')) {
            if (! $from && ! $to) {
                $this->error('--scope-only needs a --from or a --to, otherwise it is the whole campaign.');

                return self::FAILURE;
            }

            $this->reportRange($campaign, $service, $from, $to);

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            // Claimed the same way the campaign page claims it, so a run started here shows
            // its phase and its progress on that page rather than being invisible until it
            // finishes, and so starting one from here while one is already running refuses
            // instead of queueing a job the worker would discard.
            $task = CampaignTask::claim(
                $campaign,
                AnalyzeJob::TASK_TYPE,
                AnalyzeJob::dedupeKey(),
                ['eligible_claims' => $campaign->claims()->whereNotNull('transaction_hash')->count()],
            );

            if (! $task) {
                $this->error("An analysis is already running for {$campaign->name}.");

                return self::FAILURE;
            }

            AnalyzeJob::dispatch($campaign->id, $task->id);
            $this->info("Queued onboarding analysis for {$campaign->name}.");

            return self::SUCCESS;
        }

        $eligible = $campaign->claims()->whereNotNull('transaction_hash')->count();
        $this->info("Analyzing {$eligible} confirmed claim(s) for {$campaign->name} on {$campaign->network}...");
        $this->line('This makes roughly one Koios query per distinct wallet, so it takes a while.');

        $analysis = $service->analyze($campaign);

        // A run with holes in it says so before its numbers are read. The wallets behind a
        // failed read are unknown, and a table that does not mention them reads as a
        // measurement of the whole campaign.
        if ($analysis->isPartial()) {
            $this->warn("The chain data provider did not answer for {$analysis->unread_wallets} wallet(s). "
                .'Those wallets are unknown below rather than counted as having done nothing. Re-run to fill them in.');
        }

        $this->report($campaign, $analysis->summary ?? []);

        if ($from || $to) {
            $this->reportRange($campaign, $service, $from, $to);
        }

        return self::SUCCESS;
    }

    /**
     * @throws \InvalidArgumentException when the option is not a date
     */
    private function rangeEnd(string $option): ?Carbon
    {
        $value = trim((string) $this->option($option));

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = null;
        }

        // Compared back against the input because PHP rolls an impossible date over
        // rather than refusing it, and 31 February silently becoming 3 March is a
        // different question from the one that was asked.
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("--{$option} must be a date in YYYY-MM-DD form.");
        }

        return $date;
    }

    /**
     * The same metrics over a date range, recomputed from stored rows.
     *
     * Printed beside the whole-campaign table rather than instead of it: the difference
     * between the two is what the range was asked for. A campaign run at an event and
     * then left open collects claims the event did not produce, and measured together
     * they average into a number that describes neither.
     */
    private function reportRange(Campaign $campaign, OnboardingAnalysisService $service, ?Carbon $from, ?Carbon $to): void
    {
        $window = trim(($from ? 'from '.$from->format('Y-m-d') : '').' '.($to ? 'to '.$to->format('Y-m-d') : ''));

        $this->newLine();
        $this->line("=== {$campaign->name} — claims {$window} ===");
        $this->line('Recomputed from stored results. Nothing was written and no chain queries were made.');

        $this->report($campaign, $service->summarizeWindow($campaign, $from, $to));
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

        // Nobody to measure. A third state, alongside a result and a measurement that has
        // not happened: a full table of zeros here says the campaign onboarded nobody and
        // nobody did anything, when what happened is that no wallet fell in it. What the
        // claims did is still printed, because that is what says why the population is
        // empty.
        if (($s['genuine_wallets'] ?? 0) === 0) {
            $this->warn('No claimant wallets fall in this result, so there is nothing to measure. '
                .'That is an answer rather than a missing measurement: re-running the analysis would not change it.');

            $this->table(['Metric', 'Value'], [
                ['Claimant wallets', $s['claimants_total']],
                ['Operator/test wallets excluded', $s['operator_wallets']],
                ['Claims covered', $this->covered($s)],
                ['Claims not confirmed on chain', $s['claims_unconfirmed'] ?? 0],
            ]);

            return;
        }

        // A denominator a summary stored before it was exported does not carry. Falling
        // back to the wider count keeps the row honest rather than printing an empty one.
        $read = static fn (array $s, string $key, string $fallback) => (int) ($s[$key] ?? $s[$fallback] ?? 0);

        // A share of a population nobody read is not zero percent. Every rate below is
        // printed with the population it was taken over, and a population of nobody is
        // reported as unknown rather than rounded to a figure the run never measured.
        $rate = static fn (int|float $count, int $of, int|float $pct): string => $of > 0
            ? "{$count} of {$of} read ({$pct}%)"
            : 'unknown (none read)';

        // A count is no safer than a rate. Taken over a population nobody read, a bare 0
        // says the thing was looked for and not found, when nothing was looked at. The
        // panel already replaces these with the provider sentence; the console said 0.
        $tally = static fn (int $count, int $of): string => $of > 0 ? (string) $count : 'unknown (none read)';

        $this->table(['Metric', 'Value'], [
            ['Claimant wallets', $s['claimants_total']],
            ['Operator/test wallets excluded', $s['operator_wallets']],
            ['Genuine claimant wallets', $s['genuine_wallets']],
            ['Newly onboarded (no prior tx)', $rate($s['new_wallets'], $read($s, 'classified_wallets', 'genuine_wallets'), $s['new_pct'])],
            ['Established (had prior tx)', $rate($s['established_wallets'], $read($s, 'classified_wallets', 'genuine_wallets'), $s['established_pct'])],
            // Delegating is not transacting for yourself. A wallet that registers a stake
            // key and delegates is an input of that transaction because it pays the
            // deposit and the fee, so the two are counted separately and never added up.
            // Each rate over the population it was taken from. The NEW rate is a share of
            // the new wallets a windowed run read, not of every wallet it read, and the
            // wider number printed beside it made the row contradict itself.
            ['NEW wallets that transacted for themselves', $rate($s['new_active'], $read($s, 'windows_observed_new', 'windows_observed'), $s['new_active_pct'])],
            ['Established that transacted for themselves', $rate($s['established_active'], $read($s, 'windows_observed_established', 'windows_observed'), $s['established_active_pct'])],
            ['Wallets that only delegated', $tally($s['delegation_only'] ?? 0, $read($s, 'windows_observed', 'windows_observed'))],
            // Current delegation is its own read and fails on its own, so it carries its
            // own denominator: the wallets the account read answered for, not every
            // claimant and not every new wallet.
            ['Delegated to a stake pool (current)', $rate($s['delegated'], $read($s, 'delegation_known', 'genuine_wallets'), $s['delegated_pct'])],
            ['NEW wallets delegated (current)', $rate($s['new_delegated'], $read($s, 'new_delegation_known', 'new_wallets'), $s['new_delegated_pct'])],
            ['NEW wallets that delegated after claiming', $rate($s['new_delegated_after_claim'], $read($s, 'new_delegated_after_claim_known', 'windows_observed_new'), $s['new_delegated_after_claim_pct'])],
            ['Wallets no windowed run has read', $s['windows_unknown'] ?? 0],
            ['Wallets whose history could not be read', $s['unclassified_wallets'] ?? 0],
            ['Interacted with a contract', $rate($s['script_interactors'], (int) ($s['windows_observed'] ?? 0), $s['script_interactors_pct'])],
            ['Observation window (avg days)', $s['observation_days_avg'] ?? 'unknown (no wallet watched)'],
            // What the percentages above do not describe. A claim with no transaction
            // hash has not been confirmed on chain and the analysis cannot see it.
            ['Claims covered', $this->covered($s)],
            ['Claims not confirmed on chain', $s['claims_unconfirmed'] ?? 0],
        ]);

        // Activity and retention mean nothing without the window they were measured over,
        // so it is printed with the numbers rather than left for the reader to work out.
        // A run that watched nobody says so: printing "~0 days" for it would put a
        // measurement where there is none.
        if (($s['observation_days_avg'] ?? null) === null) {
            $this->line('No wallet has a recorded length of observation, so nothing above is measured over a window.');
        } else {
            $this->line('Activity is measured over ~'.$s['observation_days_avg'].' days since claim on average, '
                .'across '.($s['observation_wallets'] ?? 0).' wallet(s) with a recorded length of observation.');
        }

        $this->windowTable($s);
    }

    /**
     * How many claims the numbers describe, out of how many exist.
     *
     * A percentage of no claims at all is not zero percent covered, so a range holding no
     * claims says so rather than printing a rate over nobody.
     */
    private function covered(array $s): string
    {
        $total = (int) ($s['claims_total'] ?? 0);

        if ($total <= 0) {
            return 'no claims in this result';
        }

        return ($s['claims_analyzable'] ?? 0).' of '.$total.' ('.($s['coverage_pct'] ?? 0).'%)';
    }

    /**
     * The same population cut by how long after the claim anything happened.
     *
     * "Not yet" is not a zero. A wallet that claimed nine days ago cannot have a ninety-day
     * answer, and putting it in the denominator would report the campaign as having failed
     * at something it has not been given time to do.
     */
    private function windowTable(array $s): void
    {
        if (empty($s['windows'])) {
            $this->newLine();
            $this->warn('No windowed result: re-run the analysis to measure follow-up activity.');

            return;
        }

        // Every column of this table is taken over the new wallets a windowed run read. With
        // none read, each cell is a count over nobody: "Not yet 0" reads as nobody being too
        // recent to answer, when the truth is that nothing was asked. The panel drops the
        // whole table for the same reason rather than filling it with zeros.
        if ((int) ($s['windows_observed_new'] ?? 0) <= 0) {
            $this->newLine();
            $this->warn(
                'No follow-up windows: no new wallet here was read by a windowed run, so there is '
                .'nothing to place inside or outside 30, 60 and 90 days.'
            );

            return;
        }

        $this->newLine();
        $this->line('=== Follow-up windows (NEW wallets) ===');

        // Each rate with the population it was taken over, and "unknown" where that
        // population is empty. Transacting and delegating are timed separately, so a
        // wallet held out of one window can be in the other and the two denominators are
        // not the same number.
        $rate = static fn (int $count, int $of, int|float $pct): string => $of > 0
            ? "{$count} of {$of} ({$pct}%)"
            : 'unknown (none observable)';

        $this->table(
            // "No time" is a wallet that did something the chain query returned no time
            // for. It is held out of the window rather than counted as having failed it,
            // the same way a wallet that claimed too recently is.
            // The two "no time" counts are not added together: a wallet can be untimed in
            // both, and one column would count it twice.
            ['Window', 'Not yet', 'No tx time', 'No delegation time', 'Transacted', 'Delegated'],
            array_map(static fn (array $w) => [
                $w['days'].' days',
                $w['new_not_yet'],
                (int) ($w['new_untimed'] ?? 0),
                (int) ($w['new_delegation_untimed'] ?? 0),
                $rate($w['new_active'], (int) $w['new_observable'], $w['new_active_pct']),
                $rate(
                    $w['new_delegated'],
                    (int) ($w['new_delegation_observable'] ?? $w['new_observable']),
                    $w['new_delegated_pct']
                ),
            ], $s['windows'])
        );
    }
}
