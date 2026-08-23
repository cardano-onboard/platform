<?php

namespace Tests\Feature;

use App\Models\KnownAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncKnownAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function registryPage(): array
    {
        return [
            [
                'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
                'asset_name' => '484f534b59',
                'asset_name_ascii' => 'HOSKY',
                'ticker' => 'HOSKY',
                'name' => 'HOSKY Token',
                'decimals' => 0,
                'description' => 'The original meme coin of Cardano.',
                'url' => 'https://hosky.io',
            ],
            [
                'policy_id' => 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad',
                'asset_name' => '0014df105553444d',
                'asset_name_ascii' => 'USDM',
                'ticker' => 'USDM',
                'name' => 'USDM',
                'decimals' => 6,
            ],
        ];
    }

    public function test_sync_populates_known_assets_from_registry(): void
    {
        // First call returns a page; second call returns empty to end pagination.
        Http::fakeSequence()
            ->push($this->registryPage())
            ->push([]);

        $this->artisan('assets:sync-registry', ['--network' => 'mainnet'])
            ->assertSuccessful();

        $this->assertDatabaseCount('known_assets', 2);
        $this->assertDatabaseHas('known_assets', ['ticker' => 'USDM', 'decimals' => 6, 'network' => 'mainnet']);
        $this->assertDatabaseHas('known_assets', ['ticker' => 'HOSKY', 'decimals' => 0]);
    }

    public function test_sync_is_idempotent_and_updates_existing_rows(): void
    {
        KnownAsset::factory()->create([
            'policy_id' => 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad',
            'asset_name' => '0014df105553444d',
            'network' => 'mainnet',
            'ticker' => 'OLD',
            'decimals' => 0,
        ]);

        Http::fakeSequence()->push($this->registryPage())->push([]);

        $this->artisan('assets:sync-registry', ['--network' => 'mainnet'])->assertSuccessful();

        // No duplicate row; the existing one is refreshed to the registry values.
        $this->assertDatabaseCount('known_assets', 2);
        $this->assertDatabaseHas('known_assets', ['ticker' => 'USDM', 'decimals' => 6]);
        $this->assertDatabaseMissing('known_assets', ['ticker' => 'OLD']);
    }
}
