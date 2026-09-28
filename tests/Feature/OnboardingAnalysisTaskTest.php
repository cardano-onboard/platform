<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeCampaignOnboarding;
use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignTask;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Services\ClaimStatusChecker;
use App\Services\OnboardingAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * The onboarding analysis as a campaign task: what the operator can see while it runs.
 *
 * A run takes minutes and used to write nothing anyone could read until it was over. What
 * matters here is that the phase and the count are in the database while the work is still
 * going, because that is the only reason the page can show them.
 */
class OnboardingAnalysisTaskTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM_TX = 'aa11';

    private const LATER_TX = 'bb22';

    private const STAKE = 'stake1uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function ownedCampaignWithConfirmedClaim(): array
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->mainnet()->create(['user_id' => $user->id]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        Claim::factory()->create([
            'code_id' => $code->id,
            'address' => 'addr1q'.str_repeat('x', 50),
            'stake_key' => self::STAKE,
            'transaction_id' => 'tx-id',
            'transaction_hash' => self::CLAIM_TX,
            'status' => 'completed',
        ]);

        return [$user, $campaign];
    }

    private function task(Campaign $campaign): CampaignTask
    {
        return CampaignTask::claim(
            $campaign,
            AnalyzeCampaignOnboarding::TASK_TYPE,
            AnalyzeCampaignOnboarding::dedupeKey(),
        );
    }

    /** The service with its pacing off: the sleep between Koios calls is politeness to a live service. */
    private function service(): OnboardingAnalysisService
    {
        return new OnboardingAnalysisService(paced: false);
    }

    /**
     * The status check the run makes before it measures anything. Real rather than faked:
     * with no claim awaiting confirmation it is never reached, and the tests that do reach
     * it fake the backend it calls.
     */
    private function checker(): ClaimStatusChecker
    {
        return new ClaimStatusChecker;
    }

    public function test_requesting_an_analysis_claims_a_task_and_hands_the_job_its_id(): void
    {
        Queue::fake();

        [$user, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        $this->actingAs($user)
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertRedirect();

        $task = CampaignTask::where('campaign_id', $campaign->id)->sole();

        $this->assertSame(AnalyzeCampaignOnboarding::TASK_TYPE, $task->type);
        $this->assertSame(CampaignTask::DEFAULT_KEY, $task->dedupe_key);
        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertSame($user->id, $task->requested_by);
        $this->assertSame(1, $task->payload['eligible_claims']);

        Queue::assertPushed(
            AnalyzeCampaignOnboarding::class,
            fn ($job) => $job->campaign_id === $campaign->id && $job->task_id === $task->id,
        );
    }

    /**
     * The refusal has to happen in the request. Dispatching a second job would have it
     * dropped by WithoutOverlapping out on the worker, where there is nobody to tell, and
     * the operator would be told an analysis had started that never produced anything.
     */
    public function test_a_second_request_while_one_is_running_dispatches_nothing(): void
    {
        Queue::fake();

        [$user, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        $this->actingAs($user)->post(route('campaigns.analyze-onboarding', $campaign));

        CampaignTask::where('campaign_id', $campaign->id)->update([
            'status' => CampaignTask::STATUS_RUNNING,
            'heartbeat_at' => now(),
        ]);

        Queue::fake();

        $this->actingAs($user)
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertRedirect()
            ->assertSessionHas('message', fn ($message) => str_contains($message, 'already running'));

        Queue::assertNothingPushed();
        $this->assertSame(1, CampaignTask::where('campaign_id', $campaign->id)->count());
    }

    /**
     * A refused request must leave the previous result alone. Moving the analysis row back
     * to pending would blank a finished result on screen to describe a run that was never
     * started.
     */
    public function test_a_refused_request_does_not_erase_the_stored_result(): void
    {
        Queue::fake();

        [$user, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        CampaignAnalysis::create([
            'campaign_id' => $campaign->id,
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'completed_at' => now(),
            'summary' => ['genuine_wallets' => 3],
        ]);

        CampaignTask::factory()->for($campaign)->running()->create([
            'type' => AnalyzeCampaignOnboarding::TASK_TYPE,
        ]);

        $this->actingAs($user)->post(route('campaigns.analyze-onboarding', $campaign));

        $analysis = CampaignAnalysis::where('campaign_id', $campaign->id)->sole();

        $this->assertSame(CampaignAnalysis::STATUS_COMPLETE, $analysis->status);
        $this->assertSame(3, $analysis->summary['genuine_wallets']);
    }

    /** Re-running a finished analysis is the ordinary case: activation climbs for weeks afterwards. */
    public function test_a_finished_run_can_be_requested_again(): void
    {
        Queue::fake();

        [$user, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        $first = CampaignTask::factory()->for($campaign)->complete(['wallets' => 4])->create([
            'type' => AnalyzeCampaignOnboarding::TASK_TYPE,
        ]);

        $this->actingAs($user)
            ->post(route('campaigns.analyze-onboarding', $campaign))
            ->assertRedirect();

        $task = $first->refresh();

        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertNull($task->result, 'the new run starts with no result of its own');
        $this->assertNull($task->completed_at);
        Queue::assertPushed(AnalyzeCampaignOnboarding::class);
    }

    public function test_the_poll_endpoint_reports_the_run_and_the_prop_it_refreshes(): void
    {
        [$user, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        $task = CampaignTask::factory()->for($campaign)->running()->create([
            'type' => AnalyzeCampaignOnboarding::TASK_TYPE,
            'stage' => 'Reading wallet history',
            'progress_done' => 12,
            'progress_total' => 40,
        ]);

        $this->actingAs($user)
            ->getJson(route('campaigns.tasks', $campaign))
            ->assertOk()
            ->assertJsonPath('tasks.0.id', $task->id)
            ->assertJsonPath('tasks.0.type', AnalyzeCampaignOnboarding::TASK_TYPE)
            ->assertJsonPath('tasks.0.stage', 'Reading wallet history')
            ->assertJsonPath('tasks.0.progress_done', 12)
            ->assertJsonPath('tasks.0.progress_total', 40)
            // Only the onboarding prop. Naming the campaign here would make the page fetch
            // codes, claims and a wallet balance to redraw one panel.
            ->assertJsonPath('tasks.0.reloads', ['onboarding']);
    }

    public function test_the_campaign_page_hands_a_running_analysis_to_its_poller(): void
    {
        [$user, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        CampaignTask::factory()->for($campaign)->running()->create([
            'type' => AnalyzeCampaignOnboarding::TASK_TYPE,
            'stage' => 'Reading claim transactions',
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn ($page) => $page
                ->has('tasks.items', 1)
                ->where('tasks.items.0.type', AnalyzeCampaignOnboarding::TASK_TYPE)
                ->where('tasks.items.0.stage', 'Reading claim transactions')
                ->has('tasks.now')
            );
    }

    /**
     * The point of the whole change: the phase is readable while the work is still going.
     *
     * Each Koios fake reads the task row back out of the database at the moment it is
     * called, which is the same thing the page's poller does a moment later. Asserting the
     * phases after the run would prove nothing, because the run clears the phase when it
     * finishes.
     */
    public function test_the_run_names_each_phase_while_it_is_still_working(): void
    {
        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();
        $task = $this->task($campaign);

        $seen = [];
        $observe = function () use ($task, &$seen) {
            $row = CampaignTask::find($task->id);
            $seen[] = [$row->status, $row->stage, $row->progress_done, $row->progress_total];
        };

        Http::fake([
            '*tx_info*' => function ($request) use ($observe) {
                $observe();
                $hashes = $request->data()['_tx_hashes'] ?? [];

                return Http::response(in_array(self::CLAIM_TX, $hashes, true)
                    ? [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(4)->timestamp]]
                    : [[
                        'tx_hash' => self::LATER_TX,
                        'block_height' => 1100,
                        'inputs' => [['stake_addr' => self::STAKE]],
                        'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('y', 50)]]],
                    ]]);
            },
            '*account_txs*' => function () use ($observe) {
                $observe();

                return Http::response([
                    ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000],
                    ['tx_hash' => self::LATER_TX, 'block_height' => 1100],
                ]);
            },
            '*account_info*' => function () use ($observe) {
                $observe();

                return Http::response([['stake_address' => self::STAKE, 'delegated_pool' => 'pool1abc']]);
            },
        ]);

        (new AnalyzeCampaignOnboarding($campaign->id, $task->id))->handle($this->service(), $this->checker());

        $stages = array_column($seen, 1);

        $this->assertSame([
            'Reading claim transactions',
            'Reading wallet history',
            'Reading follow-up transactions',
            'Reading stake delegations',
        ], $stages);

        // Every one of those reads had the row saying "running", which is what stops the
        // page declaring the run dead and offering to start another.
        $this->assertSame([CampaignTask::STATUS_RUNNING], array_unique(array_column($seen, 0)));

        // The wallet loop is the only phase that counts, and it reports its denominator
        // before it makes its first call rather than after.
        $this->assertSame([0, 1], [$seen[1][2], $seen[1][3]]);
    }

    public function test_a_finished_run_records_what_it_read_and_clears_its_phase(): void
    {
        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();
        $task = $this->task($campaign);

        Http::fake([
            '*tx_info*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(4)->timestamp]]),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]),
            '*account_info*' => Http::response([]),
        ]);

        (new AnalyzeCampaignOnboarding($campaign->id, $task->id))->handle($this->service(), $this->checker());

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertNull($task->stage, 'a finished run has no phase to report');
        $this->assertSame(1, $task->progress_done);
        $this->assertSame(1, $task->progress_total);
        $this->assertSame(1, $task->result['wallets']);
        $this->assertNotNull($task->completed_at);

        $this->assertSame(
            CampaignAnalysis::STATUS_COMPLETE,
            CampaignAnalysis::where('campaign_id', $campaign->id)->value('status'),
        );
    }

    /**
     * A campaign deleted between the request and the worker picking the job up. Campaigns
     * are soft-deleted, so the task row outlives the campaign and would otherwise sit there
     * reading as running until the stale sweep reached it a quarter of an hour later.
     */
    public function test_a_run_whose_campaign_has_gone_fails_with_a_reason(): void
    {
        Http::fake();

        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();
        $task = $this->task($campaign);

        $campaign->delete();

        (new AnalyzeCampaignOnboarding($campaign->id, $task->id))->handle($this->service(), $this->checker());

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertSame('That campaign no longer exists.', $task->error);
        $this->assertNotNull($task->completed_at);
        Http::assertNothingSent();
    }

    /**
     * Declaring failed() on the job silently replaces the trait's. Both rows have to be
     * written: the task row is what the poller reads while somebody watches, the analysis
     * row is what the panel shows afterwards.
     */
    public function test_a_crashed_run_marks_both_the_analysis_and_the_task_failed(): void
    {
        Sleep::fake();

        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();
        $task = $this->task($campaign);

        Http::fake(function () {
            throw new RuntimeException('Koios is unreachable.');
        });

        $job = new AnalyzeCampaignOnboarding($campaign->id, $task->id);

        try {
            $job->handle($this->service(), $this->checker());
            $this->fail('the run should have thrown');
        } catch (RuntimeException $e) {
            // The queue calls this after the final attempt, which is where both rows get
            // their answer.
            $job->failed($e);
        }

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('Koios is unreachable.', $task->error);
        $this->assertNull($task->stage);
        $this->assertNotNull($task->completed_at);

        $analysis = CampaignAnalysis::where('campaign_id', $campaign->id)->sole();
        $this->assertSame(CampaignAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertStringContainsString('Koios is unreachable.', $analysis->error);
    }

    /**
     * The console command runs the service inline, with no task row anywhere. Every
     * reporting call has to be a no-op there, or a self-hosted install with no queue worker
     * could not run an analysis at all.
     */
    public function test_the_analysis_runs_with_no_task_row_at_all(): void
    {
        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        Http::fake([
            '*tx_info*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(4)->timestamp]]),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]),
            '*account_info*' => Http::response([]),
        ]);

        $analysis = $this->service()->analyze($campaign);

        $this->assertSame(CampaignAnalysis::STATUS_COMPLETE, $analysis->status);
        $this->assertSame(1, $analysis->wallets_analyzed);
        $this->assertSame(0, CampaignTask::where('campaign_id', $campaign->id)->count());
    }

    /** The command's queued path claims the same row, so a run started there is visible on the page. */
    public function test_the_console_command_queues_through_a_task_row(): void
    {
        Queue::fake();

        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        $this->artisan('onboard:analyze', ['campaign' => $campaign->id, '--queue' => true])
            ->assertSuccessful();

        $task = CampaignTask::where('campaign_id', $campaign->id)->sole();

        $this->assertSame(AnalyzeCampaignOnboarding::TASK_TYPE, $task->type);
        $this->assertNull($task->requested_by, 'nobody is signed in on the command line');

        Queue::assertPushed(
            AnalyzeCampaignOnboarding::class,
            fn ($job) => $job->task_id === $task->id,
        );
    }

    public function test_the_console_command_refuses_to_queue_a_second_run(): void
    {
        Queue::fake();

        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        CampaignTask::factory()->for($campaign)->running()->create([
            'type' => AnalyzeCampaignOnboarding::TASK_TYPE,
        ]);

        $this->artisan('onboard:analyze', ['campaign' => $campaign->id, '--queue' => true])
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    /**
     * Two campaigns analysing at once is ordinary. The unique key is per campaign, so one
     * operator's run must never be what stops another's from starting.
     */
    public function test_one_campaigns_analysis_does_not_hold_up_another(): void
    {
        Queue::fake();

        [$user, $first] = $this->ownedCampaignWithConfirmedClaim();
        [, $second] = $this->ownedCampaignWithConfirmedClaim();
        $second->update(['user_id' => $user->id]);

        CampaignTask::factory()->for($first)->running()->create([
            'type' => AnalyzeCampaignOnboarding::TASK_TYPE,
        ]);

        $this->actingAs($user)->post(route('campaigns.analyze-onboarding', $second));

        $this->assertSame(1, CampaignTask::where('campaign_id', $second->id)->count());
        Queue::assertPushed(AnalyzeCampaignOnboarding::class);
    }

    /** Another tenant's run is not readable, whatever the poll endpoint is asked for. */
    public function test_another_user_cannot_poll_an_analysis_they_do_not_own(): void
    {
        [, $campaign] = $this->ownedCampaignWithConfirmedClaim();

        CampaignTask::factory()->for($campaign)->running()->create([
            'type' => AnalyzeCampaignOnboarding::TASK_TYPE,
            'stage' => 'Reading wallet history',
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('campaigns.tasks', $campaign))
            ->assertForbidden();
    }
}
