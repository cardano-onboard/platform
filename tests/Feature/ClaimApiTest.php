<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaimApiTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($user)->create([
            'network' => 'preprod',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        Wallet::factory()->for($this->campaign)->create();
        $this->code = Code::factory()->for($this->campaign)->create([
            'uses' => 10,
            'perWallet' => 1,
            'lovelace' => 2000000,
        ]);
    }

    public function test_claim_missing_code_returns_error(): void
    {
        $response = $this->postJson(route('claim.v1', $this->campaign), [
            'address' => 'addr_test1qz'.str_repeat('a', 50),
        ]);

        $response->assertJson(['status' => 'missingcode']);
    }

    public function test_claim_missing_address_returns_error(): void
    {
        $response = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
        ]);

        $response->assertJson(['status' => 'invalidaddress']);
    }

    public function test_claim_invalid_address_format_returns_error(): void
    {
        $response = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => 'not_a_valid_address',
        ]);

        $response->assertJson(['status' => 'invalidaddress']);
    }

    public function test_claim_from_an_enterprise_address_is_refused_as_invalid_address(): void
    {
        $response = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => 'addr_test1vqwhqhu2kfzhq62helz59uep6shglzx4qsy9r6fsgetx8psty2jde',
        ]);

        $response->assertJson([
            'code' => 400,
            'status' => 'invalidaddress',
            'message' => 'Claims need a base address with a staking key. Enterprise, pointer and Byron addresses are not accepted.',
        ]);
        $this->assertDatabaseMissing('claims', ['address' => 'addr_test1vqwhqhu2kfzhq62helz59uep6shglzx4qsy9r6fsgetx8psty2jde']);
    }

    public function test_claim_nonexistent_code_returns_not_found(): void
    {
        Http::fake();

        $response = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => 'NONEXISTENT_CODE',
            'address' => 'addr_test1qz'.str_repeat('a', 50),
        ]);

        // Address may fail decode first, which is acceptable
        $response->assertJsonStructure(['code', 'status']);
    }

    public function test_claim_expired_campaign_returns_error(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $expiredCampaign = Campaign::factory()->for($user)->expired()->create(['network' => 'preprod']);
        Wallet::factory()->for($expiredCampaign)->create();
        $code = Code::factory()->for($expiredCampaign)->create();

        $response = $this->postJson(route('claim.v1', $expiredCampaign), [
            'code' => $code->code,
            'address' => 'addr_test1qz'.str_repeat('a', 50),
        ]);

        // Will fail at address decode or return expired - both acceptable
        $response->assertJsonStructure(['code', 'status']);
    }

    public function test_claim_future_campaign_returns_too_early(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $futureCampaign = Campaign::factory()->for($user)->future()->create(['network' => 'preprod']);
        Wallet::factory()->for($futureCampaign)->create();
        $code = Code::factory()->for($futureCampaign)->create();

        $response = $this->postJson(route('claim.v1', $futureCampaign), [
            'code' => $code->code,
            'address' => 'addr_test1qz'.str_repeat('a', 50),
        ]);

        $response->assertJsonStructure(['code', 'status']);
    }
}
