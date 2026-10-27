<?php

namespace App\Console\Commands;

use App\Services\ClaimCompletionBackfill;
use Illuminate\Console\Command;

/**
 * Set status completed on claims that have a real transaction hash and still say pending
 * or failed.
 *
 * The same walk the migration makes, for the claims an earlier release confirms while a
 * deploy is under way. Safe to run at any time and as often as wanted: a second run finds
 * nothing to do.
 */
class CompleteConfirmedClaims extends Command
{
    protected $signature = 'claims:complete-confirmed
        {--dry-run : Report how many claims would be completed without changing anything}';

    protected $description = 'Complete claims that have a transaction hash but a pending or failed status';

    public function handle(ClaimCompletionBackfill $backfill): int
    {
        if ($this->option('dry-run')) {
            $would = $backfill->count();
            $this->info("{$would} claim(s) would be completed.");

            return self::SUCCESS;
        }

        $completed = $backfill->run();

        $this->info("{$completed} claim(s) completed.");

        return self::SUCCESS;
    }
}
