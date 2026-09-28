<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ApiAbilities;
use App\Support\ClaimClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The claim API stays public for every attendee's own wallet, but a claim carrying a
 * Sanctum token scoped to codes:claim is judged differently: it is an external caller
 * submitting on the attendee's behalf, every such claim shares that caller's own IP, and
 * the request may name which wallet the attendee actually used with a forwarded header.
 * This suite is the one that has to prove the difference, since ClaimApiTest and
 * ClaimClientTallyTest between them already cover the public path this leaves untouched.
 */
class TokenedClaimTest extends TestCase
{
    use RefreshDatabase;

    // A real, decodable mainnet Shelley address — the same fixture ClaimClientTallyTest
    // and ClaimMultiUseTest use. perWallet and uses are set generously below specifically
    // so this one address can claim the same code repeatedly across a test without
    // tripping ERROR_ALREADY_CLAIMED, which is not what any of these tests are about.
    private const ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const LACE = 'okhttp/4.12.0';

    private const VESPR = 'axios/1.19.0';

    private const CLIENT_HEADER = 'X-Claim-Client-User-Agent';

    private User $owner;

    private Campaign $campaign;

    private Code $code;

    /** A token minted with exactly codes:claim, for $this->campaign's own owner. */
    private string $claimToken;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->owner = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->owner)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
        ]);
        Wallet::factory()->for($this->campaign)->create();
        $this->code = Code::factory()->for($this->campaign)->create([
            'uses' => 10000,
            'perWallet' => 1000,
            'lovelace' => 2000000,
        ]);

        $this->claimToken = $this->owner->createToken('code-api', [ApiAbilities::CODES_CLAIM])->plainTextToken;
    }

    private function claim(?string $token = null, array $headers = [], ?string $ip = null)
    {
        // Laravel's test client keeps both its own header bag and one application
        // container across every call a test makes, but a real deployment starts each
        // request with neither. withHeaders()/withToken() merge into that persistent bag,
        // so a header or an Authorization token set on one claim() call would otherwise
        // still be attached to the next one that asks for neither. flushHeaders() clears
        // it before every simulated request.
        //
        // Sanctum's guard has the same problem one layer down: it caches whichever user it
        // resolves for the life of the container, and the cache only takes effect for a
        // resolved user, not a null one — so a public claim never triggers it, but a
        // tokened claim's resolved user would otherwise stick around and answer for a
        // request that follows it with no token or a different one. forgetGuards() is what
        // makes that resolution happen fresh for every claim() call too.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $request = $this->withHeaders($headers);

        if ($ip !== null) {
            $request = $request->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => self::ADDRESS,
        ]);
    }

    // --- the rate limit ---

    public function test_a_tokened_claim_is_limited_by_the_token_not_the_shared_ip(): void
    {
        config(['cardano.claim_rate_per_ip' => 1, 'cardano.claim_rate_per_token' => 10]);

        // The shared IP bucket is exhausted by one ordinary, unauthenticated claim.
        $this->claim()->assertStatus(200);
        $this->claim()->assertStatus(429);

        // The same IP, now carrying the codes:claim token, is judged by the token's own
        // bucket instead and is unaffected by the IP bucket it just exhausted.
        $this->claim($this->claimToken)->assertStatus(200);
        $this->claim($this->claimToken)->assertStatus(200);
    }

    public function test_a_tokened_claim_stops_at_its_own_token_limit(): void
    {
        config(['cardano.claim_rate_per_token' => 2, 'cardano.claim_rate_per_ip' => 100, 'cardano.claim_rate_per_campaign' => 100]);

        $this->claim($this->claimToken)->assertStatus(200);
        $this->claim($this->claimToken)->assertStatus(200);
        $this->claim($this->claimToken)->assertStatus(429);
    }

    public function test_a_public_claim_keeps_the_per_ip_limit(): void
    {
        config(['cardano.claim_rate_per_ip' => 2, 'cardano.claim_rate_per_campaign' => 1000]);

        $this->claim()->assertStatus(200);
        $this->claim()->assertStatus(200);
        $this->claim()->assertStatus(429);
    }

    public function test_a_public_claim_keeps_the_per_campaign_limit_across_different_ips(): void
    {
        config(['cardano.claim_rate_per_ip' => 1000, 'cardano.claim_rate_per_campaign' => 2]);

        $this->claim(null, [], '10.0.0.1')->assertStatus(200);
        $this->claim(null, [], '10.0.0.2')->assertStatus(200);
        $this->claim(null, [], '10.0.0.3')->assertStatus(429);
    }

    public function test_an_invalid_token_is_treated_as_public(): void
    {
        config(['cardano.claim_rate_per_ip' => 1, 'cardano.claim_rate_per_token' => 100]);

        $this->claim('this-is-not-a-real-token')->assertStatus(200);
        $this->claim('this-is-not-a-real-token')->assertStatus(429);
    }

    public function test_an_expired_token_is_treated_as_public(): void
    {
        $expired = $this->owner->createToken('expired', [ApiAbilities::CODES_CLAIM], now()->subMinute())->plainTextToken;

        config(['cardano.claim_rate_per_ip' => 1, 'cardano.claim_rate_per_token' => 100]);

        $this->claim($expired)->assertStatus(200);
        $this->claim($expired)->assertStatus(429);
    }

    public function test_a_revoked_token_is_treated_as_public(): void
    {
        $revocable = $this->owner->createToken('revoked', [ApiAbilities::CODES_CLAIM]);
        $plainText = $revocable->plainTextToken;
        $revocable->accessToken->delete();

        config(['cardano.claim_rate_per_ip' => 1, 'cardano.claim_rate_per_token' => 100]);

        $this->claim($plainText)->assertStatus(200);
        $this->claim($plainText)->assertStatus(429);
    }

    public function test_a_token_without_the_claim_ability_gains_nothing(): void
    {
        $createOnly = $this->owner->createToken('create-only', [ApiAbilities::CODES_CREATE])->plainTextToken;

        config(['cardano.claim_rate_per_ip' => 1, 'cardano.claim_rate_per_token' => 100]);

        $this->claim($createOnly)->assertStatus(200);
        $this->claim($createOnly)->assertStatus(429);
    }

    /**
     * The profile page mints exactly this kind of token — every campaign owner already
     * holds one, for the proxy API, valid 24 hours — and Sanctum's own can() answers true
     * for it against any ability at all. It must gain nothing here: only a token actually
     * issued codes:claim may raise the limit or be trusted with the client header.
     */
    public function test_a_wildcard_ability_token_gains_nothing(): void
    {
        $wildcard = $this->owner->createToken('profile-page-token', ['*'])->plainTextToken;

        config(['cardano.claim_rate_per_ip' => 1, 'cardano.claim_rate_per_token' => 100]);

        $this->claim($wildcard)->assertStatus(200);
        $this->claim($wildcard)->assertStatus(429);
    }

    /**
     * Rotating tokens — minting a second one rather than reusing the first — must never
     * multiply what one account can claim at. Both tokens draw from the same bucket.
     */
    public function test_two_claim_tokens_on_one_account_share_one_budget(): void
    {
        $secondToken = $this->owner->createToken('code-api-2', [ApiAbilities::CODES_CLAIM])->plainTextToken;

        config(['cardano.claim_rate_per_token' => 2, 'cardano.claim_rate_per_ip' => 100, 'cardano.claim_rate_per_campaign' => 100]);

        $this->claim($this->claimToken)->assertStatus(200);
        $this->claim($secondToken)->assertStatus(200);
        $this->claim($this->claimToken)->assertStatus(429);
    }

    /**
     * A regression test for the test suite's own isolation, not the application: without
     * forgetGuards() in claim(), a resolved tokened request's cached user could answer for
     * the very next simulated request even though that one carries no token at all.
     */
    public function test_a_tokened_claim_followed_by_a_tokenless_claim_does_not_leak_the_guard(): void
    {
        config(['cardano.claim_rate_per_ip' => 100, 'cardano.claim_rate_per_token' => 100]);

        $this->claim($this->claimToken, [
            'User-Agent' => self::LACE,
            self::CLIENT_HEADER => self::VESPR,
        ])->assertJson(['status' => 'accepted']);

        $this->claim(null, [
            'User-Agent' => self::LACE,
            self::CLIENT_HEADER => self::VESPR,
        ])->assertJson(['status' => 'accepted']);

        // The first claim honoured the header (tokened); the second must not have, because
        // it carried no token of its own.
        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'axios', 'claims' => 1]);
        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'okhttp', 'claims' => 1]);
    }

    /**
     * The same rule codes:create and codes:status are scoped by: a token is only good for
     * a campaign its own account can already see. Minted for a real account with the real
     * ability, but for an account that does not own this campaign.
     */
    public function test_a_token_scoped_to_another_account_gains_nothing_for_this_campaign(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignToken = $foreignOwner->createToken('foreign', [ApiAbilities::CODES_CLAIM])->plainTextToken;

        config(['cardano.claim_rate_per_ip' => 1, 'cardano.claim_rate_per_token' => 100]);

        $this->claim($foreignToken)->assertStatus(200);
        $this->claim($foreignToken)->assertStatus(429);
    }

    // --- the forwarded client header ---

    public function test_the_forwarded_header_is_ignored_without_the_claim_ability(): void
    {
        $this->claim(null, [
            'User-Agent' => self::LACE,
            self::CLIENT_HEADER => self::VESPR,
        ])->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'okhttp', 'claims' => 1]);
        $this->assertDatabaseMissing('campaign_claim_clients', ['client' => 'axios']);
    }

    public function test_the_forwarded_header_is_counted_with_the_claim_ability(): void
    {
        $this->claim($this->claimToken, [
            'User-Agent' => self::LACE,
            self::CLIENT_HEADER => self::VESPR,
        ])->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'axios', 'claims' => 1]);
        $this->assertDatabaseMissing('campaign_claim_clients', ['client' => 'okhttp']);
    }

    /** A token without the ability gains neither the rate limit nor the header. */
    public function test_the_forwarded_header_is_ignored_for_a_token_without_the_claim_ability(): void
    {
        $createOnly = $this->owner->createToken('create-only', [ApiAbilities::CODES_CREATE])->plainTextToken;

        $this->claim($createOnly, [
            'User-Agent' => self::LACE,
            self::CLIENT_HEADER => self::VESPR,
        ])->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'okhttp', 'claims' => 1]);
        $this->assertDatabaseMissing('campaign_claim_clients', ['client' => 'axios']);
    }

    /** A wildcard token is not a codes:claim token, so it is trusted with nothing here either. */
    public function test_the_forwarded_header_is_ignored_for_a_wildcard_ability_token(): void
    {
        $wildcard = $this->owner->createToken('profile-page-token', ['*'])->plainTextToken;

        $this->claim($wildcard, [
            'User-Agent' => self::LACE,
            self::CLIENT_HEADER => self::VESPR,
        ])->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'okhttp', 'claims' => 1]);
        $this->assertDatabaseMissing('campaign_claim_clients', ['client' => 'axios']);
    }

    public function test_a_tokened_claim_with_no_forwarded_header_still_records_its_own_user_agent(): void
    {
        $this->claim($this->claimToken, [
            'User-Agent' => self::LACE,
        ])->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'okhttp', 'claims' => 1]);
    }

    /**
     * The header is an arbitrary, unauthenticated, attacker-controlled string on a public
     * claim, and without codes:claim it is never looked at again after this point. Logging
     * it anyway would keep that string in every claim's log line for nothing.
     */
    public function test_the_forwarded_header_is_not_logged_without_a_resolved_claim_token(): void
    {
        Log::spy();

        $this->claim(null, [self::CLIENT_HEADER => self::VESPR])->assertJson(['status' => 'accepted']);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context) => $message === 'Claim Request'
                && array_key_exists('claim_client_header', $context)
                && $context['claim_client_header'] === null
        );
    }

    /**
     * Logged only once a codes:claim token has resolved, and cut to the same length
     * ClaimClient itself ever keeps, so the log line can never carry more of the header
     * than the tally it feeds ever will.
     */
    public function test_the_forwarded_header_is_logged_cut_to_the_max_length_when_a_claim_token_resolves(): void
    {
        Log::spy();

        $oversized = str_repeat('a', ClaimClient::MAX_LENGTH + 100);

        $this->claim($this->claimToken, [self::CLIENT_HEADER => $oversized])->assertJson(['status' => 'accepted']);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context) => $message === 'Claim Request'
                && ($context['claim_client_header'] ?? null) === str_repeat('a', ClaimClient::MAX_LENGTH)
        );
    }
}
