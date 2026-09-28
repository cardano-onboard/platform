<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\AnalyzeCampaignOnboarding;
use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignTask;
use App\Models\CampaignWalletInsight;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OnboardingAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function campaignWithConfirmedClaim(User $owner): Campaign
    {
        $campaign = Campaign::factory()->create(['user_id' => $owner->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        Claim::factory()->completed()->create([
            'code_id' => $code->id,
            'stake_key' => 'stake1u'.str_repeat('a', 50),
        ]);

        return $campaign;
    }

    public function test_owner_can_queue_an_analysis(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        $this->actingAs($user)
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertRedirect();

        Queue::assertPushed(AnalyzeCampaignOnboarding::class,
            fn ($job) => $job->campaign_id === $campaign->id);

        $this->assertSame(
            CampaignAnalysis::STATUS_PENDING,
            CampaignAnalysis::where('campaign_id', $campaign->id)->value('status')
        );
    }

    public function test_another_user_cannot_queue_an_analysis_for_a_campaign_they_do_not_own(): void
    {
        Queue::fake();

        $campaign = $this->campaignWithConfirmedClaim(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_guest_cannot_queue_an_analysis(): void
    {
        Queue::fake();

        $campaign = $this->campaignWithConfirmedClaim(User::factory()->create());

        $this->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }

    /**
     * Nothing on chain means nothing to classify, and queueing a run that can only
     * report zero wastes a few hundred third-party queries.
     */
    public function test_a_campaign_with_no_confirmed_claims_does_not_queue_a_run(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);
        Claim::factory()->create(['code_id' => $code->id, 'transaction_hash' => null]);

        $this->actingAs($user)
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_the_campaign_page_carries_the_analysis_results(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'completed_at' => now(),
            'summary' => ['genuine_wallets' => 1, 'new_wallets' => 1, 'new_pct' => 100],
        ]);

        CampaignWalletInsight::create([
            'campaign_id' => $campaign->id,
            'stake_key' => 'stake1u'.str_repeat('a', 50),
            'is_new' => true,
            'activity_count' => 1,
            'windows_observed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.status', CampaignAnalysis::STATUS_COMPLETE)
                ->where('onboarding.summary.new_wallets', 1)
                ->where('onboarding.unread_wallets', 0)
                ->has('onboarding.wallets', 1)
            );
    }

    /**
     * A run the provider left holes in reaches the page as a partial result.
     *
     * The panel has to be able to say a wallet is unknown rather than show it as one that
     * was read and did nothing, so both the run's own state and the per-wallet nulls travel
     * with it.
     */
    public function test_the_campaign_page_carries_a_run_that_could_not_read_everything(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_PARTIAL,
            'completed_at' => now(),
            'windowed_at' => now(),
            'unread_wallets' => 2,
            'summary' => ['genuine_wallets' => 3, 'classified_wallets' => 1, 'unclassified_wallets' => 2],
        ]);

        CampaignWalletInsight::create([
            'campaign_id' => $campaign->id,
            'stake_key' => 'stake1u'.str_repeat('c', 50),
            'is_new' => null,
            'prior_tx_count' => null,
            'delegated' => null,
            'windows_observed_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.status', CampaignAnalysis::STATUS_PARTIAL)
                ->where('onboarding.unread_wallets', 2)
                ->where('onboarding.wallets.0.is_new', null)
                ->where('onboarding.wallets.0.delegated', null)
                ->where('onboarding.wallets.0.prior_tx_count', null)
                ->where('onboarding.wallets.0.windowed', false)
            );
    }

    /**
     * The gap this has to close: a claim gains its transaction hash when its status is
     * checked, so a campaign whose claims have never been checked has nothing to analyse
     * and everything to confirm. Refusing it would leave the operator with a button that
     * does nothing and no way to find out why.
     */
    public function test_a_campaign_whose_claims_are_all_unconfirmed_still_queues_a_run(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);
        Claim::factory()->count(3)->withTransaction()->create(['code_id' => $code->id]);

        $this->actingAs($user)
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertRedirect()
            ->assertSessionHas('message', fn ($message) => str_contains($message, '3 unconfirmed claim(s)'));

        Queue::assertPushed(AnalyzeCampaignOnboarding::class);

        // The run is claimed with what it is about to do, and the confirmation pass then
        // announces itself on that row as it happens. See OnboardingAnalysisTaskTest for
        // the phase the panel reads while the pass is running.
        $task = CampaignTask::where('campaign_id', $campaign->id)
            ->where('type', AnalyzeCampaignOnboarding::TASK_TYPE)
            ->sole();

        $this->assertSame(3, $task->payload['pending_claims']);
    }

    /**
     * A run with nothing to confirm says so, rather than telling the operator it is
     * checking claims it is not going to check.
     */
    public function test_a_run_with_nothing_outstanding_goes_straight_to_measuring(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        $this->actingAs($user)
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertSessionHas('message', fn ($message) => ! str_contains($message, 'unconfirmed'));

        $task = CampaignTask::where('campaign_id', $campaign->id)
            ->where('type', AnalyzeCampaignOnboarding::TASK_TYPE)
            ->sole();

        $this->assertSame(0, $task->payload['pending_claims']);
    }

    /**
     * Counting only claims a status check can still answer for. A failed claim will never
     * gain a hash, and nagging about it forever is worse than saying nothing.
     */
    public function test_the_panel_counts_only_claims_that_can_still_confirm(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        Claim::factory()->count(2)->withTransaction()->create(['code_id' => $code->id]);
        Claim::factory()->withTransaction()->failed()->create(['code_id' => $code->id]);
        Claim::factory()->completed()->create(['code_id' => $code->id]);
        // Never handed to the backend at all, so a status check has nothing to ask about.
        Claim::factory()->create(['code_id' => $code->id, 'transaction_id' => null]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.pending_claims', 2)
                ->where('onboarding.eligible_claims', 1)
                ->where('onboarding.total_claims', 5)
            );
    }

    /**
     * The result has to say what it did not measure. Percentages of the claims that
     * happened to be confirmed read exactly like percentages of the campaign.
     */
    public function test_the_result_records_how_much_of_the_campaign_it_covered(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        Claim::factory()->completed()->create([
            'code_id' => $code->id,
            'stake_key' => 'stake1u'.str_repeat('a', 50),
        ]);
        Claim::factory()->count(3)->withTransaction()->create(['code_id' => $code->id]);
        // Confirmed, but an enterprise address has no stake key and so no history to read.
        Claim::factory()->completed()->create(['code_id' => $code->id, 'stake_key' => '']);

        $summary = app(OnboardingAnalysisService::class)->summarize($campaign, collect());

        $this->assertSame(5, $summary['claims_total']);
        $this->assertSame(2, $summary['claims_confirmed']);
        $this->assertSame(1, $summary['claims_analyzable']);
        $this->assertSame(3, $summary['claims_unconfirmed']);
        $this->assertEquals(20.0, $summary['coverage_pct']);
    }

    public function test_a_date_range_reports_the_scoped_result_beside_the_whole_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'completed_at' => now(),
            'summary' => ['genuine_wallets' => 3, 'new_wallets' => 3, 'new_pct' => 100],
        ]);

        // Two wallets claimed at the event, one from a social post three weeks later.
        $this->insight($campaign, 'event-one', '2026-09-01 11:00:00', ['activity_count' => 1]);
        $this->insight($campaign, 'event-two', '2026-09-01 16:30:00', ['activity_count' => 1]);
        $this->insight($campaign, 'later-one', '2026-09-22 09:00:00');

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-09-01&onboarding_to=2026-09-01')
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.applied', true)
                ->where('onboarding.scope.summary.genuine_wallets', 2)
                ->where('onboarding.scope.summary.new_active', 2)
                // The stored summary goes on meaning the whole campaign.
                ->where('onboarding.summary.genuine_wallets', 3)
                ->where('onboarding.scope.wallets_total', 3)
            );
    }

    /**
     * A range is a question asked of stored rows. Nothing about it may be written, or the
     * campaign's own result would become whatever the last person to look at it asked for.
     */
    public function test_a_scoped_view_writes_nothing_and_makes_no_chain_calls(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);
        // A provisioned wallet, so the page has no bucket to create: what is being proved
        // is that nothing goes out for the range itself.
        Wallet::factory()->for($campaign)->create(['backend' => 'null']);

        $analysis = CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'completed_at' => now(),
            'summary' => ['genuine_wallets' => 2, 'new_wallets' => 2],
        ]);

        $this->insight($campaign, 'in-range', '2026-09-01 11:00:00');
        $this->insight($campaign, 'out-of-range', '2026-09-22 09:00:00');

        $before = $analysis->fresh()->only(['status', 'summary', 'completed_at', 'updated_at']);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-09-01&onboarding_to=2026-09-01')
            ->assertOk();

        $this->assertEquals($before, $analysis->fresh()->only(['status', 'summary', 'completed_at', 'updated_at']));
        $this->assertSame(1, CampaignAnalysis::where('campaign_id', $campaign->id)->count());
        $this->assertSame(2, $campaign->walletInsights()->count());
        Http::assertNothingSent();
    }

    public function test_the_per_wallet_table_lists_only_the_wallets_inside_the_range(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'summary' => ['genuine_wallets' => 2],
        ]);

        $this->insight($campaign, 'in-range', '2026-09-01 11:00:00');
        $this->insight($campaign, 'out-of-range', '2026-09-22 09:00:00');

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-09-01&onboarding_to=2026-09-01')
            ->assertInertia(fn ($page) => $page
                ->has('onboarding.wallets', 1)
                ->where('onboarding.wallets.0.stake_key', 'in-range')
            );
    }

    /**
     * The day named as the end of the range belongs inside it. Anything else silently
     * drops the last afternoon of an event, which is the part people care most about.
     */
    public function test_the_last_day_of_a_range_is_included_whole(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'summary' => ['genuine_wallets' => 1],
        ]);

        $this->insight($campaign, 'late-in-the-day', '2026-09-02 23:45:00');

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-09-02&onboarding_to=2026-09-02')
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.summary.claimants_total', 1)
            );
    }

    public function test_a_range_that_is_not_a_date_is_reported_rather_than_applied(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        $this->insight($campaign, 'somebody', '2026-09-01 11:00:00');

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=last%20tuesday')
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.applied', false)
                ->where('onboarding.scope.summary', null)
                ->whereNot('onboarding.scope.error', null)
                // The whole campaign is still there to read.
                ->has('onboarding.wallets', 1)
            );
    }

    /**
     * A date PHP would happily roll over into the following month is not the range
     * anybody asked for.
     */
    public function test_an_impossible_date_is_refused_rather_than_rolled_over(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-02-31')
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.applied', false)
                ->whereNot('onboarding.scope.error', null)
            );
    }

    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-09-10&onboarding_to=2026-09-01')
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.applied', false)
                ->where('onboarding.scope.error', 'The end of the range is before its start.')
            );
    }

    /**
     * Either end on its own is a question worth asking: everything since the event, or
     * everything up to the day it closed.
     */
    public function test_a_range_with_only_one_end_is_applied(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'summary' => ['genuine_wallets' => 2],
        ]);

        $this->insight($campaign, 'before', '2026-09-01 11:00:00');
        $this->insight($campaign, 'after', '2026-09-22 09:00:00');

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-09-10')
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.applied', true)
                ->where('onboarding.scope.summary.claimants_total', 1)
                ->has('onboarding.wallets', 1)
                ->where('onboarding.wallets.0.stake_key', 'after')
            );
    }

    /**
     * A row with no claim date cannot be placed in any range, so it is absent from every
     * scoped result. Said out loud rather than left to vanish.
     */
    public function test_wallets_with_no_claim_date_are_counted_where_they_cannot_be_ranged(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'summary' => ['genuine_wallets' => 2],
        ]);

        $this->insight($campaign, 'dated', '2026-09-01 11:00:00');
        $this->insight($campaign, 'undated', null);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign).'?onboarding_from=2026-09-01&onboarding_to=2026-09-01')
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.wallets_undated', 1)
                ->where('onboarding.scope.summary.claimants_total', 1)
                ->has('onboarding.wallets', 1)
            );
    }

    public function test_the_panel_offers_the_days_the_campaign_has_rows_for(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        $this->insight($campaign, 'first', '2026-09-01 11:00:00');
        $this->insight($campaign, 'last', '2026-09-22 09:00:00');

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.scope.bounds.first', '2026-09-01')
                ->where('onboarding.scope.bounds.last', '2026-09-22')
                ->where('onboarding.scope.applied', false)
            );
    }

    /**
     * The request the panel actually makes: a partial reload asking for the onboarding
     * prop alone. The range travels in the query string, so a scoped view can be linked
     * to, and the rest of a campaign page that carries a wallet balance and a cost
     * statement is not rebuilt to answer a question about a date.
     */
    public function test_the_panel_can_ask_for_a_range_on_its_own(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignWithConfirmedClaim($user);

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'summary' => ['genuine_wallets' => 2],
        ]);

        $this->insight($campaign, 'in-range', '2026-09-01 11:00:00');
        $this->insight($campaign, 'out-of-range', '2026-09-22 09:00:00');

        // The asset version the page would be served with. A request quoting a stale one
        // is answered with a full refresh instead of the props, so it has to be asked for
        // rather than assumed.
        $version = app(HandleInertiaRequests::class)->version(request());

        $response = $this->actingAs($user)->get(
            route('campaigns.show', $campaign).'?onboarding_from=2026-09-01&onboarding_to=2026-09-01',
            [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'Campaign/Show',
                'X-Inertia-Partial-Data' => 'onboarding',
            ]
        );

        $response->assertOk();

        $props = $response->json('props');

        // The panel comes back scoped, and the rest of the page does not come back at all.
        // Shared props travel with every Inertia response and are not the page's own.
        $this->assertArrayHasKey('onboarding', $props);
        $this->assertArrayNotHasKey('campaign', $props);
        $this->assertArrayNotHasKey('stats', $props);
        $this->assertTrue($props['onboarding']['scope']['applied']);
        $this->assertSame(1, $props['onboarding']['scope']['summary']['claimants_total']);
    }

    /**
     * The self-hosted path to the same answer. A range costs nothing to ask, so an
     * install with no queue worker can re-scope a campaign it has already analysed
     * without spending its rate limit again.
     */
    public function test_the_command_reports_a_range_from_stored_results(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->create(['name' => 'Conference drop']);
        $this->insight($campaign, 'event-one', '2026-09-01 11:00:00');
        $this->insight($campaign, 'later-one', '2026-09-22 09:00:00');

        $this->artisan('onboard:analyze', [
            'campaign' => $campaign->id,
            '--from' => '2026-09-01',
            '--to' => '2026-09-01',
            '--scope-only' => true,
        ])
            ->expectsOutputToContain('claims from 2026-09-01 to 2026-09-01')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, CampaignAnalysis::where('campaign_id', $campaign->id)->count());
    }

    /**
     * The rate the command prints and the population it names are the same one.
     *
     * The NEW rate is a share of the new wallets a windowed run read. Printed against the
     * count of every wallet read, the row said three of ten was fifty percent.
     */
    public function test_the_command_prints_each_rate_against_its_own_population(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->create(['name' => 'Conference drop']);

        foreach (range(1, 6) as $i) {
            $this->insight($campaign, "new-{$i}", '2026-09-01 11:00:00', [
                'is_new' => true,
                'activity_count' => $i <= 3 ? 1 : 0,
                'first_activity_seconds' => $i <= 3 ? 86400 : null,
            ]);
        }

        foreach (range(1, 4) as $i) {
            $this->insight($campaign, "established-{$i}", '2026-09-01 11:00:00', ['is_new' => false]);
        }

        $this->artisan('onboard:analyze', [
            'campaign' => $campaign->id,
            '--from' => '2026-09-01',
            '--scope-only' => true,
        ])
            ->expectsOutputToContain('3 of 6 read (50%)')
            ->assertSuccessful();
    }

    /**
     * Every row the command prints carries the population its rate was taken over.
     *
     * The delegation row named the count of new wallets instead of the count the account
     * read answered for. With six new wallets and two of them read, the row said "2 of 6
     * (100%)": the percentage is right, the denominator belongs to a different question,
     * and the operator reading the console sees two out of six labelled a hundred percent.
     */
    public function test_the_command_prints_the_delegation_rate_against_the_accounts_that_were_read(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->create(['name' => 'Conference drop']);

        // Six new wallets. The account read answered for two of them and failed on the
        // other four, which is why their delegation is null rather than false.
        foreach (range(1, 6) as $i) {
            $this->insight($campaign, "new-{$i}", '2026-09-01 11:00:00', [
                'is_new' => true,
                'delegated' => $i <= 2 ? true : null,
            ]);
        }

        foreach (range(1, 4) as $i) {
            $this->insight($campaign, "established-{$i}", '2026-09-01 11:00:00', [
                'is_new' => false,
                'delegated' => null,
            ]);
        }

        $this->artisan('onboard:analyze', [
            'campaign' => $campaign->id,
            '--from' => '2026-09-01',
            '--scope-only' => true,
        ])
            ->expectsOutputToContain('2 of 2 read (100%)')
            ->doesntExpectOutputToContain('2 of 6 read (100%)')
            ->assertSuccessful();
    }

    /**
     * A rate over a population nobody read is reported as unknown, on every row.
     *
     * A denominator of zero came out of the percentage helper as 0.0, and the console
     * printed "0 of 0 read (0%)" for wallets, activity, delegation and contracts alike. A
     * reader cannot tell that from a campaign that was read and did nothing.
     */
    public function test_the_command_says_unknown_rather_than_zero_percent_of_nobody(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->create(['name' => 'Conference drop']);

        // Two wallets the provider never answered for: nothing about them was read.
        foreach (range(1, 2) as $i) {
            $this->insight($campaign, "unread-{$i}", '2026-09-01 11:00:00', [
                'is_new' => null,
                'prior_tx_count' => null,
                'delegated' => null,
                'windows_observed_at' => null,
                'observed_seconds' => null,
            ]);
        }

        $this->artisan('onboard:analyze', [
            'campaign' => $campaign->id,
            '--from' => '2026-09-01',
            '--scope-only' => true,
        ])
            ->expectsOutputToContain('unknown (none read)')
            ->doesntExpectOutputToContain('0 of 0 read (0%)')
            ->doesntExpectOutputToContain('~0 days')
            ->assertSuccessful();
    }

    /**
     * A range nobody claimed in is a third state, and the console has it too.
     *
     * A full table of zeros and a windows table of zeros says the campaign onboarded
     * nobody and nobody did anything, which is a measurement. What happened is that no
     * wallet falls in the range, and re-running the analysis would not change it.
     */
    public function test_the_command_reports_an_empty_range_rather_than_a_table_of_zeros(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->create(['name' => 'Conference drop']);
        $this->insight($campaign, 'claimed-in-september', '2026-09-01 11:00:00');

        $this->artisan('onboard:analyze', [
            'campaign' => $campaign->id,
            '--from' => '2026-10-01',
            '--to' => '2026-10-31',
            '--scope-only' => true,
        ])
            ->expectsOutputToContain('No claimant wallets fall in this result')
            ->doesntExpectOutputToContain('Follow-up windows')
            ->doesntExpectOutputToContain('Newly onboarded (no prior tx)')
            ->assertSuccessful();
    }

    /**
     * The command describes itself by what it measures now.
     *
     * "Activation" was the retired figure, which counted a wallet whose only act was
     * delegating. Unlike the remaining mentions of it this one is printed by `artisan
     * list` and ships in the published edition.
     */
    public function test_the_command_description_does_not_name_the_retired_metric(): void
    {
        $description = (string) $this->app[\Illuminate\Contracts\Console\Kernel::class]
            ->all()['onboard:analyze']
            ->getDescription();

        $this->assertStringNotContainsStringIgnoringCase('activation', $description);
        $this->assertStringContainsString('new or established', $description);
    }

    public function test_the_command_refuses_a_date_it_cannot_read(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->create();

        $this->artisan('onboard:analyze', [
            'campaign' => $campaign->id,
            '--from' => 'last tuesday',
            '--scope-only' => true,
        ])->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * Scoping to no range at all is the whole campaign, which the command already
     * reports. Accepting it would produce a second table saying the same thing.
     */
    public function test_the_command_refuses_a_scope_with_no_range(): void
    {
        Http::fake();

        $campaign = Campaign::factory()->create();

        $this->artisan('onboard:analyze', [
            'campaign' => $campaign->id,
            '--scope-only' => true,
        ])->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * A stored per-wallet row, as a windowed run would have written it.
     *
     * `windows_observed_at` is set by default, because a row without it is one no windowed
     * run has read and the panel reports it as unknown rather than as a wallet that did
     * nothing. A test wanting that state passes it as null.
     */
    private function insight(Campaign $campaign, string $stakeKey, ?string $claimedAt, array $attributes = []): CampaignWalletInsight
    {
        $observedAt = array_key_exists('windows_observed_at', $attributes)
            ? $attributes['windows_observed_at']
            : ($claimedAt ? Carbon::parse($claimedAt)->addDays(120) : null);

        return CampaignWalletInsight::create($attributes + [
            'campaign_id' => $campaign->id,
            'stake_key' => $stakeKey,
            'claimed_at' => $claimedAt,
            'is_new' => true,
            'activity_count' => 0,
            'delegation_events' => 0,
            'windows_observed_at' => $observedAt,
            'observed_seconds' => ($claimedAt && $observedAt)
                ? Carbon::parse($observedAt)->getTimestamp() - Carbon::parse($claimedAt)->getTimestamp()
                : null,
        ]);
    }

    public function test_owner_can_export_claimed_addresses(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'code' => 'TESTCODE']);

        $claim = Claim::factory()->completed()->create([
            'code_id' => $code->id,
            'address' => 'addr1q'.str_repeat('x', 50),
            'stake_key' => 'stake1u'.str_repeat('a', 50),
        ]);

        CampaignWalletInsight::create([
            'campaign_id' => $campaign->id,
            'stake_key' => $claim->stake_key,
            'is_new' => true,
            'activity_count' => 1,
            'windows_observed_at' => now(),
            'delegated' => true,
            'pool_id' => 'pool1abc',
            'prior_tx_count' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('campaigns.export-claims', $campaign));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('wallet_classification', $csv);
        $this->assertStringContainsString($claim->address, $csv);
        $this->assertStringContainsString('TESTCODE', $csv);
        $this->assertStringContainsString('new', $csv);
        $this->assertStringContainsString('pool1abc', $csv);
    }

    /**
     * A wallet the chain read failed on exports as blank, not as an established wallet
     * that never delegated.
     *
     * The row exists, because the wallet claimed. What it says about the wallet is nothing,
     * because nothing about the wallet was read, and a default written into those cells
     * would be indistinguishable from a measurement.
     */
    public function test_export_leaves_a_wallet_the_chain_read_failed_on_blank(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        $claim = Claim::factory()->completed()->create([
            'code_id' => $code->id,
            'address' => 'addr1q'.str_repeat('x', 50),
            'stake_key' => 'stake1u'.str_repeat('b', 50),
        ]);

        CampaignWalletInsight::create([
            'campaign_id' => $campaign->id,
            'stake_key' => $claim->stake_key,
            'is_new' => null,
            'prior_tx_count' => null,
            'delegated' => null,
            'windows_observed_at' => null,
            'analyzed_at' => now(),
        ]);

        $csv = $this->actingAs($user)
            ->get(route('campaigns.export-claims', $campaign))
            ->streamedContent();

        $row = array_values(array_filter(explode("\n", trim($csv))))[1];

        $this->assertStringContainsString($claim->address, $row);
        // Classification through pool id: every cell the analysis fills is empty.
        $this->assertStringEndsWith(',,,,,,,,', trim($row));
        $this->assertStringNotContainsString('established', $row);
    }

    /**
     * An un-analyzed campaign still exports its claims; the classification columns are
     * simply blank. A blank cell is honest where a default value would not be.
     */
    public function test_export_leaves_classification_blank_when_no_analysis_has_run(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);
        $claim = Claim::factory()->completed()->create(['code_id' => $code->id]);

        $csv = $this->actingAs($user)
            ->get(route('campaigns.export-claims', $campaign))
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString($claim->address, $lines[1]);
        $this->assertStringEndsWith(',,,,', trim($lines[1]));
    }

    public function test_another_user_cannot_export_claims_from_a_campaign_they_do_not_own(): void
    {
        $campaign = $this->campaignWithConfirmedClaim(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get(route('campaigns.export-claims', $campaign))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_export_claims(): void
    {
        $campaign = $this->campaignWithConfirmedClaim(User::factory()->create());

        $this->get(route('campaigns.export-claims', $campaign))
            ->assertRedirect(route('login'));
    }
}
