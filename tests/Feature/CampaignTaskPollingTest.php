<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedCodes;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The endpoint the campaign page polls, and the import that reports itself to it.
 *
 * The page's own endpoint loads codes, claims, rewards and a wallet balance, so the one
 * thing this must never become is a second way to ask for that.
 */
class CampaignTaskPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_work_running_on_the_campaign(): void
    {
        [$user, $campaign] = $this->owned();

        $task = CampaignTask::factory()->for($campaign)->running()->create([
            'type' => 'codes-import',
            'stage' => 'Creating codes',
            'progress_done' => 120,
            'progress_total' => 1000,
        ]);

        $response = $this->actingAs($user)->getJson(route('campaigns.tasks', $campaign));

        $response->assertOk()
            ->assertJsonPath('tasks.0.id', $task->id)
            ->assertJsonPath('tasks.0.status', 'running')
            ->assertJsonPath('tasks.0.stage', 'Creating codes')
            ->assertJsonPath('tasks.0.progress_done', 120)
            ->assertJsonPath('tasks.0.progress_total', 1000)
            // What the client refreshes when this finishes. The codes live inside the
            // campaign prop, so naming "codes" here would reload nothing.
            ->assertJsonPath('tasks.0.reloads', ['campaign', 'stats']);

        $this->assertNotNull($response->json('now'), 'the client polls from the server clock, not its own');
    }

    public function test_polling_does_not_call_the_transaction_backend(): void
    {
        // The whole reason this endpoint exists. Polling the page instead would make one
        // balance call per poll for as long as the job runs.
        Http::fake();
        [$user, $campaign] = $this->owned();
        Wallet::factory()->for($campaign)->create();

        CampaignTask::factory()->for($campaign)->running()->create();

        $this->actingAs($user)->getJson(route('campaigns.tasks', $campaign))->assertOk();

        Http::assertNothingSent();
    }

    public function test_another_account_cannot_read_a_campaigns_work(): void
    {
        // authorizeResource() covers the seven resource methods and not this one, so the
        // check is written out in the method. Without it this is a cross-tenant leak.
        [, $campaign] = $this->owned();
        $stranger = User::factory()->create();

        CampaignTask::factory()->for($campaign)->running()->create();

        $this->actingAs($stranger)
            ->getJson(route('campaigns.tasks', $campaign))
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_sent_to_log_in(): void
    {
        [, $campaign] = $this->owned();

        $this->get(route('campaigns.tasks', $campaign))->assertRedirect(route('login'));
    }

    public function test_a_run_that_finished_between_two_polls_is_still_reported(): void
    {
        [$user, $campaign] = $this->owned();

        $task = CampaignTask::factory()->for($campaign)->complete(['codes' => 4])->create();

        $this->actingAs($user)
            ->getJson(route('campaigns.tasks', $campaign).'?since='.urlencode(now()->subMinutes(2)->toIso8601String()))
            ->assertOk()
            ->assertJsonPath('tasks.0.id', $task->id)
            ->assertJsonPath('tasks.0.status', 'complete')
            ->assertJsonPath('tasks.0.result.codes', 4);
    }

    public function test_a_run_that_finished_before_the_client_arrived_is_not_reported(): void
    {
        // Otherwise every poll would tell the page to refresh data it already has.
        [$user, $campaign] = $this->owned();

        CampaignTask::factory()->for($campaign)->create([
            'status' => CampaignTask::STATUS_COMPLETE,
            'completed_at' => now()->subHours(3),
        ]);

        $this->actingAs($user)
            ->getJson(route('campaigns.tasks', $campaign).'?since='.urlencode(now()->subMinute()->toIso8601String()))
            ->assertOk()
            ->assertJsonCount(0, 'tasks');
    }

    /**
     * A `since` the server cannot use is either nonsense or an attempt at something, and
     * either way the answer is the work that is running. Nothing unreadable reaches a date
     * comparison, and nothing ancient reaches back past the one day floor.
     */
    #[DataProvider('unreadableStamps')]
    public function test_an_unreadable_since_does_not_break_the_poll(string $since): void
    {
        [$user, $campaign] = $this->owned();

        $running = CampaignTask::factory()->for($campaign)->running()->create();
        CampaignTask::factory()->for($campaign)->complete()->create([
            'type' => 'qr-export',
            'completed_at' => now()->subDays(3),
        ]);

        $this->actingAs($user)
            ->getJson(route('campaigns.tasks', $campaign).'?since='.urlencode($since))
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.id', $running->id);
    }

    public static function unreadableStamps(): array
    {
        return [
            'empty' => [''],
            'words' => ['yesterday please'],
            'markup' => ['<script>alert(1)</script>'],
            'sql' => ["2026-01-01' or '1'='1"],
            'zero date' => ['0000-00-00 00:00:00'],
            'huge number' => ['99999999999999999999'],
            'negative' => ['-1'],
        ];
    }

    public function test_a_since_from_the_future_still_reports_what_just_finished(): void
    {
        // A client clock running fast would otherwise step over the completion it is
        // waiting for and sit on a progress bar that never moves.
        [$user, $campaign] = $this->owned();

        $task = CampaignTask::factory()->for($campaign)->complete()->create();

        $this->actingAs($user)
            ->getJson(route('campaigns.tasks', $campaign).'?since='.urlencode(now()->addYear()->toIso8601String()))
            ->assertOk()
            ->assertJsonPath('tasks.0.id', $task->id);
    }

    public function test_a_since_from_last_year_does_not_drag_the_whole_history_back(): void
    {
        [$user, $campaign] = $this->owned();

        CampaignTask::factory()->for($campaign)->complete()->create([
            'completed_at' => now()->subMonths(2),
        ]);

        $this->actingAs($user)
            ->getJson(route('campaigns.tasks', $campaign).'?since='.urlencode(now()->subYears(3)->toIso8601String()))
            ->assertOk()
            ->assertJsonCount(0, 'tasks');
    }

    public function test_an_unknown_task_type_reports_nothing_to_reload(): void
    {
        // A row written before a deployment dropped its job still has to be readable.
        [$user, $campaign] = $this->owned();

        CampaignTask::factory()->for($campaign)->running()->create(['type' => 'something-else']);

        $this->actingAs($user)
            ->getJson(route('campaigns.tasks', $campaign))
            ->assertOk()
            ->assertJsonPath('tasks.0.reloads', []);
    }

    public function test_the_poller_is_rate_limited(): void
    {
        $middleware = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($route) => $route->getName() === 'campaigns.tasks')
            ->gatherMiddleware();

        $this->assertContains('throttle:campaign-tasks', $middleware);
    }

    public function test_the_campaign_page_carries_the_work_already_running(): void
    {
        // So a reload mid-import picks the watch back up instead of going quiet.
        [$user, $campaign] = $this->owned();
        Wallet::factory()->for($campaign)->create();

        $running = CampaignTask::factory()->for($campaign)->running()->create();
        CampaignTask::factory()->for($campaign)->complete()->create(['type' => 'qr-export']);

        $props = $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->original->getData()['page']['props'];

        $this->assertCount(1, $props['tasks']['items'], 'only unfinished work belongs in the page props');
        $this->assertSame($running->id, $props['tasks']['items'][0]['id']);
        $this->assertNotNull($props['tasks']['now']);
    }

    public function test_importing_a_file_claims_a_task_and_hands_the_job_its_id(): void
    {
        Bus::fake();
        [$user, $campaign] = $this->owned();

        $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'uploadedCodes' => true,
            'file_key' => 'uploads/one.json',
        ])->assertRedirect(route('campaigns.show', $campaign->id));

        $task = CampaignTask::where('campaign_id', $campaign->id)->sole();

        $this->assertSame(ProcessUploadedCodes::TASK_TYPE, $task->type);
        $this->assertSame(ProcessUploadedCodes::dedupeKey('uploads/one.json'), $task->dedupe_key);
        $this->assertSame($user->id, $task->requested_by);
        $this->assertSame(['file' => 'one.json'], $task->payload);

        Bus::assertDispatched(
            ProcessUploadedCodes::class,
            static fn (ProcessUploadedCodes $job) => $job->task_id === $task->id
                && $job->file_path === 'uploads/one.json',
        );
    }

    public function test_posting_the_same_file_twice_imports_it_once(): void
    {
        Bus::fake();
        [$user, $campaign] = $this->owned();

        $post = fn () => $this->actingAs($user)->post(route('codes.store'), [
            'campaign_id' => $campaign->id,
            'uploadedCodes' => true,
            'file_key' => 'uploads/one.json',
        ]);

        $post();
        $post()->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'already being imported'));

        Bus::assertDispatchedTimes(ProcessUploadedCodes::class, 1);
        $this->assertDatabaseCount('campaign_tasks', 1);
    }

    public function test_two_different_files_import_side_by_side(): void
    {
        Bus::fake();
        [$user, $campaign] = $this->owned();

        foreach (['uploads/one.json', 'uploads/two.json'] as $key) {
            $this->actingAs($user)->post(route('codes.store'), [
                'campaign_id' => $campaign->id,
                'uploadedCodes' => true,
                'file_key' => $key,
            ]);
        }

        Bus::assertDispatchedTimes(ProcessUploadedCodes::class, 2);
        $this->assertDatabaseCount('campaign_tasks', 2);
    }

    public function test_an_import_reports_what_it_did(): void
    {
        Storage::fake(config('filesystems.default'));
        [, $campaign] = $this->owned();

        $task = CampaignTask::claim($campaign, ProcessUploadedCodes::TASK_TYPE);

        Storage::disk(config('filesystems.default'))->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
            'CODE002' => ['lovelaces' => 3000000],
            // Real files carry rows like this, and an import that drops them silently
            // looks exactly like one that worked.
            'CODE003' => ['lovelaces' => 'not a number'],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', $task->id))->handle();

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertSame(2, $task->result['codes']);
        $this->assertSame(1, $task->result['skipped']);
        $this->assertSame(3, $task->progress_done);
        $this->assertSame(3, $task->progress_total);
        $this->assertNotNull($task->completed_at);
    }

    public function test_an_import_of_a_file_that_is_too_large_says_so_on_the_row(): void
    {
        Storage::fake(config('filesystems.default'));
        [, $campaign] = $this->owned();

        $task = CampaignTask::claim($campaign, ProcessUploadedCodes::TASK_TYPE);

        Storage::disk(config('filesystems.default'))->put(
            'test/large.json',
            str_repeat('x', config('cardano.max_file_size') + 1),
        );

        (new ProcessUploadedCodes($campaign->id, 'test/large.json', $task->id))->handle();

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('limit is', $task->error);
        $this->assertDatabaseCount('codes', 0);
    }

    public function test_an_import_of_something_that_is_not_the_expected_json_says_so_on_the_row(): void
    {
        Storage::fake(config('filesystems.default'));
        [, $campaign] = $this->owned();

        $task = CampaignTask::claim($campaign, ProcessUploadedCodes::TASK_TYPE);

        Storage::disk(config('filesystems.default'))->put('test/codes.json', '"just a string"');

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', $task->id))->handle();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->refresh()->status);
        $this->assertStringContainsString('JSON', $task->error);
    }

    public function test_an_import_for_a_campaign_that_has_gone_says_so_instead_of_crashing(): void
    {
        Storage::fake(config('filesystems.default'));
        [, $campaign] = $this->owned();

        $task = CampaignTask::claim($campaign, ProcessUploadedCodes::TASK_TYPE);

        Storage::disk(config('filesystems.default'))->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
        ]));

        $campaign->delete();

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', $task->id))->handle();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->refresh()->status);
        $this->assertStringContainsString('no longer exists', $task->error);
    }

    /** @return array{0: User, 1: Campaign} */
    private function owned(): array
    {
        $user = User::factory()->create();

        return [$user, Campaign::factory()->for($user)->create()];
    }
}
