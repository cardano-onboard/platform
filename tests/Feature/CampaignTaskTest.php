<?php

namespace Tests\Feature;

use App\Jobs\Concerns\TracksCampaignTask;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The claim transition and the reporting trait.
 *
 * These are the two halves of the rule that only one copy of a job runs: the row is the
 * lock, and the heartbeat is what says the holder is still alive.
 */
class CampaignTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_claim_creates_the_row(): void
    {
        $campaign = $this->campaign();

        $task = CampaignTask::claim($campaign, 'codes-import');

        $this->assertNotNull($task);
        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertSame(CampaignTask::DEFAULT_KEY, $task->dedupe_key);
        $this->assertNotNull($task->heartbeat_at, 'a new row has to look alive from the moment it exists');
        $this->assertDatabaseCount('campaign_tasks', 1);
    }

    public function test_a_second_claim_while_the_first_is_running_is_refused(): void
    {
        $campaign = $this->campaign();

        $first = CampaignTask::claim($campaign, 'codes-import');
        $second = CampaignTask::claim($campaign, 'codes-import');

        $this->assertNotNull($first);
        $this->assertNull($second, 'the caller must have nothing to dispatch');
        $this->assertDatabaseCount('campaign_tasks', 1);
    }

    public function test_the_database_refuses_a_second_row_for_the_same_campaign_type_and_key(): void
    {
        // The unique index is the guarantee, not the check in claim(). Two web requests
        // arriving in the same millisecond both find no row and both try to insert.
        $campaign = $this->campaign();

        CampaignTask::claim($campaign, 'codes-import', 'abc');

        $this->expectException(QueryException::class);

        CampaignTask::create([
            'campaign_id' => $campaign->id,
            'type' => 'codes-import',
            'dedupe_key' => 'abc',
            'status' => CampaignTask::STATUS_QUEUED,
        ]);
    }

    public function test_a_finished_run_can_be_claimed_again(): void
    {
        // An export that expired has to be able to regenerate, and an analysis has to be
        // able to run again when the chain has moved. A job that must never happen twice
        // is held off by its dedupe key instead.
        $campaign = $this->campaign();

        CampaignTask::factory()->for($campaign)->complete(['codes' => 4])->create([
            'type' => 'qr-export',
        ]);

        $task = CampaignTask::claim($campaign, 'qr-export');

        $this->assertNotNull($task);
        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertDatabaseCount('campaign_tasks', 1);
    }

    public function test_a_failed_run_can_be_claimed_again(): void
    {
        $campaign = $this->campaign();

        CampaignTask::factory()->for($campaign)->failed('the disk was full')->create([
            'type' => 'qr-export',
        ]);

        $task = CampaignTask::claim($campaign, 'qr-export');

        $this->assertNotNull($task);
        $this->assertNull($task->error, 'the new run starts without the old run\'s error');
    }

    public function test_a_run_whose_worker_stopped_reporting_is_claimed(): void
    {
        $campaign = $this->campaign();

        CampaignTask::factory()->for($campaign)->stale()->create(['type' => 'codes-import']);

        $task = CampaignTask::claim($campaign, 'codes-import');

        $this->assertNotNull($task, 'a killed worker must not wedge the campaign forever');
        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
    }

    public function test_a_long_run_that_is_still_reporting_is_not_claimed(): void
    {
        // The point of a separate heartbeat. This row has not changed for an hour, which
        // makes updated_at look abandoned, and its worker reported a second ago.
        $campaign = $this->campaign();

        $running = CampaignTask::factory()->for($campaign)->running()->create([
            'type' => 'codes-import',
        ]);

        DB::table('campaign_tasks')->where('id', $running->id)->update([
            'heartbeat_at' => now()->subSecond(),
            'updated_at' => now()->subHour(),
        ]);

        $this->assertNull(
            CampaignTask::claim($campaign, 'codes-import'),
            'a healthy long job was treated as stale, so a second copy of it would start',
        );
    }

    public function test_claiming_clears_the_previous_run_and_takes_the_new_options(): void
    {
        $campaign = $this->campaign();
        $user = User::factory()->create();

        $previous = CampaignTask::factory()->for($campaign)->complete(['codes' => 9])->create([
            'type' => 'qr-export',
            'stage' => 'Rendering stickers',
            'progress_done' => 900,
            'progress_total' => 900,
            'error' => 'something earlier',
            'payload' => ['format' => 'pdf'],
        ]);

        $task = CampaignTask::claim($campaign, 'qr-export', CampaignTask::DEFAULT_KEY, ['format' => 'png'], $user->id);

        $this->assertNotNull($task);
        $this->assertSame($previous->id, $task->id, 'the row is reused, not duplicated');
        $this->assertNull($task->stage);
        $this->assertSame(0, $task->progress_done);
        $this->assertNull($task->progress_total, 'a denominator from the previous run would describe the wrong work');
        $this->assertNull($task->result);
        $this->assertNull($task->error);
        $this->assertNull($task->completed_at);
        $this->assertSame(['format' => 'png'], $task->payload, 'the re-run has to run the options it was asked for');
        $this->assertSame($user->id, $task->requested_by);
    }

    public function test_two_dedupe_keys_on_one_campaign_run_side_by_side(): void
    {
        $campaign = $this->campaign();

        $first = CampaignTask::claim($campaign, 'qr-export', 'settings-a');
        $second = CampaignTask::claim($campaign, 'qr-export', 'settings-b');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_one_campaigns_run_does_not_hold_up_another_campaign(): void
    {
        $user = User::factory()->create();
        $mine = Campaign::factory()->for($user)->create();
        $theirs = Campaign::factory()->for(User::factory())->create();

        CampaignTask::claim($mine, 'codes-import');

        $this->assertNotNull(CampaignTask::claim($theirs, 'codes-import'));
    }

    public function test_progress_writes_a_heartbeat_and_stops_the_run_being_reclaimed(): void
    {
        $campaign = $this->campaign();
        $task = CampaignTask::claim($campaign, 'codes-import');

        // A worker that started eleven hours ago and is still going.
        DB::table('campaign_tasks')->where('id', $task->id)->update([
            'status' => CampaignTask::STATUS_RUNNING,
            'heartbeat_at' => now()->subHours(11),
        ]);

        $this->job($task->id)->progress(500, 1000);

        $task->refresh();

        $this->assertSame(500, $task->progress_done);
        $this->assertSame(1000, $task->progress_total);
        $this->assertTrue($task->heartbeat_at->gt(now()->subMinute()));
        $this->assertNull(CampaignTask::claim($campaign, 'codes-import'));
    }

    public function test_progress_is_written_every_so_many_items_rather_than_every_item(): void
    {
        // Ten thousand codes must not be ten thousand writes. The first call and the last
        // one always land, so the bar starts at zero and ends full.
        $campaign = $this->campaign();
        $task = CampaignTask::claim($campaign, 'codes-import');
        $job = $this->job($task->id);

        $writes = 0;
        DB::listen(static function ($query) use (&$writes) {
            // Identifier quoting is the engine's, not the application's: MySQL wraps a table
            // name in backticks and SQLite in double quotes. Count the statement rather than
            // one engine's spelling of it, or this counts nothing on the engine that matters
            // and says progress reporting is broken when it is working.
            $sql = strtolower(str_replace(['`', '"'], '', $query->sql));

            if (str_starts_with($sql, 'update campaign_tasks')) {
                $writes++;
            }
        });

        $job->progress(0, 10000);
        for ($done = 1; $done <= 10000; $done++) {
            $job->progress($done, 10000);
        }

        $this->assertLessThanOrEqual(60, $writes, 'progress reporting is writing per item');
        $this->assertGreaterThan(10, $writes, 'progress is barely being reported at all');
        $this->assertSame(10000, $task->refresh()->progress_done, 'the last item always lands');
    }

    public function test_a_job_with_no_task_reports_nothing_and_does_not_fail(): void
    {
        // Every one of these is a no-op without a task id, so a job can still be run from a
        // test or a console command.
        $job = $this->job(null);

        $job->beginTask('Reading');
        $job->stage('Creating');
        $job->progress(1, 2);
        $job->succeed(['codes' => 1]);

        $this->assertDatabaseCount('campaign_tasks', 0);
    }

    public function test_succeeding_records_the_result_and_the_finish_time(): void
    {
        $campaign = $this->campaign();
        $task = CampaignTask::claim($campaign, 'codes-import');

        $job = $this->job($task->id);
        $job->beginTask('Reading the file', 3);
        $job->succeed(['codes' => 3, 'skipped' => 1]);

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertSame(['codes' => 3, 'skipped' => 1], $task->result);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->completed_at);
        $this->assertNull($task->stage);
    }

    public function test_a_thrown_failure_is_recorded_on_the_row(): void
    {
        // Without this the row says "running" until the stale sweep catches it, and the
        // operator watches a bar that will never move.
        $campaign = $this->campaign();
        $task = CampaignTask::claim($campaign, 'codes-import');

        $this->job($task->id)->failed(new RuntimeException('the disk was full'));

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertSame('the disk was full', $task->error);
        $this->assertNotNull($task->completed_at);
    }

    public function test_a_failure_message_is_cut_to_something_an_operator_can_read(): void
    {
        $campaign = $this->campaign();
        $task = CampaignTask::claim($campaign, 'codes-import');

        $this->job($task->id)->failed(new RuntimeException(str_repeat('stack frame ', 500)));

        $this->assertLessThan(600, strlen($task->refresh()->error));
    }

    public function test_a_stage_longer_than_the_column_is_cut_rather_than_rejected(): void
    {
        $campaign = $this->campaign();
        $task = CampaignTask::claim($campaign, 'codes-import');

        $this->job($task->id)->stage(str_repeat('Rendering ', 40));

        $this->assertLessThanOrEqual(48, strlen($task->refresh()->stage));
    }

    public function test_deleting_the_campaign_takes_its_task_rows_with_it(): void
    {
        $campaign = $this->campaign();
        CampaignTask::claim($campaign, 'codes-import');

        $campaign->forceDelete();

        $this->assertDatabaseCount('campaign_tasks', 0);
    }

    private function campaign(): Campaign
    {
        return Campaign::factory()->for(User::factory())->create();
    }

    /** A job that does nothing but report, so the trait is tested rather than a consumer. */
    private function job(?string $taskId): object
    {
        return new class($taskId)
        {
            use TracksCampaignTask;

            public function __construct(public ?string $task_id) {}
        };
    }
}
