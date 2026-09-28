<?php

namespace Tests\Feature;

use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\Partner;
use App\Models\QrExport;
use App\Models\User;
use App\Services\QrExportService;
use App\Support\QrExportFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordingQueueJob;
use Tests\TestCase;

/**
 * One archive, one folder per partner, and three counts that have to agree.
 *
 * What an operator does with a grouped export is hand a stack of stickers to somebody at a
 * table and tell them how many they have. The archive is only worth anything if that number
 * is true, and it is checkable three separate ways: the count in the folder's manifest
 * header, the codes listed underneath it, and the files the operating system shows in the
 * folder. Those three agreeing is the claim this feature makes, so it is what is tested
 * here, off a real archive that a real request produced.
 *
 * The other half is the handover sheet at the root, whose total has to be the sum of the
 * folder headers, so the sheet somebody carries can never disagree with the folders it
 * lists. A folder that never reached the archive at all is the failure underneath that one,
 * and it is caught before any of this is written: a run refuses to store an archive holding
 * fewer stickers than it rendered.
 */
class QrExportGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_three_counts_in_every_folder_agree(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['name' => 'Cardano Summit Booth']);

        $expected = [
            'vendor-a' => $this->partnerWithCodes($campaign, 'Vendor A', 3),
            'booth-staff' => $this->partnerWithCodes($campaign, 'Booth Staff', 2),
            'mailing-list' => $this->partnerWithCodes($campaign, 'Mailing List', 1),
        ];

        $unassigned = Code::factory()->for($campaign)->count(4)->create()->pluck('code')->sort()->values()->all();

        $entries = $this->exportArchive($user, $campaign);

        $expected[QrExportFolders::UNASSIGNED] = $unassigned;

        foreach ($expected as $folder => $codes) {
            $manifest = $entries[$folder.'/'.QrExportService::MANIFEST_TXT] ?? null;

            $this->assertNotNull($manifest, "the {$folder} folder has no manifest");

            $header = $this->headerCount($manifest);
            $listed = $this->listedCodes($manifest);
            $files = $this->stickersIn($entries, $folder);

            $this->assertSame(count($codes), $header, "the {$folder} header does not count the codes it was given");
            $this->assertSame($header, count($listed), "the {$folder} header and its code list disagree");
            $this->assertSame($header, count($files), "the {$folder} header and the files in the folder disagree");

            // And the same codes, not merely the same number of them. Three counts that
            // agree on a folder holding somebody else's stickers is worse than none.
            $this->assertSame($codes, $listed);
            $this->assertSame($codes, array_values(array_map(
                static fn (string $name) => basename($name, '.svg'),
                $files,
            )));
        }
    }

    public function test_the_handover_total_is_the_sum_of_the_folder_headers(): void
    {
        // The sheet somebody prints and carries to the table. Its total is added up from the
        // per-folder counts printed beside each name, so it cannot say one thing while the
        // folders it lists say another.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->partnerWithCodes($campaign, 'Vendor A', 3);
        $this->partnerWithCodes($campaign, 'Booth Staff', 2);
        Code::factory()->for($campaign)->count(2)->create();

        $entries = $this->exportArchive($user, $campaign);
        $handover = $entries[QrExportService::HANDOVER_TXT] ?? null;

        $this->assertNotNull($handover, 'the archive has no handover sheet at its root');

        $headers = [];

        foreach ($this->folders($entries) as $folder) {
            $headers[$folder] = $this->headerCount($entries[$folder.'/'.QrExportService::MANIFEST_TXT]);

            $this->assertSame(
                $headers[$folder],
                $this->handoverCount($handover, $folder),
                "the handover sheet and the {$folder} folder disagree about how many codes it holds",
            );
        }

        $this->assertSame(7, array_sum($headers));
        $this->assertSame(array_sum($headers), $this->handoverTotal($handover));
        $this->assertSame(
            array_sum($headers),
            count($this->stickers($entries)),
            'the handover total is not the number of stickers in the archive',
        );
    }

    public function test_codes_with_no_partner_land_in_the_unassigned_folder_and_nothing_says_so(): void
    {
        // A blank partner is a normal state: the field is optional, and interrupting an
        // operator about it would be the platform second-guessing a choice it offered.
        // The folder existing is the whole of the signal.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->partnerWithCodes($campaign, 'Vendor A', 2);
        Code::factory()->for($campaign)->count(3)->create();

        $response = $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), ['format' => 'svg', 'group' => 'partner']);

        $response->assertSessionHasNoErrors();

        foreach (['message', 'qr_export'] as $key) {
            $flash = session($key);
            $said = is_array($flash) ? json_encode($flash) : (string) $flash;

            $this->assertStringNotContainsStringIgnoringCase('unassigned', $said, 'the operator was warned about a normal state');
            $this->assertStringNotContainsStringIgnoringCase('no partner', $said);
        }

        $task = $campaign->tasks()->where('type', GenerateQrExport::TASK_TYPE)->firstOrFail();
        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertNull($task->error);

        $entries = $this->exportArchive($user, $campaign);

        $this->assertSame(3, $this->headerCount($entries[QrExportFolders::UNASSIGNED.'/'.QrExportService::MANIFEST_TXT]));
        $this->assertCount(3, $this->stickersIn($entries, QrExportFolders::UNASSIGNED));
    }

    public function test_two_partners_whose_names_slug_the_same_do_not_share_a_folder(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $first = $this->partnerWithCodes($campaign, 'Vendor A', 2);
        $second = $this->partnerWithCodes($campaign, 'vendor a!', 3);

        $entries = $this->exportArchive($user, $campaign);
        $folders = $this->folders($entries);

        $vendorFolders = array_values(array_filter(
            $folders,
            static fn (string $folder) => str_starts_with($folder, 'vendor-a'),
        ));

        $this->assertCount(2, $vendorFolders, 'two partners were filed into one folder');

        $found = [];

        foreach ($vendorFolders as $folder) {
            $manifest = $entries[$folder.'/'.QrExportService::MANIFEST_TXT];
            $listed = $this->listedCodes($manifest);

            $this->assertSame($this->headerCount($manifest), count($listed));
            $this->assertSame(count($listed), count($this->stickersIn($entries, $folder)));

            $found[count($listed)] = $listed;
        }

        // Each folder holds exactly one partner's codes, so the two stacks are the two that
        // were generated rather than a mixture of both.
        $this->assertSame($first, $found[2] ?? null);
        $this->assertSame($second, $found[3] ?? null);
    }

    public function test_no_partner_name_can_write_outside_the_archive(): void
    {
        // The archive is unzipped on somebody's laptop, and the name is eighty characters an
        // operator typed. Nothing that came from the name may reach a path.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        foreach (['../../etc/passwd', '..', 'a/b', 'C:\\Windows\\System32', '!!!'] as $name) {
            $this->partnerWithCodes($campaign, $name, 1);
        }

        $entries = $this->exportArchive($user, $campaign);

        foreach (array_keys($entries) as $name) {
            $segments = explode('/', $name);

            $this->assertLessThanOrEqual(2, count($segments), "the entry {$name} is deeper than one folder");
            $this->assertStringStartsNotWith('/', $name, "the entry {$name} is an absolute path");
            $this->assertStringNotContainsString('\\', $name);
            $this->assertStringNotContainsString(':', $name);

            foreach ($segments as $segment) {
                $this->assertNotSame('', $segment, "the entry {$name} has an empty path segment");
                $this->assertNotSame('.', $segment);
                $this->assertNotSame('..', $segment, "the entry {$name} climbs out of the archive");
            }
        }

        // Five partners, each with its own folder, and the codes still filed under them.
        $folders = $this->folders($entries);
        $this->assertCount(5, $folders);

        foreach ($folders as $folder) {
            $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*$/', $folder, 'a folder is not a plain slug');
        }
    }

    public function test_a_partner_name_cannot_write_its_own_line_into_a_manifest(): void
    {
        // A manifest is read line by line, and its count is the number the whole feature
        // rests on. A name carrying a newline would otherwise be able to add a second
        // "Codes:" line saying whatever it liked.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->partnerWithCodes($campaign, "Vendor A\nCodes:       999", 2);

        $entries = $this->exportArchive($user, $campaign);
        $folder = $this->folders($entries)[0];
        $manifest = $entries[$folder.'/'.QrExportService::MANIFEST_TXT];

        $this->assertSame(1, preg_match_all('/^Codes:/m', $manifest), 'a partner name wrote a second count into the manifest');
        $this->assertSame(2, $this->headerCount($manifest));
        $this->assertStringContainsString('Partner:     Vendor A Codes: 999', $manifest);
    }

    public function test_the_root_manifest_names_every_code_with_its_partner_and_its_claim_url(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $partner = Partner::factory()->for($campaign)->create(['name' => 'Vendor A, Ltd "the booth"']);
        $assigned = Code::factory()->for($campaign)->count(2)->create(['partner_id' => $partner->id]);
        $loose = Code::factory()->for($campaign)->create();

        $entries = $this->exportArchive($user, $campaign);
        $rows = $this->manifestRows($entries[QrExportService::MANIFEST_CSV]);

        $this->assertCount(3, $rows);

        foreach ($assigned as $code) {
            $row = $rows[$code->code];

            // The name is written as the operator typed it, commas and quotes and all: the
            // slug is for the folder, and a manifest that renamed the partner would be
            // useless for finding out who a stack belongs to.
            $this->assertSame('Vendor A, Ltd "the booth"', $row['partner']);
            $this->assertSame('vendor-a-ltd-the-booth/'.$code->code.'.svg', $row['filename']);
            $this->assertArrayHasKey($row['filename'], $entries, 'the manifest names a file that is not in the archive');
            $this->assertSame(
                'web+cardano://claim/v1?faucet_url='.urlencode($campaign->claimUrl()).'&code='.$code->code,
                $row['claim_url'],
                'the manifest does not carry what the sticker encodes',
            );
        }

        $this->assertSame('', $rows[$loose->code]['partner'], 'a code with no partner was given one');
        $this->assertSame(QrExportFolders::UNASSIGNED.'/'.$loose->code.'.svg', $rows[$loose->code]['filename']);
    }

    public function test_a_partner_that_was_removed_keeps_the_name_its_codes_were_given_under(): void
    {
        // Removing a partner takes it off the picker. It does not unassign the codes it
        // handed out, and it must not move somebody's stack into the leftovers.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $partner = Partner::factory()->for($campaign)->create(['name' => 'Vendor A']);
        Code::factory()->for($campaign)->count(2)->create(['partner_id' => $partner->id]);
        Code::factory()->for($campaign)->create();

        $partner->delete();

        $entries = $this->exportArchive($user, $campaign);

        $this->assertSame(2, $this->headerCount($entries['vendor-a/'.QrExportService::MANIFEST_TXT]));
        $this->assertStringContainsString('Partner:     Vendor A', $entries['vendor-a/'.QrExportService::MANIFEST_TXT]);
        $this->assertSame(1, $this->headerCount($entries[QrExportFolders::UNASSIGNED.'/'.QrExportService::MANIFEST_TXT]));
    }

    public function test_renaming_a_partner_rebuilds_the_archive(): void
    {
        // A rename changes every folder name and every manifest line while changing no
        // codes, so nothing else in the cache key moves. Without this the archive keeps
        // being served with yesterday's name on it, which is the morning of the event.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $partner = Partner::factory()->for($campaign)->create(['name' => 'Vendor A']);
        Code::factory()->for($campaign)->count(2)->create(['partner_id' => $partner->id]);

        $before = $this->exportArchive($user, $campaign);
        $this->assertArrayHasKey('vendor-a/'.QrExportService::MANIFEST_TXT, $before);

        $partner->update(['name' => 'Vendor B']);

        $after = $this->exportArchive($user, $campaign);

        $this->assertArrayHasKey('vendor-b/'.QrExportService::MANIFEST_TXT, $after);
        $this->assertStringContainsString('Partner:     Vendor B', $after['vendor-b/'.QrExportService::MANIFEST_TXT]);
        $this->assertCount(2, Storage::disk('local')->allFiles('qr-exports'), 'the rename was served from the old archive');
    }

    public function test_a_grouped_render_that_was_interrupted_still_counts_correctly(): void
    {
        // The manifests are written once, at the end, from an archive several attempts
        // contributed to. A folder placement that only held inside one attempt, or a count
        // taken from what this attempt rendered rather than from the whole archive, would
        // show up here and nowhere else.
        config([
            'cardano.qr_storage.chunk_size' => 2,
            'cardano.qr_storage.work_budget_seconds' => 0,
        ]);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $expected = [
            'vendor-a' => $this->partnerWithCodes($campaign, 'Vendor A', 3),
            'booth-staff' => $this->partnerWithCodes($campaign, 'Booth Staff', 2),
        ];
        $expected[QrExportFolders::UNASSIGNED] = Code::factory()->for($campaign)
            ->count(2)->create()->pluck('code')->sort()->values()->all();

        $opts = [
            'format' => 'svg',
            'size' => 1.0,
            'dpi' => 203,
            'ecc' => 'L',
            'header' => false,
            'footer' => false,
            'group' => QrExportService::GROUP_PARTNER,
            'scope' => null,
        ];

        $exports = app(QrExportService::class);
        $task = CampaignTask::claim(
            $campaign,
            GenerateQrExport::TASK_TYPE,
            GenerateQrExport::dedupeKey($exports->cacheKey($campaign, $opts)),
            ['options' => $opts],
            $user->id,
        );

        $attempts = 0;

        do {
            $message = new RecordingQueueJob('database');
            $job = new GenerateQrExport($campaign->id, $task->id);
            $job->setJob($message);
            $job->handle(app(QrExportService::class));

            $this->assertLessThan(20, ++$attempts, 'the render never finished');
        } while ($message->isReleased());

        $this->assertGreaterThan(1, $attempts, 'the render finished in one sitting and proved nothing about resuming');

        $entries = $this->entriesOf(QrExport::where('campaign_id', $campaign->id)->firstOrFail());

        foreach ($expected as $folder => $codes) {
            $manifest = $entries[$folder.'/'.QrExportService::MANIFEST_TXT];

            $this->assertSame(count($codes), $this->headerCount($manifest));
            $this->assertSame($codes, $this->listedCodes($manifest));
            $this->assertCount(count($codes), $this->stickersIn($entries, $folder));
        }

        $this->assertSame(7, $this->handoverTotal($entries[QrExportService::HANDOVER_TXT]));
    }

    public function test_an_archive_short_of_what_was_rendered_is_never_stored(): void
    {
        // The manifests are counted off the archive, so they always agree with it. What they
        // cannot notice on their own is the archive itself coming up short: a batch written
        // and then lost across a resume takes its folder's stickers, its manifest and its
        // handover row with it, and what is left is internally consistent and wrong. The run
        // compares the archive against what it rendered before anything describes it.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->partnerWithCodes($campaign, 'Vendor A', 2);

        // A renderer that reports a sticker it did not write, which is what a lost entry
        // looks like from the outside.
        $this->app->bind(QrExportService::class, fn () => new class extends QrExportService
        {
            private bool $lost = false;

            public function renderInto(
                string $localZipPath,
                Campaign $campaign,
                array $opts,
                ?int $after = null,
                ?int $limit = null,
                ?callable $onEach = null,
            ): array {
                $batch = parent::renderInto($localZipPath, $campaign, $opts, $after, $limit, $onEach);

                if (! $this->lost && $batch['rendered'] > 0 && $onEach !== null) {
                    $this->lost = true;
                    $onEach();
                }

                return $batch;
            }
        });

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), ['format' => 'svg', 'group' => 'partner'])
            ->assertSessionHasNoErrors();

        $task = $campaign->tasks()->firstOrFail();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('stickers where', (string) $task->error);
        $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'), 'a short archive was stored anyway');
        $this->assertSame(0, QrExport::where('campaign_id', $campaign->id)->count(), 'a short archive was advertised as ready');
    }

    public function test_the_export_row_records_what_is_in_the_archive(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->partnerWithCodes($campaign, 'Vendor A', 2);
        Code::factory()->for($campaign)->create();

        $this->exportArchive($user, $campaign);

        $export = QrExport::where('campaign_id', $campaign->id)->firstOrFail();

        $this->assertSame(QrExportService::GROUP_PARTNER, $export->manifest['layout']);
        $this->assertSame(3, $export->manifest['entries']);
        $this->assertSameJson(
            [
                ['folder' => 'vendor-a', 'partner' => 'Vendor A', 'codes' => 2],
                ['folder' => QrExportFolders::UNASSIGNED, 'partner' => QrExportFolders::UNASSIGNED_LABEL, 'codes' => 1],
            ],
            $export->manifest['folders'],
        );
    }

    public function test_a_flat_export_still_puts_every_sticker_at_the_root(): void
    {
        // Grouping is a choice, and the archive exports have always produced is the other
        // one. It gains the row-per-code manifest and nothing else.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->partnerWithCodes($campaign, 'Vendor A', 2);
        $entries = $this->exportArchive($user, $campaign, ['group' => QrExportService::GROUP_FLAT]);

        $this->assertSame([], $this->folders($entries));
        $this->assertArrayHasKey(QrExportService::MANIFEST_CSV, $entries);
        $this->assertArrayNotHasKey(QrExportService::HANDOVER_TXT, $entries);
        $this->assertCount(2, $this->stickers($entries));

        // The partner is still named on every row: a flat archive is a different layout,
        // not a different set of facts.
        foreach ($this->manifestRows($entries[QrExportService::MANIFEST_CSV]) as $code => $row) {
            $this->assertSame('Vendor A', $row['partner']);
            $this->assertSame($code.'.svg', $row['filename']);
        }
    }

    public function test_a_grouped_archive_and_a_flat_one_are_two_different_files(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->partnerWithCodes($campaign, 'Vendor A', 2);

        $this->exportArchive($user, $campaign, ['group' => QrExportService::GROUP_FLAT]);
        $this->exportArchive($user, $campaign, ['group' => QrExportService::GROUP_PARTNER]);

        $this->assertCount(
            2,
            Storage::disk('local')->allFiles('qr-exports'),
            'the second layout was served the archive built for the first',
        );
    }

    /**
     * A partner with codes generated for it.
     *
     * @return list<string> the codes, sorted the way a manifest lists them
     */
    private function partnerWithCodes(Campaign $campaign, string $name, int $codes): array
    {
        $partner = Partner::factory()->for($campaign)->create(['name' => $name]);

        return Code::factory()->for($campaign)->count($codes)->create(['partner_id' => $partner->id])
            ->pluck('code')->sort()->values()->all();
    }

    /**
     * Ask for an export the way the dialog does, and open what came out.
     *
     * The request runs the render inline on the sync connection, which is what a developer
     * checkout and an install with no worker both do.
     *
     * @return array<string, string> entry name => contents
     */
    private function exportArchive(User $user, Campaign $campaign, array $settings = []): array
    {
        $settings += ['format' => 'svg', 'group' => QrExportService::GROUP_PARTNER];

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), $settings)
            ->assertSessionHasNoErrors();

        // Opened by the key the request answered with, which is what the dialog follows.
        // Picking the newest row instead would be a guess, and two archives built in one
        // second are two rows with one timestamp.
        $answer = session('qr_export');

        $this->assertIsArray($answer);
        $this->assertArrayHasKey('cache_key', $answer, 'the request did not say which export it was: '.json_encode($answer));

        return $this->entriesOf(
            QrExport::where('campaign_id', $campaign->id)->where('cache_key', $answer['cache_key'])->firstOrFail(),
        );
    }

    /** @return array<string, string> */
    private function entriesOf(QrExport $export): array
    {
        $this->assertSame(QrExport::STATUS_READY, $export->status);

        $local = tempnam(sys_get_temp_dir(), 'qr-grouped');
        file_put_contents($local, Storage::disk($export->disk)->get($export->path));

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($local) === true, 'the stored archive could not be opened');

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->statIndex($i)['name'];
            $entries[$name] = $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($local);

        return $entries;
    }

    /**
     * The folders an archive has, from the archive rather than from what it should have.
     *
     * @param  array<string, string>  $entries
     * @return list<string>
     */
    private function folders(array $entries): array
    {
        $folders = [];

        foreach (array_keys($entries) as $name) {
            if (str_contains($name, '/')) {
                $folders[dirname($name)] = true;
            }
        }

        return array_keys($folders);
    }

    /**
     * The sticker files in one folder, sorted by name.
     *
     * @param  array<string, string>  $entries
     * @return list<string>
     */
    private function stickersIn(array $entries, string $folder): array
    {
        $names = array_values(array_filter(
            array_keys($entries),
            static fn (string $name) => str_starts_with($name, $folder.'/') && str_ends_with($name, '.svg'),
        ));

        sort($names);

        return $names;
    }

    /**
     * Every sticker in the archive, wherever it is.
     *
     * @param  array<string, string>  $entries
     * @return list<string>
     */
    private function stickers(array $entries): array
    {
        return array_values(array_filter(
            array_keys($entries),
            static fn (string $name) => str_ends_with($name, '.svg'),
        ));
    }

    /** The number a folder's manifest says is in it. */
    private function headerCount(string $manifest): int
    {
        $this->assertSame(1, preg_match('/^Codes:\s+(\d+)$/m', $manifest, $matches), 'the manifest has no count in it');

        return (int) $matches[1];
    }

    /**
     * The codes a folder's manifest lists, which is everything after its header block.
     *
     * @return list<string>
     */
    private function listedCodes(string $manifest): array
    {
        $body = preg_split('/^Export ref:.*$/m', $manifest)[1] ?? '';

        return array_values(array_filter(array_map('trim', explode("\n", $body))));
    }

    /**
     * The root manifest, keyed by code.
     *
     * Read with the CSV reader rather than by splitting on commas, because a partner name is
     * free text and the file has to survive one with a comma in it.
     *
     * @return array<string, array{partner: string, code: string, filename: string, claim_url: string}>
     */
    private function manifestRows(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle);
        $this->assertSame(['partner', 'code', 'filename', 'claim_url'], $header);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $rows[$row[1]] = array_combine($header, $row);
        }

        fclose($handle);

        return $rows;
    }

    /** What the handover sheet says one folder holds. */
    private function handoverCount(string $handover, string $folder): int
    {
        $this->assertSame(
            1,
            preg_match('/^.*\s'.preg_quote($folder, '/').'\s+(\d+)\s*$/m', $handover, $matches),
            "the handover sheet has no row for {$folder}",
        );

        return (int) $matches[1];
    }

    /** What the handover sheet says the archive holds altogether. */
    private function handoverTotal(string $handover): int
    {
        $this->assertSame(1, preg_match('/^Total\s+(\d+)\s*$/m', $handover, $matches), 'the handover sheet has no total');

        return (int) $matches[1];
    }
}
