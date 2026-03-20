<?php

namespace App\Console\Commands;

use App\Jobs\CreateCampaignBucket;
use App\Models\Campaign;
use Illuminate\Console\Command;

class ProvisionOrphanedCampaigns extends Command
{
    protected $signature = 'campaigns:provision-orphaned {--dry-run : List orphaned campaigns without dispatching jobs}';

    protected $description = 'Find campaigns without wallets and dispatch CreateCampaignBucket for each';

    public function handle(): int
    {
        $orphaned = Campaign::whereDoesntHave('wallet')->get();

        if ($orphaned->isEmpty()) {
            $this->info('No orphaned campaigns found. All campaigns have wallets.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Network', 'Created'],
            $orphaned->map(fn ($c) => [$c->id, $c->name, $c->network, $c->created_at])
        );

        $this->info("Found {$orphaned->count()} campaign(s) without wallets.");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no jobs dispatched.');
            return self::SUCCESS;
        }

        foreach ($orphaned as $campaign) {
            CreateCampaignBucket::dispatch($campaign->id);
            $this->line("  Dispatched CreateCampaignBucket for [{$campaign->id}] {$campaign->name}");
        }

        $this->info("Done. {$orphaned->count()} job(s) dispatched.");

        return self::SUCCESS;
    }
}
