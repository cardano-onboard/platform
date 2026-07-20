<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedCodes;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CodeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createCampaignWithWallet(User $user): Campaign
    {
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create();

        return $campaign;
    }

    public function test_authenticated_user_can_create_code(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'lovelace' => 2000000,
                'perWallet' => 1,
                'uses' => 10,
                'tokens' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('codes', 1);
    }

    public function test_code_creation_validates_lovelace_minimum(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'lovelace' => 500,
                'perWallet' => 1,
                'uses' => 1,
            ])
            ->assertSessionHasErrors(['lovelace']);
    }

    public function test_code_creation_validates_per_wallet(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'lovelace' => 2000000,
                'perWallet' => -1,
                'uses' => 1,
            ])
            ->assertSessionHasErrors(['perWallet']);
    }

    public function test_code_creation_validates_uses(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'lovelace' => 2000000,
                'perWallet' => 1,
                'uses' => 0,
            ])
            ->assertSessionHasErrors(['uses']);
    }

    public function test_code_creation_validates_token_structure(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'lovelace' => 2000000,
                'perWallet' => 1,
                'uses' => 1,
                'tokens' => [
                    ['policy_id' => 'not-hex', 'token_id' => 'abc123', 'quantity' => 1],
                ],
            ])
            ->assertSessionHasErrors(['tokens.0.policy_id']);
    }

    public function test_user_cannot_create_code_for_another_users_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($owner);

        $this->actingAs($other)
            ->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'lovelace' => 2000000,
                'perWallet' => 1,
                'uses' => 1,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_code_creation_with_tokens(): void
    {
        $user = User::factory()->create();
        $campaign = $this->createCampaignWithWallet($user);

        $this->actingAs($user)
            ->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'lovelace' => 2000000,
                'perWallet' => 1,
                'uses' => 5,
                'tokens' => [
                    ['policy_id' => 'a'.str_repeat('0', 55), 'token_id' => 'ff', 'quantity' => 10],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('codes', 1);
        $this->assertDatabaseCount('rewards', 1);
    }

    private function endedCampaign(User $user): Campaign
    {
        return Campaign::factory()->for($user)->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);
    }

    public function test_cannot_create_code_on_ended_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = $this->endedCampaign($user);

        $this->travelTo('2026-02-15');  // after the redemption window closed
        $response = $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'lovelace' => 2000000,
            'perWallet' => 1,
            'uses' => 5,
            'tokens' => [],
        ]);
        $this->travelBack();

        $response->assertRedirect()
            ->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'has ended'));
        $this->assertDatabaseCount('codes', 0);
    }

    public function test_cannot_import_codes_on_ended_campaign(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $campaign = $this->endedCampaign($user);

        $this->travelTo('2026-02-15');
        $response = $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'uploadedCodes' => true,
            'file_key' => 'uploads/some-file.csv',
        ]);
        $this->travelBack();

        $response->assertRedirect()
            ->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'has ended'));
        Bus::assertNotDispatched(ProcessUploadedCodes::class);
    }
}
