<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ClaimFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_campaign_shows_codes_table(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'name' => 'Code Test Campaign',
            'network' => 'preprod',
        ]);
        Code::factory()->for($campaign)->create([
            'code' => 'DUSKCODE1',
            'lovelace' => 2000000,
        ]);

        $this->browse(function (Browser $browser) use ($user, $campaign) {
            $browser->loginAs($user)
                ->visit('/campaigns/'.$campaign->id)
                ->assertSee('Campaign Codes')
                ->assertSee('DUSKCODE1');
        });
    }

    public function test_campaign_shows_claim_url(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'name' => 'URL Test Campaign',
            'network' => 'preprod',
        ]);

        $this->browse(function (Browser $browser) use ($user, $campaign) {
            $browser->loginAs($user)
                ->visit('/campaigns/'.$campaign->id)
                ->assertSee('Claim URL');
        });
    }

    public function test_claim_api_rejects_missing_code_via_http(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'network' => 'preprod',
        ]);

        // Test via HTTP since claim endpoint is POST-only
        $response = $this->postJson('/api/claim/v1/'.$campaign->id, [
            'address' => 'addr_test1qz'.str_repeat('a', 50),
        ]);

        $response->assertJson(['status' => 'missingcode']);
    }

    public function test_claim_api_rejects_invalid_address_via_http(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'network' => 'preprod',
        ]);
        Code::factory()->for($campaign)->create(['code' => 'VALIDCODE']);

        $response = $this->postJson('/api/claim/v1/'.$campaign->id, [
            'code' => 'VALIDCODE',
            'address' => 'not_a_valid_address',
        ]);

        $response->assertJson(['status' => 'invalidaddress']);
    }
}
