<?php

namespace Tests\Feature;

use App\Http\Controllers\KnownAssetController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Campaign;
use App\Models\Code;
use App\Models\KnownAsset;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reward-asset metadata for the campaign page.
 *
 * A campaign that handed a distinct NFT to each of 500 codes made the page send 500
 * lookups at once, each waiting on its own Koios call, and the host stopped answering
 * anybody. The page now arrives with what is cached, asks for the rest in batches of
 * fifty, and each batch is one Koios call.
 */
class AssetMetadataLookupTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = '12ee2b385da7440849df6c3c4a77af15e8f3cec46f533b22269e7fdf';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private static function nftHex(int $i): string
    {
        return bin2hex(sprintf('Gateway%03d', $i));
    }

    /** Koios answers every asset it is asked about with CIP-0025 metadata. */
    private function fakeKoiosNfts(): void
    {
        Http::fake(['*asset_info*' => function (HttpRequest $request) {
            return Http::response(array_map(fn (array $pair) => [
                'policy_id' => $pair[0],
                'asset_name' => $pair[1],
                'asset_name_ascii' => hex2bin($pair[1]),
                'token_registry_metadata' => null,
                'cip68_metadata' => null,
                'minting_tx_metadata' => ['721' => [$pair[0] => [hex2bin($pair[1]) => ['name' => 'NFT '.hex2bin($pair[1])]]]],
            ], $request->data()['_asset_list']));
        }]);
    }

    private function batch(int $from, int $count): array
    {
        return array_map(fn (int $i) => ['policy' => self::POLICY, 'asset_name' => self::nftHex($i)], range($from, $from + $count - 1));
    }

    public function test_a_guest_cannot_look_assets_up(): void
    {
        $this->postJson(route('known-assets.lookup-many'), ['assets' => $this->batch(1, 1)])->assertUnauthorized();
    }

    public function test_a_batch_is_one_koios_call_and_a_second_ask_is_none(): void
    {
        $this->fakeKoiosNfts();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 50)])
            ->assertOk();

        $this->assertCount(50, $response->json());
        $this->assertSame('NFT Gateway007', $response->json(self::POLICY.self::nftHex(7).'.name'));
        Http::assertSentCount(1);

        $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 50)])
            ->assertOk();
        Http::assertSentCount(1);
    }

    public function test_a_batch_over_the_limit_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['assets' => $this->batch(1, KnownAssetController::BATCH_LIMIT + 1)])
            ->assertUnprocessable();
    }

    // Only the off-chain registry is permanent; CIP-25 and CIP-68 metadata live in the cache.
    public function test_only_a_registry_token_is_written_to_the_shared_table(): void
    {
        $usdmPolicy = 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad';
        Http::fake(['*asset_info*' => Http::response([
            [
                'policy_id' => $usdmPolicy, 'asset_name' => '0014df105553444d', 'asset_name_ascii' => 'USDM',
                'token_registry_metadata' => ['name' => 'USDM', 'ticker' => 'USDM', 'decimals' => 6],
            ],
            [
                'policy_id' => self::POLICY, 'asset_name' => self::nftHex(1), 'asset_name_ascii' => 'Gateway001',
                'token_registry_metadata' => null,
                'minting_tx_metadata' => ['721' => [self::POLICY => ['Gateway001' => ['name' => 'NFT']]]],
            ],
            [
                'policy_id' => $usdmPolicy, 'asset_name' => '0014df10'.bin2hex('Coin'), 'asset_name_ascii' => 'Coin',
                'token_registry_metadata' => null,
                'cip68_metadata' => ['333' => ['constructor' => 0, 'fields' => [
                    ['map' => [['k' => ['bytes' => bin2hex('decimals')], 'v' => ['int' => 2]]]],
                    ['int' => 1],
                ]]],
            ],
        ])]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => [
                ['policy' => $usdmPolicy, 'asset_name' => '0014df105553444d'],
                ['policy' => self::POLICY, 'asset_name' => self::nftHex(1)],
                ['policy' => $usdmPolicy, 'asset_name' => '0014df10'.bin2hex('Coin')],
            ]])
            ->assertOk();

        $this->assertSame(2, $response->json($usdmPolicy.'0014df10'.bin2hex('Coin').'.decimals'));
        $this->assertDatabaseCount('known_assets', 1);
        $this->assertDatabaseHas('known_assets', ['ticker' => 'USDM', 'asset_name' => '0014df105553444d']);
    }

    public function test_an_unknown_asset_is_null_and_not_asked_about_again_for_a_while(): void
    {
        Http::fake(['*asset_info*' => Http::response([])]);
        $user = User::factory()->create();

        foreach ([1, 2] as $_) {
            $response = $this->actingAs($user)
                ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)])
                ->assertOk();
            $this->assertNull($response->json(self::POLICY.self::nftHex(1)));
        }

        Http::assertSentCount(1);
    }

    // An outage is not remembered as every asset being unknown.
    public function test_a_failed_koios_call_caches_nothing(): void
    {
        $calls = 0;
        Http::fake(['*asset_info*' => function (HttpRequest $request) use (&$calls) {
            if (++$calls === 1) {
                return Http::response('down', 500);
            }
            $pair = $request->data()['_asset_list'][0];

            return Http::response([[
                'policy_id' => $pair[0], 'asset_name' => $pair[1], 'asset_name_ascii' => hex2bin($pair[1]),
                'minting_tx_metadata' => ['721' => [$pair[0] => [hex2bin($pair[1]) => ['name' => 'NFT '.hex2bin($pair[1])]]]],
            ]]);
        }]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)])
            ->assertOk();
        $this->assertSame([], $response->json());

        $response = $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)])
            ->assertOk();
        $this->assertSame('NFT Gateway001', $response->json(self::POLICY.self::nftHex(1).'.name'));
    }

    public function test_cip25_metadata_is_cached_for_a_year_and_cip68_for_a_day(): void
    {
        $this->fakeKoiosNfts();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)])
            ->assertOk();

        $this->travel(364)->days();
        $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)]);
        Http::assertSentCount(1);

        $this->travel(2)->days();
        $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)]);
        Http::assertSentCount(2);
    }

    public function test_cip68_metadata_is_read_again_after_a_day(): void
    {
        $policy = 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad';
        $name = '0014df10'.bin2hex('Coin');
        Http::fake(['*asset_info*' => Http::response([[
            'policy_id' => $policy, 'asset_name' => $name, 'asset_name_ascii' => 'Coin',
            'cip68_metadata' => ['333' => ['constructor' => 0, 'fields' => [
                ['map' => [['k' => ['bytes' => bin2hex('ticker')], 'v' => ['bytes' => bin2hex('COIN')]]]],
                ['int' => 1],
            ]]],
        ]])]);
        $user = User::factory()->create();
        $ask = fn () => $this->actingAs($user)->postJson(route('known-assets.lookup-many'), [
            'network' => 'mainnet', 'assets' => [['policy' => $policy, 'asset_name' => $name]],
        ]);

        $this->assertSame('COIN', $ask()->json($policy.$name.'.ticker'));
        $this->travel(23)->hours();
        $ask();
        Http::assertSentCount(1);

        $this->travel(2)->hours();
        $ask();
        Http::assertSentCount(2);
    }

    // The page arrives with what is cached, so a reload asks nothing for those assets.
    public function test_the_campaign_page_carries_cached_asset_metadata_without_calling_koios(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'mainnet']);
        foreach ([1, 2, 3] as $i) {
            Reward::factory()->for(Code::factory()->for($campaign))->create([
                'policy_hex' => self::POLICY,
                'asset_hex' => self::nftHex($i),
                'quantity' => 1,
            ]);
        }

        // Cache two of the three, as an earlier batch would have.
        $this->fakeKoiosNfts();
        $this->actingAs($user)->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 2)]);
        Http::assertSentCount(1);

        $version = app(HandleInertiaRequests::class)->version(request());
        $props = $this->actingAs($user)
            ->get(route('campaigns.show', $campaign), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
                'X-Inertia-Partial-Component' => 'Campaign/Show',
                'X-Inertia-Partial-Data' => 'asset_meta',
            ])
            ->assertOk()
            ->json('props.asset_meta');

        Http::assertSentCount(1);
        $this->assertSame('NFT Gateway001', $props[self::POLICY.self::nftHex(1)]['name']);
        $this->assertArrayHasKey(self::POLICY.self::nftHex(2), $props);
        // Not cached yet, so absent: the page asks for it.
        $this->assertArrayNotHasKey(self::POLICY.self::nftHex(3), $props);
    }

    // Koios refuses a whole batch over one odd-length name; that one is left out instead.
    public function test_a_malformed_asset_name_is_left_out_without_failing_the_batch(): void
    {
        $this->fakeKoiosNfts();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => [
                ['policy' => self::POLICY, 'asset_name' => 'abc'],
                ['policy' => 'not-a-policy', 'asset_name' => ''],
                ['policy' => self::POLICY, 'asset_name' => self::nftHex(1)],
            ]])
            ->assertOk();

        $this->assertSame(['NFT Gateway001'], array_column($response->json(), 'name'));
        Http::assertSent(fn (HttpRequest $request) => $request->data()['_asset_list'] === [[self::POLICY, self::nftHex(1)]]);
    }

    /**
     * Koios as the public tier runs it: a request body over 5120 bytes is refused with a 413
     * before anything is looked up. Every other request is answered like fakeKoiosNfts.
     */
    private function fakeKoiosWithBodyLimit(?callable $refuse = null): void
    {
        Http::fake(['*asset_info*' => function (HttpRequest $request) use ($refuse) {
            $length = strlen($request->body());
            if ($length >= 5120) {
                return Http::response("Payload too large, body length was {$length}. Please ensure your request body size is below 5120 bytes", 413);
            }
            if ($refuse && $refuse($request)) {
                return Http::response('down', 500);
            }

            return Http::response(array_map(fn (array $pair) => [
                'policy_id' => $pair[0],
                'asset_name' => $pair[1],
                'asset_name_ascii' => hex2bin($pair[1]),
                'minting_tx_metadata' => ['721' => [$pair[0] => [hex2bin($pair[1]) => ['name' => 'NFT '.hex2bin($pair[1])]]]],
            ], $request->data()['_asset_list']));
        }]);
    }

    /** A full batch of the 28-byte names a real campaign carried, which came to 6045 bytes. */
    private function longNameBatch(int $count = KnownAssetController::BATCH_LIMIT): array
    {
        return array_map(
            fn (int $i) => ['policy' => self::POLICY, 'asset_name' => bin2hex(sprintf('NFTxLV23aeoniumskyGateway%03d', $i))],
            range(1, $count),
        );
    }

    public function test_a_batch_too_large_for_one_koios_request_is_split_and_fully_answered(): void
    {
        $this->fakeKoiosWithBodyLimit();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->longNameBatch()])
            ->assertOk();

        $this->assertCount(KnownAssetController::BATCH_LIMIT, $response->json());
        $this->assertNotContains(null, $response->json());
        $this->assertSame('NFT NFTxLV23aeoniumskyGateway050', $response->json(self::POLICY.bin2hex('NFTxLV23aeoniumskyGateway050').'.name'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (HttpRequest $request) => strlen($request->body()) >= 5120);
    }

    // The largest asset name a ledger allows is 32 bytes, the worst case for the split.
    public function test_every_request_stays_within_the_limit_at_the_longest_asset_names(): void
    {
        $this->fakeKoiosWithBodyLimit();
        $assets = array_map(
            fn (int $i) => ['policy' => self::POLICY, 'asset_name' => bin2hex(str_pad("N{$i}", 32, 'x'))],
            range(1, KnownAssetController::BATCH_LIMIT),
        );

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $assets])
            ->assertOk();

        $this->assertCount(KnownAssetController::BATCH_LIMIT, array_filter($response->json()));
        Http::assertNotSent(fn (HttpRequest $request) => strlen($request->body()) >= 5120);
    }

    public function test_the_body_limit_is_configurable(): void
    {
        config(['cardano.koios.max_body_bytes' => 1000]);
        $this->fakeKoiosWithBodyLimit();

        $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->longNameBatch(20)])
            ->assertOk();

        Http::assertNotSent(fn (HttpRequest $request) => strlen($request->body()) >= 1000);
        $this->assertGreaterThan(2, count(Http::recorded()));
    }

    // A blank KOIOS_MAX_BODY_BYTES reads as 0, which would send one asset per call.
    public function test_a_blank_or_tiny_body_limit_falls_back_to_the_public_tier_limit(): void
    {
        config(['cardano.koios.max_body_bytes' => 0]);
        $this->fakeKoiosWithBodyLimit();

        $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->longNameBatch()])
            ->assertOk();

        Http::assertSentCount(2);
    }

    // When Koios cannot be reached, the rest of the batch would only wait out the same timeout.
    public function test_an_unreachable_koios_is_not_asked_again_in_the_same_request(): void
    {
        $calls = 0;
        Http::fake(['*asset_info*' => function () use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        }]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->longNameBatch()])
            ->assertOk();

        $this->assertSame([], $response->json());
        $this->assertSame(1, $calls);
    }

    // One failed request answers nothing for its own assets and still leaves the others, and
    // its assets are asked about again on the next load rather than remembered as unknown.
    public function test_a_failed_request_leaves_out_only_its_own_assets(): void
    {
        $failing = true;
        $this->fakeKoiosWithBodyLimit(
            function (HttpRequest $request) use (&$failing) {
                return $failing && $request->data()['_asset_list'][0][1] === bin2hex('NFTxLV23aeoniumskyGateway001');
            },
        );
        $user = User::factory()->create();

        $retry = function () use ($user, &$failing) {
            $failing = false;
            $sentBefore = count(Http::recorded());
            $again = $this->actingAs($user)
                ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->longNameBatch()])
                ->assertOk();
            $this->assertCount(KnownAssetController::BATCH_LIMIT, array_filter($again->json()));
            $this->assertCount($sentBefore + 1, Http::recorded());
        };

        $response = $this->actingAs($user)
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->longNameBatch()])
            ->assertOk();

        $answered = $response->json();
        $this->assertNotEmpty($answered);
        $this->assertLessThan(KnownAssetController::BATCH_LIMIT, count($answered));
        $this->assertArrayNotHasKey(self::POLICY.bin2hex('NFTxLV23aeoniumskyGateway001'), $answered);
        $this->assertSame('NFT NFTxLV23aeoniumskyGateway050', $answered[self::POLICY.bin2hex('NFTxLV23aeoniumskyGateway050')]['name']);

        // Only the failed request's assets go to Koios again, in one call.
        $retry();
    }

    public function test_lookups_are_throttled_per_user(): void
    {
        config(['cardano.asset_metadata.lookups_per_minute' => 2]);
        $this->fakeKoiosNfts();
        $user = User::factory()->create();
        $ask = fn () => $this->actingAs($user)->postJson(route('known-assets.lookup-many'), ['assets' => $this->batch(1, 1)]);

        $ask()->assertOk();
        $ask()->assertOk();
        $ask()->assertTooManyRequests();
    }

    // A registry token whose logo has been fetched is answered from the table alone.
    public function test_a_fetched_registry_token_is_served_from_the_table(): void
    {
        Http::fake();
        KnownAsset::factory()->create([
            'policy_id' => self::POLICY, 'asset_name' => self::nftHex(1), 'network' => 'mainnet',
            'ticker' => 'GATE', 'decimals' => 2, 'logo' => 'iVBOR',
            'metadata' => ['logo_checked' => true],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)])
            ->assertOk();

        $this->assertSame(['ticker' => 'GATE', 'name' => $response->json(self::POLICY.self::nftHex(1).'.name'), 'decimals' => 2, 'logo' => 'iVBOR'], $response->json(self::POLICY.self::nftHex(1)));
        Http::assertNothingSent();
    }

    // The registry sync writes no logo, so a synced row is fetched once and then marked.
    public function test_a_synced_registry_token_is_fetched_once_for_its_logo(): void
    {
        KnownAsset::factory()->create([
            'policy_id' => self::POLICY, 'asset_name' => self::nftHex(1), 'network' => 'mainnet',
            'ticker' => 'GATE', 'logo' => null, 'metadata' => ['url' => null, 'ascii' => 'Gateway001'],
        ]);
        Http::fake(['*asset_info*' => Http::response([[
            'policy_id' => self::POLICY, 'asset_name' => self::nftHex(1), 'asset_name_ascii' => 'Gateway001',
            'token_registry_metadata' => ['name' => 'Gate', 'ticker' => 'GATE', 'decimals' => 0, 'logo' => 'iVBOR'],
        ]])]);
        $user = User::factory()->create();

        foreach ([1, 2] as $_) {
            $response = $this->actingAs($user)
                ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)])
                ->assertOk();
            $this->assertSame('iVBOR', $response->json(self::POLICY.self::nftHex(1).'.logo'));
        }

        Http::assertSentCount(1);
        $this->assertTrue(KnownAsset::first()->metadata['logo_checked']);
    }

    // A value in the cache wins over a stale "not found" marker for the same asset.
    public function test_a_cached_answer_outranks_a_missing_marker(): void
    {
        Cache::put('koios:asset_meta_missing:v1:mainnet:'.self::POLICY.self::nftHex(1), true, now()->addMinutes(10));
        Cache::put('koios:asset_meta:v1:mainnet:'.self::POLICY.self::nftHex(1), [
            'name' => 'Cached', 'ticker' => null, 'decimals' => 0, 'logo' => null, 'source' => 'cip25',
        ], now()->addDay());

        $cached = app(\App\Services\AssetDisplay::class)->cached([[self::POLICY, self::nftHex(1)]], 'mainnet');

        $this->assertSame('Cached', $cached[self::POLICY.self::nftHex(1)]['name']);
    }

    public function test_the_costs_tab_carries_cached_metadata_on_its_single_asset_rows(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'mainnet']);
        $code = Code::factory()->for($campaign)->create();
        Reward::factory()->for($code)->create(['policy_hex' => self::POLICY, 'asset_hex' => self::nftHex(1), 'quantity' => 1]);
        \App\Models\Claim::factory()->for($code)->create();

        $this->fakeKoiosNfts();
        $this->actingAs($user)->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)]);

        $version = app(HandleInertiaRequests::class)->version(request());
        $policies = $this->actingAs($user)
            ->get(route('campaigns.show', $campaign), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
                'X-Inertia-Partial-Component' => 'Campaign/Show',
                'X-Inertia-Partial-Data' => 'costs',
            ])
            ->assertOk()
            ->json('props.costs.policies');

        $this->assertSame('NFT Gateway001', $policies[0]['meta']['name']);
    }

    // A registry token only the sync has written still has its decimals when Koios fails.
    public function test_a_synced_registry_token_keeps_its_decimals_when_koios_fails(): void
    {
        KnownAsset::factory()->create([
            'policy_id' => self::POLICY, 'asset_name' => self::nftHex(1), 'network' => 'mainnet',
            'ticker' => 'GATE', 'decimals' => 6, 'metadata' => ['ascii' => 'Gateway001'],
        ]);
        Http::fake(['*asset_info*' => Http::response('down', 500)]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('known-assets.lookup-many'), ['network' => 'mainnet', 'assets' => $this->batch(1, 1)])
            ->assertOk();

        $this->assertSame(['ticker' => 'GATE', 'name' => KnownAsset::first()->name, 'decimals' => 6, 'logo' => null], $response->json(self::POLICY.self::nftHex(1)));
    }

    public function test_the_page_carries_a_synced_rows_decimals_when_koios_is_remembered_not_to_know_it(): void
    {
        KnownAsset::factory()->create([
            'policy_id' => self::POLICY, 'asset_name' => self::nftHex(1), 'network' => 'mainnet',
            'ticker' => 'GATE', 'decimals' => 6, 'metadata' => ['ascii' => 'Gateway001'],
        ]);
        Cache::put('koios:asset_meta_missing:v1:mainnet:'.self::POLICY.self::nftHex(1), true, now()->addMinutes(10));

        $cached = app(\App\Services\AssetDisplay::class)->cached([[self::POLICY, self::nftHex(1)]], 'mainnet');

        $this->assertSame(6, $cached[self::POLICY.self::nftHex(1)]['decimals']);
    }
}
