<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CampaignAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Whether a campaign should be telling its operator it is running short.
 *
 * Nothing delivers these yet, so what is under test is the decision and the settings
 * behind it: when a campaign alerts, when it stays quiet, and what the threshold is
 * measured against.
 */
class CampaignAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Campaign $campaign;

    /** What the backend says is in the bucket, in the shape its balance call returns. */
    private array $liveUtxos = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Read at request time rather than pinned here, because a stub registered first
        // wins and a second Http::fake() in a test would never be reached.
        Http::fake(fn () => Http::response([
            'status' => 'ok',
            'data' => [null, ['liveUtxos' => $this->liveUtxos]],
        ]));
        Queue::fake();

        config([
            'pricing.enabled' => true,
            // The shipped ADA path: the campaign's own bucket pays for the reward, the
            // chain and the fee, so how many more claims it can serve is a question
            // about that bucket.
            'pricing.paths.ada.collection' => 'in_band',
            'pricing.paths.ada.in_band_rates' => ['platform_fee_lovelace' => 1000000],
            'cardano.network_fee_lovelace' => 200000,
        ]);

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        Wallet::factory()->for($this->campaign)->create();
    }

    /** The wallet's live UTxOs, in the shape the campaign page already fetched them. */
    private function bucket(int $lovelace): array
    {
        return [['lovelace' => (string) $lovelace, 'nativeAssets' => []]];
    }

    private function alerts(Campaign $campaign, array $bucket = []): array
    {
        return app(CampaignAlerts::class)->for($campaign->fresh()->load('codes'), $bucket);
    }

    /**
     * The dearest still-claimable code, not the average.
     *
     * An alert exists to be early. A bucket that covers the average and not the dearest
     * will fail on somebody, and the next person through the door might be holding either
     * code. Telling the operator afterwards is what this is meant to avoid.
     */
    public function test_the_remaining_claims_figure_uses_the_dearest_code_still_claimable(): void
    {
        Code::factory()->for($this->campaign)->create([
            'uses' => 100, 'perWallet' => 1, 'lovelace' => 1000000,
        ]);
        Code::factory()->for($this->campaign)->create([
            'uses' => 100, 'perWallet' => 1, 'lovelace' => 10000000,
        ]);

        $state = $this->alerts($this->campaign, $this->bucket(100000000));

        // 10 ADA of reward plus 1 ADA of fee plus 0.2 to the chain is 11.2 a claim, so
        // eight are certain out of a hundred ADA.
        $this->assertSame(8, $state['claims_remaining']);
        // The average would have promised fourteen and the cheapest code forty-five,
        // and both of those are a bucket that runs out on somebody.
        $this->assertNotSame(14, $state['claims_remaining']);
        $this->assertNotSame(45, $state['claims_remaining']);

        // Which is the whole point: the threshold decision turns on that figure. At the
        // default of ten, eight left alerts and fourteen would not have.
        $this->assertSame(10, $state['threshold']);
        $this->assertTrue($state['alerting']);
        $this->assertSame('low', $state['reason']);
    }

    /**
     * The self-hosted edition ships with no pricing configuration file at all, so every
     * config('pricing.*') lookup returns its own default instead of a real value read
     * from one. Billing enabled, a path configured, and a path that collects in band are
     * all false in that state, which used to be read as "this path collects from a
     * prepaid balance" and answered with a credit balance nobody has. The alert has to
     * fall back to the campaign's own bucket instead, the same figure the shipped ada
     * path already uses, because a self-hosted campaign never has a balance to consult.
     */
    public function test_claims_remaining_is_answered_from_the_bucket_when_pricing_is_not_configured_at_all(): void
    {
        config(['pricing' => []]);

        Code::factory()->for($this->campaign)->create([
            'uses' => 100, 'perWallet' => 1, 'lovelace' => 1000000,
        ]);
        Code::factory()->for($this->campaign)->create([
            'uses' => 100, 'perWallet' => 1, 'lovelace' => 10000000,
        ]);

        $state = $this->alerts($this->campaign, $this->bucket(100000000));

        // With no platform fee to add, the dearest claim is 10 ADA of reward plus 0.2 to
        // the chain: 10.2 ADA a claim, so nine are certain out of a hundred ADA.
        $this->assertSame(9, $state['claims_remaining']);
        $this->assertTrue($state['alerting']);
        $this->assertSame('low', $state['reason']);
    }

    /**
     * A code nobody can claim any more cannot be what the next claim costs, so it stops
     * setting the figure once its uses are gone.
     */
    public function test_a_code_that_is_used_up_stops_setting_the_per_claim_cost(): void
    {
        Code::factory()->for($this->campaign)->create([
            'uses' => 100, 'perWallet' => 1, 'lovelace' => 1000000,
        ]);
        $dear = Code::factory()->for($this->campaign)->create([
            'uses' => 1, 'perWallet' => 1, 'lovelace' => 10000000,
        ]);

        Claim::factory()->for($dear)->create();

        // 1 ADA of reward plus 1.2 of fees is 2.2 a claim once the dear code is spent.
        $this->assertSame(45, $this->alerts($this->campaign, $this->bucket(100000000))['claims_remaining']);
    }

    /**
     * End to end, from the balance the page actually fetched. The figure is computed from
     * the bucket the page already had rather than from a second call for it, so this is
     * the one that proves the two are the same bucket.
     */
    public function test_the_campaign_page_decides_the_alert_from_the_bucket_it_loaded(): void
    {
        $this->liveUtxos = $this->bucket(100000000);
        $this->campaign->wallet->forceFill(['backend' => 'phyrhose'])->save();

        Code::factory()->for($this->campaign)->create([
            'uses' => 100, 'perWallet' => 1, 'lovelace' => 10000000,
        ]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.claims_remaining', 8)
                ->where('alerts.alerting', true)
                ->where('alerts.reason', 'low')
                ->where('alerts.threshold', 10)
                ->where('alerts.held', 0)
            );
    }

    /**
     * Before a campaign starts there is nothing to be short of yet, and an operator
     * funding a bucket over the week before an event does not want chasing through it.
     * Held claims are the loudest case there is and are still not a reason to.
     */
    public function test_a_campaign_that_has_not_started_does_not_alert_even_with_claims_held(): void
    {
        $code = Code::factory()->for($this->campaign)->create(['uses' => 10, 'lovelace' => 1000000]);
        Claim::factory()->for($code)->create(['held_reason' => 'no_credit']);

        $this->campaign->update([
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $state = $this->alerts($this->campaign, $this->bucket(0));

        $this->assertSame('upcoming', $this->campaign->fresh()->status);
        $this->assertSame(1, $state['held']);
        $this->assertSame(0, $state['claims_remaining']);
        // The situation is reported as it is. Whether anybody should be told about it yet
        // is the separate decision, and before the campaign opens the answer is no.
        $this->assertSame('held', $state['reason']);
        $this->assertFalse($state['alerting']);
    }

    /**
     * An operator who has funded a bucket and wants to be left alone can say so, and it
     * has to hold for an empty bucket as well as for held claims.
     */
    public function test_alerts_turned_off_stay_off_however_short_the_bucket_is(): void
    {
        Code::factory()->for($this->campaign)->create(['uses' => 10, 'lovelace' => 1000000]);
        $this->campaign->forceFill(['alerts_enabled' => false])->save();

        $state = $this->alerts($this->campaign, $this->bucket(0));

        // Nothing left to serve, which is as short as a campaign gets.
        $this->assertSame(0, $state['claims_remaining']);
        $this->assertSame('exhausted', $state['reason']);
        $this->assertFalse($state['alerting']);
    }

    /**
     * The settings sit outside mass assignment with the money columns, so they move only
     * where a rule named them. An endpoint with no such rule leaves them alone rather
     * than taking whatever the request happened to carry.
     */
    public function test_alert_settings_are_ignored_where_no_rule_names_them(): void
    {
        $this->actingAs($this->user)
            ->post(route('campaigns.store'), [
                'name' => 'Booth drop',
                'description' => 'A campaign created with settings nothing asked for.',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'network' => 'preprod',
                'alerts_enabled' => false,
                'alert_threshold_claims' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $created = Campaign::where('name', 'Booth drop')->sole();

        $this->assertTrue((bool) $created->alerts_enabled);
        $this->assertNull($created->alert_threshold_claims);
    }
}
