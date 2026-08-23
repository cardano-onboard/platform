<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaimMultiUseTest extends TestCase
{
    use RefreshDatabase;

    // Two valid mainnet Shelley addresses with distinct stake keys, sourced from
    // cardano-php/bech32 test fixtures so they decode cleanly via Bech32::decodeCardanoAddress().
    private const VALID_MAINNET_ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const VALID_MAINNET_ADDRESS_2 = 'addr1xxgx3far7qygq0k6epa0zcvcvrevmn0ypsnfsue94nsn3tfvjel5h55fgjcxgchp830r7h2l5msrlpt8262r3nvr8eks2utwdd';

    private Campaign $campaign;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->campaign = $this->makeCampaign();
        $this->code = Code::factory()->for($this->campaign)->create([
            'uses' => 10000,
            'perWallet' => 5,
            'lovelace' => 2000000,
        ]);
    }

    private function makeCampaign(array $attrs = []): Campaign
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(array_merge([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
        ], $attrs));
        Wallet::factory()->for($campaign)->create();

        return $campaign;
    }

    public function test_second_claim_within_per_wallet_limit_succeeds(): void
    {
        $first = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ]);
        $first->assertJson(['status' => 'accepted']);

        $second = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ]);
        $second->assertJson(['status' => 'accepted']);

        $this->assertSame(2, $this->code->claims()->count());
    }

    public function test_claim_blocked_once_per_wallet_limit_reached(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('claim.v1', $this->campaign), [
                'code' => $this->code->code,
                'address' => self::VALID_MAINNET_ADDRESS,
            ])->assertJson(['status' => 'accepted']);
        }

        $blocked = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ]);

        $blocked->assertJsonStructure(['code', 'status']);
        $this->assertNotSame('accepted', $blocked->json('status'));
        $this->assertSame(5, $this->code->claims()->count());
    }

    public function test_one_per_wallet_blocks_same_wallet_across_different_codes(): void
    {
        $campaign = $this->makeCampaign(['one_per_wallet' => true]);
        $code1 = Code::factory()->for($campaign)->create([
            'uses' => 10, 'perWallet' => 5, 'lovelace' => 2000000,
        ]);
        $code2 = Code::factory()->for($campaign)->create([
            'uses' => 10, 'perWallet' => 5, 'lovelace' => 2000000,
        ]);

        $this->postJson(route('claim.v1', $campaign), [
            'code' => $code1->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ])->assertJson(['status' => 'accepted']);

        $blocked = $this->postJson(route('claim.v1', $campaign), [
            'code' => $code2->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ]);
        $blocked->assertJson(['status' => 'alreadyclaimed']);

        $this->assertSame(1, $code1->claims()->count());
        $this->assertSame(0, $code2->claims()->count());
    }

    public function test_one_per_wallet_allows_different_wallets_on_same_campaign(): void
    {
        $campaign = $this->makeCampaign(['one_per_wallet' => true]);
        $code = Code::factory()->for($campaign)->create([
            'uses' => 10, 'perWallet' => 1, 'lovelace' => 2000000,
        ]);

        $this->postJson(route('claim.v1', $campaign), [
            'code' => $code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ])->assertJson(['status' => 'accepted']);

        $this->postJson(route('claim.v1', $campaign), [
            'code' => $code->code,
            'address' => self::VALID_MAINNET_ADDRESS_2,
        ])->assertJson(['status' => 'accepted']);

        $this->assertSame(2, $code->claims()->count());
    }

    public function test_exhausted_code_returns_existing_claim_for_original_claimer(): void
    {
        $code = Code::factory()->for($this->campaign)->create([
            'uses' => 1, 'perWallet' => 1, 'lovelace' => 2000000,
        ]);

        $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ])->assertJson(['status' => 'accepted']);

        $repeat = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ]);

        $repeat->assertJsonStructure(['code', 'status']);
        $this->assertContains($repeat->json('status'), ['queued', 'claimed']);
        $this->assertSame(1, $code->claims()->count());
    }

    public function test_exhausted_code_rejects_new_wallet(): void
    {
        $code = Code::factory()->for($this->campaign)->create([
            'uses' => 1, 'perWallet' => 1, 'lovelace' => 2000000,
        ]);

        $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ])->assertJson(['status' => 'accepted']);

        $rejected = $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $code->code,
            'address' => self::VALID_MAINNET_ADDRESS_2,
        ]);
        $rejected->assertJson(['status' => 'alreadyclaimed']);

        $this->assertSame(1, $code->claims()->count());
    }

    public function test_mainnet_address_rejected_on_preprod_campaign(): void
    {
        $campaign = $this->makeCampaign(['network' => 'preprod']);
        $code = Code::factory()->for($campaign)->create([
            'uses' => 10, 'perWallet' => 5, 'lovelace' => 2000000,
        ]);

        $response = $this->postJson(route('claim.v1', $campaign), [
            'code' => $code->code,
            'address' => self::VALID_MAINNET_ADDRESS,
        ]);

        $response->assertJson(['status' => 'invalidnetwork']);
        $this->assertSame(0, $code->claims()->count());
    }
}
