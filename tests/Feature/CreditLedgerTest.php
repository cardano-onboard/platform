<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\CreditTransaction;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedger;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const ADDRESS_2 = 'addr1xxgx3far7qygq0k6epa0zcvcvrevmn0ypsnfsue94nsn3tfvjel5h55fgjcxgchp830r7h2l5msrlpt8262r3nvr8eks2utwdd';

    private User $user;

    private Campaign $campaign;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        // The queue is faked because nothing here is about sending a transaction, and a
        // real dispatch leaves an object that runs when it is destroyed, which lands
        // after this class has finished and its database is gone. Release still asserts
        // that it queued the campaigns it released.
        Queue::fake();
        config([
            'pricing.enabled' => true,
            // The credit machinery is exercised on a path configured to use credits. The
            // shipped ADA path collects in band, and that behaviour is asserted on its
            // own below rather than being the backdrop to everything else.
            'pricing.paths.ada.collection' => 'credits',
        ]);

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
        ]);
        Wallet::factory()->for($this->campaign)->create();
        $this->code = Code::factory()->for($this->campaign)->create([
            'uses' => 10000,
            'perWallet' => 100,
            'lovelace' => 2000000,
        ]);
    }

    private function claim(string $address = self::ADDRESS)
    {
        return $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => $address,
        ]);
    }

    private function ledger(): CreditLedger
    {
        return app(CreditLedger::class);
    }

    /**
     * A deployment that is not charging has no balance to run out of. Without this every
     * development and staging box would hold every claim it took, for a fee nobody
     * intended to collect.
     */
    public function test_a_deployment_that_is_not_billing_never_holds_a_claim(): void
    {
        config(['pricing.enabled' => false]);

        $this->claim()->assertJson(['status' => 'accepted']);

        $this->assertNull(Claim::first()->held_reason);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_a_pack_cannot_be_sold_on_a_path_with_no_rates(): void
    {
        config(['pricing.paths.usd.tiers' => []]);

        $this->expectException(\RuntimeException::class);

        $this->ledger()->grant($this->user, 100, null, 'usd');
    }

    /**
     * The billing controls are outside $fillable on purpose, the same rule the money
     * columns and the admin flag follow. Nothing a request carries can reach them.
     */
    public function test_a_spend_limit_cannot_be_mass_assigned(): void
    {
        $this->campaign->update(['spend_limit_micro' => 999 * Pricing::MICRO, 'billing_path' => 'usd']);

        $this->assertNull($this->campaign->fresh()->spend_limit_micro);
        $this->assertNull($this->campaign->fresh()->billing_path);
    }

    /** A campaign may override its account, for a customer running both kinds of event. */
    public function test_a_campaign_overrides_its_account_path(): void
    {
        $this->user->forceFill(['billing_path' => 'ada'])->save();
        $this->campaign->forceFill(['billing_path' => 'usd'])->save();

        $this->assertSame('usd', Pricing::pathFor($this->campaign->fresh()));
        $this->assertSame('ada', Pricing::pathFor($this->user->fresh()));
    }

    /**
     * An in-band path has no balance to run out of, so nothing queues. The funding bucket
     * is the control there, and a claim it cannot cover fails on chain as it always has.
     */
    public function test_an_in_band_path_never_holds_a_claim(): void
    {
        config(['pricing.paths.ada.collection' => 'in_band']);

        $this->claim()->assertJson(['status' => 'accepted']);

        $this->assertNull(Claim::first()->held_reason);
    }

    public function test_a_spend_limit_does_not_apply_to_an_in_band_path(): void
    {
        config(['pricing.paths.ada.collection' => 'in_band']);

        $this->campaign->forceFill(['spend_limit_micro' => 1])->save();

        $this->claim()->assertJson(['status' => 'accepted']);

        $this->assertNull(Claim::first()->held_reason);
    }

    /**
     * The operator has to be able to say what a campaign cost them, and the two figures
     * are not the same kind of money: one is a payment to us, the other goes to the chain.
     */
    public function test_an_in_band_claim_records_what_it_cost_the_bucket(): void
    {
        config([
            'pricing.paths.ada.collection' => 'in_band',
            'pricing.paths.ada.in_band_rates' => [
                'platform_fee_lovelace' => 1000000,
                'network_fee_lovelace' => 200000,
            ],
        ]);

        $this->claim()->assertJson(['status' => 'accepted']);

        $claim = Claim::first();
        $this->assertSame(1000000, (int) $claim->revenue_lovelace);
        $this->assertSame(200000, (int) $claim->network_fee_lovelace);
    }

    /**
     * A self-hoster pays us nothing, so the fee line is absent rather than zero. What the
     * chain took and what they gave away are real for them and stay, because the tax
     * reasoning is the same.
     */
    public function test_a_deployment_with_no_pricing_reports_costs_without_a_platform_fee(): void
    {
        config(['pricing.paths' => []]);

        $this->claim();

        $costs = app(\App\Services\CampaignCosts::class)->for($this->campaign);

        $this->assertNull($costs['platform_fee_lovelace']);
        $this->assertArrayHasKey('network_fee_lovelace', $costs);
        $this->assertArrayHasKey('reward_lovelace', $costs);
    }

    /**
     * A drop that mints a serial for each recipient has one asset per claim. Collapsed to
     * a policy on the page, with every asset still reachable through the export.
     */
    public function test_assets_collapse_by_policy_and_the_export_carries_every_one(): void
    {
        $policy = str_repeat('ab', 28);

        foreach (['4e4654303031', '4e4654303032', '4e4654303033'] as $assetHex) {
            Reward::factory()->for($this->code)->create([
                'policy_hex' => $policy,
                'asset_hex' => $assetHex,
                'quantity' => 1,
            ]);
        }

        $this->claim();

        $service = app(\App\Services\CampaignCosts::class);
        $costs = $service->for($this->campaign);

        $this->assertCount(1, $costs['policies']);
        $this->assertSame(3, $costs['policies'][0]['assets']);
        $this->assertSame($policy, $costs['policies'][0]['policy_hex']);
        // No single name stands for a policy holding three assets, and picking one would
        // misdescribe what was given away.
        $this->assertNull($costs['policies'][0]['asset_name']);
        $this->assertSame(3, $costs['distinct_assets']);

        $rows = iterator_to_array($service->assetRows($this->campaign));

        $this->assertCount(3, $rows);
        $this->assertSame('NFT001', $rows[0]['asset_name']);
    }

    public function test_the_asset_export_downloads_as_csv(): void
    {
        Reward::factory()->for($this->code)->create([
            'policy_hex' => str_repeat('cd', 28),
            'asset_hex' => '4e4654303031',
            'quantity' => 1,
        ]);

        $this->claim();

        $this->actingAs($this->user)
            ->get(route('campaigns.export-costs', $this->campaign))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    /** An in-band path is what a fee-taking backend is for, so those always agree. */
    public function test_an_in_band_path_agrees_with_a_fee_taking_backend(): void
    {
        config(['pricing.paths.ada.collection' => 'in_band']);

        $this->assertTrue(Pricing::backendAgreesWithPath('phyrhose', 'ada'));
    }

    /**
     * Three separate conditions, kept apart so whoever has to fix it is told which one is
     * missing rather than that the path is unavailable.
     */
    public function test_a_path_reports_which_condition_is_missing(): void
    {
        config([
            'pricing.paths.usd.enabled' => false,
            'pricing.paths.usd.requires_merchant' => true,
            'pricing.paths.usd.merchant' => null,
            'pricing.paths.usd.tiers' => [],
        ]);

        $this->assertStringContainsString('switched off', Pricing::pathUnavailableReason('usd'));

        config(['pricing.paths.usd.enabled' => true]);
        $this->assertStringContainsString('no merchant connected', Pricing::pathUnavailableReason('usd'));

        config(['pricing.paths.usd.merchant' => 'a-connected-merchant']);
        $this->assertStringContainsString('no rates', Pricing::pathUnavailableReason('usd'));

        config(['pricing.paths.usd.tiers' => [1 => '0.680000']]);
        $this->assertNull(Pricing::pathUnavailableReason('usd'));
        $this->assertTrue(Pricing::pathEnabled('usd'));
    }

    public function test_a_pack_cannot_be_sold_on_a_path_that_is_switched_off(): void
    {
        config(['pricing.paths.ada.enabled' => false]);

        $this->expectExceptionMessageMatches('/switched off/');

        $this->ledger()->grant($this->user, 100, null, 'ada');
    }

    /**
     * The top-up figure has always bundled rewards, network fees and the platform fee
     * into one number, so an operator asked to send ADA could not see how much of it
     * reached claimants. They are three different things and the last does not exist on
     * every deployment.
     */
    public function test_the_funding_estimate_says_what_the_ada_is_for(): void
    {
        config([
            'pricing.paths.ada.collection' => 'in_band',
            'pricing.paths.ada.in_band_rates' => ['platform_fee_lovelace' => 1000000],
            'cardano.network_fee_lovelace' => 200000,
        ]);

        // Ten uses, none claimed, two ADA each.
        $this->code->update(['uses' => 10, 'lovelace' => 2000000]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('funding.remaining_claims', 10)
                ->where('funding.reward_lovelace', 20000000)
                ->where('funding.network_fee_lovelace', 2000000)
                ->where('funding.platform_fee_lovelace', 10000000)
            );
    }

    /**
     * A deployment that does not charge shows no platform fee line, but still has to fund
     * the rewards and the chain.
     */
    public function test_the_funding_estimate_omits_a_platform_fee_where_there_is_none(): void
    {
        config(['pricing.paths' => [], 'cardano.network_fee_lovelace' => 200000]);

        $this->code->update(['uses' => 4, 'lovelace' => 1000000]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('funding.platform_fee_lovelace', null)
                ->where('funding.network_fee_lovelace', 800000)
                ->where('funding.reward_lovelace', 4000000)
            );
    }

    /** The chain charges a self-hoster too, so the fee is recorded whoever is running it. */
    public function test_a_network_fee_is_recorded_even_with_no_pricing_at_all(): void
    {
        config(['pricing.paths' => [], 'cardano.network_fee_lovelace' => 200000]);

        $this->claim();

        $claim = Claim::first();
        $this->assertSame(200000, (int) $claim->network_fee_lovelace);
        $this->assertNull($claim->revenue_lovelace);
    }

    private function alerts(): \App\Services\CampaignAlerts
    {
        return app(\App\Services\CampaignAlerts::class);
    }

    /**
     * Before a campaign starts there is nothing to be short of yet, and an operator
     * funding a bucket over the week before an event does not want chasing through it.
     */
    public function test_a_campaign_that_has_not_started_does_not_alert(): void
    {
        $this->campaign->update([
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $state = $this->alerts()->for($this->campaign->fresh());

        $this->assertSame('upcoming', $this->campaign->fresh()->status);
        $this->assertFalse($state['alerting']);
    }

    /**
     * After it ends nothing more will be claimed, so a shortfall has stopped mattering and
     * a warning about it arrives after the fact.
     */
    public function test_a_campaign_that_has_ended_does_not_alert(): void
    {
        $this->claim();
        $this->campaign->update(['end_date' => now()->subDay()->toDateString()]);

        $state = $this->alerts()->for($this->campaign->fresh());

        $this->assertSame('ended', $this->campaign->fresh()->status);
        $this->assertFalse($state['alerting']);
    }

    /** An operator who wants to fund it once and leave it alone can say so. */
    public function test_alerts_can_be_turned_off_entirely(): void
    {
        $this->claim();
        $this->campaign->forceFill(['alerts_enabled' => false])->save();

        $this->assertFalse($this->alerts()->for($this->campaign->fresh())['alerting']);
    }

    public function test_the_threshold_is_the_operators_to_set(): void
    {
        $this->actingAs($this->user)
            ->put(route('campaigns.update', $this->campaign), [
                'name' => $this->campaign->name,
                'description' => $this->campaign->description,
                'start_date' => $this->campaign->start_date,
                'end_date' => $this->campaign->end_date,
                // Required while the campaign has no claims, and a validation failure
                // redirects too, so leaving it out would let this pass while saving
                // nothing.
                'network' => $this->campaign->network,
                'alerts_enabled' => false,
                'alert_threshold_claims' => 42,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $campaign = $this->campaign->fresh();

        $this->assertFalse((bool) $campaign->alerts_enabled);
        $this->assertSame(42, (int) $campaign->alert_threshold_claims);
    }

    /** The settings sit outside mass assignment with the money columns, same reason. */
    public function test_alert_settings_cannot_be_mass_assigned(): void
    {
        $this->campaign->update(['alerts_enabled' => false, 'alert_threshold_claims' => 999]);

        $this->assertTrue((bool) $this->campaign->fresh()->alerts_enabled);
        $this->assertNull($this->campaign->fresh()->alert_threshold_claims);
    }

    /**
     * Billing on, and the shipped ADA path still holds nothing and writes nothing at all.
     * The fee came out of the campaign's own bucket as the claim was sent, so there is no
     * credit to debit and no balance to run out of. The outcome is the same as a
     * deployment that is not billing, for an entirely different reason, and the two states
     * are worth being able to tell apart.
     */
    public function test_billing_on_with_an_in_band_path_writes_no_ledger_row(): void
    {
        config(['pricing.paths.ada.collection' => 'in_band']);

        $this->assertTrue(Pricing::billingEnabled(), 'guard precondition: this is the billing-on case');

        $this->claim()->assertJson(['status' => 'accepted']);

        $claim = Claim::first();
        $this->assertNull($claim->held_reason);
        $this->assertNull($claim->credits_micro);
        $this->assertSame(0, CreditTransaction::count());
    }
}
