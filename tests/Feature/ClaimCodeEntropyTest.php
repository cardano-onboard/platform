<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimCodeEntropyTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_codes_are_independent_random_and_unique(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'quantity' => 40,
            'uses' => 1,
            'perWallet' => 1,
            'lovelace' => 1000000,
        ])->assertRedirect();

        $codes = $campaign->codes()->pluck('code')->all();
        $this->assertCount(40, $codes);

        // All unique.
        $this->assertCount(40, array_unique($codes));

        // Crockford base32 (uppercase, no ambiguous I/L/O/U), fixed length.
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{20}$/', $code);
        }

        // Independence: monotonic ULIDs would share long common prefixes across a batch.
        // Independent randomness must not — every 8-char prefix should be distinct.
        $prefixes = array_map(static fn ($c) => substr($c, 0, 8), $codes);
        $this->assertCount(40, array_unique($prefixes), 'codes share prefixes — not independently random');
    }

    public function test_same_code_is_allowed_across_different_campaigns(): void
    {
        // The SaaS case: two tenants (via generation or JSON upload) landing on the same
        // code must NOT collide — uniqueness is scoped per campaign, not globally.
        $shared = 'SHARED12345678901234';
        $a = Campaign::factory()->for(User::factory())->create();
        $b = Campaign::factory()->for(User::factory())->create();

        Code::factory()->for($a)->create(['code' => $shared]);
        Code::factory()->for($b)->create(['code' => $shared]);

        $this->assertDatabaseCount('codes', 2);
    }

    public function test_duplicate_code_within_a_campaign_is_rejected(): void
    {
        $campaign = Campaign::factory()->create();
        Code::factory()->for($campaign)->create(['code' => 'DUPLICATE12345678901']);

        $this->expectException(QueryException::class);
        Code::factory()->for($campaign)->create(['code' => 'DUPLICATE12345678901']);
    }
}
