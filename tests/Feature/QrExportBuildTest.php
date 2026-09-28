<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Services\QrExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Building the archive itself, one batch at a time.
 *
 * The download tests cover what an operator gets. These cover what only shows up on a
 * campaign bigger than a test usually makes: that reading the codes in batches still renders
 * every one of them exactly once, that the caller is told about each sticker as it goes, and
 * that the working file lands somewhere a locked-down worker can write.
 *
 * Driven the way the export job drives it, because there is no other way to drive it. A
 * render-it-all-in-one-call convenience would be a second path through the renderer that
 * nothing in the application uses, tested here and nowhere else.
 */
class QrExportBuildTest extends TestCase
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

    public function test_every_code_is_rendered_once_across_the_batches_it_is_read_in(): void
    {
        // A campaign has no limit on its codes, so the codes are read a batch at a time. A
        // batch boundary that lost a row, or handed one back twice, would show up as a print
        // run short of stickers, which is only discovered at the event.
        $campaign = Campaign::factory()->create();
        Code::factory()->for($campaign)->count(7)->create();

        $exports = new QrExportService;
        $zip = $exports->tempZipPath();

        $this->renderAll($exports, $zip, $campaign, 2);

        $entries = $this->entries($zip);
        @unlink($zip);

        $this->assertCount(7, $entries);

        foreach ($campaign->codes as $code) {
            $this->assertArrayHasKey($code->code.'.svg', $entries, 'a code was left out of the archive');
            $this->assertStringContainsString('<svg', $entries[$code->code.'.svg']);
        }
    }

    public function test_the_cursor_a_batch_returns_is_where_the_next_one_starts(): void
    {
        // The whole of resuming rests on this number. A cursor that ran ahead of what was
        // actually written would skip codes on the next batch; one that lagged would render
        // them twice.
        $campaign = Campaign::factory()->create();
        Code::factory()->for($campaign)->count(5)->create();

        $exports = new QrExportService;
        $zip = $exports->tempZipPath();

        $first = $exports->renderInto($zip, $campaign, self::OPTS, null, 3);

        $this->assertSame(3, $first['rendered']);
        $this->assertSame(
            (int) $campaign->codes()->orderBy('id')->skip(2)->first()->id,
            $first['cursor'],
            'the cursor is not the last code the batch actually wrote',
        );

        $second = $exports->renderInto($zip, $campaign, self::OPTS, $first['cursor'], 3);

        $this->assertSame(2, $second['rendered']);
        $this->assertSame(0, $exports->renderInto($zip, $campaign, self::OPTS, $second['cursor'], 3)['rendered']);

        $this->assertCount(5, $this->entries($zip));
        @unlink($zip);
    }

    public function test_the_caller_is_told_about_each_sticker_as_it_is_written(): void
    {
        $campaign = Campaign::factory()->create();
        Code::factory()->for($campaign)->count(4)->create();

        $exports = new QrExportService;
        $zip = $exports->tempZipPath();
        $seen = [];

        $this->renderAll($exports, $zip, $campaign, 3, static function ($code) use (&$seen) {
            $seen[] = $code->code;
        });

        @unlink($zip);

        $this->assertCount(4, $seen);
        $this->assertSame($campaign->codes()->orderBy('id')->pluck('code')->all(), $seen);
    }

    public function test_the_archive_is_written_outside_the_application_directory(): void
    {
        // The worker that renders this may have the application mounted read-only, and the
        // file is the render's own working copy rather than anything to keep.
        $campaign = Campaign::factory()->create();
        Code::factory()->for($campaign)->create();

        $exports = new QrExportService;
        $zip = $exports->tempZipPath();

        $exports->renderInto($zip, $campaign, self::OPTS);

        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '/').'/', $zip);
        $this->assertStringNotContainsString(base_path(), $zip);
        $this->assertGreaterThan(0, filesize($zip));

        @unlink($zip);
    }

    public function test_a_batch_with_nothing_in_it_leaves_the_archive_alone(): void
    {
        // The last batch of every render. Opening an archive to write no entries is the one
        // way to turn a finished render into a failure, because ZipArchive refuses to close
        // an archive it was given nothing to put in.
        $campaign = Campaign::factory()->create();

        $exports = new QrExportService;
        $zip = $exports->tempZipPath();

        $batch = $exports->renderInto($zip, $campaign, self::OPTS);

        $this->assertSame(0, $batch['rendered']);
        $this->assertNull($batch['cursor']);
        $this->assertFileDoesNotExist($zip);
    }

    /** Drive the renderer the way the export job does: batches until it runs out. */
    private function renderAll(
        QrExportService $exports,
        string $zip,
        Campaign $campaign,
        int $batchSize,
        ?callable $onEach = null,
    ): void {
        $cursor = null;

        while (true) {
            $batch = $exports->renderInto($zip, $campaign, self::OPTS, $cursor, $batchSize, $onEach);

            if ($batch['rendered'] === 0) {
                return;
            }

            $cursor = $batch['cursor'];
        }
    }

    /** @return array<string, string> */
    private function entries(string $path): array
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->statIndex($i)['name']] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }
}
