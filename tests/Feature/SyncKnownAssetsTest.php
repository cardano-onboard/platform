<?php

namespace Tests\Feature;

use App\Models\KnownAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncKnownAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

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

    // A lookup marks a row once it has fetched the logo; a sync must not undo that.
    public function test_sync_keeps_the_mark_a_lookup_left_on_a_row(): void
    {
        KnownAsset::factory()->create([
            'policy_id' => 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad',
            'asset_name' => '0014df105553444d',
            'network' => 'mainnet',
            'logo' => 'iVBOR',
            'metadata' => ['logo_checked' => true],
        ]);

        Http::fakeSequence()->push($this->registryPage())->push([]);

        $this->artisan('assets:sync-registry', ['--network' => 'mainnet'])->assertSuccessful();

        $row = KnownAsset::where('asset_name', '0014df105553444d')->first();
        $this->assertTrue($row->metadata['logo_checked']);
        $this->assertSame('iVBOR', $row->logo);
        $this->assertSame(6, (int) $row->decimals);
    }

    public function test_a_sync_koios_stopped_part_way_through_exits_as_a_failure(): void
    {
        // A first page, then Koios failing on every retry of the second. The rows already
        // fetched are kept, but the run is not done, and whatever runs it on a timer must
        // see a failure so that it tries again.
        Http::fakeSequence()
            ->push($this->registryPage())
            ->whenEmpty(Http::response('unavailable', 503));

        $this->artisan('assets:sync-registry', ['--network' => 'mainnet', '--limit' => 2])
            ->assertFailed();

        $this->assertDatabaseCount('known_assets', 2);

        // The pause after the first page, then the four attempts at the second backing off
        // one second longer each time.
        Sleep::assertSequence([
            Sleep::usleep(400_000),
            Sleep::for(1)->seconds(),
            Sleep::for(2)->seconds(),
            Sleep::for(3)->seconds(),
            Sleep::for(4)->seconds(),
        ]);
    }
}
