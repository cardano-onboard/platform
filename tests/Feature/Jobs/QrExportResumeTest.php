<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\QrExport;
use App\Models\User;
use App\Services\QrExportService;
use App\Services\QrStickerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\RecordingQueueJob;
use Tests\TestCase;

/**
 * A render that is interrupted, and what the next attempt does about it.
 *
 * There is no ceiling on how many codes an export may hold, so the only thing standing
 * between a large campaign and a printable archive is whether a render survives being cut
 * off. Everything here is about that, and every one of these is a real interruption: the run
 * is given a real queue message, renders real stickers into a real ZIP, stores a real partial
 * on a real disk, and is then called again the way a redelivered message would call it.
 *
 * The failure this is all guarding against is not a crash. It is an archive that opens
 * cleanly, looks finished, and is missing three hundred stickers, which nobody finds out
 * about until the print run is short at the event.
 */
class QrExportResumeTest extends TestCase
{
    use RefreshDatabase;

    private const OPTS = [
        'format' => 'svg',
        'size' => 1.0,
        'dpi' => 203,
        'ecc' => 'L',
        'header' => false,
        'footer' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Two stickers per batch, and store after every batch. Both are settings a
        // deployment can really choose, so this is the shipped code path at its most
        // interruptible rather than a test-only mode.
        config([
            'cardano.qr_storage.chunk_size' => 2,
            'cardano.qr_storage.work_budget_seconds' => 0,
        ]);
    }

    public function test_a_run_that_is_interrupted_carries_on_from_where_it_stopped(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(7);
        $task = $this->claim($campaign, $user);

        $attempts = 0;
        $stops = [];

        do {
            $released = $this->attempt($campaign, $task)->isReleased();
            $attempts++;
            $stops[] = (int) $task->refresh()->progress_done;

            $this->assertLessThan(20, $attempts, 'the render never finished');
        } while ($released);

        // Four batches of two and a batch of one, so the render genuinely had to come back
        // for more. A single attempt would prove nothing about resuming.
        $this->assertGreaterThan(1, $attempts, 'the render finished in one sitting and never resumed');

        // Progress only ever goes forwards. A reset to zero would be the run starting over,
        // which is what the operator sees as a bar that keeps going back to the beginning.
        $this->assertSame($stops, array_values(array_unique($stops)));
        $this->assertSame($stops, $this->sorted($stops), 'progress went backwards between attempts');

        $entries = $this->finishedArchive($campaign);

        $this->assertCount(7, $entries, 'the finished archive is not the size of the campaign');

        foreach ($campaign->codes as $code) {
            $this->assertArrayHasKey($code->code.'.svg', $entries, 'a code was lost across a resume');
            $this->assertStringContainsString('<svg', $entries[$code->code.'.svg']);
        }
    }

    public function test_an_interrupted_run_does_not_render_again_what_it_already_rendered(): void
    {
        // The point of resuming. An attempt that produced the right archive by rendering
        // everything from the beginning again would pass the test above and still cost the
        // operator the compute this exists to save.
        [$user, $campaign] = $this->campaignWithCodes(6);
        $task = $this->claim($campaign, $user);

        $stickers = $this->countingRenderer();

        do {
            $released = $this->attempt($campaign, $task)->isReleased();
        } while ($released);

        $this->assertSame(6, $stickers->rendered, 'a sticker was rendered more than once across the resumes');
        $this->assertCount(6, $this->finishedArchive($campaign));
    }

    public function test_a_paused_run_stores_what_it_has_and_is_still_running(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(6);
        $task = $this->claim($campaign, $user);

        $message = $this->attempt($campaign, $task);

        $this->assertTrue($message->isReleased(), 'the run did not give the message back');
        $this->assertSame(2, $message->releaseDelay ?? -1, 'a paused run should come back promptly, not back off');

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_RUNNING, $task->status, 'a pause is not a finished run');
        $this->assertSame(2, $task->progress_done);
        $this->assertSame(6, $task->progress_total);

        $checkpoint = $task->checkpoint;
        $this->assertIsArray($checkpoint);
        $this->assertSame(2, $checkpoint['done']);
        $this->assertSame(2, $checkpoint['entries']);

        $exports = app(QrExportService::class);
        $this->assertTrue(
            Storage::disk('local')->exists($exports->partPath($campaign, $checkpoint['key'])),
            'the work done so far was not stored anywhere the next attempt can reach',
        );

        // The half-finished archive must not be sitting where a download looks.
        $this->assertFalse(
            Storage::disk('local')->exists($exports->path($campaign, $checkpoint['key'])),
            'a half-finished render was published as the finished archive',
        );
        $this->assertDatabaseCount('qr_exports', 0);
    }

    public function test_a_partial_cut_off_in_transit_is_discarded_rather_than_appended_to(): void
    {
        // What a failed upload leaves behind. The bytes are a prefix of a real archive, so
        // they are plausible; appending to them and calling the result finished is the one
        // outcome that cannot be allowed.
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(7);

        $whole = Storage::disk('local')->get($part);
        Storage::disk('local')->put($part, substr($whole, 0, (int) floor(strlen($whole) / 2)));

        $this->runToCompletion($campaign, $task);

        $entries = $this->finishedArchive($campaign);
        $this->assertCount(7, $entries, 'a truncated partial was appended to instead of discarded');

        foreach ($campaign->codes as $code) {
            $this->assertArrayHasKey($code->code.'.svg', $entries);
            $this->assertStringContainsString('<svg', $entries[$code->code.'.svg']);
        }
    }

    public function test_a_partial_that_is_the_right_length_and_the_wrong_bytes_is_discarded(): void
    {
        // The nastier version: an object half-overwritten by another write is exactly the
        // size the checkpoint recorded and is not the archive that was stored. A length
        // check alone would wave this through.
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(5);

        $length = strlen(Storage::disk('local')->get($part));
        Storage::disk('local')->put($part, str_repeat('x', $length));

        $this->runToCompletion($campaign, $task);

        $entries = $this->finishedArchive($campaign);
        $this->assertCount(5, $entries, 'the archive was built on bytes that were not an archive');

        foreach ($campaign->codes as $code) {
            $this->assertArrayHasKey($code->code.'.svg', $entries);
        }
    }

    public function test_a_partial_holding_fewer_stickers_than_it_claims_is_discarded(): void
    {
        // A valid archive, correctly stored, that simply is not the one the checkpoint
        // describes. Resuming into it would skip the codes it is missing, and the result
        // would open cleanly and be short.
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(6);

        $shrunk = $this->archiveOf(['only-one.svg' => '<svg></svg>']);
        Storage::disk('local')->put($part, file_get_contents($shrunk));
        @unlink($shrunk);

        // Make the length agree, so the entry count is the only check left to catch it.
        $checkpoint = $task->refresh()->checkpoint;
        $checkpoint['bytes'] = Storage::disk('local')->size($part);
        $task->forceFill(['checkpoint' => $checkpoint])->save();

        $this->runToCompletion($campaign, $task);

        $entries = $this->finishedArchive($campaign);
        $this->assertCount(6, $entries);
        $this->assertArrayNotHasKey('only-one.svg', $entries, 'the wrong archive was resumed into');
    }

    public function test_a_partial_from_different_settings_is_never_resumed_into(): void
    {
        // A code added mid-render changes what the archive is meant to hold, and with it the
        // key the archive is stored under. The partial describes the old set, and the run has
        // to notice that rather than carry on and store a mixture of the two.
        [$campaign, $task] = $this->campaignPausedAfterOneBatch(4);

        Code::factory()->for($campaign)->create();
        $campaign->refresh();

        $this->runToCompletion($campaign, $task);

        $entries = $this->finishedArchive($campaign);
        $this->assertCount(5, $entries, 'the archive does not hold the codes it was keyed on');

        foreach ($campaign->codes as $code) {
            $this->assertArrayHasKey($code->code.'.svg', $entries);
        }
    }

    public function test_a_partial_that_is_gone_from_the_disk_starts_the_render_again(): void
    {
        // Pruned, or written to a disk this worker resolves differently. Starting over costs
        // compute and nothing else, which is the right trade against guessing.
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(5);

        Storage::disk('local')->delete($part);

        $this->runToCompletion($campaign, $task);

        $this->assertCount(5, $this->finishedArchive($campaign));
    }

    public function test_a_finished_run_leaves_no_partial_behind(): void
    {
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(5);

        $this->runToCompletion($campaign, $task);

        $this->assertFalse(
            Storage::disk('local')->exists($part),
            'the half-finished archive was left on the disk next to the finished one',
        );
        $this->assertNull($task->refresh()->checkpoint, 'a finished run still says where it got to');
    }

    public function test_an_attempt_that_threw_leaves_its_work_for_the_attempt_after_it(): void
    {
        // Retries are automatic, and this is what makes that affordable. An attempt that
        // threw keeps the archive it had already stored and the note of where it got to, so
        // the next one appends to that rather than rendering the campaign from the start.
        // Without it, a transient failure on a large export would spend the whole render
        // again for every attempt, which is the reason automatic retries were once refused.
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(6);

        $this->app->instance(QrStickerService::class, new class extends QrStickerService
        {
            public function render(
                string $data,
                string $format,
                float $inches,
                int $dpi,
                string $ecc,
                ?string $header = null,
                ?string $footer = null,
            ): string {
                throw new RuntimeException('the renderer gave out');
            }
        });

        try {
            $this->attempt($campaign, $task);
            $this->fail('the render did not throw');
        } catch (RuntimeException) {
            // The queue is what decides whether there is another attempt; this only has to
            // leave the work where the next one can find it.
        }

        $this->assertTrue(
            Storage::disk('local')->exists($part),
            'an attempt that threw took the work it had already stored with it',
        );
        $this->assertIsArray($task->refresh()->checkpoint, 'an attempt that threw forgot where it got to');

        $counting = $this->countingRenderer();
        $this->runToCompletion($campaign, $task);

        $this->assertSame(4, $counting->rendered, 'the attempt after the failure started the render again');
        $this->assertCount(6, $this->finishedArchive($campaign));
    }

    public function test_a_run_that_is_given_up_on_takes_its_partial_with_it(): void
    {
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(5);

        $job = new GenerateQrExport($campaign->id, $task->id);
        $job->failed(new RuntimeException('the renderer gave out'));

        $this->assertFalse(Storage::disk('local')->exists($part), 'a run nobody will resume left its partial on a paid disk');

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertSame('the renderer gave out', $task->error);
        $this->assertNull($task->checkpoint);
    }

    public function test_a_run_with_no_queue_behind_it_renders_to_the_end(): void
    {
        // A console command or a sync connection has no later attempt coming. Pausing there
        // would not schedule anything; it would just stop, with the export half rendered.
        [$user, $campaign] = $this->campaignWithCodes(5);
        $task = $this->claim($campaign, $user);

        (new GenerateQrExport($campaign->id, $task->id))->handle(app(QrExportService::class));

        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->refresh()->status);
        $this->assertCount(5, $this->finishedArchive($campaign));
    }

    public function test_a_sync_connection_renders_to_the_end_rather_than_releasing(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(5);
        $task = $this->claim($campaign, $user);

        $message = new RecordingQueueJob('sync');

        $job = new GenerateQrExport($campaign->id, $task->id);
        $job->setJob($message);
        $job->handle(app(QrExportService::class));

        $this->assertFalse($message->isReleased(), 'releasing under sync drops the rest of the render');
        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->refresh()->status);
        $this->assertCount(5, $this->finishedArchive($campaign));
    }

    public function test_an_attempt_that_finds_the_archive_already_built_clears_the_partial(): void
    {
        // Two attempts in flight, and the other one finished first. This one has nothing to
        // render and a partial on the disk that nothing will ever resume.
        [$campaign, $task, $part] = $this->campaignPausedAfterOneBatch(5);

        $exports = app(QrExportService::class);
        $key = $task->refresh()->checkpoint['key'];
        Storage::disk('local')->put($exports->path($campaign, $key), 'the other attempt got there first');

        $this->attempt($campaign, $task);

        $this->assertFalse(Storage::disk('local')->exists($part));
        $this->assertNull($task->refresh()->checkpoint);
        $this->assertTrue($task->result['from_storage'], 'nothing should have been rendered');
        $this->assertSame(QrExport::STATUS_READY, QrExport::where('campaign_id', $campaign->id)->firstOrFail()->status);
    }

    /**
     * Run one attempt the way a delivered queue message would, and hand back the message so
     * the caller can see whether the run asked to be given back.
     */
    private function attempt(Campaign $campaign, CampaignTask $task): RecordingQueueJob
    {
        $message = new RecordingQueueJob('database');

        $job = new GenerateQrExport($campaign->id, $task->id);
        $job->setJob($message);
        $job->handle(app(QrExportService::class));

        return $message;
    }

    /** Keep handing the message back until the run stops asking for it. */
    private function runToCompletion(Campaign $campaign, CampaignTask $task): void
    {
        $attempts = 0;

        while ($this->attempt($campaign, $task)->isReleased()) {
            $this->assertLessThan(20, ++$attempts, 'the render never finished');
        }
    }

    /**
     * A campaign whose render has been interrupted once.
     *
     * @return array{0: Campaign, 1: CampaignTask, 2: string} campaign, task, and the path the partial is at
     */
    private function campaignPausedAfterOneBatch(int $codes): array
    {
        [$user, $campaign] = $this->campaignWithCodes($codes);
        $task = $this->claim($campaign, $user);

        $this->assertTrue($this->attempt($campaign, $task)->isReleased(), 'the run did not pause');

        $checkpoint = $task->refresh()->checkpoint;
        $this->assertIsArray($checkpoint);

        return [$campaign, $task, app(QrExportService::class)->partPath($campaign, $checkpoint['key'])];
    }

    /**
     * The renderer, counting itself.
     *
     * A real QrStickerService that renders real stickers; the only thing added is a tally, so
     * "did it render this one twice" is answered by the object that would have done it.
     */
    private function countingRenderer(): QrStickerService
    {
        $counting = new class extends QrStickerService
        {
            public int $rendered = 0;

            public function render(
                string $data,
                string $format,
                float $inches,
                int $dpi,
                string $ecc,
                ?string $header = null,
                ?string $footer = null,
            ): string {
                $this->rendered++;

                return parent::render($data, $format, $inches, $dpi, $ecc, $header, $footer);
            }
        };

        $this->app->instance(QrStickerService::class, $counting);

        return $counting;
    }

    /**
     * The stickers in the finished archive, by entry name.
     *
     * The manifest a finished export writes is not a sticker, so it is left out of what a
     * test counts codes with. That it is there at all is asserted here rather than in each
     * test, because every run that finishes writes one, including every run that only
     * finished on its fourth attempt.
     *
     * @return array<string, string>
     */
    private function finishedArchive(Campaign $campaign): array
    {
        $export = QrExport::where('campaign_id', $campaign->id)->firstOrFail();

        $this->assertSame(QrExport::STATUS_READY, $export->status);
        $this->assertTrue(Storage::disk('local')->exists($export->path));

        $local = tempnam(sys_get_temp_dir(), 'qr-finished');
        file_put_contents($local, Storage::disk('local')->get($export->path));
        $entries = $this->entries($local);
        @unlink($local);

        $this->assertArrayHasKey(
            QrExportService::MANIFEST_CSV,
            $entries,
            'the finished archive does not carry the manifest describing it',
        );

        return array_filter(
            $entries,
            static fn (string $name) => $name !== QrExportService::MANIFEST_CSV
                && $name !== QrExportService::HANDOVER_TXT
                && ! str_ends_with($name, '/'.QrExportService::MANIFEST_TXT),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @param  array<string, string>  $entries */
    private function archiveOf(array $entries): string
    {
        $path = rtrim(sys_get_temp_dir(), '/').'/qr-wrong-'.bin2hex(random_bytes(6)).'.zip';

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }

        $zip->close();

        return $path;
    }

    /** @return array<string, string> */
    private function entries(string $path): array
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->statIndex($i)['name']] = $zip->getFromIndex($i);
        }

        $zip->close();

        return $entries;
    }

    /** @return array{0: User, 1: Campaign} */
    private function campaignWithCodes(int $codes): array
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count($codes)->create();

        return [$user, $campaign->refresh()];
    }

    private function claim(Campaign $campaign, User $user): CampaignTask
    {
        $task = CampaignTask::claim(
            $campaign,
            GenerateQrExport::TASK_TYPE,
            GenerateQrExport::dedupeKey(hash('sha256', $campaign->id)),
            ['options' => self::OPTS],
            $user->id,
        );

        $this->assertNotNull($task, 'the run could not be claimed');

        return $task;
    }

    /** @param  list<int>  $values */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
