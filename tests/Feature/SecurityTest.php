<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Security Headers ─────────────────────────────────────────────────

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    // ── XSS Prevention ──────────────────────────────────────────────────

    public function test_campaign_name_strips_html_tags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => '<script>alert("xss")</script>Test Campaign',
            'description' => 'Normal description',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'network' => 'preprod',
        ]);

        $this->assertDatabaseHas('campaigns', [
            'name' => 'alert("xss")Test Campaign',
        ]);
        $this->assertDatabaseMissing('campaigns', [
            'name' => '<script>alert("xss")</script>Test Campaign',
        ]);
    }

    public function test_campaign_description_strips_html_tags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'Safe Campaign',
            'description' => '<img src=x onerror=alert(1)>Safe description',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'network' => 'preprod',
        ]);

        $this->assertDatabaseHas('campaigns', [
            'description' => 'Safe description',
        ]);
    }

    public function test_campaign_txn_msg_strips_html_tags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'Txn Test',
            'description' => 'Test',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'network' => 'preprod',
            'txn_msg' => '<script>steal()</script>Hello',
        ]);

        $this->assertDatabaseHas('campaigns', [
            'txn_msg' => 'steal()Hello',
        ]);
    }

    // ── Authentication Bypass ────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_unauthenticated_user_cannot_create_campaign(): void
    {
        $response = $this->post(route('campaigns.store'), [
            'name' => 'Hacker Campaign',
            'network' => 'preprod',
        ]);
        $response->assertRedirect('/login');
    }

    #[Group('saas-only')]
    public function test_unauthenticated_user_cannot_access_profile(): void
    {
        $response = $this->get('/profile');
        $response->assertRedirect('/login');
    }

    // ── Authorization (Cross-User Access) ────────────────────────────────

    public function test_user_cannot_view_other_users_campaign(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create();

        $response = $this->actingAs($user2)->get(route('campaigns.show', $campaign));
        $response->assertForbidden();
    }

    public function test_user_cannot_update_other_users_campaign(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create();

        $response = $this->actingAs($user2)->put(route('campaigns.update', $campaign), [
            'name' => 'Hijacked Campaign',
        ]);
        $response->assertForbidden();
    }

    public function test_user_cannot_delete_other_users_campaign(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create();

        $response = $this->actingAs($user2)->delete(route('campaigns.destroy', $campaign));
        $response->assertForbidden();
    }

    public function test_user_cannot_create_code_on_other_users_campaign(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create();

        $response = $this->actingAs($user2)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'lovelace' => 2_000_000,
            'perWallet' => 1,
            'uses' => 1,
        ]);

        // Controller short-circuits non-owners with a redirect to dashboard.
        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('codes', 0);
    }

    public function test_user_cannot_refund_other_users_campaign(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create(['network' => 'preprod']);
        Wallet::factory()->for($campaign)->create();

        $response = $this->actingAs($user2)->post(route('campaigns.refund', $campaign), [
            'address' => 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3jcu5d8ps7zex2k2xt3uqxgjqnnj83ws8lhrn648jjxtwq2ytjc7',
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_check_claims_on_other_users_campaign(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create();

        $response = $this->actingAs($user2)->post(route('campaigns.check-claims', $campaign));

        $response->assertForbidden();
    }

    #[Group('saas-only')]
    public function test_user_cannot_revoke_other_users_api_token(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token = $user1->createToken('user1-token')->accessToken;

        $response = $this->actingAs($user2)->delete(route('profile.tokens.revoke', $token->id));

        // Controller scopes the delete to the authenticated user's tokens, so the
        // attempt silently no-ops. Verify the target token still exists.
        $response->assertRedirect(route('profile.edit'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->id,
            'tokenable_id' => $user1->id,
        ]);
    }

    // ── Mass Assignment ──────────────────────────────────────────────────

    public function test_campaign_store_ignores_user_id_override(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->actingAs($user1)->post(route('campaigns.store'), [
            'name' => 'Mass Assign Test',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'network' => 'preprod',
            'user_id' => $user2->id,
        ]);

        // Campaign should belong to authenticated user, not the injected user_id
        $this->assertDatabaseHas('campaigns', [
            'name' => 'Mass Assign Test',
            'user_id' => $user1->id,
        ]);
        $this->assertDatabaseMissing('campaigns', [
            'name' => 'Mass Assign Test',
            'user_id' => $user2->id,
        ]);
    }

    // ── Claim API Validation ─────────────────────────────────────────────

    public function test_claim_rejects_missing_code(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);

        $response = $this->postJson('/api/claim/v1/'.$campaign->id, [
            'address' => 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3jcu5d8ps7zex2k2xt3uqxgjqnnj83ws8lhrn648jjxtwq2ytjc7',
        ]);

        $response->assertJson(['status' => 'missingcode']);
    }

    public function test_claim_rejects_invalid_bech32_address(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        Code::factory()->for($campaign)->create(['code' => 'SECTEST1']);

        $response = $this->postJson('/api/claim/v1/'.$campaign->id, [
            'code' => 'SECTEST1',
            'address' => 'not_a_valid_cardano_address',
        ]);

        $response->assertJson(['status' => 'invalidaddress']);
    }

    public function test_claim_rejects_address_with_invalid_charset(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        Code::factory()->for($campaign)->create(['code' => 'SECTEST2']);

        // Bech32 does not allow 'b', 'i', 'o', '1' (except as separator)
        $response = $this->postJson('/api/claim/v1/'.$campaign->id, [
            'code' => 'SECTEST2',
            'address' => 'addr_test1qbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);

        $response->assertJson(['status' => 'invalidaddress']);
    }

    public function test_claim_returns_404_for_nonexistent_campaign(): void
    {
        $response = $this->postJson('/api/claim/v1/01NONEXISTENT000000000000', [
            'code' => 'TEST',
            'address' => 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3jcu5d8ps7zex2k2xt3uqxgjqnnj83ws8lhrn648jjxtwq2ytjc7',
        ]);

        $response->assertNotFound();
    }

    // ── SQL Injection Attempts ───────────────────────────────────────────

    public function test_campaign_name_resists_sql_injection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => "'; DROP TABLE campaigns; --",
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'network' => 'preprod',
        ]);

        // Should succeed (stored safely via parameterized queries)
        $this->assertDatabaseHas('campaigns', [
            'user_id' => $user->id,
        ]);

        // Table should still exist
        $this->assertDatabaseCount('campaigns', 1);
    }

    public function test_claim_code_resists_sql_injection(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        Code::factory()->for($campaign)->create(['code' => 'LEGIT']);

        $response = $this->postJson('/api/claim/v1/'.$campaign->id, [
            'code' => "' OR 1=1; --",
            'address' => 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3jcu5d8ps7zex2k2xt3uqxgjqnnj83ws8lhrn648jjxtwq2ytjc7',
        ]);

        // Should return an application-level error response, not a 500 or DB crash
        $response->assertOk();
        $response->assertJsonFragment(['code' => 400]);

        // The legitimate code should still be intact (table not dropped or corrupted)
        $this->assertDatabaseHas('codes', ['code' => 'LEGIT']);
    }

    // ── Proxy API Authentication ─────────────────────────────────────────

    public function test_proxy_api_rejects_unauthenticated_requests(): void
    {
        $response = $this->postJson('/api/v1/proxy/bucket', [
            'network' => 'preprod',
            'campaign_id' => 'test',
        ]);

        $response->assertUnauthorized();
    }

    public function test_proxy_api_rejects_invalid_token(): void
    {
        $response = $this->postJson('/api/v1/proxy/bucket', [
            'network' => 'preprod',
            'campaign_id' => 'test',
        ], [
            'Authorization' => 'Bearer invalid-token-12345',
        ]);

        $response->assertUnauthorized();
    }

    public function test_proxy_balance_rejects_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/proxy/balance?network=preprod&address=addr_test1abc');
        $response->assertUnauthorized();
    }

    // ── Input Validation ─────────────────────────────────────────────────

    public function test_campaign_rejects_oversized_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => str_repeat('A', 256),
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'network' => 'preprod',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_campaign_rejects_invalid_network(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'Bad Network Campaign',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'network' => 'bitcoin',
        ]);

        $response->assertSessionHasErrors('network');
    }

    public function test_campaign_rejects_end_date_before_start(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'Bad Dates Campaign',
            'start_date' => '2026-04-30',
            'end_date' => '2026-04-01',
            'network' => 'preprod',
        ]);

        $response->assertSessionHasErrors('end_date');
    }

    // ── Sensitive Data Protection ────────────────────────────────────────

    public function test_wallet_keys_are_hidden_from_json(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        $wallet = Wallet::factory()->for($campaign)->create();

        $walletArray = $wallet->toArray();

        $this->assertArrayNotHasKey('key', $walletArray);
        $this->assertArrayNotHasKey('skey', $walletArray);
        $this->assertArrayNotHasKey('vkey', $walletArray);
    }
}
