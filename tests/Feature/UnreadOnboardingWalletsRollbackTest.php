<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignWalletInsight;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a rollback of the unread-wallets migration leaves behind.
 *
 * Its own class, and migrating rather than wrapping every test in a transaction, because
 * rolling a migration back is schema work: on MySQL that commits, and a rollback run inside
 * a shared transaction would leave every later test looking at the wrong tables.
 */
class UnreadOnboardingWalletsRollbackTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Nothing is left on a status the code being rolled back to cannot read.
     *
     * 'partial' is written by the same runs that write the nulls this rollback fills in,
     * and the older code recognises it as neither finished nor running: the campaign page
     * would show no result and no run in flight, and offer nothing to press. It becomes
     * 'complete', which is what a run with holes in it was recorded as before, and matches
     * the zeros the per-wallet rows are given at the same time.
     */
    public function test_a_partial_run_becomes_one_the_rolled_back_code_recognises(): void
    {
        $campaign = Campaign::factory()->create();

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_PARTIAL,
            'unread_wallets' => 2,
            'wallets_total' => 3,
            'wallets_analyzed' => 3,
        ]);

        CampaignWalletInsight::create([
            'campaign_id' => $campaign->id,
            'stake_key' => 'stake1u'.str_repeat('a', 50),
            'is_new' => null,
            'prior_tx_count' => null,
            'delegated' => null,
        ]);

        // Named by --path rather than by --step: --step=1 rolls back whichever migration
        // this run's batch treats as last, which is a question of every migration's
        // filename, not of this one. A later migration dated after this one, added for
        // reasons that have nothing to do with onboarding, would silently roll itself back
        // here instead and leave this assertion looking at a status nothing had touched.
        // --path does not filter which rows the rollback selects — that selection is still
        // the last batch, every migration in this fresh test database's single run of
        // `migrate` — but it does filter which of those rows this command can find a file
        // for, and it skips (rather than fails) any it cannot. Naming only this file's path
        // means only this migration's down() runs, whatever else is in that batch.
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_17_091500_record_unread_onboarding_wallets.php',
        ])->assertSuccessful();

        $status = DB::table('campaign_analyses')->where('campaign_id', $campaign->id)->value('status');

        $this->assertSame(CampaignAnalysis::STATUS_COMPLETE, $status);
        $this->assertNotSame(CampaignAnalysis::STATUS_PARTIAL, $status);

        // The rest of the rollback, which is what makes the status one worth fixing: the
        // rows it leaves behind are the ones the older code would have written itself.
        $insight = DB::table('campaign_wallet_insights')->where('campaign_id', $campaign->id)->first();

        $this->assertSame(0, (int) $insight->prior_tx_count);
        $this->assertSame(0, (int) $insight->is_new);
        $this->assertSame(0, (int) $insight->delegated);
    }
}
