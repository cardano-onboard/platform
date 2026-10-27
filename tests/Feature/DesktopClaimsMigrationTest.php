<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Its own class and DatabaseMigrations, not a shared transaction: running the migration
 * back and forth is DDL, which commits the RefreshDatabase transaction on MySQL.
 */
class DesktopClaimsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_migration_defaults_existing_campaigns_to_off(): void
    {
        $migration = require base_path('database/migrations/2026_10_26_110000_add_desktop_claims_enabled_to_campaigns.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('campaigns', 'desktop_claims_enabled'));

        $owner = User::factory()->create();
        DB::table('campaigns')->insert([
            'id' => '01HEXISTINGCAMPAIGN0000000',
            'user_id' => $owner->id,
            'name' => 'Existing',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'network' => 'preprod',
        ]);

        $migration->up();

        $this->assertSame(0, (int) DB::table('campaigns')->where('name', 'Existing')->value('desktop_claims_enabled'));
    }
}
