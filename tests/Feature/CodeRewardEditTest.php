<?php

namespace Tests\Feature;

use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Changing what a code pays after it has been printed, and what that does to claims.
 *
 * The rule the whole feature rests on: a claim records what it was actually paid at the
 * moment the payment was submitted, so an edit applies forward. Without that, a code with
 * ten uses and three claims against it is one row whose reward would mean two different
 * things and only one of them could be read back.
 *
 * These tests do not trust the code row to answer what somebody was paid. They change the
 * code and then read the claim, which is the only place the old answer survives.
 */
class CodeRewardEditTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235';

    private const ASSET = '484f534b59'; // HOSKY

    private const OTHER_POLICY = 'cd1d3fd1e9b5a8a8b4c1aa9de6f1c26a1b9f0a2d3e4f5a6b7c8d9e0f';

    private const ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const STAKE = 'stake1uxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9gxnrpsl';

    private User $user;

    private Campaign $campaign;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);
        Wallet::factory()->for($this->campaign)->create(['backend' => 'null']);

        $this->code = Code::factory()->for($this->campaign)->create([
            'lovelace' => 3_000_000,
            'uses' => 10,
            'perWallet' => 1,
        ]);
        Reward::factory()->for($this->code)->create([
            'policy_hex' => self::POLICY,
            'asset_hex' => self::ASSET,
            'quantity' => 5,
        ]);
    }

    private function edit(array $overrides = [], ?Code $code = null)
    {
        return $this->actingAs($this->user)->put(
            route('codes.update', ($code ?? $this->code)->id),
            array_merge([
                'lovelace' => 4_000_000,
                'tokens' => [[
                    'policy_id' => self::POLICY,
                    'token_id' => self::ASSET,
                    'quantity' => 5,
                ]],
            ], $overrides)
        );
    }

    /** A claim taken and then submitted, which is when a reward is recorded. */
    private function claimAndSend(string $address = self::ADDRESS, string $stake = self::STAKE): Claim
    {
        $claim = $this->code->claims()->create(['address' => $address, 'stake_key' => $stake]);

        (new ProcessClaims($this->campaign->id))->handle();

        return $claim->fresh();
    }

    public function test_the_owner_can_change_what_a_code_pays(): void
    {
        $this->edit(['lovelace' => 7_500_000])->assertRedirect();

        $this->assertSame(7_500_000, (int) $this->code->fresh()->lovelace);
    }

    public function test_a_token_can_be_added_to_a_code_that_already_exists(): void
    {
        $this->edit(['tokens' => [
            ['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 5],
            ['policy_id' => self::OTHER_POLICY, 'token_id' => bin2hex('SUNDAE'), 'quantity' => 2],
        ]])->assertSessionHasNoErrors();

        $rewards = $this->code->fresh()->rewards;

        $this->assertCount(2, $rewards);
        $this->assertEqualsCanonicalizing(
            [self::POLICY, self::OTHER_POLICY],
            $rewards->pluck('policy_hex')->all()
        );
    }

    public function test_a_token_can_be_taken_off_a_code(): void
    {
        $this->edit(['tokens' => []])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->code->fresh()->rewards);
        $this->assertDatabaseCount('rewards', 0);
    }

    public function test_a_quantity_can_be_changed_without_duplicating_the_reward(): void
    {
        $this->edit(['tokens' => [
            ['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 99],
        ]])->assertSessionHasNoErrors();

        $rewards = $this->code->fresh()->rewards;

        $this->assertCount(1, $rewards);
        $this->assertSame(99, (int) $rewards->first()->quantity);
    }

    public function test_the_code_itself_and_its_usage_limits_are_untouched(): void
    {
        $before = $this->code->code;

        $this->edit(['lovelace' => 9_000_000]);

        $after = $this->code->fresh();

        $this->assertSame($before, $after->code, 'A printed QR has to keep working.');
        $this->assertSame(10, (int) $after->uses);
        $this->assertSame(1, (int) $after->perWallet);
    }

    // ---------------------------------------------------------------------------------
    // What a claim keeps.
    // ---------------------------------------------------------------------------------

    public function test_a_submitted_claim_records_what_it_was_paid(): void
    {
        $claim = $this->claimAndSend();

        $this->assertNotNull($claim->transaction_id);
        $this->assertSame(3_000_000, $claim->reward_lovelace);
        $this->assertSameJson(
            [['policy' => self::POLICY, 'asset' => self::ASSET, 'quantity' => 5]],
            $claim->reward_tokens
        );
    }

    public function test_an_edit_does_not_rewrite_what_an_earlier_claim_was_paid(): void
    {
        $claim = $this->claimAndSend();

        $this->edit([
            'lovelace' => 12_000_000,
            'tokens' => [['policy_id' => self::OTHER_POLICY, 'token_id' => bin2hex('SUNDAE'), 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $claim = $claim->fresh();

        $this->assertSame(3_000_000, $claim->reward_lovelace, 'The claim keeps what it was sent.');
        $this->assertSame(self::POLICY, $claim->reward_tokens[0]['policy']);
        $this->assertSame(5, $claim->reward_tokens[0]['quantity']);

        // And the code really did change, so the assertion above is not passing because
        // nothing happened.
        $this->assertSame(12_000_000, (int) $this->code->fresh()->lovelace);
    }

    /**
     * The case the snapshot exists for. One code, two claimants, an edit between them, and
     * two different correct answers about what each was paid.
     */
    public function test_a_partly_used_code_pays_two_different_rewards_and_records_both(): void
    {
        $first = $this->claimAndSend();

        $this->edit(['lovelace' => 8_000_000, 'tokens' => []])->assertSessionHasNoErrors();

        $second = $this->claimAndSend(self::ADDRESS.'x', 'stake1second');

        $this->assertSame(3_000_000, $first->fresh()->reward_lovelace);
        $this->assertCount(1, $first->fresh()->reward_tokens);

        $this->assertSame(8_000_000, $second->reward_lovelace);
        $this->assertSame([], $second->reward_tokens);
    }

    public function test_a_claim_that_has_not_been_sent_has_recorded_no_reward(): void
    {
        $claim = $this->code->claims()->create([
            'address' => self::ADDRESS,
            'stake_key' => self::STAKE,
        ]);

        $this->assertNull($claim->fresh()->reward_lovelace);
        $this->assertNull($claim->fresh()->rewardPaid());
    }

    /**
     * Unrecorded is not the same as nothing. A claim taken before rewards were recorded
     * has an answer nobody wrote down, and reading the code back would report today's
     * configuration as a payment.
     */
    public function test_an_unrecorded_reward_is_reported_as_unknown_rather_than_as_the_code(): void
    {
        $claim = $this->code->claims()->create([
            'address' => self::ADDRESS,
            'stake_key' => self::STAKE,
            'transaction_id' => 'legacy-purchase-id',
        ]);

        $this->assertNull($claim->fresh()->rewardPaid());
    }

    /**
     * A submission the backend refused. Nothing went on chain, so nothing may be recorded
     * as having been paid: a reward stamped here would be a payment in the record that
     * never happened, and the claim would stop being eligible for the retry that is
     * supposed to rescue it.
     */
    public function test_a_submission_that_returns_nothing_records_no_reward(): void
    {
        $this->campaign->wallet->update(['backend' => 'phyrhose']);
        Http::fake(['*' => Http::response(['status' => 'error'], 500)]);

        $claim = $this->code->claims()->create([
            'address' => self::ADDRESS,
            'stake_key' => self::STAKE,
        ]);

        (new ProcessClaims($this->campaign->id))->handle();

        $claim = $claim->fresh();

        $this->assertNull($claim->transaction_id);
        $this->assertNull($claim->reward_lovelace);
        $this->assertNull($claim->reward_tokens);
    }

    // ---------------------------------------------------------------------------------
    // What the edit refuses, and what it only warns about.
    // ---------------------------------------------------------------------------------

    public function test_an_edit_below_the_chain_minimum_is_refused_and_changes_nothing(): void
    {
        $this->edit(['lovelace' => 1_000_000])->assertSessionHasErrors('lovelace');

        $this->assertSame(3_000_000, (int) $this->code->fresh()->lovelace);
        $this->assertCount(1, $this->code->fresh()->rewards);
    }

    public function test_an_edit_that_leaves_no_room_is_saved_and_warned_about(): void
    {
        $this->edit(['lovelace' => 1_159_390])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message');

        $this->assertSame(1_159_390, (int) $this->code->fresh()->lovelace);
        $this->assertStringContainsString('of room', session('message'));
    }

    public function test_the_operator_is_told_what_the_change_reaches(): void
    {
        $this->claimAndSend();
        $this->claimAndSend(self::ADDRESS.'x', 'stake1second');

        $this->edit();

        $this->assertStringContainsString('2 claims have already been paid', session('message'));
        $this->assertStringContainsString('keep what they were sent', session('message'));
    }

    /**
     * A code nobody has claimed gets none of that. A sentence about claims that do not
     * exist is noise, and noise in a confirmation is how people stop reading them.
     */
    public function test_a_code_with_no_claims_is_told_only_that_it_saved(): void
    {
        $this->edit();

        $this->assertSame('Rewards updated.', session('message'));
    }

    public function test_a_claim_waiting_to_be_sent_is_named_before_it_surprises_anybody(): void
    {
        $this->code->claims()->create(['address' => self::ADDRESS, 'stake_key' => self::STAKE]);

        $this->edit();

        $this->assertStringContainsString('accepted and not yet sent', session('message'));
    }

    public function test_a_code_with_no_uses_left_says_so(): void
    {
        $spent = Code::factory()->for($this->campaign)->create(['lovelace' => 3_000_000, 'uses' => 1]);
        $spent->claims()->create([
            'address' => self::ADDRESS,
            'stake_key' => self::STAKE,
            'transaction_id' => 'sent',
        ]);

        $this->edit(['tokens' => []], $spent);

        $this->assertStringContainsString('no uses left', session('message'));
        $this->assertSame(4_000_000, (int) $spent->fresh()->lovelace);
    }

    public function test_rewards_cannot_be_changed_on_an_ended_campaign(): void
    {
        $this->campaign->update([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $this->edit(['lovelace' => 9_000_000]);

        $this->assertSame(3_000_000, (int) $this->code->fresh()->lovelace);
    }

    // ---------------------------------------------------------------------------------
    // Who may do it.
    // ---------------------------------------------------------------------------------

    public function test_another_users_code_cannot_be_edited(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put(route('codes.update', $this->code->id), [
                'lovelace' => 9_000_000,
                'tokens' => [],
            ])
            ->assertForbidden();

        $this->assertSame(3_000_000, (int) $this->code->fresh()->lovelace);
        $this->assertCount(1, $this->code->fresh()->rewards);
    }

    public function test_a_signed_out_visitor_cannot_edit_a_code(): void
    {
        $this->put(route('codes.update', $this->code->id), ['lovelace' => 9_000_000])
            ->assertRedirect(route('login'));

        $this->assertSame(3_000_000, (int) $this->code->fresh()->lovelace);
    }

    public function test_a_code_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs($this->user)
            ->put(route('codes.update', 99999), ['lovelace' => 9_000_000, 'tokens' => []])
            ->assertNotFound();
    }

    public function test_a_malformed_token_is_refused(): void
    {
        $this->edit(['tokens' => [
            ['policy_id' => 'not hex at all', 'token_id' => self::ASSET, 'quantity' => 1],
        ]])->assertSessionHasErrors('tokens.0.policy_id');

        $this->assertCount(1, $this->code->fresh()->rewards);
        $this->assertSame(self::POLICY, $this->code->fresh()->rewards->first()->policy_hex);
    }

    public function test_a_zero_quantity_is_refused(): void
    {
        $this->edit(['tokens' => [
            ['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 0],
        ]])->assertSessionHasErrors('tokens.0.quantity');
    }

    // ---------------------------------------------------------------------------------
    // What the campaign page says afterwards.
    // ---------------------------------------------------------------------------------

    public function test_the_funding_figure_follows_the_new_reward(): void
    {
        // Ten uses at 3 ADA.
        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertInertia(fn ($page) => $page->where('funding.reward_lovelace', 30_000_000));

        $this->edit(['lovelace' => 5_000_000]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertInertia(fn ($page) => $page->where('funding.reward_lovelace', 50_000_000));
    }

    public function test_the_token_the_campaign_still_owes_follows_the_edit(): void
    {
        $this->edit(['tokens' => [
            ['policy_id' => self::OTHER_POLICY, 'token_id' => bin2hex('SUNDAE'), 'quantity' => 3],
        ]]);

        // Read out of the props directly: the campaign's reward totals are keyed by
        // "policy.asset", so a dot-path lookup would split one key into two.
        $response = $this->actingAs($this->user)->get(route('campaigns.show', $this->campaign->id));
        $rewards = $response->original->getData()['page']['props']['campaign']['rewards'];

        // Ten unclaimed uses at three each, under the policy that is now on the code.
        $this->assertSame(30, $rewards[self::OTHER_POLICY.'.'.bin2hex('SUNDAE')]);
        $this->assertArrayNotHasKey(self::POLICY.'.'.self::ASSET, $rewards);
    }

    /**
     * The export is where an operator actually reads the record back, so it has to carry
     * what was paid rather than what the code says now.
     */
    public function test_the_claims_export_carries_what_each_claim_was_paid(): void
    {
        $this->claimAndSend();

        $this->edit(['lovelace' => 12_000_000, 'tokens' => []]);

        $csv = $this->actingAs($this->user)
            ->get(route('campaigns.export-claims', $this->campaign->id))
            ->streamedContent();

        $this->assertStringContainsString('reward_lovelace', $csv);
        $this->assertStringContainsString('3000000', $csv);
        $this->assertStringContainsString(self::POLICY.'.'.self::ASSET.'=5', $csv);
        $this->assertStringNotContainsString('12000000', $csv, 'The export must not report the code as the payment.');
    }

    public function test_the_export_leaves_an_unrecorded_reward_blank(): void
    {
        $this->code->claims()->create([
            'address' => self::ADDRESS,
            'stake_key' => self::STAKE,
            'transaction_id' => 'legacy',
        ]);

        $csv = $this->actingAs($this->user)
            ->get(route('campaigns.export-claims', $this->campaign->id))
            ->streamedContent();

        $rows = array_values(array_filter(explode("\n", trim($csv))));
        $columns = str_getcsv($rows[1]);
        $header = str_getcsv($rows[0]);

        $this->assertSame('', $columns[array_search('reward_lovelace', $header, true)]);
        $this->assertSame('', $columns[array_search('reward_tokens', $header, true)]);
    }

    public function test_an_edit_that_clears_an_unpayable_code_clears_the_warning(): void
    {
        $bad = Code::factory()->for($this->campaign)->create(['lovelace' => 1_000_000, 'uses' => 5]);
        Reward::factory()->for($bad)->create([
            'policy_hex' => self::POLICY,
            'asset_hex' => self::ASSET,
            'quantity' => 1,
        ]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertInertia(fn ($page) => $page->where('min_utxo.below_minimum_codes', 1));

        $this->edit([
            'lovelace' => 5_000_000,
            'tokens' => [['policy_id' => self::POLICY, 'token_id' => self::ASSET, 'quantity' => 1]],
        ], $bad);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign->id))
            ->assertInertia(fn ($page) => $page->where('min_utxo.below_minimum_codes', 0));
    }
}
