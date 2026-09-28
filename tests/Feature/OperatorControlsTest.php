<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The three settings an operator sets for themselves: whether a campaign warns them when
 * it is running short, how much warning they want, and how much of their credit it may
 * spend.
 *
 * All three existed end to end in the backend before anything could reach them, so what
 * is under test here is the reachable half: what the page hands the controls, what the
 * endpoints accept, and what they refuse.
 */
class OperatorControlsTest extends TestCase
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
        Queue::fake();

        config([
            'pricing.enabled' => true,
            // A path billed in credits, because a limit is only ever consulted on one.
            // The shipped ADA path collects in band and is asserted on its own below.
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

    private function setLimit(mixed $credits)
    {
        return $this->actingAs($this->user)
            ->patch(route('campaigns.spend-limit', $this->campaign), [
                'spend_limit_credits' => $credits,
            ]);
    }

    /** The whole campaign, as the edit dialog has to send it back. */
    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->campaign->name,
            'description' => $this->campaign->description,
            'start_date' => $this->campaign->start_date,
            'end_date' => $this->campaign->end_date,
            'network' => $this->campaign->network,
        ], $overrides);
    }

    /**
     * The conversion is string arithmetic rather than a multiplication, because a credit
     * is millionths and a float cannot hold them. Every case here is one a float gets
     * wrong or one that shows what the precision is for.
     */
    public function test_a_figure_typed_in_credits_becomes_the_millionths_the_column_holds(): void
    {
        $this->assertSame(150000000, Pricing::creditsToMicro('150'));
        $this->assertSame(0, Pricing::creditsToMicro('0'));
        $this->assertSame(1, Pricing::creditsToMicro('0.000001'));
        $this->assertSame(1250000, Pricing::creditsToMicro('1.25'));

        $this->assertSame(8700000, Pricing::creditsToMicro('8.7'));
        $this->assertSame(2500500000, Pricing::creditsToMicro('2500.5'));

        // 1.005 times a million is 1004999.9999999999 in binary, so multiplying and
        // casting stores a limit a millionth of a credit below the one that was typed.
        // This is the case the string arithmetic exists for.
        $this->assertSame(1005000, Pricing::creditsToMicro('1.005'));
        $this->assertSame(1004999, (int) (1.005 * Pricing::MICRO));
    }

    /**
     * A ceiling is truncated rather than rounded, because rounding one up authorises
     * spending nobody asked for. The endpoint refuses the seventh place before it gets
     * here, so this is the guarantee behind that refusal rather than a path in use.
     */
    public function test_precision_past_a_credit_is_dropped_rather_than_rounded_up(): void
    {
        $this->assertSame(1999999, Pricing::creditsToMicro('1.9999999'));
    }

    /** What the control shows is what was typed, not the column's zero padding. */
    public function test_millionths_read_back_as_the_figure_that_was_typed(): void
    {
        $this->assertSame('150', Pricing::microToCredits(150000000));
        $this->assertSame('8.7', Pricing::microToCredits(8700000));
        $this->assertSame('0.000001', Pricing::microToCredits(1));
        $this->assertSame('0', Pricing::microToCredits(0));

        foreach (['0', '1', '8.7', '150', '0.000001', '2500.5'] as $typed) {
            $this->assertSame($typed, Pricing::microToCredits(Pricing::creditsToMicro($typed)));
        }
    }

    /**
     * The column sits outside mass assignment with the money columns, and adding a way to
     * set it must not change that. A value arriving where no rule names it is still
     * ignored, whether it is named as the column or as the figure the control sends.
     */
    public function test_a_spend_limit_is_ignored_at_an_endpoint_with_no_rule_for_it(): void
    {
        $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'name' => 'Booth drop',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'network' => 'preprod',
                'spend_limit_micro' => 999 * Pricing::MICRO,
                'spend_limit_credits' => '999',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull(Campaign::where('name', 'Booth drop')->sole()->spend_limit_micro);

        // And at the campaign edit endpoint, which does have rules, just not for this.
        $this->actingAs($this->user)
            ->patch(route('campaigns.update', $this->campaign), $this->editPayload([
                'spend_limit_micro' => 999 * Pricing::MICRO,
                'spend_limit_credits' => '999',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->campaign->fresh()->spend_limit_micro);
    }

    /**
     * A path whose fee comes out of the campaign's own bucket has been paid by the time a
     * claim is served, so a limit could not hold anything. The page says so and the
     * control is left out, the way the funding card omits a platform fee line where there
     * is no platform fee.
     */
    public function test_the_spend_limit_is_withheld_where_a_limit_could_not_bite(): void
    {
        config(['pricing.paths.ada.collection' => 'in_band']);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('spending.applies', false));

        config(['pricing.enabled' => false, 'pricing.paths.ada.collection' => 'credits']);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('spending.applies', false));
    }

    /**
     * The dialog has to seed its fields from what is stored rather than from what is in
     * force, or opening it and saving anything else would write the default threshold in
     * as though the operator had chosen it.
     */
    public function test_the_campaign_page_carries_the_alert_settings_as_they_are_stored(): void
    {
        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('campaign.alert_threshold_claims', null)
                // The figure in force is still reported beside it, and it is the default
                // rather than the stored value, which is exactly why the two are separate.
                ->where('alerts.threshold', 10)
            );

        $this->campaign->forceFill(['alert_threshold_claims' => 42, 'alerts_enabled' => false])->save();

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('campaign.alert_threshold_claims', 42)
                // A tinyint with no cast, so the page receives 0 and 1 rather than false
                // and true. The switch is bound to those values for that reason: bound to
                // a boolean it would show a campaign with alerts on as though they were off.
                ->where('campaign.alerts_enabled', 0)
            );
    }

    /** A threshold set and then cleared goes back to the default rather than to zero. */
    public function test_an_empty_threshold_puts_the_campaign_back_on_the_default(): void
    {
        $this->actingAs($this->user)
            ->patch(route('campaigns.update', $this->campaign), $this->editPayload([
                'alerts_enabled' => 1,
                'alert_threshold_claims' => 42,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(42, (int) $this->campaign->fresh()->alert_threshold_claims);

        $this->actingAs($this->user)
            ->patch(route('campaigns.update', $this->campaign), $this->editPayload([
                'alerts_enabled' => 1,
                'alert_threshold_claims' => null,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->campaign->fresh()->alert_threshold_claims);
    }

    /** Zero is a threshold an operator may mean, and is kept apart from having none. */
    public function test_a_threshold_of_zero_is_kept_rather_than_read_as_unset(): void
    {
        $this->actingAs($this->user)
            ->patch(route('campaigns.update', $this->campaign), $this->editPayload([
                'alerts_enabled' => 1,
                'alert_threshold_claims' => 0,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $this->campaign->fresh()->alert_threshold_claims);
        $this->assertNotNull($this->campaign->fresh()->alert_threshold_claims);
    }

    /** A threshold nothing could mean is refused rather than stored. */
    public function test_a_threshold_that_is_not_a_count_of_claims_is_refused(): void
    {
        $this->actingAs($this->user)
            ->patch(route('campaigns.update', $this->campaign), $this->editPayload([
                'alert_threshold_claims' => 'soon',
            ]))
            ->assertSessionHasErrors('alert_threshold_claims');

        $this->assertNull($this->campaign->fresh()->alert_threshold_claims);
    }
}
