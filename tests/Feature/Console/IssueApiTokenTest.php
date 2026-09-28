<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * api:token — the only way a self-hosted operator, or an integration with no session at
 * all, can mint a Sanctum token, since token issuance from the profile page is a SaaS-only
 * route the publish transform strips.
 */
class IssueApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_mints_a_token_with_exactly_the_abilities_given(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CREATE, ApiAbilities::CODES_STATUS],
            '--expires' => '+30 days',
        ])->assertSuccessful();

        $token = $user->tokens()->sole();

        $this->assertSame([ApiAbilities::CODES_CREATE, ApiAbilities::CODES_STATUS], $token->abilities);
        $this->assertNotNull($token->expires_at);
    }

    public function test_mints_a_token_with_the_claim_ability(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CLAIM],
            '--expires' => '+30 days',
        ])->assertSuccessful();

        $this->assertSame([ApiAbilities::CODES_CLAIM], $user->tokens()->sole()->abilities);
    }

    public function test_defaults_the_name_to_the_abilities_given(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CREATE],
            '--expires' => '+30 days',
        ])->assertSuccessful();

        $this->assertSame(ApiAbilities::CODES_CREATE, $user->tokens()->sole()->name);
    }

    public function test_a_given_name_is_used_instead(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CREATE],
            '--expires' => '+30 days',
            '--name' => 'the integration app',
        ])->assertSuccessful();

        $this->assertSame('the integration app', $user->tokens()->sole()->name);
    }

    public function test_expires_is_required_and_mints_nothing(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CREATE],
        ])->assertFailed();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_expires_stamps_the_token_with_the_date_given(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CREATE],
            '--expires' => '2026-12-31',
        ])->assertSuccessful();

        $this->assertSame('2026-12-31', $user->tokens()->sole()->expires_at->toDateString());
    }

    public function test_an_unparseable_expiry_is_refused_and_mints_nothing(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CREATE],
            '--expires' => 'not a date',
        ])->assertFailed();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_expiry_already_in_the_past_is_refused_and_mints_nothing(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => [ApiAbilities::CODES_CREATE],
            '--expires' => '2020-01-01',
        ])->assertFailed();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_unknown_ability_is_refused_and_mints_nothing(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
            '--abilities' => ['codes:launch-rocket'],
        ])->assertFailed();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_no_abilities_at_all_is_refused_and_mints_nothing(): void
    {
        $user = User::factory()->create();

        $this->artisan('api:token', [
            'user' => $user->email,
        ])->assertFailed();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_unknown_email_is_refused(): void
    {
        $this->artisan('api:token', [
            'user' => 'nobody@example.com',
            '--abilities' => [ApiAbilities::CODES_CREATE],
        ])->assertFailed();
    }
}
