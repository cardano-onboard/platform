<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createCampaignWithWallet(User $user, array $overrides = []): Campaign
    {
        $campaign = Campaign::factory()->for($user)->create($overrides);
        Wallet::factory()->for($campaign)->create();

        return $campaign;
    }

    public function test_guest_cannot_access_campaigns(): void
    {
        $this->get(route('campaigns.create'))->assertRedirect('/login');
    }

    public function test_authenticated_user_can_create_campaign(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['bucketAddress' => 'addr_test1abc', 'campaignId' => 'test-id']],
        ])]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('campaigns.store'), [
                'name'           => 'Test Campaign',
                'description'    => 'A test campaign',
                'start_date'     => now()->toDateString(),
                'end_date'       => now()->addMonth()->toDateString(),
                'one_per_wallet' => false,
                'network'        => 'preprod',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('campaigns', [
            'name'    => 'Test Campaign',
            'user_id' => $user->id,
        ]);
    }

    public function test_campaign_creation_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('campaigns.store'), [])
            ->assertSessionHasErrors(['name', 'start_date', 'end_date', 'network']);
    }

    public function test_campaign_creation_validates_network(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('campaigns.store'), [
                'name'       => 'Test',
                'start_date' => now()->toDateString(),
                'end_date'   => now()->addMonth()->toDateString(),
                'network'    => 'invalid_network',
            ])
            ->assertSessionHasErrors(['network']);
    }

    public function test_user_can_view_own_campaign(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['liveUtxos' => []]],
        ])]);

        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk();
    }

    public function test_user_cannot_view_other_users_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($owner);

        $this->actingAs($other)
            ->get(route('campaigns.show', $campaign))
            ->assertForbidden();
    }

    public function test_user_can_update_own_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $response = $this->actingAs($user)
            ->put(route('campaigns.update', $campaign), [
                'name'        => 'Updated Name',
                'description' => 'Updated desc',
                'start_date'  => now()->toDateString(),
                'end_date'    => now()->addMonth()->toDateString(),
                'network'     => 'preprod',
            ]);
        $response->assertRedirect();

        $this->assertDatabaseHas('campaigns', [
            'id'   => $campaign->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_campaign_fields_locked_when_claims_exist(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user, ['network' => 'preprod']);

        $code = $campaign->codes()->create([
            'code' => 'TEST123',
            'perWallet' => 1,
            'uses' => 1,
            'lovelace' => 2000000,
        ]);
        $code->claims()->create([
            'address' => 'addr_test1abc',
            'stake_key' => 'stake_test1abc',
        ]);

        $this->actingAs($user)
            ->put(route('campaigns.update', $campaign), [
                'name'           => 'Updated Name',
                'start_date'     => now()->toDateString(),
                'end_date'       => now()->addMonth()->toDateString(),
                'network'        => 'mainnet',
                'one_per_wallet' => true,
            ])
            ->assertRedirect();

        $campaign->refresh();
        $this->assertEquals('preprod', $campaign->network);
    }

    public function test_campaign_show_does_not_expose_user_id(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['liveUtxos' => []]],
        ])]);

        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $response = $this->actingAs($user)
            ->get(route('campaigns.show', $campaign));

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $this->assertArrayNotHasKey('user_id', $props['campaign']);
    }

    public function test_campaign_show_does_not_expose_wallet_key(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['liveUtxos' => []]],
        ])]);

        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $response = $this->actingAs($user)
            ->get(route('campaigns.show', $campaign));

        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $this->assertArrayNotHasKey('key', $props['campaign']['wallet']);
    }

    public function test_store_strips_html_tags_from_name(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'data' => [null, ['bucketAddress' => 'addr_test1abc', 'campaignId' => 'test-id']],
        ])]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('campaigns.store'), [
                'name'       => '<script>alert("xss")</script>Clean Name',
                'start_date' => now()->toDateString(),
                'end_date'   => now()->addMonth()->toDateString(),
                'network'    => 'preprod',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('campaigns', [
            'name' => 'alert("xss")Clean Name',
        ]);
    }

    public function test_user_can_delete_own_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->delete(route('campaigns.destroy', $campaign))
            ->assertRedirect(route('dashboard'));

        $this->assertSoftDeleted('campaigns', ['id' => $campaign->id]);
    }

    public function test_user_cannot_delete_other_users_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($owner);

        $this->actingAs($other)
            ->delete(route('campaigns.destroy', $campaign))
            ->assertForbidden();
    }
}
