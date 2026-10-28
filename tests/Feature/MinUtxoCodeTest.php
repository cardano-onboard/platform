<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the chain will accept, applied where a code is created and where a campaign is
 * looked at.
 *
 * A code is stored at the higher of the amount entered and the exact minimum for its
 * assets. A reward under the minimum is raised to it rather than refused, because it would
 * otherwise be a payment that could be created, printed on a sticker, scanned at a booth
 * and never sent. A reward at or above the minimum is stored exactly as entered.
 */
class MinUtxoCodeTest extends TestCase
{
    use RefreshDatabase;

    /** A real policy id and a real asset name, because name length is part of the figure. */
    private const POLICY = 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235';

    private const ASSET = '484f534b59'; // HOSKY

    /** What one HOSKY in an output actually costs at mainnet's 4310 coins per byte. */
    private const ONE_ASSET_MINIMUM = 1_150_770;

    private User $user;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create(['network' => 'mainnet']);
        Wallet::factory()->for($this->campaign)->create();
    }

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->user)->post(route('codes.store'), array_merge([
            'campaign_id' => $this->campaign->id,
            'lovelace' => 2_000_000,
            'perWallet' => 1,
            'uses' => 1,
            'tokens' => [[
                'policy_id' => self::POLICY,
                'token_id' => self::ASSET,
                'quantity' => 1,
            ]],
        ], $overrides));
    }

    public function test_one_ada_with_a_native_asset_is_stored_at_exactly_the_minimum(): void
    {
        $this->create(['lovelace' => 1_000_000])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('codes', 1);
        $this->assertDatabaseCount('rewards', 1);
        $this->assertSame(self::ONE_ASSET_MINIMUM, (int) Code::query()->first()->lovelace);
    }

    public function test_ten_ada_with_native_assets_stays_ten_ada(): void
    {
        $this->create(['lovelace' => 10_000_000])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('message');

        $this->assertSame(10_000_000, (int) Code::query()->first()->lovelace);
    }

    public function test_raising_a_reward_tells_the_operator_what_it_became(): void
    {
        $this->create(['lovelace' => 1_000_000]);

        $this->assertStringContainsString('raised from 1 ADA to 1.15077 ADA', session('message'));
    }

    public function test_one_lovelace_under_the_minimum_is_raised_by_that_lovelace(): void
    {
        $this->create(['lovelace' => self::ONE_ASSET_MINIMUM - 1]);

        $this->assertSame(self::ONE_ASSET_MINIMUM, (int) Code::query()->first()->lovelace);
    }

    public function test_a_code_exactly_on_the_minimum_is_stored_as_entered_without_a_message(): void
    {
        $this->create(['lovelace' => self::ONE_ASSET_MINIMUM])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('message');

        $this->assertSame(self::ONE_ASSET_MINIMUM, (int) Code::query()->first()->lovelace);
    }

    public function test_a_code_a_little_above_the_minimum_gets_no_headroom_added_or_removed(): void
    {
        $this->create(['lovelace' => self::ONE_ASSET_MINIMUM + 1_000_000])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('message');

        $this->assertSame(self::ONE_ASSET_MINIMUM + 1_000_000, (int) Code::query()->first()->lovelace);
    }

    /**
     * The flat floor still applies where there is nothing to weigh. An ADA-only output is
     * cheaper than 1 ADA on the current parameters, and dropping the old rule would have
     * quietly allowed codes paying less than the round number every operator expects.
     */
    public function test_an_ada_only_code_keeps_the_flat_one_ada_floor(): void
    {
        $this->create(['lovelace' => 1_000_000, 'tokens' => []])
            ->assertSessionHasNoErrors();

        $this->create(['lovelace' => 999_999, 'tokens' => []])
            ->assertSessionHasErrors('lovelace');

        $this->assertDatabaseCount('codes', 1);
    }

    public function test_more_policies_raise_the_stored_amount_further(): void
    {
        $twoPolicies = [
            ['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1],
            ['policy_id' => str_repeat('cd', 28), 'token_id' => bin2hex('SUNDAE'), 'quantity' => 1],
        ];

        // Enough for one asset, not enough for two under separate policies.
        $this->create(['lovelace' => self::ONE_ASSET_MINIMUM, 'tokens' => $twoPolicies])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan(self::ONE_ASSET_MINIMUM, (int) Code::query()->first()->lovelace);
    }

    /**
     * The other half of the same rule. Three assets under one policy pay for one policy id,
     * so a bundle that is raised when spread across three policies is stored as entered when
     * it is not, at the same amount.
     */
    public function test_assets_sharing_a_policy_cost_less_than_assets_that_do_not(): void
    {
        $shared = [
            ['policy_id' => self::POLICY, 'token_id' => bin2hex('AAAA'), 'quantity' => 1],
            ['policy_id' => self::POLICY, 'token_id' => bin2hex('BBBB'), 'quantity' => 1],
            ['policy_id' => self::POLICY, 'token_id' => bin2hex('CCCC'), 'quantity' => 1],
        ];
        $spread = [
            ['policy_id' => str_repeat('11', 28), 'token_id' => bin2hex('AAAA'), 'quantity' => 1],
            ['policy_id' => str_repeat('22', 28), 'token_id' => bin2hex('BBBB'), 'quantity' => 1],
            ['policy_id' => str_repeat('33', 28), 'token_id' => bin2hex('CCCC'), 'quantity' => 1],
        ];

        $amount = 1_400_000;

        $this->create(['lovelace' => $amount, 'tokens' => $shared])->assertSessionHasNoErrors();
        $this->create(['lovelace' => $amount, 'tokens' => $spread])->assertSessionHasNoErrors();

        $amounts = Code::query()->orderBy('created_at')->get()->map(fn ($code) => (int) $code->lovelace);
        $this->assertContains($amount, $amounts->all());
        $this->assertSame(1, $amounts->filter(fn ($value) => $value === $amount)->count());
        $this->assertSame(1, $amounts->filter(fn ($value) => $value > $amount)->count());
    }

    public function test_a_longer_asset_name_raises_the_stored_amount(): void
    {
        $long = [[
            'policy_id' => self::POLICY,
            'token_id' => bin2hex(str_repeat('N', 32)),
            'quantity' => 1,
        ]];

        $this->create(['lovelace' => self::ONE_ASSET_MINIMUM, 'tokens' => $long])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan(self::ONE_ASSET_MINIMUM, (int) Code::query()->first()->lovelace);
    }

    public function test_every_code_in_a_batch_carries_the_same_checked_amount(): void
    {
        $this->create(['lovelace' => self::ONE_ASSET_MINIMUM + 2_000_000, 'quantity' => 5])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('codes', 5);
        $this->assertDatabaseCount('rewards', 5);
        $this->assertSame(
            [self::ONE_ASSET_MINIMUM + 2_000_000],
            Code::query()->pluck('lovelace')->unique()->values()->all()
        );
    }

    public function test_the_quote_endpoint_answers_for_a_bundle_nobody_has_saved(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('campaigns.min-utxo', $this->campaign->id), [
                'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
                'lovelace' => 1_000_000,
            ]);

        $response->assertOk()
            ->assertJsonPath('min_lovelace', self::ONE_ASSET_MINIMUM)
            ->assertJsonPath('asset_count', 1)
            ->assertJsonPath('policy_count', 1)
            ->assertJsonPath('state', 'below_minimum');

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_the_quote_endpoint_answers_for_an_empty_bundle(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('campaigns.min-utxo', $this->campaign->id), ['tokens' => []])
            ->assertOk()
            ->assertJsonPath('min_lovelace', 978_370)
            ->assertJsonPath('asset_count', 0);
    }

    public function test_the_quote_endpoint_rejects_someone_elses_campaign(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson(route('campaigns.min-utxo', $this->campaign->id), ['tokens' => []])
            ->assertForbidden();
    }

    public function test_the_quote_endpoint_needs_a_signed_in_user(): void
    {
        $this->postJson(route('campaigns.min-utxo', $this->campaign->id), ['tokens' => []])
            ->assertUnauthorized();
    }

    public function test_the_quote_endpoint_refuses_a_policy_that_is_not_hex(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('campaigns.min-utxo', $this->campaign->id), [
                'tokens' => [['policy_id' => 'not a policy', 'token_id' => self::ASSET, 'quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_the_campaign_page_counts_codes_that_can_never_be_paid(): void
    {
        // Written straight to the database, because the form now raises it instead.
        // Codes made before this rule existed are exactly the ones an operator has to find.
        $bad = Code::factory()->for($this->campaign)->create(['lovelace' => 1_000_000, 'uses' => 5]);
        Reward::factory()->for($bad)->create([
            'policy_hex' => self::POLICY,
            'asset_hex' => self::ASSET,
            'quantity' => 1,
        ]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('min_utxo.below_minimum_codes', 1)
                ->where('min_utxo.coins_per_utxo_byte', 4310)
                ->where('campaign.codes.0.min_utxo.state', 'below_minimum')
                ->where('campaign.codes.0.min_utxo.min_lovelace', self::ONE_ASSET_MINIMUM)
            );
    }

    public function test_a_code_on_its_minimum_is_not_flagged_at_all(): void
    {
        $exact = Code::factory()->for($this->campaign)->create([
            'lovelace' => self::ONE_ASSET_MINIMUM,
            'uses' => 5,
        ]);
        Reward::factory()->for($exact)->create([
            'policy_hex' => self::POLICY,
            'asset_hex' => self::ASSET,
            'quantity' => 1,
        ]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('min_utxo.below_minimum_codes', 0)
                ->where('campaign.codes.0.min_utxo.state', 'ok')
                ->missing('min_utxo.tight_codes')
                ->missing('min_utxo.headroom_lovelace')
            );
    }

    /**
     * A code nobody can claim any more is not something to chase. Counting it would send an
     * operator to fix a code that is already finished with.
     */
    public function test_a_fully_claimed_code_is_not_counted_against_the_campaign(): void
    {
        $spent = Code::factory()->for($this->campaign)->create(['lovelace' => 1_000_000, 'uses' => 1]);
        Reward::factory()->for($spent)->create([
            'policy_hex' => self::POLICY,
            'asset_hex' => self::ASSET,
            'quantity' => 1,
        ]);
        $spent->claims()->create([
            'address' => 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy',
            'stake_key' => 'stake1uxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9gxnrpsl',
        ]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('min_utxo.below_minimum_codes', 0)
                ->where('campaign.codes.0.min_utxo.state', 'below_minimum')
            );
    }

    public function test_an_ada_only_code_is_not_unpayable(): void
    {
        Code::factory()->for($this->campaign)->create(['lovelace' => 2_000_000, 'uses' => 5]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('min_utxo.below_minimum_codes', 0)
            );
    }
}
