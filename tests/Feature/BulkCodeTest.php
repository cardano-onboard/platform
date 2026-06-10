<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_code_creation(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'quantity' => 1,
            'lovelace' => 2000000,
            'uses' => 5,
            'perWallet' => 1,
        ]);

        $this->assertDatabaseCount('codes', 1);
    }

    public function test_bulk_code_creation(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'quantity' => 10,
            'lovelace' => 2000000,
            'uses' => 5,
            'perWallet' => 1,
        ]);

        $this->assertDatabaseCount('codes', 10);
    }

    public function test_bulk_codes_have_unique_codes(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'quantity' => 20,
            'lovelace' => 2000000,
            'uses' => 5,
            'perWallet' => 1,
        ]);

        $codes = $campaign->codes()->pluck('code')->toArray();
        $this->assertCount(20, array_unique($codes));
    }

    public function test_bulk_codes_share_same_config(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'quantity' => 5,
            'lovelace' => 3000000,
            'uses' => 10,
            'perWallet' => 2,
        ]);

        $codes = $campaign->codes()->get();
        foreach ($codes as $code) {
            $this->assertEquals(3000000, $code->lovelace);
            $this->assertEquals(10, $code->uses);
            $this->assertEquals(2, $code->perWallet);
        }
    }

    public function test_bulk_codes_with_tokens(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'quantity' => 3,
            'lovelace' => 2000000,
            'uses' => 5,
            'perWallet' => 1,
            'tokens' => [
                [
                    'policy_id' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef12',
                    'token_id' => 'abcdef',
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertDatabaseCount('codes', 3);
        $this->assertDatabaseCount('rewards', 3);
    }

    public function test_quantity_max_is_500(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'quantity' => 501,
            'lovelace' => 2000000,
            'uses' => 5,
            'perWallet' => 1,
        ]);

        $response->assertSessionHasErrors('quantity');
    }

    public function test_default_quantity_is_one(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'lovelace' => 2000000,
            'uses' => 5,
            'perWallet' => 1,
        ]);

        $this->assertDatabaseCount('codes', 1);
    }
}
