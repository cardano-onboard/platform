<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data migration that closes the hole a null global expiration opened. Every token
 * minted before per-token expiry existed has expires_at = null, and used to be bounded by
 * Sanctum's own global expiration window; the moment that window was unset in favour of
 * stamping expiry on each token, a pre-existing row stopped being bounded by anything.
 *
 * Its own class and DatabaseMigrations, not a shared transaction, for the same reason
 * UnreadOnboardingWalletsRollbackTest is: rolling one migration back and running it again
 * is schema work this suite needs to actually happen, not something to wrap in a
 * transaction that would just roll it back itself.
 */
class BackfillTokenExpiryTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_09_22_090200_backfill_token_expiry.php';

    public function test_a_token_from_before_this_migration_expires_after_it_runs(): void
    {
        $user = User::factory()->create();

        // Puts this one migration back in a state where it can run again. Its own up()
        // never touched anything (the fresh table this test started with had no rows in
        // it yet), so nothing about the data changes here.
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        // No third argument, exactly how every token was minted before per-token expiry
        // existed: expires_at = null.
        $token = $user->createToken('an old profile token', ['*']);

        // Held rather than recomputed at the assertion, which runs after the migration and
        // can fall in a later second than this line.
        $createdAt = now()->subDays(30);

        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['created_at' => $createdAt, 'expires_at' => null]);

        // Confirms the state this migration exists to fix: a 30-day-old token with no
        // expiry, minted under the old global-expiration regime, is valid forever once
        // that regime is turned off.
        $this->withToken($token->plainTextToken)->getJson('/api/user')->assertOk();

        // Laravel's RequestGuard caches the user it resolved for as long as the guard
        // instance lives, which is the whole test method here, not one request the way it
        // would be in a real process. Without this, the check below would read that cache
        // rather than re-evaluating the token this migration just changed.
        Auth::forgetGuards();

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $expiresAt = DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->value('expires_at');

        $this->assertNotNull($expiresAt);
        $this->assertSame(
            $createdAt->copy()->addHours(24)->toDateTimeString(),
            Carbon::parse($expiresAt)->toDateTimeString(),
        );

        $this->withToken($token->plainTextToken)->getJson('/api/user')->assertStatus(401);
    }

    public function test_a_token_that_already_has_an_expiry_is_left_alone(): void
    {
        $user = User::factory()->create();
        $keepsExpiry = now()->addDays(10);

        $token = $user->createToken('a normal token', ['*'], $keepsExpiry);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $expiresAt = DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->value('expires_at');

        $this->assertSame(
            $keepsExpiry->toDateTimeString(),
            Carbon::parse($expiresAt)->toDateTimeString(),
        );
    }
}
