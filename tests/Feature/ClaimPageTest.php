<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClaimPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setting_is_off_for_a_new_campaign(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'Fresh',
            'description' => 'Created without touching the setting.',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'network' => 'preprod',
        ])->assertSessionHasNoErrors();

        $this->assertFalse((bool) Campaign::where('name', 'Fresh')->sole()->desktop_claims_enabled);
        $this->assertFalse((bool) Campaign::factory()->create()->fresh()->desktop_claims_enabled);
    }

    public function test_the_owner_can_turn_it_on_when_creating_a_campaign(): void
    {
        $this->actingAs(User::factory()->create())->post(route('campaigns.store'), [
            'name' => 'Opted in',
            'description' => 'Created with the setting on.',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'network' => 'preprod',
            'desktop_claims_enabled' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue((bool) Campaign::where('name', 'Opted in')->sole()->desktop_claims_enabled);
    }

    public function test_the_campaign_page_carries_the_setting_as_it_is_stored(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'data' => [null, ['liveUtxos' => []]]])]);
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->for($owner)->create();

        $this->actingAs($owner)->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaign.desktop_claims_enabled', 0));

        $campaign->forceFill(['desktop_claims_enabled' => true])->save();

        $this->actingAs($owner)->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('campaign.desktop_claims_enabled', 1));
    }

    public function test_the_owner_can_change_the_setting_and_nobody_else_can(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->for($owner)->create(['name' => 'Mine']);
        $payload = fn (bool $on) => [
            'name' => 'Mine',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'network' => 'preprod',
            'desktop_claims_enabled' => $on,
        ];

        $this->actingAs(User::factory()->create())
            ->put(route('campaigns.update', $campaign), $payload(true))
            ->assertForbidden();
        $this->assertFalse((bool) $campaign->fresh()->desktop_claims_enabled);

        $this->actingAs($owner)
            ->put(route('campaigns.update', $campaign), $payload(true))
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $campaign->fresh()->desktop_claims_enabled);

        $this->actingAs($owner)
            ->put(route('campaigns.update', $campaign), $payload(false))
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) $campaign->fresh()->desktop_claims_enabled);
    }

    public function test_an_edit_that_omits_the_setting_leaves_it_alone(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->for($owner)->desktopClaims()->create(['name' => 'Mine']);

        $this->actingAs($owner)->put(route('campaigns.update', $campaign), [
            'name' => 'Mine',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'network' => 'preprod',
        ])->assertSessionHasNoErrors();

        $this->assertTrue((bool) $campaign->fresh()->desktop_claims_enabled);
    }

    public function test_the_claim_page_renders_for_a_signed_out_visitor_when_the_campaign_opted_in(): void
    {
        $campaign = Campaign::factory()->desktopClaims()->create();

        $this->get('/api/claim/v1/'.$campaign->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Claim')
                // Shared by the Inertia middleware, which the page's share card needs:
                // proof the route runs the web group.
                ->has('transaction_backend')
                ->has('meta.title'));
    }

    public function test_with_the_setting_off_the_page_only_says_to_use_a_wallet_app(): void
    {
        $campaign = Campaign::factory()->create([
            'name' => 'Internal Label 4471',
            'network' => 'preview',
            'txn_msg' => 'Secret Reward Message',
        ]);
        Code::factory()->for($campaign)->create(['lovelace' => 7654321, 'uses' => 3]);

        $response = $this->get('/api/claim/v1/'.$campaign->id)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('ClaimInWallet')
            ->missing('campaign')
            ->has('meta.title'));

        $content = $response->getContent();
        foreach (['Internal Label 4471', 'preview', 'Secret Reward Message', '7654321'] as $leak) {
            $this->assertStringNotContainsString($leak, $content);
        }
    }

    public function test_an_unknown_deleted_or_disabled_campaign_gets_the_same_page(): void
    {
        $disabled = Campaign::factory()->create();
        $deleted = Campaign::factory()->desktopClaims()->create();
        $deleted->delete();

        $pages = collect(['does-not-exist', $deleted->id, $disabled->id])->map(function (string $id) {
            $response = $this->get('/api/claim/v1/'.$id)->assertOk();

            return $response->viewData('page')['component'].'|'.json_encode($response->viewData('page')['props']['meta']);
        });

        $this->assertCount(1, $pages->unique());
        $this->assertStringStartsWith('ClaimInWallet|', $pages->first());
    }

    public function test_the_claim_post_is_unaffected_by_the_setting(): void
    {
        foreach ([false, true] as $on) {
            $campaign = Campaign::factory()->create(['desktop_claims_enabled' => $on]);

            $this->postJson('/api/claim/v1/'.$campaign->id, [])->assertOk()->assertJson(['status' => 'missingcode']);
            $this->postJson('/api/claim/v1/'.$campaign->id, ['code' => 'NOPE', 'address' => 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3n0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgs68faae'])
                ->assertJson(['status' => 'notfound']);
        }
    }

    public function test_the_page_shares_its_address_with_the_claim_endpoint(): void
    {
        $campaign = Campaign::factory()->create();

        $this->assertSame(route('claim.v1', $campaign), route('claim.page', $campaign));
    }

    public function test_the_page_carries_nothing_about_the_campaign(): void
    {
        $campaign = Campaign::factory()->desktopClaims()->create([
            'name' => 'Internal Label 4471',
            'network' => 'preview',
            'txn_msg' => 'Secret Reward Message',
        ]);
        Code::factory()->for($campaign)->create(['lovelace' => 7654321, 'uses' => 3]);

        $response = $this->get('/api/claim/v1/'.$campaign->id)->assertOk();

        foreach (['Internal Label 4471', 'preview', 'Secret Reward Message', '7654321'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
        $response->assertInertia(fn (Assert $page) => $page->component('Claim')->missing('campaign'));
    }

    public function test_the_page_is_served_on_the_claim_subdomain(): void
    {
        config()->set('cardano.claim_domain', 'claim.onbd.test');
        Route::middleware('api')->domain('claim.onbd.test')->group(base_path('routes/claim.php'));
        Route::getRoutes()->refreshNameLookups();
        $campaign = Campaign::factory()->desktopClaims()->create();

        $this->get('http://claim.onbd.test/v1/'.$campaign->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Claim')->has('transaction_backend'));
    }

    public function test_page_views_are_throttled_per_ip_and_do_not_spend_the_claim_budget(): void
    {
        config()->set('cardano.claim_rate_per_ip', 2);
        $campaign = Campaign::factory()->create();

        $this->get('/api/claim/v1/'.$campaign->id)->assertOk();
        $this->get('/api/claim/v1/'.$campaign->id)->assertOk();
        $this->get('/api/claim/v1/'.$campaign->id)->assertStatus(429);
        // An id that does not exist spends the same bucket.
        $this->get('/api/claim/v1/does-not-exist')->assertStatus(429);

        // The claim endpoint has its own bucket, still untouched.
        $this->postJson('/api/claim/v1/'.$campaign->id, [])->assertOk()->assertJson(['status' => 'missingcode']);
    }
}
