<?php

namespace App\Console\Commands;

use App\Models\QrExport;
use App\Services\QrExportService;
use App\Support\StorageDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keep the stored archives and the rows describing them telling the same story.
 *
 * Two things delete a sticker archive and only one of them is this command. A deployment on
 * object storage is usually told to expire the export prefix with a lifecycle rule, which
 * removes the object without anything here being asked. The row then goes on calling itself
 * ready for good, and everything reading it treats an archive that is gone as one that can be
 * downloaded. So the sweep runs in both directions: archives past the TTL are deleted, and then
 * every row still advertising one is checked against the disk it was written to.
 *
 * Rows outlive their archives deliberately. A row is the history of what was exported and
 * the settings a regenerate replays, so an expired archive leaves its row behind with the
 * path cleared, and the row itself is deleted only once it is older than the retention
 * window. An expired row is regenerable: asking for the same export again claims the
 * campaign task afresh and the render replaces the row in place.
 */
class PruneQrExports extends Command
{
    protected $signature = 'qr:prune-exports
        {--days= : Override the age in days at which a stored archive is deleted}
        {--retention-days= : Override the age in days at which an expired row is deleted}';

    protected $description = 'Delete QR export archives past their TTL and reconcile the export rows against the disk';

    /**
     * Disks found unusable during this run, so a store that cannot be asked is reported once
     * rather than once per row.
     *
     * @var array<string, true>
     */
    private array $unusableDisks = [];

    public function handle(QrExportService $exports): int
    {
        $days = $this->ageInDays('days', 'cardano.qr_storage.ttl_days', 7);
        $retentionDays = $this->ageInDays('retention-days', 'cardano.qr_storage.retention_days', 90);

        if ($days === null || $retentionDays === null) {
            // Nothing has been touched yet, and an age nobody can read is not an age to
            // start deleting by.
            return self::FAILURE;
        }

        $archivesDeleted = $this->deleteArchivesPastTtl($exports, $days);

        // After the archives, so an object this run deleted is reflected on its row in this
        // run rather than in tomorrow's.
        $rowsExpired = $this->expireRowsWithNoArchive();
        $rowsDeleted = $this->deleteRowsPastRetention($retentionDays);

        $this->info("Deleted {$archivesDeleted} QR export archive(s) older than {$days} day(s) from [{$exports->diskName()}].");
        $this->info("Marked {$rowsExpired} export row(s) expired.");
        $this->info("Deleted {$rowsDeleted} expired export row(s) older than {$retentionDays} day(s).");

        if ($this->unusableDisks !== []) {
            $this->warn(
                'Rows were left as they are on disks that could not be asked: '
                .implode(', ', array_keys($this->unusableDisks)).'.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * An age in days from the option when one was given, otherwise from configuration, or
     * null when what was given is not an age.
     *
     * Zero is a value an operator can mean: it prunes everything that is there now, which is
     * what clearing a deployment's exports by hand looks like. Reading the option as falsy
     * would quietly substitute the default for it. Anything else that is not a whole number
     * of days stops the run instead of being cast: a negative age puts the cutoff in the
     * future, and a word cast to an integer is zero, so both delete everything while looking
     * like a typo.
     */
    private function ageInDays(string $option, string $configKey, int $fallback): ?int
    {
        $given = $this->option($option);
        $source = '--'.$option;

        if ($given === null || $given === '') {
            $given = config($configKey, $fallback);
            $source = $configKey;
        }

        if (! is_numeric($given) || (int) $given < 0) {
            $this->error("{$source} is a number of days and is set to '{$given}'. Nothing was pruned.");

            return null;
        }

        return (int) $given;
    }

    /**
     * Delete stored archives past the TTL.
     *
     * The configured disk only, which is where exports are written now. An archive on a disk
     * a deployment has since moved off is not reachable to delete from here; its row is
     * still reconciled below.
     */
    private function deleteArchivesPastTtl(QrExportService $exports, int $days): int
    {
        $disk = $exports->disk();
        $base = trim(config('cardano.qr_storage.path', 'qr-exports'), '/');
        $cutoff = now()->subDays($days)->getTimestamp();

        $deleted = 0;

        foreach ($disk->allFiles($base) as $file) {
            try {
                if ($disk->lastModified($file) >= $cutoff) {
                    continue;
                }

                $removed = $disk->delete($file);
            } catch (Throwable $e) {
                // A lifecycle rule can take an object between the listing and the read, and
                // one file that cannot be read is not a reason to leave the rest of the
                // sweep undone.
                Log::warning('QR export archive could not be pruned.', [
                    'disk' => $exports->diskName(),
                    'path' => $file,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $removed) {
                // A disk configured not to raise answers a refused delete with false. Counting
                // it would report archives freed that are still sitting there taking up room.
                Log::warning('QR export archive was not deleted.', [
                    'disk' => $exports->diskName(),
                    'path' => $file,
                ]);

                continue;
            }

            $deleted++;
        }

        return $deleted;
    }

    /**
     * Mark every row that is still advertising an archive nobody can download.
     *
     * Every ready row, with no join to campaigns: campaigns soft-delete, so a deleted
     * campaign's foreign key cascade never fires and its exports are still here to reconcile.
     *
     * chunkById rather than chunk, because the loop moves rows out of the set it is reading.
     * Paging by id cannot step over the rows that shift up behind it; paging by offset would.
     */
    private function expireRowsWithNoArchive(): int
    {
        $now = now();
        $expired = 0;

        QrExport::query()->ready()->chunkById(200, function ($rows) use (&$expired, $now) {
            foreach ($rows as $export) {
                if (! $this->shouldExpire($export, $now)) {
                    continue;
                }

                $export->markExpired();
                $expired++;
            }
        });

        return $expired;
    }

    /** Whether this row is offering a download that would not work. */
    private function shouldExpire(QrExport $export, Carbon $now): bool
    {
        // The advertised expiry, which costs no call to the store: a row past it is offering
        // an archive the TTL has already taken away.
        if ($export->hasExpired($now)) {
            return true;
        }

        // A ready row with nowhere to read from cannot be downloaded whatever the disk says.
        if (blank($export->path)) {
            return true;
        }

        return $this->archiveMissing($export);
    }

    /**
     * Whether the archive a row points at is gone from the disk it was written to.
     *
     * A store that cannot be asked answers false rather than true. A missing object and a
     * disk whose credentials have lapsed, or that this deployment no longer configures, look
     * identical from here, and expiring every row on a disk that is merely misconfigured
     * would throw away the one path each of them needs to be found again. A sweep that does
     * nothing is recoverable; one that clears ten thousand paths is not.
     *
     * One existence check per row. The set is every archive still inside its TTL, which is
     * what the window is for: an export whose archive is gone is expired once and never
     * asked about again.
     */
    private function archiveMissing(QrExport $export): bool
    {
        $disk = (string) $export->disk;
        $name = $disk === '' ? '(unnamed)' : $disk;

        if (isset($this->unusableDisks[$name])) {
            return false;
        }

        // Asked of the configuration rather than by building the adapter and finding out: a
        // disk with no bucket or region type-errors deep inside Flysystem instead of
        // answering, which reaches the operator as a failed sweep rather than a named
        // misconfiguration.
        if ($disk === '' || StorageDisk::missingConfig($disk) !== []) {
            $this->skipDisk($name, 'this deployment does not configure it');

            return false;
        }

        try {
            return ! Storage::disk($disk)->exists($export->path);
        } catch (Throwable $e) {
            // Deliberately for the rest of the run, not just this row. A store answering
            // with an error once is a store whose answers cannot be trusted this time round,
            // and the next sweep asks it again.
            $this->skipDisk($name, $e->getMessage());

            return false;
        }
    }

    private function skipDisk(string $name, string $reason): void
    {
        $this->unusableDisks[$name] = true;

        Log::warning('QR export rows on a disk that could not be asked were left as they are.', [
            'disk' => $name,
            'reason' => $reason,
        ]);
    }

    /**
     * Delete rows whose archive went long enough ago that the history is no longer of use.
     *
     * Expired rows only. A ready row is a download that still works, and no retention window
     * is a reason to forget where it is, so a window set shorter than the TTL by mistake
     * costs nothing.
     *
     * Measured from updated_at rather than created_at, because regenerating an export
     * replaces its row in place: an archive rebuilt every week keeps the date it was first
     * built, and dating the window from that would delete the row for an archive that is live.
     */
    private function deleteRowsPastRetention(int $days): int
    {
        $cutoff = now()->subDays($days);
        $deleted = 0;

        while (true) {
            $ids = QrExport::query()
                ->where('status', QrExport::STATUS_EXPIRED)
                ->where('updated_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // In batches rather than one statement: the first sweep after this ships meets
            // however many rows the table gathered while nothing was deleting any.
            $removed = QrExport::query()->whereIn('id', $ids)->delete();

            if ($removed === 0) {
                // Nothing was deletable after all. Asking again would return the same ids.
                break;
            }

            $deleted += $removed;
        }

        return $deleted;
    }
}
