<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeCampaignOnboarding;
use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignWalletInsight;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'activated' => true,
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->where('onboarding.status', CampaignAnalysis::STATUS_COMPLETE)
                ->where('onboarding.summary.new_wallets', 1)
                ->has('onboarding.wallets', 1)
            );
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
            'activated' => true,
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
