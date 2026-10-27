<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * The two endpoints an external event application reaches Onboard.Ninja through: make one
 * code for an identity it already knows about, and ask what happened to it. Nothing here
 * exercises the campaign page's own code creation — that is MinUtxoCodeTest and
 * CodeControllerTest's territory, and this suite asserts the API stays behind its own
 * token, its own abilities and its own campaign boundary instead of retesting the form.
 */
class CodeApiTest extends TestCase
{
    use RefreshDatabase;

    /** A real policy id and a real asset name, because name length is part of the figure. */
    private const POLICY = 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235';

    private const ASSET = '484f534b59'; // HOSKY

    /** What one HOSKY in an output actually costs at mainnet's 4310 coins per byte. */
    private const ONE_ASSET_MINIMUM = 1_150_770;

    private User $user;

    private Campaign $campaign;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        Wallet::factory()->for($this->campaign)->create();

        $this->token = $this->user->createToken('code-api', ['codes:create', 'codes:status'])->plainTextToken;
    }

    private function create(array $overrides = [], ?string $token = null, ?Campaign $campaign = null)
    {
        return $this->withToken($token ?? $this->token)
            ->postJson(route('api.codes.store', $campaign ?? $this->campaign), array_merge([
                'reference' => 'attendee-1',
                'lovelace' => 2_000_000,
            ], $overrides));
    }

    /**
     * By the code's own string, which is the only thing a caller of store() ever receives
     * and therefore the only thing a caller of status() ever has to ask with.
     */
    private function statusForCode(string $code, ?string $token = null, ?Campaign $campaign = null)
    {
        return $this->withToken($token ?? $this->token)
            ->getJson(route('api.codes.status', [$campaign ?? $this->campaign, $code]));
    }

    private function statusFor(Code $code, ?string $token = null, ?Campaign $campaign = null)
    {
        return $this->statusForCode($code->code, $token, $campaign);
    }

    // --- authentication and abilities ---

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->postJson(route('api.codes.store', $this->campaign), [
            'reference' => 'attendee-1',
            'lovelace' => 2_000_000,
        ])->assertStatus(401);
    }

    public function test_a_token_without_the_create_ability_is_refused(): void
    {
        $statusOnly = $this->user->createToken('status-only', ['codes:status'])->plainTextToken;

        $this->create([], $statusOnly)->assertStatus(403);

        $this->assertSame(0, Code::count());
    }

    public function test_a_token_without_the_status_ability_is_refused(): void
    {
        $createOnly = $this->user->createToken('create-only', ['codes:create'])->plainTextToken;
        $code = Code::factory()->for($this->campaign)->create();

        $this->statusFor($code, $createOnly)->assertStatus(403);
    }

    // --- campaign ownership ---

    public function test_a_campaign_belonging_to_another_user_404s_on_create(): void
    {
        $foreign = Campaign::factory()->for(User::factory())->create();

        $this->create([], null, $foreign)->assertStatus(404);

        $this->assertSame(0, Code::count());
    }

    public function test_a_campaign_belonging_to_another_user_404s_on_status(): void
    {
        $foreignOwner = User::factory()->create();
        $foreign = Campaign::factory()->for($foreignOwner)->create();
        $code = Code::factory()->for($foreign)->create();

        $this->statusFor($code, null, $foreign)->assertStatus(404);
    }

    public function test_a_code_from_another_campaign_404s_on_status(): void
    {
        $otherCampaign = Campaign::factory()->for($this->user)->create();
        $foreignCode = Code::factory()->for($otherCampaign)->create();

        // The URL names $this->campaign, but the code belongs to a campaign the same
        // account also owns. Ownership alone is not enough: the code has to be this
        // campaign's own.
        $this->statusFor($foreignCode)->assertStatus(404);
    }

    public function test_an_unknown_code_string_404s_on_status(): void
    {
        $this->statusForCode('THIS-CODE-WAS-NEVER-MADE-AAAA')->assertStatus(404);
    }

    /**
     * $code is looked up by its own string, scoped to the campaign, never by the row's
     * numeric id. Binding the route parameter to Code's id column instead would work by
     * accident for a code string that happens to start with another row's id and, on a
     * database whose comparison coerces a string operand for an integer column, would not
     * even fail: 'WHERE id = ?' with a code string starting with a digit can compare true
     * against a completely different code's row. This is exercised here too, but it is
     * only a true regression test on the MySQL CI run, where that coercion actually
     * happens; SQLite's comparison does not coerce the operand and would pass either way.
     */
    public function test_status_looks_up_by_the_code_string_even_when_its_leading_digits_match_another_codes_id(): void
    {
        $other = Code::factory()->for($this->campaign)->create();
        Claim::factory()->completed()->for($other)->create();

        $collidingCode = ((string) $other->id).'COLLIDESWITHITSID12';
        $this->assertStringStartsWith((string) $other->id, $collidingCode);

        Code::factory()->for($this->campaign)->create(['code' => $collidingCode]);

        $response = $this->statusForCode($collidingCode)->assertOk();

        // The other row's completed claim must never answer for this one, whatever the two
        // rows' ids and code strings happen to share as text.
        $this->assertFalse($response->json('claimed'));
    }

    // --- creation ---

    public function test_a_code_is_created_from_reference_lovelace_and_tokens(): void
    {
        $response = $this->create([
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ]);

        $response->assertStatus(201)->assertJsonStructure(['code']);

        $code = Code::sole();
        $this->assertSame($response->json('code'), $code->code);
        $this->assertSame('attendee-1', $code->reference);
        $this->assertSame(1, (int) $code->uses);
        $this->assertSame(1, (int) $code->perWallet);
        $this->assertSame(2_000_000, (int) $code->lovelace);
        $this->assertCount(1, $code->rewards);
        $this->assertSame(self::POLICY, $code->rewards->first()->policy_hex);
    }

    public function test_a_code_with_no_tokens_is_created(): void
    {
        $this->create()->assertStatus(201);

        $code = Code::sole();
        $this->assertSame('attendee-1', $code->reference);
        $this->assertCount(0, $code->rewards);
    }

    public function test_the_same_reference_twice_gives_one_code(): void
    {
        $first = $this->create()->assertStatus(201)->json('code');
        $second = $this->create()->assertStatus(200)->json('code');

        $this->assertSame($first, $second);
        $this->assertSame(1, Code::count());
    }

    public function test_a_different_reference_makes_a_second_code(): void
    {
        $this->create(['reference' => 'attendee-1'])->assertStatus(201);
        $this->create(['reference' => 'attendee-2'])->assertStatus(201);

        $this->assertSame(2, Code::count());
    }

    public function test_the_same_reference_is_scoped_to_its_own_campaign(): void
    {
        $other = Campaign::factory()->for($this->user)->create(['network' => 'mainnet']);
        Wallet::factory()->for($other)->create();

        $this->create(['reference' => 'attendee-1'])->assertStatus(201);
        $this->create(['reference' => 'attendee-1'], null, $other)->assertStatus(201);

        $this->assertSame(2, Code::count());
    }

    /**
     * The reference column has a binary collation on MySQL specifically because that
     * engine's default collation compares text case-insensitively, which would make these
     * two references collide on the unique index and the second request would silently
     * return the first request's code. SQLite's TEXT columns already compare byte-for-byte,
     * so this passes here regardless; it is the MySQL CI run that proves the migration's
     * collation override actually took effect.
     */
    public function test_a_reference_differing_only_by_case_makes_a_second_code(): void
    {
        $this->create(['reference' => 'attendee-1'])->assertStatus(201);
        $this->create(['reference' => 'ATTENDEE-1'])->assertStatus(201);

        $this->assertSame(2, Code::count());
    }

    public function test_a_reference_with_characters_outside_the_allowed_set_is_refused(): void
    {
        $this->create(['reference' => 'attendee 1!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference');

        $this->assertSame(0, Code::count());
    }

    public function test_a_reference_using_the_full_allowed_character_set_is_created(): void
    {
        $this->create(['reference' => 'AZaz09._:-'])->assertStatus(201);
    }

    public function test_one_ada_with_a_token_is_stored_at_exactly_the_minimum(): void
    {
        $this->create([
            'lovelace' => 1_000_000,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame(self::ONE_ASSET_MINIMUM, (int) Code::first()->lovelace);
    }

    public function test_ten_ada_with_a_token_stays_ten_ada(): void
    {
        $this->create([
            'lovelace' => 10_000_000,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame(10_000_000, (int) Code::first()->lovelace);
    }

    public function test_repeating_a_raised_request_returns_the_same_code(): void
    {
        $payload = [
            'lovelace' => 1_000_000,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ];

        $first = $this->create($payload)->assertStatus(201)->json('code');
        $second = $this->create($payload)->assertStatus(200)->json('code');

        $this->assertSame($first, $second);
        $this->assertSame(1, Code::count());
    }

    public function test_the_same_reference_with_a_genuinely_different_amount_still_conflicts(): void
    {
        $tokens = [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]];

        $this->create(['lovelace' => 1_000_000, 'tokens' => $tokens])->assertStatus(201);
        $this->create(['lovelace' => 5_000_000, 'tokens' => $tokens])->assertStatus(409);

        $this->assertSame(1, Code::count());
    }

    public function test_a_code_exactly_on_the_minimum_is_created(): void
    {
        $this->create([
            'lovelace' => self::ONE_ASSET_MINIMUM,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ])->assertStatus(201);
    }

    public function test_lovelace_below_the_flat_floor_is_refused_even_with_no_tokens(): void
    {
        $this->create(['lovelace' => 999_999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lovelace');
    }

    public function test_a_missing_reference_is_refused(): void
    {
        $this->create(['reference' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference');
    }

    public function test_a_direct_duplicate_insert_is_refused_by_the_database(): void
    {
        Code::factory()->for($this->campaign)->create(['reference' => 'attendee-1']);

        $this->expectException(QueryException::class);

        Code::factory()->for($this->campaign)->create(['reference' => 'attendee-1']);
    }

    /**
     * The code and its rewards are created inside one transaction specifically so a reward
     * that fails to insert leaves no orphaned code behind. Simulated with a model event
     * rather than a real constraint violation, because the whole point is that this holds
     * for ANY failure partway through, not only the ones this suite happens to be able to
     * provoke another way.
     */
    public function test_a_failed_reward_insert_leaves_no_code_behind(): void
    {
        $this->withoutExceptionHandling();

        Reward::creating(function () {
            throw new RuntimeException('simulated reward failure');
        });

        try {
            $this->create([
                'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
            ]);
            $this->fail('Expected the simulated reward failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated reward failure', $e->getMessage());
        }

        $this->assertSame(0, Code::count());
        $this->assertSame(0, Reward::count());
    }

    public function test_the_shipped_ada_path_never_blocks_creation(): void
    {
        // The default, in_band, path has already been paid by the time a claim is taken and
        // has no balance to run out of, so it never holds a claim and must never block a
        // code either — this is the state almost every campaign is actually in.
        $this->campaign->forceFill(['spend_limit_micro' => 0])->save();

        $this->create()->assertStatus(201);
    }

    public function test_an_ended_campaign_refuses_a_new_code(): void
    {
        $this->campaign->update(['end_date' => now()->subDay()->toDateString()]);

        $this->create()->assertStatus(422)->assertJsonValidationErrors('campaign');
        $this->assertSame(0, Code::count());
    }

    // --- the operator's own cap on how many codes a campaign may hold ---

    public function test_creating_past_the_campaigns_own_code_cap_is_refused(): void
    {
        $this->campaign->forceFill(['max_codes' => 1])->save();
        Code::factory()->for($this->campaign)->create();

        $this->create(['reference' => 'attendee-2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_codes');

        $this->assertSame(1, Code::count());
    }

    public function test_a_campaign_with_room_under_its_own_code_cap_still_creates(): void
    {
        $this->campaign->forceFill(['max_codes' => 2])->save();
        Code::factory()->for($this->campaign)->create();

        $this->create(['reference' => 'attendee-2'])->assertStatus(201);

        $this->assertSame(2, Code::count());
    }

    public function test_a_campaign_with_no_cap_is_unaffected(): void
    {
        $this->campaign->forceFill(['max_codes' => null])->save();
        Code::factory()->count(5)->for($this->campaign)->create();

        $this->create(['reference' => 'attendee-6'])->assertStatus(201);
    }

    // --- a reference sent again with a different payload ---

    public function test_the_same_reference_with_different_lovelace_conflicts(): void
    {
        $first = $this->create(['lovelace' => 2_000_000])->assertStatus(201)->json('code');

        $response = $this->create(['lovelace' => 5_000_000])->assertStatus(409);

        $this->assertSame($first, $response->json('code'));
        $this->assertSame(1, Code::count());
    }

    public function test_the_same_reference_with_a_different_token_set_conflicts(): void
    {
        $first = $this->create([
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ])->assertStatus(201)->json('code');

        $response = $this->create([
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 2]],
        ])->assertStatus(409);

        $this->assertSame($first, $response->json('code'));
        $this->assertSame(1, Code::count());
    }

    public function test_the_same_reference_with_the_same_tokens_in_a_different_order_still_matches(): void
    {
        $tokenA = ['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1];
        $tokenB = ['policy_id' => str_repeat('b', 56), 'token_id' => 'ff', 'quantity' => 2];

        $first = $this->create(['lovelace' => 5_000_000, 'tokens' => [$tokenA, $tokenB]])
            ->assertStatus(201)->json('code');
        $second = $this->create(['lovelace' => 5_000_000, 'tokens' => [$tokenB, $tokenA]])
            ->assertStatus(200)->json('code');

        $this->assertSame($first, $second);
        $this->assertSame(1, Code::count());
    }

    /**
     * A retry has to be answered from what was already made. Protocol parameters read
     * live from the chain can only ever rise between the original request and a retry of
     * it, and a retry is not the moment to discover that the lovelace it already carries
     * would no longer clear today's minimum.
     */
    public function test_a_retry_still_returns_its_code_after_the_live_minimum_has_risen(): void
    {
        $payload = [
            'lovelace' => self::ONE_ASSET_MINIMUM,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ];

        $first = $this->create($payload)->assertStatus(201)->json('code');

        // Whatever the real figure the chain last reported, something higher than it now
        // reads, exactly as a protocol parameter that moved since the original request
        // would.
        config(['cardano.min_utxo.coins_per_utxo_byte' => 100_000_000]);

        $second = $this->create($payload)->assertStatus(200)->json('code');

        $this->assertSame($first, $second);
        $this->assertSame(1, Code::count());
    }

    /**
     * A genuinely new reference is stored at today's live minimum when that is higher than
     * what was sent.
     */
    public function test_a_new_reference_is_raised_to_the_live_minimum(): void
    {
        config(['cardano.min_utxo.coins_per_utxo_byte' => 100_000_000]);

        $this->create([
            'lovelace' => self::ONE_ASSET_MINIMUM,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertGreaterThan(self::ONE_ASSET_MINIMUM, (int) Code::first()->lovelace);
    }

    // --- tightened validation on this path only ---

    public function test_a_policy_id_of_the_wrong_length_is_refused(): void
    {
        $this->create([
            'tokens' => [['policy_id' => 'ab', 'token_id' => self::ASSET, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('tokens.0.policy_id');
    }

    public function test_an_odd_length_asset_hex_is_refused(): void
    {
        $this->create([
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => 'abc', 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('tokens.0.token_id');
    }

    public function test_an_asset_hex_over_sixty_four_characters_is_refused(): void
    {
        $this->create([
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => str_repeat('ab', 33), 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('tokens.0.token_id');
    }

    public function test_a_token_quantity_over_the_configured_maximum_is_refused(): void
    {
        config(['cardano.code_api.max_token_quantity' => 100]);

        $this->create([
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 101]],
        ])->assertStatus(422)->assertJsonValidationErrors('tokens.0.quantity');
    }

    public function test_more_tokens_than_the_configured_maximum_is_refused(): void
    {
        config(['cardano.code_api.max_tokens_per_code' => 1]);

        $this->create([
            'lovelace' => 5_000_000,
            'tokens' => [
                ['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1],
                ['policy_id' => str_repeat('b', 56), 'token_id' => 'ff', 'quantity' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('tokens');
    }

    // --- what reaches the log ---

    /**
     * The reference is the caller's own identifier for a person, so it must never reach
     * the log. What identifies the row for anybody reading the log is the code id and the
     * campaign id, not the reference that named it.
     */
    public function test_the_reference_is_never_written_to_the_log(): void
    {
        Log::spy();

        $this->create(['reference' => 'super-secret-attendee-handle'])->assertStatus(201);

        $code = Code::sole();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context) => str_contains($message, 'Code created')
                && $context['code_id'] === $code->id
                && $context['campaign_id'] === $this->campaign->id
                && ! in_array('super-secret-attendee-handle', $context, true)
        );
    }

    // --- the rate limit ---

    public function test_the_create_rate_limit_is_keyed_on_the_token(): void
    {
        config(['cardano.code_api.create_rate_per_token' => 2]);

        $this->create(['reference' => 'attendee-1'])->assertStatus(201);
        $this->create(['reference' => 'attendee-2'])->assertStatus(201);
        $this->create(['reference' => 'attendee-3'])->assertStatus(429);
    }

    public function test_the_status_rate_limit_is_keyed_on_the_token(): void
    {
        config(['cardano.code_api.status_rate_per_token' => 2]);

        $code = Code::factory()->for($this->campaign)->create();

        $this->statusFor($code)->assertOk();
        $this->statusFor($code)->assertOk();
        $this->statusFor($code)->assertStatus(429);
    }

    /**
     * Create and status are two separate buckets specifically so a caller polling status
     * while it waits on a claim can never starve its own ability to create the next code.
     */
    public function test_exhausting_the_status_rate_limit_does_not_block_create(): void
    {
        config(['cardano.code_api.status_rate_per_token' => 2]);

        $code = Code::factory()->for($this->campaign)->create();

        $this->statusFor($code)->assertOk();
        $this->statusFor($code)->assertOk();
        $this->statusFor($code)->assertStatus(429);

        $this->create(['reference' => 'attendee-1'])->assertStatus(201);
    }

    public function test_exhausting_the_create_rate_limit_does_not_block_status(): void
    {
        config(['cardano.code_api.create_rate_per_token' => 2]);

        $this->create(['reference' => 'attendee-1'])->assertStatus(201);
        $this->create(['reference' => 'attendee-2'])->assertStatus(201);
        $this->create(['reference' => 'attendee-3'])->assertStatus(429);

        $code = Code::factory()->for($this->campaign)->create();
        $this->statusFor($code)->assertOk();
    }

    // --- status ---

    public function test_status_reports_an_unclaimed_code(): void
    {
        $code = Code::factory()->for($this->campaign)->create();

        $this->statusFor($code)->assertOk()->assertExactJson([
            'claimed' => false,
            'stake_key' => null,
            'address' => null,
            'status' => null,
            'held_reason' => null,
            'transaction_hash' => null,
            'claimed_at' => null,
        ]);
    }

    public function test_status_reports_a_claim_with_no_hash_yet(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        $claim = Claim::factory()->for($code)->create();

        $response = $this->statusFor($code)->assertOk();

        $response->assertJson([
            'claimed' => true,
            'stake_key' => $claim->stake_key,
            'address' => $claim->address,
            'status' => 'pending',
            'held_reason' => null,
            'transaction_hash' => null,
        ]);
        $this->assertNotNull($response->json('claimed_at'));
    }

    public function test_status_reports_a_claim_that_has_landed(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        $claim = Claim::factory()->completed()->for($code)->create();

        $this->statusFor($code)->assertOk()->assertJson([
            'claimed' => true,
            'stake_key' => $claim->stake_key,
            'address' => $claim->address,
            'status' => 'completed',
            'held_reason' => null,
            'transaction_hash' => $claim->transaction_hash,
        ]);
    }

    /**
     * held_reason is what keeps a claim held for lack of credit or past a spend limit from
     * reading, to a caller polling this endpoint, as though it were simply pending forever.
     */
    public function test_status_reports_the_held_reason_for_a_claim_waiting_on_credit(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        $claim = Claim::factory()->for($code)->create();
        $claim->forceFill(['held_reason' => CreditLedger::HELD_NO_CREDIT])->save();

        $this->statusFor($code)->assertOk()->assertJson([
            'claimed' => true,
            'status' => 'pending',
            'held_reason' => CreditLedger::HELD_NO_CREDIT,
        ]);
    }

    public function test_status_reports_the_most_recent_claim(): void
    {
        $code = Code::factory()->for($this->campaign)->create(['uses' => 0, 'perWallet' => 0]);
        Claim::factory()->for($code)->create(['stake_key' => 'stake_test1first']);
        $latest = Claim::factory()->completed()->for($code)->create(['stake_key' => 'stake_test1second']);

        $this->statusFor($code)->assertOk()->assertJson([
            'stake_key' => $latest->stake_key,
            'status' => 'completed',
        ]);
    }

    // --- reading a campaign ---

    private function showCampaign(string $id, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)
            ->getJson('/api/v1/campaigns/'.$id);
    }

    public function test_the_owner_reads_their_campaign_with_the_fields_a_caller_confirms_it_by(): void
    {
        $a = Code::factory()->for($this->campaign)->create();
        $a->rewards()->create(['policy_hex' => self::POLICY, 'asset_hex' => self::ASSET, 'quantity' => 3]);
        $b = Code::factory()->for($this->campaign)->create();
        $b->rewards()->create(['policy_hex' => self::POLICY, 'asset_hex' => self::ASSET, 'quantity' => 3]);

        $response = $this->showCampaign($this->campaign->id)->assertOk();

        $response->assertExactJson([
            'id' => $this->campaign->id,
            'name' => $this->campaign->name,
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'claims_open' => true,
            'claims_closed_reason' => null,
            'funding_address' => $this->campaign->wallet->address,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 3]],
        ]);
    }

    public function test_a_campaign_that_has_not_started_or_has_ended_says_why_claims_are_closed(): void
    {
        $upcoming = Campaign::factory()->for($this->user)->create([
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(9)->toDateString(),
        ]);
        $ended = Campaign::factory()->for($this->user)->create([
            'start_date' => now()->subDays(9)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
        ]);

        $this->showCampaign($upcoming->id)->assertOk()
            ->assertJsonPath('claims_open', false)
            ->assertJsonPath('claims_closed_reason', 'upcoming');
        $this->showCampaign($ended->id)->assertOk()
            ->assertJsonPath('claims_open', false)
            ->assertJsonPath('claims_closed_reason', 'ended');
    }

    public function test_the_campaign_read_carries_no_wallet_or_other_account_data(): void
    {
        $body = $this->showCampaign($this->campaign->id)->assertOk()->json();

        $this->assertSame(
            ['claims_closed_reason', 'claims_open', 'end_date', 'funding_address', 'id', 'name', 'network', 'start_date', 'status', 'tokens'],
            collect($body)->keys()->sort()->values()->all()
        );
    }

    public function test_another_users_campaign_and_an_unknown_one_answer_identically(): void
    {
        $foreign = Campaign::factory()->for(User::factory())->create(['name' => 'Someone Else']);

        $foreignResponse = $this->showCampaign($foreign->id)->assertStatus(404)
            ->assertJsonPath('code', 'campaign_not_found');
        $unknownResponse = $this->showCampaign('01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertStatus(404)
            ->assertJsonPath('code', 'campaign_not_found');

        $this->assertSame($unknownResponse->getContent(), $foreignResponse->getContent());
        $this->assertStringNotContainsString('Someone Else', $foreignResponse->getContent());
    }

    public function test_reading_a_campaign_needs_the_status_ability(): void
    {
        $createOnly = $this->user->createToken('create-only', ['codes:create'])->plainTextToken;

        $this->showCampaign($this->campaign->id, $createOnly)->assertStatus(403);
    }

    public function test_reading_a_campaign_without_a_token_is_401(): void
    {
        $this->getJson('/api/v1/campaigns/'.$this->campaign->id)->assertStatus(401);
    }

    public function test_a_foreign_campaign_and_an_unknown_one_are_answered_alike_on_create_and_status(): void
    {
        $foreign = Campaign::factory()->for(User::factory())->create();
        $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $createForeign = $this->withToken($this->token)->postJson('/api/v1/campaigns/'.$foreign->id.'/codes', ['reference' => 'r-1', 'lovelace' => 2000000]);
        $createUnknown = $this->withToken($this->token)->postJson('/api/v1/campaigns/'.$unknown.'/codes', ['reference' => 'r-1', 'lovelace' => 2000000]);
        $statusForeign = $this->withToken($this->token)->getJson('/api/v1/campaigns/'.$foreign->id.'/codes/ABC/status');
        $statusUnknown = $this->withToken($this->token)->getJson('/api/v1/campaigns/'.$unknown.'/codes/ABC/status');

        $createForeign->assertStatus(404);
        $statusForeign->assertStatus(404);
        $this->assertSame($createUnknown->getContent(), $createForeign->getContent());
        $this->assertSame($statusUnknown->getContent(), $statusForeign->getContent());
    }

    public function test_the_campaign_read_gives_the_funding_address_and_null_when_there_is_no_wallet(): void
    {
        $this->assertNotEmpty($this->campaign->wallet->address);
        $this->showCampaign($this->campaign->id)->assertJsonPath('funding_address', $this->campaign->wallet->address);

        $bare = Campaign::factory()->for($this->user)->create();
        $this->showCampaign($bare->id)->assertOk()->assertJsonPath('funding_address', null);
    }
}
