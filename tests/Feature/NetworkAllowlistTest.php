<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NetworkAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBucketProvisioning(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['bucketAddress' => 'addr_test1abc', 'campaignId' => 'test-id']],
        ])]);
    }

    private function campaignPayload(string $network): array
    {
        return [
            'name' => 'Campaign on '.$network,
            'description' => 'Allowlist test',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
            'network' => $network,
        ];
    }

    public function test_default_config_allows_every_network(): void
    {
        $this->assertEquals(
            ['preprod', 'preview', 'mainnet'],
            config('cardano.allowed_networks')
        );
    }

    /**
     * The self-hosted / production parity case: with the allowlist left at its
     * default, every network a campaign could previously use still works.
     */
    public function test_store_accepts_every_network_by_default(): void
    {
        $this->fakeBucketProvisioning();
        $user = User::factory()->create();

        foreach (['preprod', 'preview', 'mainnet'] as $network) {
            $this->actingAs($user)
                ->post(route('campaigns.store'), $this->campaignPayload($network))
                ->assertRedirect();

            $this->assertDatabaseHas('campaigns', [
                'user_id' => $user->id,
                'name' => 'Campaign on '.$network,
                'network' => $network,
            ]);
        }
    }

    public function test_update_accepts_every_network_by_default(): void
    {
        $user = User::factory()->create();

        foreach (['preprod', 'preview', 'mainnet'] as $network) {
            $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
            Wallet::factory()->for($campaign)->create();

            $this->actingAs($user)
                ->put(route('campaigns.update', $campaign), [
                    'name' => 'Updated for '.$network,
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addMonth()->toDateString(),
                    'network' => $network,
                ])
                ->assertRedirect();

            $this->assertEquals($network, $campaign->fresh()->network);
        }
    }

    public function test_store_rejects_a_network_outside_the_allowlist(): void
    {
        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('campaigns.store'), $this->campaignPayload('mainnet'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['network']);

        $this->assertDatabaseMissing('campaigns', ['network' => 'mainnet']);
    }

    public function test_update_rejects_a_network_outside_the_allowlist(): void
    {
        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        Wallet::factory()->for($campaign)->create();

        $this->actingAs($user)
            ->putJson(route('campaigns.update', $campaign), [
                'name' => 'Updated Name',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'network' => 'mainnet',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['network']);

        $this->assertEquals('preprod', $campaign->fresh()->network);
    }

    public function test_store_still_accepts_a_network_inside_a_narrowed_allowlist(): void
    {
        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $this->fakeBucketProvisioning();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('campaigns.store'), $this->campaignPayload('preview'))
            ->assertRedirect();

        $this->assertDatabaseHas('campaigns', [
            'user_id' => $user->id,
            'network' => 'preview',
        ]);
    }

    /**
     * An existing campaign keeps the network it was created on. The allowlist
     * governs what the deployment accepts for new work, and narrowing it must not
     * lock an operator out of editing everything else about a campaign that is
     * already running on the excluded network.
     */
    public function test_update_accepts_the_existing_network_after_the_allowlist_narrows(): void
    {
        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'mainnet']);
        Wallet::factory()->for($campaign)->create();

        $this->actingAs($user)
            ->put(route('campaigns.update', $campaign), [
                'name' => 'Renamed While Grandfathered',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'network' => 'mainnet',
            ])
            ->assertRedirect();

        $campaign->refresh();
        $this->assertEquals('Renamed While Grandfathered', $campaign->name);
        $this->assertEquals('mainnet', $campaign->network);
    }

    public function test_update_rejects_switching_to_a_different_disallowed_network(): void
    {
        config(['cardano.allowed_networks' => ['preprod']]);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'mainnet']);
        Wallet::factory()->for($campaign)->create();

        $this->actingAs($user)
            ->putJson(route('campaigns.update', $campaign), [
                'name' => 'Updated Name',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'network' => 'preview',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['network']);

        $this->assertEquals('mainnet', $campaign->fresh()->network);
    }

    public function test_campaign_page_receives_the_allowlist(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['liveUtxos' => []]],
        ])]);

        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'mainnet']);
        Wallet::factory()->for($campaign)->create();

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Campaign/Show')
                ->where('allowed_networks', ['preprod', 'preview'])
            );
    }

    /**
     * The dashboard carries its own campaign creation dialog, and it went without the
     * allowlist entirely: its selector was a hardcoded pair. A deployment restricted to
     * preprod still offered mainnet there, and the only thing stopping a campaign being
     * created on it was the controller rejecting the submission.
     */
    public function test_dashboard_receives_the_allowlist(): void
    {
        config(['cardano.allowed_networks' => ['preprod']]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('allowed_networks', ['preprod'])
            );
    }

    /**
     * The dashboard dialog is now the only way to create a campaign, so this is the one
     * selector that has to agree with the controller.
     */
    public function test_the_dashboard_offers_exactly_the_allowed_networks(): void
    {
        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('allowed_networks', ['preprod', 'preview'])
            );
    }

    /**
     * The default a self-hosted install and production both run under. The dialog that
     * creates campaigns is handed all three networks without the deployment saying
     * anything, which is the state every other case in this file narrows away from.
     */
    public function test_the_dashboard_offers_all_three_networks_by_default(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('allowed_networks', ['preprod', 'preview', 'mainnet'])
            );
    }

    public function test_dashboard_carries_no_mainnet_hint_by_default(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mainnet_app_url', null)
            );
    }

    public function test_dashboard_carries_no_mainnet_hint_when_unset_even_if_mainnet_is_excluded(): void
    {
        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mainnet_app_url', null)
            );
    }

    public function test_dashboard_carries_the_mainnet_hint_when_mainnet_is_excluded_and_configured(): void
    {
        config([
            'cardano.allowed_networks' => ['preprod', 'preview'],
            'cardano.mainnet_app_url' => 'https://example.com',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mainnet_app_url', 'https://example.com')
            );
    }

    /**
     * The hint points an operator at where a mainnet campaign is actually created; a
     * deployment that already accepts mainnet has nowhere else to send them.
     */
    public function test_dashboard_carries_no_mainnet_hint_when_mainnet_is_allowed_even_if_configured(): void
    {
        config([
            'cardano.allowed_networks' => ['preprod', 'preview', 'mainnet'],
            'cardano.mainnet_app_url' => 'https://example.com',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mainnet_app_url', null)
            );
    }

    /**
     * The create page carried a second network selector and went on offering every network
     * after the allowlist arrived, because nothing linked to it and nothing looked at it.
     * The dialog on the dashboard is now the only way to create a campaign, and that is
     * only true for as long as these stay unroutable.
     */
    public function test_the_removed_campaign_pages_are_not_routable(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        foreach (['campaigns.index', 'campaigns.create', 'campaigns.edit'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} is registered again.");
        }

        foreach (['/campaigns', '/campaigns/create', "/campaigns/{$campaign->id}/edit"] as $url) {
            $response = $this->actingAs($user)->get($url);

            $this->assertFalse(
                $response->isSuccessful(),
                "{$url} answered a signed-in GET with {$response->status()}."
            );
        }

        // What the interface does reach has to survive the removal.
        $this->assertTrue(Route::has('campaigns.store'));
        $this->assertTrue(Route::has('campaigns.update'));
        $this->assertTrue(Route::has('campaigns.show'));
    }

    /**
     * Four empty scaffold methods that returned a blank 200 to any signed-in request. They
     * are on the same resource registration as the three the campaign page actually uses,
     * so a later ->only() edit can put them back without anyone noticing.
     *
     * `codes.update` was one of them and is not any more: it carries the reward edit, which
     * is a dialog on the campaign page rather than a page of its own. `codes.edit`, the GET
     * that would serve such a page, stays unregistered.
     */
    public function test_the_empty_code_routes_are_not_routable(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        $code = Code::factory()->for($campaign)->create();

        foreach (['codes.index', 'codes.create', 'codes.show', 'codes.edit'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} is registered again.");
        }

        $this->assertTrue(Route::has('codes.store'));
        $this->assertTrue(Route::has('codes.update'));
        $this->assertTrue(Route::has('codes.destroy'));

        foreach (['/codes', '/codes/create', "/codes/{$code->id}", "/codes/{$code->id}/edit"] as $url) {
            $response = $this->actingAs($user)->get($url);

            $this->assertFalse(
                $response->isSuccessful(),
                "{$url} answered a signed-in GET with {$response->status()}."
            );
        }
    }

    /**
     * Grandfathering once the campaign has been claimed against, which is what a campaign
     * "already running" on an excluded network usually means. Its network is settled at
     * that point and the update rules leave the field out altogether, so a payload naming
     * another excluded network moves nothing and the rest of the edit still saves.
     */
    public function test_a_claimed_campaign_on_an_excluded_network_renames_and_cannot_move(): void
    {
        config(['cardano.allowed_networks' => ['preprod']]);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'mainnet']);
        Wallet::factory()->for($campaign)->create();
        Claim::factory()->for(Code::factory()->for($campaign)->create())->create();

        $this->actingAs($user)
            ->put(route('campaigns.update', $campaign), [
                'name' => 'Renamed After Claims',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'network' => 'preview',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $campaign->refresh();
        $this->assertSame('Renamed After Claims', $campaign->name);
        $this->assertSame('mainnet', $campaign->network);
    }
}
