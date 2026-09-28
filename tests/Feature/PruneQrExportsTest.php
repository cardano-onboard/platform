<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\QrExport;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter as LaravelDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToRetrieveMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The export sweep, in both directions.
 *
 * Deleting the stored archives is only half of it. A deployment on object storage is usually
 * told to expire the export prefix with a lifecycle rule, so the object goes without this
 * command ever seeing it, and a row that goes on calling itself ready is a download button
 * that answers 404. These tests hold both halves: the disk is swept, and then the rows are
 * checked against the disk.
 *
 * The disks here are real. A store that cannot answer is the one thing that cannot be
 * produced with a real disk, so it is produced with a real Flysystem adapter that raises the
 * exception the S3 adapter raises, which is the code path a lapsed credential takes.
 */
class PruneQrExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** A stored archive, written where a row for it would say it is. */
    private function storeArchive(QrExport $export, string $contents = 'zip'): void
    {
        Storage::disk('local')->put($export->path, $contents);
    }

    /** Age a stored file, the way the disk sees age: its modification time. */
    private function ageFile(string $path, int $days): void
    {
        touch(Storage::disk('local')->path($path), now()->subDays($days)->getTimestamp());
    }

    /**
     * Age a row without Eloquent refreshing the timestamp as it saves.
     *
     * Written through the query builder deliberately: the retention window is measured
     * against this column, so a test that let the model rewrite it would be measuring
     * nothing.
     */
    private function ageRow(QrExport $export, int $days, ?int $createdDaysAgo = null): void
    {
        DB::table('qr_exports')
            ->where('id', $export->id)
            ->update([
                'updated_at' => now()->subDays($days),
                'created_at' => now()->subDays($createdDaysAgo ?? $days),
            ]);
    }

    public function test_it_deletes_archives_past_the_ttl_and_leaves_the_rest(): void
    {
        $disk = Storage::disk('local');
        $disk->put('qr-exports/c/old.zip', 'old');
        $disk->put('qr-exports/c/fresh.zip', 'fresh');
        $this->ageFile('qr-exports/c/old.zip', 10);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertFalse($disk->exists('qr-exports/c/old.zip'));
        $this->assertTrue($disk->exists('qr-exports/c/fresh.zip'));
    }

    /**
     * Zero days is a value an operator can mean: clear out what is there now. Read as a falsy
     * option it would silently become the seven-day default, and the archive here, a day old
     * and well inside that default, would still be sitting there afterwards.
     *
     * Both spellings, because a shell hands the option over as the string "0" while a caller
     * inside the application hands over the integer, and a check written against one of them
     * passes while the other goes quietly back to the default.
     *
     * @param  string|int  $zero
     */
    #[DataProvider('spellings_of_zero')]
    public function test_a_zero_day_age_is_honoured_rather_than_replaced_by_the_default($zero): void
    {
        $disk = Storage::disk('local');
        $disk->put('qr-exports/c/yesterday.zip', 'zip');
        $this->ageFile('qr-exports/c/yesterday.zip', 1);

        $this->artisan('qr:prune-exports', ['--days' => $zero])->assertSuccessful();

        $this->assertFalse($disk->exists('qr-exports/c/yesterday.zip'));
    }

    public static function spellings_of_zero(): array
    {
        return ['as a shell gives it' => ['0'], 'as an integer' => [0]];
    }

    /**
     * An age that is not an age stops the run rather than being cast to one. A word becomes
     * zero and a negative number puts the cutoff in the future, and either of them would
     * delete every archive on the disk while reading, in a scheduler's log, like a typo.
     */
    #[DataProvider('ages_that_are_not_ages')]
    public function test_an_age_that_is_not_an_age_prunes_nothing(string $option, mixed $value): void
    {
        $disk = Storage::disk('local');
        $disk->put('qr-exports/c/old.zip', 'old');
        $this->ageFile('qr-exports/c/old.zip', 400);

        $export = QrExport::factory()->expired()->create();
        $this->ageRow($export, 400);

        $this->artisan('qr:prune-exports', [$option => $value])->assertFailed();

        $this->assertTrue($disk->exists('qr-exports/c/old.zip'));
        $this->assertNotNull($export->fresh());
    }

    public static function ages_that_are_not_ages(): array
    {
        return [
            'a word for the archive age' => ['--days', 'weekly'],
            'a negative archive age' => ['--days', '-1'],
            'a word for the retention window' => ['--retention-days', 'forever'],
            'a negative retention window' => ['--retention-days', '-30'],
        ];
    }

    /**
     * A misconfigured age stops the sweep before it deletes anything, including the rows. The
     * two ages are read together for that reason: failing halfway would leave the disk swept
     * and the rows describing it untouched, which is the state this command exists to end.
     */
    public function test_a_misconfigured_retention_window_stops_the_archive_sweep_too(): void
    {
        $disk = Storage::disk('local');
        $disk->put('qr-exports/c/old.zip', 'old');
        $this->ageFile('qr-exports/c/old.zip', 400);

        config()->set('cardano.qr_storage.retention_days', 'never');

        $this->artisan('qr:prune-exports')->assertFailed();

        $this->assertTrue($disk->exists('qr-exports/c/old.zip'));
    }

    /**
     * A lifecycle rule can take an object between the listing and the read, and an object
     * store can refuse one read out of a thousand. Either way the file that could not be read
     * is the only one left behind: stopping there would leave the rest of the prefix, however
     * old, to be swept a day later, and a day later the same thing can happen again.
     *
     * The adapter raises on the first read whichever file the disk lists first, so the test
     * holds whatever order the listing comes back in: two old files, one throw, one deleted.
     */
    public function test_one_file_the_disk_cannot_read_does_not_stop_the_sweep(): void
    {
        $root = sys_get_temp_dir().'/prune-qr-exports-'.Str::random(8);
        File::ensureDirectoryExists($root.'/qr-exports/c');

        foreach (['first.zip', 'second.zip'] as $name) {
            file_put_contents("{$root}/qr-exports/c/{$name}", 'zip');
            touch("{$root}/qr-exports/c/{$name}", now()->subDays(10)->getTimestamp());
        }

        Storage::extend('half-readable', function ($app, $config) {
            $adapter = new class($config['root']) extends LocalFilesystemAdapter
            {
                private int $reads = 0;

                public function lastModified(string $path): FileAttributes
                {
                    if (++$this->reads === 1) {
                        throw UnableToRetrieveMetadata::lastModified($path, 'the object could not be read');
                    }

                    return parent::lastModified($path);
                }
            };

            return new LaravelDisk(new Flysystem($adapter), $adapter, $config);
        });

        config()->set('filesystems.disks.half-readable', ['driver' => 'half-readable', 'root' => $root]);
        config()->set('cardano.qr_storage.disk', 'half-readable');

        try {
            $this->artisan('qr:prune-exports')->assertSuccessful();

            $this->assertCount(
                1,
                File::files($root.'/qr-exports/c'),
                'the sweep stopped at the file it could not read instead of carrying on',
            );
        } finally {
            File::deleteDirectory($root);
        }
    }

    /**
     * The hole this command exists to close: something other than this command deleted the
     * object, so the sweep never walked past it and the row went on advertising it.
     */
    public function test_a_ready_row_whose_object_was_removed_elsewhere_is_marked_expired(): void
    {
        $export = QrExport::factory()->create(['expires_at' => now()->addDays(5)]);
        // The archive is never written: this is what the row looks like the morning after a
        // bucket lifecycle rule took the object.

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $export->refresh();
        $this->assertSame(QrExport::STATUS_EXPIRED, $export->status);
        $this->assertNull($export->path, 'the path described bytes that are gone');
    }

    public function test_a_ready_row_past_its_advertised_expiry_is_marked_expired(): void
    {
        $export = QrExport::factory()->create(['expires_at' => now()->subMinute()]);
        // Still on the disk, and freshly written, so the TTL sweep has no reason to take it.
        // The row's own expiry is what decides.
        $this->storeArchive($export);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $export->refresh();
        $this->assertSame(QrExport::STATUS_EXPIRED, $export->status);
        $this->assertNull($export->path);
    }

    public function test_a_ready_row_whose_archive_is_still_there_is_left_alone(): void
    {
        $export = QrExport::factory()->create(['expires_at' => now()->addDays(5)]);
        $this->storeArchive($export);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $fresh = $export->fresh();
        $this->assertSame(QrExport::STATUS_READY, $fresh->status);
        $this->assertSame($export->path, $fresh->path);
        $this->assertTrue(Storage::disk('local')->exists($export->path));
    }

    /**
     * A row with no advertised expiry is judged by the disk alone. The column is nullable, so
     * a row written before the expiry was recorded is real data, and reading a null expiry as
     * "expired" would retire every one of them on the first sweep.
     */
    public function test_a_ready_row_with_no_advertised_expiry_is_judged_by_the_disk(): void
    {
        $present = QrExport::factory()->create(['expires_at' => null]);
        $this->storeArchive($present);
        $absent = QrExport::factory()->create(['expires_at' => null]);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertSame(QrExport::STATUS_READY, $present->fresh()->status);
        $this->assertSame(QrExport::STATUS_EXPIRED, $absent->fresh()->status);
    }

    /** A ready row pointing nowhere cannot be downloaded, whatever the disk would say. */
    public function test_a_ready_row_with_no_path_is_marked_expired(): void
    {
        $export = QrExport::factory()->create(['path' => null, 'expires_at' => now()->addDays(5)]);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertSame(QrExport::STATUS_EXPIRED, $export->fresh()->status);
    }

    /**
     * One run, both halves. The archive is past the TTL, so the disk sweep takes it and the
     * row sweep behind it finds it gone: the row is not left advertising a download for a day
     * because the two passes happened to run in the wrong order.
     */
    public function test_an_archive_this_run_deleted_leaves_its_row_expired_in_the_same_run(): void
    {
        $export = QrExport::factory()->create(['expires_at' => now()->addDays(5)]);
        $this->storeArchive($export);
        $this->ageFile($export->path, 10);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($export->path));
        $this->assertSame(QrExport::STATUS_EXPIRED, $export->fresh()->status);
    }

    /**
     * A campaign that was deleted still has its exports: campaigns soft-delete, so the
     * foreign key's cascade never fires. Reconciling through a join to campaigns would leave
     * every one of those rows untouched forever.
     */
    public function test_rows_belonging_to_a_deleted_campaign_are_still_reconciled(): void
    {
        $campaign = Campaign::factory()->create();
        $export = QrExport::factory()->for($campaign)->create(['expires_at' => now()->addDays(5)]);
        $campaign->delete();

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertSame(QrExport::STATUS_EXPIRED, $export->fresh()->status);
    }

    /**
     * A disk this deployment does not configure cannot be asked, and an unasked disk is not
     * an empty one. Treating it as empty would clear the path of every row on it, which is
     * the one piece of information needed to find those archives again once the setting that
     * went missing is put back.
     */
    public function test_rows_on_a_disk_this_deployment_cannot_configure_are_left_alone(): void
    {
        // An s3 disk with no bucket and no region: the shape of a deployment that moved off
        // object storage, or one whose credentials were never injected.
        config()->set('filesystems.disks.s3.bucket', null);
        config()->set('filesystems.disks.s3.region', null);

        $onS3 = QrExport::factory()->create(['disk' => 's3', 'expires_at' => now()->addDays(5)]);
        $onNothing = QrExport::factory()->create(['disk' => 'no-such-disk', 'expires_at' => now()->addDays(5)]);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        foreach ([$onS3, $onNothing] as $export) {
            $fresh = $export->fresh();
            $this->assertSame(QrExport::STATUS_READY, $fresh->status, "a row on [{$export->disk}] was retired without asking the disk");
            $this->assertSame($export->path, $fresh->path);
        }
    }

    /**
     * A store that raises rather than answers is the shape of a lapsed credential or a
     * network that is down, and it must not read as "the object is gone". The adapter here
     * is a real Flysystem adapter raising the real exception the S3 adapter raises.
     */
    public function test_rows_on_a_store_that_cannot_answer_are_left_alone(): void
    {
        Storage::extend('throwing', function ($app, $config) {
            $adapter = new class(sys_get_temp_dir()) extends LocalFilesystemAdapter
            {
                public function fileExists(string $path): bool
                {
                    throw UnableToCheckFileExistence::forLocation($path);
                }
            };

            return new LaravelDisk(new Flysystem($adapter), $adapter, $config);
        });

        config()->set('filesystems.disks.wobbly', ['driver' => 'throwing']);

        $export = QrExport::factory()->create(['disk' => 'wobbly', 'expires_at' => now()->addDays(5)]);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $fresh = $export->fresh();
        $this->assertSame(QrExport::STATUS_READY, $fresh->status);
        $this->assertSame($export->path, $fresh->path);
    }

    public function test_expired_rows_past_the_retention_window_are_deleted(): void
    {
        $old = QrExport::factory()->expired()->create();
        $this->ageRow($old, 200);
        $recent = QrExport::factory()->expired()->create();

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertNull($old->fresh(), 'an expired row past the retention window should be gone');
        $this->assertNotNull($recent->fresh(), 'an export that expired this week is still history worth keeping');
    }

    /**
     * Regenerating an export replaces its row rather than adding one, so a campaign whose
     * stickers are reprinted every week has a row first written a year ago and rebuilt on
     * Monday. Dating the retention window from when the row was created would delete it while
     * the archive it describes is the one on the operator's desk.
     */
    public function test_a_row_first_written_long_ago_and_rebuilt_since_is_kept(): void
    {
        $export = QrExport::factory()->expired()->create();
        $this->ageRow($export, days: 1, createdDaysAgo: 400);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertNotNull($export->fresh(), 'the row was dated from when it was first written');
    }

    public function test_the_retention_window_is_configurable(): void
    {
        $export = QrExport::factory()->expired()->create();
        $this->ageRow($export, 20);

        $this->artisan('qr:prune-exports')->assertSuccessful();
        $this->assertNotNull($export->fresh(), 'twenty days is inside the ninety-day default');

        config()->set('cardano.qr_storage.retention_days', 14);
        $this->artisan('qr:prune-exports')->assertSuccessful();
        $this->assertNull($export->fresh());
    }

    public function test_the_retention_window_can_be_overridden_for_one_run(): void
    {
        $export = QrExport::factory()->expired()->create();
        $this->ageRow($export, 20);

        $this->artisan('qr:prune-exports', ['--retention-days' => 10])->assertSuccessful();

        $this->assertNull($export->fresh());
    }

    /**
     * A download that still works is never thrown away by the retention window, however
     * short the window is set. Otherwise a retention shorter than the TTL, which is a typo
     * rather than an intention, would delete the record of an archive still sitting on the
     * disk and leave it there with nothing describing it.
     */
    public function test_a_row_whose_archive_is_still_downloadable_survives_any_retention_window(): void
    {
        $export = QrExport::factory()->create(['expires_at' => now()->addDays(5)]);
        $this->storeArchive($export);
        $this->ageRow($export, 500);

        $this->artisan('qr:prune-exports', ['--retention-days' => 0])->assertSuccessful();

        $fresh = $export->fresh();
        $this->assertNotNull($fresh, 'a ready row was deleted by the retention window');
        $this->assertSame(QrExport::STATUS_READY, $fresh->status);
    }

    /**
     * The whole point of keeping the row. An operator whose archive was swept still has the
     * export in front of them, and pressing the button again has to rebuild it rather than
     * find the way blocked by the record of the one that went.
     */
    public function test_an_expired_export_can_be_asked_for_again_and_replaces_its_own_row(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('campaigns.show', $campaign));

        $export = QrExport::where('campaign_id', $campaign->id)->sole();
        $path = $export->path;
        $this->assertSame(QrExport::STATUS_READY, $export->status);
        $this->assertTrue(Storage::disk('local')->exists($path));

        // What a lifecycle rule on the export prefix does, which is the case the row sweep
        // is here for.
        Storage::disk('local')->delete($path);
        $this->artisan('qr:prune-exports')->assertSuccessful();

        $export->refresh();
        $this->assertSame(QrExport::STATUS_EXPIRED, $export->status);
        $this->assertNull($export->path);

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('campaigns.show', $campaign));

        $export->refresh();
        $this->assertSame(QrExport::STATUS_READY, $export->status, 'an expired export could not be regenerated');
        $this->assertSame($path, $export->path);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame(
            1,
            QrExport::where('campaign_id', $campaign->id)->count(),
            'regenerating should replace the row rather than leave one per attempt',
        );
    }

    /**
     * Ten thousand rows is a deployment that has been running a while, not a stress test, and
     * the sweep reads them in chunks keyed on the id it is ordering by. Paging by offset
     * instead would step over every row that shifted up behind the one just retired, and half
     * the table would keep advertising archives that are gone.
     */
    public function test_every_row_is_reconciled_when_there_are_more_than_one_chunk(): void
    {
        $campaign = Campaign::factory()->create();
        QrExport::factory()->for($campaign)->count(450)->create(['expires_at' => now()->addDays(5)]);

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertSame(0, QrExport::query()->ready()->count(), 'rows were skipped while the sweep paged through them');
        $this->assertSame(450, QrExport::where('status', QrExport::STATUS_EXPIRED)->count());
    }
}
