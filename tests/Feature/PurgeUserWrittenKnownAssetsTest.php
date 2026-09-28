<?php

namespace Tests\Feature;

use App\Models\KnownAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rows the old lookup and the removed user endpoint wrote carry no metadata; the registry
 * sync always sets it. Every logo in the table came from one of those two paths.
 */
class PurgeUserWrittenKnownAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_not_written_by_the_registry_sync_go_and_every_logo_is_cleared(): void
    {
        $synced = KnownAsset::factory()->create(['ticker' => 'HOSKY', 'logo' => 'spoofed', 'fingerprint' => 'asset1spoofed', 'metadata' => ['url' => null]]);
        KnownAsset::factory()->create(['ticker' => 'SCAM', 'logo' => 'x', 'metadata' => null]);

        $migration = require database_path('migrations/2026_09_27_180000_purge_user_written_known_assets.php');
        $migration->up();

        $this->assertSame([$synced->id], KnownAsset::pluck('id')->all());
        $this->assertNull($synced->fresh()->logo);
        $this->assertNull($synced->fresh()->fingerprint);
    }
}
