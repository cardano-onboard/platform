<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\QrExport;
use App\Models\User;
use App\Services\QrExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The run that builds a sticker archive.
 *
 * Two things matter more than the rendering itself, which the download tests already cover:
 * that a run never renders an archive somebody has already paid for, and that a run which
 * cannot finish says so on the row the operator is watching rather than going quiet.
 */
class GenerateQrExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_renders_the_archive_and_records_what_it_made(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(3);
        $task = $this->claim($campaign, $user);

        $this->runExport($campaign, $task);

        $export = QrExport::where('campaign_id', $campaign->id)->firstOrFail();

        $this->assertSame(QrExport::STATUS_READY, $export->status);
        $this->assertSame(3, $export->codes_total);
        $this->assertSame('local', $export->disk);
        $this->assertSame($user->id, $export->requested_by);
        $this->assertTrue(Storage::disk('local')->exists($export->path), 'the row names an archive that is not on the disk');
        $this->assertSame(strlen(Storage::disk('local')->get($export->path)), $export->bytes);
        $this->assertSameJson(['layout' => 'flat', 'extension' => 'pdf', 'entries' => 3], $export->manifest);
        $this->assertTrue($export->expires_at->greaterThan(now()), 'a fresh archive should not arrive already expired');

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertSame($export->id, $task->result['export_id']);
        $this->assertFalse($task->result['from_storage']);
        $this->assertSame(3, $task->result['codes']);
    }

    public function test_a_campaign_that_is_really_gone_takes_its_export_rows_with_it(): void
    {
        // Soft deleting leaves them, which is deliberate: the campaign can come back and the
        // archives are still on the disk. Removing the campaign for good has to take the rows,
        // or they describe a campaign nobody can reach.
        $campaign = Campaign::factory()->create();
        QrExport::factory()->for($campaign)->create();

        $campaign->delete();
        $this->assertDatabaseCount('qr_exports', 1);

        $campaign->forceDelete();
        $this->assertDatabaseCount('qr_exports', 0);
    }

    public function test_it_does_not_render_an_archive_that_is_already_stored(): void
    {
        // The reason the whole cache exists: a redelivered message, or a second request that
        // arrived while the first was still queued, must not spend the render again.
        [$user, $campaign] = $this->campaignWithCodes(2);

        $this->runExport($campaign, $this->claim($campaign, $user));

        $path = QrExport::where('campaign_id', $campaign->id)->value('path');
        Storage::disk('local')->put($path, 'SENTINEL');

        $task = $this->claim($campaign, $user);
        $this->runExport($campaign, $task);

        $this->assertSame('SENTINEL', Storage::disk('local')->get($path), 'the run re-rendered an archive that was already there');
        $this->assertCount(1, Storage::disk('local')->allFiles('qr-exports'));

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertTrue($task->result['from_storage'], 'the page has to be able to say nothing was rendered');
        // The row is still true about what is on the disk, including the sentinel's size.
        $this->assertSame(8, QrExport::where('campaign_id', $campaign->id)->value('bytes'));
    }

    public function test_it_reports_progress_the_page_can_show(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(4);
        $task = $this->claim($campaign, $user);

        $this->runExport($campaign, $task);

        $task->refresh();
        $this->assertSame(4, $task->progress_total, 'a run that knows its total has to say so');
        $this->assertSame(4, $task->progress_done);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->completed_at);
    }

    public function test_it_renders_the_settings_the_run_was_claimed_with(): void
    {
        // The settings live on the row, not in the queued message, so this is what proves the
        // run reads them back rather than falling through to its defaults.
        [$user, $campaign] = $this->campaignWithCodes(1);
        $code = $campaign->codes()->first();

        $task = $this->claim($campaign, $user, ['format' => 'svg', 'size' => 2.0, 'dpi' => 300, 'ecc' => 'H', 'header' => false, 'footer' => true]);
        $this->runExport($campaign, $task);

        $export = QrExport::where('campaign_id', $campaign->id)->firstOrFail();
        $entries = $this->zipEntries(Storage::disk('local')->get($export->path));

        $this->assertArrayHasKey($code->code.'.svg', $entries);
        $this->assertStringContainsString('<svg', $entries[$code->code.'.svg']);
        // The footer was asked for, so the code is printed under the QR.
        $this->assertStringContainsString($code->code, $entries[$code->code.'.svg']);
        $this->assertSame('svg', $export->settings['format']);
    }

    public function test_a_deleted_campaign_fails_the_run_rather_than_leaving_it_queued(): void
    {
        // Campaigns soft-delete, so the foreign key's cascade never fires and the task row
        // outlives the campaign. A run that returned quietly here would leave the row queued
        // forever, and every later request would reclaim it and queue another one.
        [$user, $campaign] = $this->campaignWithCodes(2);
        $task = $this->claim($campaign, $user);

        $campaign->delete();

        $this->runExport($campaign, $task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('no longer exists', $task->error);
        $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_a_campaign_with_no_codes_fails_the_run(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        $task = $this->claim($campaign, $user);

        $this->runExport($campaign, $task);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('no codes', $task->error);
        $this->assertDatabaseCount('qr_exports', 0);
    }

    public function test_settings_no_renderer_could_honour_fail_the_run(): void
    {
        // The request validates all of this, so a row that holds it is a row something else
        // wrote. Rendering a substitute would put a file in an operator's hands that is not
        // the one they asked for.
        [$user, $campaign] = $this->campaignWithCodes(1);

        foreach ([
            ['format' => 'gif'],
            ['ecc' => 'Z'],
            ['size' => 0],
            ['dpi' => 6],
        ] as $broken) {
            $task = $this->claim($campaign, $user, $broken + [
                'format' => 'pdf', 'size' => 1.0, 'dpi' => 203, 'ecc' => 'L', 'header' => false, 'footer' => false,
            ]);

            $this->runExport($campaign, $task);

            $task->refresh();
            $this->assertSame(CampaignTask::STATUS_FAILED, $task->status, 'accepted '.json_encode($broken));
            $this->assertNotEmpty($task->error);
            $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'), 'rendered something for '.json_encode($broken));

            $task->delete();
        }
    }

    public function test_a_run_whose_task_row_is_gone_writes_nothing(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(2);
        $task = $this->claim($campaign, $user);
        $taskId = $task->id;
        $task->delete();

        (new GenerateQrExport($campaign->id, $taskId))->handle(app(QrExportService::class));

        $this->assertDatabaseCount('qr_exports', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_it_leaves_no_temporary_file_behind(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(2);

        // The working files this run was handed, rather than everything currently sitting
        // in the temp directory. That directory belongs to the whole machine, so listing
        // it asks what every process is doing: a second test process rendering its own
        // archive at the same moment appears in the listing and reads as this run leaking
        // a file it never had.
        $service = new class extends QrExportService
        {
            /** @var array<int, string> */
            public array $handedOut = [];

            public function tempZipPath(): string
            {
                return $this->handedOut[] = parent::tempZipPath();
            }
        };

        $task = $this->claim($campaign, $user);
        (new GenerateQrExport($campaign->id, $task->id))->handle($service);

        $this->assertNotSame([], $service->handedOut, 'the render never asked for a working file');

        foreach ($service->handedOut as $path) {
            $this->assertFileDoesNotExist($path, 'the render left its working file in the temp directory');
        }
    }

    public function test_a_second_copy_of_the_run_waits_rather_than_being_thrown_away(): void
    {
        // Two copies rendering one archive at once is still wrong, so the lock stays. What
        // changed is what happens to the copy that loses: it used to be dropped, which was
        // right when an attempt could only start from the beginning, and is wrong now that
        // an attempt continues the work. Dropping it would throw away the message that
        // resumes a render whose first worker was killed.
        config(['cardano.qr_storage.job_timeout_seconds' => 600]);

        $job = new GenerateQrExport('01JCAMPAIGN', '01JTASK');

        $this->assertSame(600, $job->timeout);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('01JTASK', $middleware[0]->key);
        $this->assertNotNull($middleware[0]->releaseAfter, 'a second copy must be kept, not discarded');
        // Long enough that a copy waiting out a large render is not thousands of requeues
        // asking the same question.
        $this->assertGreaterThanOrEqual(10, $middleware[0]->releaseAfter);
        $this->assertSame(720, $middleware[0]->expiresAfter);
    }

    public function test_attempts_are_bounded_by_a_clock_rather_than_a_count(): void
    {
        // A count is the wrong bound once an attempt continues where the last one stopped:
        // a large campaign legitimately takes many of them. What must not happen is an
        // export resuming forever, so the ceiling is wall-clock and fixed when the message
        // is first dispatched rather than reset by each attempt.
        config(['cardano.qr_storage.resume_window_minutes' => 30]);

        $job = new GenerateQrExport('01JCAMPAIGN', '01JTASK');

        $this->assertSame(0, $job->tries, 'a try count would cap a render that is making progress');
        $this->assertSame(3, $job->maxExceptions, 'a render that keeps throwing is broken, not slow');

        $this->travelTo(now()->startOfSecond());
        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->getTimestamp(),
            $job->retryUntil()->getTimestamp(),
            2,
        );
    }

    public function test_one_attempt_stays_inside_the_queues_reservation_window(): void
    {
        // The budget and retry_after have to agree, and only one of them belongs to this
        // feature. An attempt that renders past the reservation window is handed to a second
        // worker while it is still going, and the attempts after that are spent on the
        // overlap lock rather than on stickers.
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
        config(['cardano.qr_storage.work_budget_seconds' => null]);

        $job = new GenerateQrExport('01JCAMPAIGN', '01JTASK');

        $this->assertLessThan(90, $job->workBudgetSeconds(), 'an attempt may not outlast the reservation it holds');
        $this->assertGreaterThan(0, $job->workBudgetSeconds());

        // A deployment that lengthens the window gets longer attempts without touching this,
        // which is the whole reason the number is derived rather than written down.
        config(['queue.connections.database.retry_after' => 1200]);
        $this->assertGreaterThan(
            300,
            (new GenerateQrExport('01JCAMPAIGN', '01JTASK'))->workBudgetSeconds(),
        );

        // An explicit setting wins over the derived one.
        config(['cardano.qr_storage.work_budget_seconds' => 42]);
        $this->assertSame(42, (new GenerateQrExport('01JCAMPAIGN', '01JTASK'))->workBudgetSeconds());
    }

    /** @return array{0: User, 1: Campaign} */
    private function campaignWithCodes(int $codes): array
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        if ($codes > 0) {
            Code::factory()->for($campaign)->count($codes)->create();
        }

        return [$user, $campaign->refresh()];
    }

    /** Claim a run the way the request does, so the payload is shaped the way the job reads it. */
    private function claim(Campaign $campaign, User $user, ?array $opts = null): CampaignTask
    {
        $opts ??= ['format' => 'pdf', 'size' => 1.0, 'dpi' => 203, 'ecc' => 'L', 'header' => false, 'footer' => false];

        $task = CampaignTask::claim(
            $campaign,
            GenerateQrExport::TASK_TYPE,
            GenerateQrExport::dedupeKey(hash('sha256', json_encode($opts).$campaign->id)),
            ['options' => $opts],
            $user->id,
        );

        $this->assertNotNull($task, 'the run could not be claimed');

        return $task;
    }

    private function runExport(Campaign $campaign, CampaignTask $task): void
    {
        (new GenerateQrExport($campaign->id, $task->id))->handle(app(QrExportService::class));
    }

    /** @return array<string, string> */
    private function zipEntries(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qrzip');
        file_put_contents($tmp, $binary);

        $zip = new \ZipArchive;
        $zip->open($tmp);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->statIndex($i)['name']] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tmp);

        return $entries;
    }
}
