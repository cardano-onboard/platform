<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\CreateCampaignBucket;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What a partial reload of the campaign page costs.
 *
 * Inertia resolves a closure prop only when the request asks for that prop, so the
 * expensive halves of show() are closures. These tests hold the two that are not just
 * data: the balance call to the transaction backend, and the bucket provisioning job.
 *
 * Every test that asserts something did not happen has a sibling asserting it does
 * happen on a full page load, because a test that only proves absence passes just as
 * well when the work was never wired up at all.
 */
class CampaignPartialReloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fake the transaction backend with a live UTxO the balance prop can actually carry,
     * so an empty balance in a response means the call was skipped rather than that
     * there was nothing to return.
     */
    private function fakeBucketBalance(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['liveUtxos' => [[
                'txHash' => '9d2f1b4c8e7a35d6c0b1f8a27e43d95c6b0a1f8e7d3c25b4a9f80e1d7c6b5a43',
                'index' => 0,
                'value' => ['lovelace' => 25000000],
            ]]]],
        ])]);
    }

    /**
     * A campaign with a wallet on the custodial backend, so getBalance() is a real HTTP
     * call rather than the null backend's empty array, and with codes whose arithmetic
     * is awkward: one partly claimed, one claimed past its own limit, one with no limit
     * at all.
     */
    private function campaignWithWork(User $user): Campaign
    {
        $campaign = Campaign::factory()->for($user)->create([
            'network' => 'preprod',
            'start_date' => now()->subDays(3),
            'end_date' => now()->addDays(3),
        ]);

        Wallet::factory()->for($campaign)->create(['backend' => 'phyrhose']);

        $partlyClaimed = Code::factory()->for($campaign)->create(['uses' => 5, 'lovelace' => 2000000]);
        Claim::factory()->count(2)->for($partlyClaimed)->create();
        Reward::factory()->for($partlyClaimed)->create([
            'policy_hex' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            'asset_hex' => '484f534b59',
            'quantity' => 5,
        ]);

        // Claimed past its own limit. max(0, uses - claims) has to clamp, or the totals
        // go negative and the funding panel asks the operator to send less than nothing.
        $overClaimed = Code::factory()->for($campaign)->create(['uses' => 1, 'lovelace' => 1500000]);
        Claim::factory()->count(3)->for($overClaimed)->create();

        // uses = 0 is the unlimited code. It owes nothing up front for the same reason.
        Code::factory()->for($campaign)->create(['uses' => 0, 'lovelace' => 1000000]);

        return $campaign;
    }

    /**
     * The headers a partial reload sends. The component name is what Inertia itself
     * tests to decide a request is a partial, and the version has to match or the
     * middleware answers 409 instead of rendering anything.
     */
    private function partialHeaders(array $only): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'Campaign/Show',
            'X-Inertia-Partial-Data' => implode(',', $only),
        ];
    }

    private function propsOf($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    /**
     * Every query a request ran. The log is flushed first so one request's queries are
     * never counted against the next.
     */
    private function queriesDuring(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $request();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return array_column($log, 'query');
    }

    public function test_a_full_page_load_asks_the_backend_for_the_balance(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $response = $this->actingAs($user)->get(route('campaigns.show', $campaign));

        $response->assertOk();
        Http::assertSentCount(1);
        $this->assertSame(25000000, $this->propsOf($response)['balance'][0]['value']['lovelace']);
    }

    public function test_a_partial_reload_for_another_prop_does_not_ask_the_backend_for_the_balance(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $response = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['onboarding'])
        );

        $response->assertOk();
        Http::assertNothingSent();

        $props = $response->json('props');
        $this->assertArrayHasKey('onboarding', $props);
        $this->assertArrayNotHasKey('balance', $props);
    }

    public function test_a_partial_reload_for_the_balance_still_asks_the_backend(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $response = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['balance'])
        );

        $response->assertOk();
        Http::assertSentCount(1);
        $this->assertSame(25000000, $response->json('props.balance.0.value.lovelace'));
    }

    public function test_two_props_that_both_need_the_balance_cost_one_backend_call(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $response = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['balance', 'alerts'])
        );

        $response->assertOk();
        Http::assertSentCount(1);

        $props = $response->json('props');
        $this->assertArrayHasKey('balance', $props);
        $this->assertArrayHasKey('alerts', $props);
    }

    public function test_a_partial_reload_asks_for_the_prop_it_named_and_gets_nothing_else(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $response = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['onboarding'])
        );

        $response->assertOk();

        $props = $response->json('props');
        $this->assertArrayHasKey('onboarding', $props);

        foreach (['campaign', 'stats', 'balance', 'funding', 'alerts', 'costs', 'wallet_clients'] as $expensive) {
            $this->assertArrayNotHasKey($expensive, $props);
        }
    }

    public function test_a_partial_reload_for_one_panel_does_not_load_the_campaign_codes(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        // The withCount aliases Code declares appear in the eager load of the codes
        // relation and nowhere else in this request, so they are what marks it.
        $marks = fn (array $queries) => (bool) array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'rewards_count')
        );

        $whole = $this->queriesDuring(function () use ($user, $campaign) {
            $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertOk();
        });

        $part = $this->queriesDuring(function () use ($user, $campaign) {
            $this->actingAs($user)->get(
                route('campaigns.show', $campaign),
                $this->partialHeaders(['onboarding'])
            )->assertOk();
        });

        $this->assertTrue($marks($whole), 'A full page load should still load the codes.');
        $this->assertFalse($marks($part), 'A partial reload for the onboarding panel loaded the codes.');
        $this->assertLessThan(count($whole), count($part));
    }

    public function test_a_full_page_load_still_queues_a_bucket_for_a_campaign_that_has_none(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk();

        Queue::assertPushed(CreateCampaignBucket::class, 1);
    }

    public function test_polling_a_campaign_that_has_no_wallet_queues_no_further_buckets(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk();

        // A minute of the six second poll the onboarding panel runs.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->get(
                route('campaigns.show', $campaign),
                $this->partialHeaders(['onboarding'])
            )->assertOk();
        }

        Queue::assertPushed(CreateCampaignBucket::class, 1);
    }

    public function test_a_partial_reload_of_a_page_whose_campaign_still_has_no_wallet_reports_it_pending(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);

        $response = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['wallet_pending', 'balance', 'backend_mismatch', 'wallet_backend'])
        );

        $response->assertOk();
        Queue::assertNothingPushed();

        $this->assertTrue($response->json('props.wallet_pending'));
        $this->assertSame([], $response->json('props.balance'));
        $this->assertFalse($response->json('props.backend_mismatch'));
        $this->assertNull($response->json('props.wallet_backend'));
    }

    public function test_a_full_page_load_carries_every_prop_it_carried_before(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $response = $this->actingAs($user)->get(route('campaigns.show', $campaign));

        $response->assertOk();
        $props = $this->propsOf($response);

        foreach ([
            'campaign', 'stats', 'claim_url', 'encoded_claim_url', 'balance', 'wallet_pending',
            'backend_mismatch', 'wallet_backend', 'max_file_size', 'allowed_networks',
            'gd_available', 'onboarding', 'funding', 'alerts', 'costs', 'wallet_clients',
        ] as $prop) {
            $this->assertArrayHasKey($prop, $props);
            $this->assertIsNotCallable($props[$prop], "Prop [{$prop}] reached the page unresolved.");
        }

        $this->assertSame(3, $props['campaign']['codes_count']);
        $this->assertSame(5, $props['campaign']['claims_count']);
        $this->assertFalse($props['wallet_pending']);
    }

    public function test_the_campaign_payload_is_the_same_reloaded_in_part_as_loaded_whole(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $whole = $this->actingAs($user)->get(route('campaigns.show', $campaign));
        $whole->assertOk();

        $part = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['campaign'])
        );
        $part->assertOk();

        $this->assertSame(
            json_decode(json_encode($this->propsOf($whole)['campaign']), true),
            $part->json('props.campaign')
        );
    }

    public function test_the_funding_figures_clamp_a_code_claimed_past_its_own_limit(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $whole = $this->actingAs($user)->get(route('campaigns.show', $campaign));
        $whole->assertOk();

        $part = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['funding'])
        );
        $part->assertOk();

        // Only the partly claimed code owes anything: three of its five uses are left.
        // The over-claimed code and the unlimited one both contribute nothing.
        $this->assertSame(3, $part->json('props.funding.remaining_claims'));
        $this->assertSame(6000000, $part->json('props.funding.reward_lovelace'));

        $this->assertSame(
            json_decode(json_encode($this->propsOf($whole)['funding']), true),
            $part->json('props.funding')
        );
    }

    public function test_the_stats_are_the_same_reloaded_in_part_as_loaded_whole(): void
    {
        $this->fakeBucketBalance();

        $user = User::factory()->create();
        $campaign = $this->campaignWithWork($user);

        $whole = $this->actingAs($user)->get(route('campaigns.show', $campaign));
        $whole->assertOk();

        $part = $this->actingAs($user)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['stats'])
        );
        $part->assertOk();

        $stats = $part->json('props.stats');

        $this->assertSame(5, $stats['claimed_vs_unclaimed']['claimed']);
        $this->assertSame(3, $stats['claimed_vs_unclaimed']['unclaimed']);
        $this->assertSame(3, $stats['code_utilization']['total']);

        $this->assertSame(
            json_decode(json_encode($this->propsOf($whole)['stats']), true),
            $stats
        );
    }

    public function test_another_users_campaign_is_refused_on_a_partial_reload_too(): void
    {
        $this->fakeBucketBalance();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = $this->campaignWithWork($owner);

        $this->actingAs($other)->get(
            route('campaigns.show', $campaign),
            $this->partialHeaders(['balance'])
        )->assertForbidden();

        Http::assertNothingSent();
    }
}
