<?php

namespace Tests\Feature;

use App\Models\KnownAsset;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnownAssetControllerTest extends TestCase
{
    use RefreshDatabase;

    private function koiosResponse(): array
    {
        return [[
            'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            'asset_name' => '484f534b59',
            'asset_name_ascii' => 'HOSKY',
            'fingerprint' => 'asset17q7r59zlc3dgw0venc80pdv566q6yguw03f0d9',
            'token_registry_metadata' => [
                'name' => 'HOSKY Token',
                'ticker' => 'HOSKY',
                'decimals' => 0,
                'logo' => 'aGVsbG8=',
                'description' => 'The original meme coin of Cardano.',
            ],
        ]];
    }

    public function test_guest_cannot_search_known_assets(): void
    {
        $this->getJson(route('known-assets.index'))->assertUnauthorized();
    }

    public function test_index_searches_by_ticker_scoped_to_network(): void
    {
        $user = User::factory()->create();
        KnownAsset::factory()->create(['ticker' => 'HOSKY', 'name' => 'HOSKY Token', 'network' => 'mainnet']);
        KnownAsset::factory()->create(['ticker' => 'MIN', 'name' => 'Minswap', 'network' => 'mainnet']);
        KnownAsset::factory()->create(['ticker' => 'TADA', 'name' => 'Testnet Token', 'network' => 'preprod']);

        $response = $this->actingAs($user)
            ->getJson(route('known-assets.index', ['q' => 'hosky', 'network' => 'mainnet']))
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame('HOSKY', $response->json('0.ticker'));
        // Subject accessor is exposed for the frontend autofill.
        $this->assertArrayHasKey('subject', $response->json('0'));
    }

    public function test_index_ranks_tokens_by_reward_usage(): void
    {
        $user = User::factory()->create();
        KnownAsset::factory()->create(['ticker' => 'HOSKY', 'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235', 'asset_name' => '484f534b59', 'network' => 'mainnet']);
        KnownAsset::factory()->create(['ticker' => 'USDM', 'policy_id' => 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad', 'asset_name' => '0014df105553444d', 'network' => 'mainnet']);
        // Alphabetically first, but never used as a reward — should rank last.
        KnownAsset::factory()->create(['ticker' => 'AAAA', 'policy_id' => str_repeat('f', 56), 'asset_name' => '6161', 'network' => 'mainnet']);

        Reward::factory()->count(3)->create(['policy_hex' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235', 'asset_hex' => '484f534b59']);
        Reward::factory()->create(['policy_hex' => 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad', 'asset_hex' => '0014df105553444d']);

        $response = $this->actingAs($user)
            ->getJson(route('known-assets.index', ['network' => 'mainnet']))
            ->assertOk();

        $tickers = collect($response->json())->pluck('ticker')->all();
        // Ranked most-used first (HOSKY:3, USDM:1), then the never-used AAAA last —
        // despite AAAA being alphabetically first.
        $this->assertSame(['HOSKY', 'USDM', 'AAAA'], $tickers);

        // Usage is used only for ordering — never exposed, so SaaS users can't see how
        // much others have used a token.
        $this->assertArrayNotHasKey('usage_count', $response->json('0'));
    }

    public function test_index_does_not_return_other_network_assets(): void
    {
        $user = User::factory()->create();
        KnownAsset::factory()->create(['ticker' => 'TADA', 'network' => 'preprod']);

        $this->actingAs($user)
            ->getJson(route('known-assets.index', ['network' => 'mainnet']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_lookup_falls_back_to_koios_and_caches_the_result(): void
    {
        Http::fake(['*' => Http::response($this->koiosResponse())]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('known-assets.lookup', [
                'policy' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
                'asset_name' => '484f534b59',
                'network' => 'mainnet',
            ]))
            ->assertOk()
            ->assertJson(['ticker' => 'HOSKY', 'name' => 'HOSKY Token', 'decimals' => 0]);

        // The Koios result is cached into the registry for next time.
        $this->assertDatabaseHas('known_assets', [
            'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            'asset_name' => '484f534b59',
            'network' => 'mainnet',
            'ticker' => 'HOSKY',
            'decimals' => 0,
        ]);
    }

    public function test_lookup_serves_from_registry_without_calling_koios(): void
    {
        Http::fake(); // any outbound HTTP would be recorded
        $user = User::factory()->create();
        KnownAsset::factory()->create([
            'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            'asset_name' => '484f534b59',
            'network' => 'mainnet',
            'ticker' => 'HOSKY',
            'decimals' => 0,
        ]);

        $this->actingAs($user)
            ->getJson(route('known-assets.lookup', [
                'policy' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
                'asset_name' => '484f534b59',
                'network' => 'mainnet',
            ]))
            ->assertOk()
            ->assertJson(['ticker' => 'HOSKY', 'decimals' => 0]);

        // Served from our table — Koios was not queried.
        Http::assertNothingSent();
    }

    public function test_lookup_validates_hex_policy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('known-assets.lookup', ['policy' => 'not-hex!!']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['policy']);
    }

    public function test_lookup_returns_404_when_asset_unknown(): void
    {
        Http::fake(['*' => Http::response([])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('known-assets.lookup', [
                'policy' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
                'asset_name' => 'deadbeef',
            ]))
            ->assertNotFound();
    }

    public function test_store_persists_and_dedupes_known_asset(): void
    {
        $user = User::factory()->create();
        $payload = [
            'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            'asset_name' => '484f534b59',
            'ticker' => 'HOSKY',
            'name' => 'HOSKY Token',
            'decimals' => 0,
            'network' => 'mainnet',
        ];

        $this->actingAs($user)->postJson(route('known-assets.store'), $payload)->assertCreated();
        // Same subject again => update, not a duplicate row.
        $this->actingAs($user)
            ->postJson(route('known-assets.store'), array_merge($payload, ['name' => 'HOSKY (updated)']))
            ->assertOk();

        $this->assertDatabaseCount('known_assets', 1);
        $this->assertDatabaseHas('known_assets', ['ticker' => 'HOSKY', 'name' => 'HOSKY (updated)']);
    }
}
