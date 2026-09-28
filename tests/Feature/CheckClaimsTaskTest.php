<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * The Check claims button as a campaign task.
 *
 * The button used to queue the check and tell the operator to refresh the page. It now
 * claims a task row the page's poller watches, so the page shows the check while it runs
 * and reloads the claims when it finishes.
 */
class CheckClaimsTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['backend' => 'phyrhose']);
    }

    private function pendingClaim(): Claim
    {
        $code = Code::factory()->for($this->campaign)->create();

        return Claim::factory()->for($code)->withTransaction()->create(['status' => 'pending']);
    }

    private function task(): CampaignTask
    {
        return CampaignTask::claim($this->campaign, CheckClaims::TASK_TYPE, CheckClaims::dedupeKey());
    }

    private function backendConfirms(string $hash = 'abc123hash'): void
    {
        Http::fake([
            '*purchaseStatus*' => Http::response([
                'status' => 'ok',
                'data' => [null, ['status' => 'completed', 'txId' => $hash]],
            ]),
        ]);
    }

    public function test_pressing_the_button_claims_a_task_and_hands_the_job_its_id(): void
    {
        Queue::fake();

        $this->pendingClaim();
        $this->pendingClaim();

        $this->actingAs($this->user)
            ->post(route('campaigns.check-claims', $this->campaign))
            ->assertRedirect()
            ->assertSessionHas('message', fn ($message) => str_contains($message, 'Checking 2 pending claim(s)')
                && ! str_contains($message, 'Refresh'));

        $task = CampaignTask::where('campaign_id', $this->campaign->id)->sole();

        $this->assertSame(CheckClaims::TASK_TYPE, $task->type);
        $this->assertSame(CampaignTask::DEFAULT_KEY, $task->dedupe_key);
        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertSame($this->user->id, $task->requested_by);
        $this->assertSame(2, $task->payload['pending_claims']);

        Queue::assertPushed(
            CheckClaims::class,
            fn ($job) => $job->campaign_id === $this->campaign->id && $job->task_id === $task->id,
        );
    }

    public function test_the_page_hands_the_running_check_to_its_poller(): void
    {
        Queue::fake();

        $this->pendingClaim();

        $this->actingAs($this->user)->post(route('campaigns.check-claims', $this->campaign));

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertInertia(fn ($page) => $page
                ->where('tasks.items.0.type', CheckClaims::TASK_TYPE)
                ->where('tasks.items.0.status', CampaignTask::STATUS_QUEUED)
                ->where('tasks.items.0.reloads', ['campaign', 'onboarding'])
                ->etc());
    }

    public function test_the_poll_endpoint_names_the_props_a_finished_check_changes(): void
    {
        CampaignTask::factory()->for($this->campaign)->complete(['checked' => 1])->create([
            'type' => CheckClaims::TASK_TYPE,
        ]);

        $this->actingAs($this->user)
            ->getJson(route('campaigns.tasks', [$this->campaign, 'since' => now()->subMinute()->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('tasks.0.type', CheckClaims::TASK_TYPE)
            ->assertJsonPath('tasks.0.status', CampaignTask::STATUS_COMPLETE)
            ->assertJsonPath('tasks.0.reloads', ['campaign', 'onboarding']);
    }

    public function test_nothing_is_queued_when_no_claim_is_awaiting_an_answer(): void
    {
        Queue::fake();

        $code = Code::factory()->for($this->campaign)->create();
        Claim::factory()->for($code)->completed()->create();

        $this->actingAs($this->user)
            ->post(route('campaigns.check-claims', $this->campaign))
            ->assertRedirect()
            ->assertSessionHas('message', 'No pending claims to check.');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('campaign_tasks', 0);
    }

    public function test_a_second_press_while_one_is_running_dispatches_nothing(): void
    {
        Queue::fake();

        $this->pendingClaim();

        $this->actingAs($this->user)->post(route('campaigns.check-claims', $this->campaign));

        CampaignTask::where('campaign_id', $this->campaign->id)->update([
            'status' => CampaignTask::STATUS_RUNNING,
            'heartbeat_at' => now(),
        ]);

        Queue::fake();

        $this->actingAs($this->user)
            ->post(route('campaigns.check-claims', $this->campaign))
            ->assertRedirect()
            ->assertSessionHas('message', fn ($message) => str_contains($message, 'already running'));

        Queue::assertNothingPushed();
        $this->assertSame(1, CampaignTask::where('campaign_id', $this->campaign->id)->count());
    }

    public function test_a_finished_check_can_be_pressed_again(): void
    {
        Queue::fake();

        $this->pendingClaim();

        $first = CampaignTask::factory()->for($this->campaign)->complete(['checked' => 3])->create([
            'type' => CheckClaims::TASK_TYPE,
        ]);

        $this->actingAs($this->user)
            ->post(route('campaigns.check-claims', $this->campaign))
            ->assertRedirect();

        $task = $first->refresh();

        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertNull($task->result);
        Queue::assertPushed(CheckClaims::class, fn ($job) => $job->task_id === $task->id);
    }

    /**
     * The scheduler queues a check for any campaign with outstanding claims every few
     * minutes, and that job holds the campaign's unique lock until a worker runs it. The
     * button's job must not be swallowed by it: a dispatch the unique lock drops never runs,
     * and the task row would say "queued" with nothing behind it.
     */
    public function test_a_queued_scheduled_check_does_not_swallow_the_one_the_operator_asked_for(): void
    {
        Queue::fake();

        $this->pendingClaim();

        CheckClaims::dispatch($this->campaign->id);
        Queue::assertPushed(CheckClaims::class, 1);

        $this->actingAs($this->user)->post(route('campaigns.check-claims', $this->campaign));

        $task = CampaignTask::where('campaign_id', $this->campaign->id)->sole();

        Queue::assertPushed(CheckClaims::class, 2);
        Queue::assertPushed(CheckClaims::class, fn ($job) => $job->task_id === $task->id);
    }

    public function test_a_dispatch_that_fails_closes_the_task_instead_of_leaving_it_queued(): void
    {
        $this->pendingClaim();

        // A queue connection that does not exist, so the push itself throws.
        config(['queue.default' => 'not-a-connection']);

        $this->actingAs($this->user)
            ->post(route('campaigns.check-claims', $this->campaign))
            ->assertRedirect()
            ->assertSessionHas('message', 'Failed to trigger claim check. Please try again.');

        $task = CampaignTask::where('campaign_id', $this->campaign->id)->sole();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertNotNull($task->error);
    }

    public function test_a_finished_check_records_what_it_found_on_the_task(): void
    {
        $claim = $this->pendingClaim();
        $task = $this->task();

        $this->backendConfirms();

        (new CheckClaims($this->campaign->id, $task->id))->handle();

        $task->refresh();
        $claim->refresh();

        $this->assertSame('completed', $claim->status);
        $this->assertSame('abc123hash', $claim->transaction_hash);

        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(1, $task->result['checked']);
        $this->assertSame(1, $task->result['completed']);
        $this->assertSame(1, $task->progress_done);
        $this->assertSame(1, $task->progress_total);
    }

    /**
     * Through the real queue, so the failure reaches the task by the route the worker takes:
     * the exception, then the job's failed() hook.
     */
    public function test_a_check_that_throws_marks_its_task_failed_with_the_reason(): void
    {
        $this->pendingClaim();
        $task = $this->task();

        Http::fake(function () {
            throw new RuntimeException('The transaction backend is unreachable.');
        });

        try {
            CheckClaims::dispatch($this->campaign->id, $task->id);
            $this->fail('the run should have thrown');
        } catch (RuntimeException) {
            // The sync queue rethrows after calling failed().
        }

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('unreachable', $task->error);
        $this->assertNotNull($task->completed_at);
    }

    public function test_a_check_whose_campaign_has_gone_fails_with_a_reason(): void
    {
        $task = $this->task();

        (new CheckClaims('01JZZZZZZZZZZZZZZZZZZZZZZZ', $task->id))->handle();

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertSame('That campaign no longer exists.', $task->error);
    }

    /**
     * A scheduled pass holding the campaign past the wait is reported as a failure the
     * operator can act on, rather than a success that checked nothing.
     */
    public function test_a_check_that_cannot_get_the_campaign_says_so(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $this->pendingClaim();
        $task = $this->task();

        Http::fake();

        $held = Cache::lock('claim-status-check:'.$this->campaign->id, 300);
        $this->assertTrue($held->get());

        (new CheckClaims($this->campaign->id, $task->id))->handle();

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('still running', $task->error);
        Http::assertNothingSent();

        $held->release();
    }

    /**
     * The scheduler's runs are dropped while another holds the campaign, which is fine for a
     * run nobody is watching. A run the operator asked for must not be dropped that way:
     * the queue discards it without calling failed(), and the page would watch a queued row
     * until the stale sweep.
     */
    public function test_a_requested_check_is_not_dropped_by_a_scheduled_run_in_flight(): void
    {
        $this->pendingClaim();
        $task = $this->task();

        $this->backendConfirms();

        $overlap = Cache::lock('laravel-queue-overlap:'.CheckClaims::class.':'.$this->campaign->id, 180);
        $this->assertTrue($overlap->get());

        CheckClaims::dispatch($this->campaign->id, $task->id);

        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->refresh()->status);

        $overlap->release();
    }

    public function test_a_scheduled_check_writes_no_task_row(): void
    {
        $this->pendingClaim();

        $this->backendConfirms();

        (new CheckClaims($this->campaign->id))->handle();

        $this->assertDatabaseCount('campaign_tasks', 0);
    }
}
