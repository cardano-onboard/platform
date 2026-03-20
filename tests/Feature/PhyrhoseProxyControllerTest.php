<?php

namespace Tests\Feature;

use App\Contracts\TransactionBackend;
use App\Models\ProxyUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhyrhoseProxyControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;

        // Bind a mock backend so we don't make real HTTP calls
        $this->app->bind(TransactionBackend::class, function () {
            return new \App\Services\NullBackend();
        });
    }

    // --- Authentication ---

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/v1/proxy/bucket', [
            'name'    => 'test',
            'network' => 'preprod',
        ])->assertStatus(401);
    }

    // --- createBucket (quota-limited) ---

    public function test_create_bucket_returns_address_and_campaign_id(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/proxy/bucket', [
            'name'    => 'Test Campaign',
            'network' => 'preprod',
        ]);

        $response->assertOk()
                 ->assertJsonStructure(['address', 'campaignId']);
    }

    public function test_create_bucket_validates_required_fields(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/proxy/bucket', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['name', 'network']);
    }

    public function test_create_bucket_validates_network(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/proxy/bucket', [
            'name'    => 'test',
            'network' => 'invalid',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['network']);
    }

    // --- submitPayment (unmetered) ---

    public function test_submit_payment_returns_purchase_ids(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/proxy/payment', [
            'campaignId' => 'camp-123',
            'recipients' => [['pooCode' => 'CODE1', 'address' => 'addr1', 'lovelace' => 2000000, 'tokens' => []]],
            'network'    => 'preprod',
        ]);

        $response->assertOk()
                 ->assertJsonStructure(['purchaseIds']);
    }

    public function test_submit_payment_validates_required_fields(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/proxy/payment', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['campaignId', 'recipients', 'network']);
    }

    // --- checkStatus (unmetered) ---

    public function test_check_status_returns_status_and_hash(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/proxy/status/purchase-123?network=preprod');

        $response->assertOk()
                 ->assertJsonStructure(['status', 'txHash']);
    }

    public function test_check_status_validates_network(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/proxy/status/purchase-123')
             ->assertStatus(422);
    }

    // --- refund (quota-limited) ---

    public function test_refund_returns_success(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/proxy/refund', [
            'campaignId' => 'camp-123',
            'address'    => 'addr_test1abc',
            'network'    => 'preprod',
        ]);

        $response->assertOk()
                 ->assertJson(['success' => true]);
    }

    public function test_refund_validates_required_fields(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/proxy/refund', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['campaignId', 'address', 'network']);
    }

    // --- getBalance (unmetered) ---

    public function test_get_balance_returns_array(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/proxy/balance?address=addr_test1abc&network=preprod');

        $response->assertOk();
    }

    public function test_get_balance_validates_required_fields(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/proxy/balance')
             ->assertStatus(422);
    }

    // --- Quota middleware ---

    public function test_quota_limited_endpoint_logs_usage(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/proxy/bucket', [
            'name'    => 'test',
            'network' => 'preprod',
        ])->assertOk();

        $this->assertDatabaseHas('proxy_usage', [
            'user_id'  => $this->user->id,
            'endpoint' => 'api/v1/proxy/bucket',
        ]);
    }

    public function test_quota_exceeded_returns_429(): void
    {
        config(['cardano.proxy.monthly_limit' => 2]);

        // Fill up quota
        ProxyUsage::create(['user_id' => $this->user->id, 'endpoint' => 'api/v1/proxy/bucket', 'created_at' => now()]);
        ProxyUsage::create(['user_id' => $this->user->id, 'endpoint' => 'api/v1/proxy/bucket', 'created_at' => now()]);

        $this->withToken($this->token)->postJson('/api/v1/proxy/bucket', [
            'name'    => 'test',
            'network' => 'preprod',
        ])->assertStatus(429)
          ->assertJson(['error' => 'Monthly proxy quota exceeded.']);
    }

    public function test_quota_resets_monthly(): void
    {
        config(['cardano.proxy.monthly_limit' => 1]);

        // Usage from last month should not count
        $usage = ProxyUsage::create([
            'user_id'  => $this->user->id,
            'endpoint' => 'api/v1/proxy/bucket',
        ]);
        $usage->created_at = now()->subMonth();
        $usage->save();

        $this->withToken($this->token)->postJson('/api/v1/proxy/bucket', [
            'name'    => 'test',
            'network' => 'preprod',
        ])->assertOk();
    }

    // --- Log-only middleware (unmetered endpoints) ---

    public function test_unmetered_endpoint_logs_usage(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/proxy/balance?address=addr_test1abc&network=preprod')
             ->assertOk();

        $this->assertDatabaseHas('proxy_usage', [
            'user_id'  => $this->user->id,
            'endpoint' => 'api/v1/proxy/balance',
        ]);
    }

    public function test_unmetered_endpoint_not_blocked_by_quota(): void
    {
        config(['cardano.proxy.monthly_limit' => 0]);

        // Even with 0 quota, unmetered endpoints should work
        $this->withToken($this->token)->getJson('/api/v1/proxy/balance?address=addr_test1abc&network=preprod')
             ->assertOk();
    }
}
