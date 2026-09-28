<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksCampaignTask;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Partner;
use App\Models\QrExport;
use App\Services\QrExportService;
use App\Services\QrStickerService;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Render a campaign's sticker archive off the web request, a batch at a time.
 *
 * Rendering ten thousand stickers is minutes of CPU. Doing it inside the download request
 * meant the operator's browser held the connection open for all of it, and on any runtime
 * with an execution limit the render was killed partway with nothing written and nothing
 * said. Here the request queues the work and returns, and the campaign page watches the task
 * row this job writes.
 *
 * There is no ceiling on how big an export may be. A refusal above some number of codes
 * would be the platform telling an operator their campaign is too large to print, which is
 * not an answer anybody can act on the week before an event. So the run is built to survive
 * being interrupted instead: it renders in batches, stores the half-finished archive and the
 * cursor it reached, and the next attempt picks up from there. A campaign twice the size of
 * anything seen so far takes twice as many attempts and still finishes.
 *
 * One job rather than a bus batch. Both give resumability, and the batch gives it in a form
 * that reports per-chunk state for free, but a ZIP has one central directory and cannot be
 * appended to from several workers at once, so a batch would have to render part files and
 * add a second job to assemble them: more storage round trips, a second progress store
 * beside the campaign task the page already polls, and a failure mode (assembly succeeded,
 * parts half-pruned) that has nothing to do with rendering stickers. One job with a cursor
 * needs one row and one file.
 */
class GenerateQrExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksCampaignTask;

    /** What this run is called on the campaign page. */
    public const TASK_TYPE = 'qr-export';

    /**
     * How long a paused run waits before it is handed back to a worker.
     *
     * Short, because the pause is not a backoff: nothing went wrong, the run simply gave the
     * message back before the queue could decide it was lost. Not zero, so the overlap lock
     * this attempt held is certainly clear before the next attempt asks for it.
     */
    private const RESUME_DELAY_SECONDS = 2;

    /**
     * How long a second copy waits before asking for the lock again.
     *
     * Longer than a resume, because the two are different situations. A resume is this run
     * handing itself back and wanting straight back on; a second copy is a message that
     * arrived while another worker is rendering, and it has nothing to do until that worker
     * stops. Asking every couple of seconds for the length of a large render would be
     * thousands of requeues to be told the same thing.
     */
    private const OVERLAP_RETRY_SECONDS = 30;

    /**
     * Attempts are not capped by a count.
     *
     * A count is the wrong bound once attempts resume: each one carries the render further,
     * so ten attempts on a large campaign is the mechanism working rather than the same
     * failure ten times. retryUntil() bounds it by the clock instead, and maxExceptions
     * bounds the case a count was really there to catch.
     */
    public int $tries = 0;

    /**
     * Three throws and the run is broken, not slow.
     *
     * Pausing is a plain return, so it does not come through here. What does is a renderer
     * that cannot produce a sticker or a disk that will not take the archive, and repeating
     * that until the clock runs out would spend an hour proving what the third attempt
     * already showed.
     */
    public int $maxExceptions = 3;

    public int $timeout;

    /**
     * Ids only, and the options come from the task row.
     *
     * A queued message can outlive the deploy that created it. Serializing the export
     * settings into the message would mean a run deciding today what to render from what
     * yesterday's code thought the defaults were; reading them from the row means the run
     * renders what the row says was asked for.
     */
    public function __construct(
        public string $campaign_id,
        public string $task_id,
    ) {
        $this->timeout = (int) config('cardano.qr_storage.job_timeout_seconds', 900);
    }

    /**
     * How long the run may keep resuming before it is given up on.
     *
     * The bound that replaces a maximum export size. Laravel fixes this to a wall-clock
     * instant when the message is first dispatched, so it covers every attempt together
     * rather than resetting with each one, which is what makes it a real ceiling on a render
     * that pauses and resumes all day without ever getting further.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('cardano.qr_storage.resume_window_minutes', 60));
    }

    /**
     * One run per task row, enforced where the work happens.
     *
     * Claiming the task row gates dispatch, and that is not the same thing: a queue whose
     * reservation lapses hands the same message to a second worker while the first is still
     * rendering, and both then render and store the same archive.
     *
     * Released rather than dropped. The previous version dropped the second copy, which was
     * right when every attempt re-rendered everything from the start and a second copy had
     * nothing to add. Now a second copy is how the render continues after the first one was
     * killed, so dropping it would throw away the only message that could resume the work.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->task_id))
                ->releaseAfter(self::OVERLAP_RETRY_SECONDS)
                ->expireAfter($this->timeout + 120),
        ];
    }

    /**
     * The dedupe key an export claims its task under.
     *
     * The cache key, so two exports of one campaign with different settings render side by
     * side, while a second request for the archive already being rendered finds the run and
     * waits for it. Already 64 characters of hex, which is the column exactly.
     */
    public static function dedupeKey(string $cacheKey): string
    {
        return $cacheKey;
    }

    /**
     * The campaign page's list of finished exports is what a completed run changes.
     *
     * The archive is not part of any prop the page renders while the run is going: the
     * finished task carries the export's id, and the download is a request of its own. What
     * does change is the panel offering the archive, so that is reloaded, once, instead of
     * the whole page.
     *
     * @return list<string>
     */
    public static function reloads(): array
    {
        return ['qr_exports'];
    }

    public function handle(QrExportService $exports): void
    {
        $task = $this->task();

        if (! $task) {
            // The row was deleted with its campaign. There is nothing to report progress to
            // and nobody waiting on it.
            return;
        }

        // withTrashed, because campaigns soft-delete: the foreign key's cascade never fires,
        // so a plain find() returns null for a campaign whose row is still right there. A
        // deleted campaign is still a refusal, but it has to be the refusal it actually is.
        $campaign = Campaign::withTrashed()->find($this->campaign_id);

        if (! $campaign || $campaign->trashed()) {
            $this->failTask('That campaign no longer exists.');

            return;
        }

        $opts = $this->options($task->payload);

        // The settings arrive from a JSON column written by some earlier request, not from
        // this process, and the renderer's behaviour on a size of zero or a format it has
        // never heard of is not something to find out in production. The request rejects all
        // of this already; anything that gets past it means the row is wrong, and saying so
        // beats rendering something nobody asked for.
        if ($problem = $this->settingsProblem($opts, $campaign)) {
            $this->failTask($problem);

            return;
        }

        // Checked here as well as at the request, because this is a different machine. The
        // web node that accepted the settings may have GD compiled in where the worker does
        // not, and a sticker renderer without GD cannot produce a single PNG. Saying so beats
        // a stack trace from the first render.
        if ($opts['format'] === 'png' && ! QrStickerService::pngSupported()) {
            $this->failTask('PNG export is unavailable on the machine that renders exports (missing GD extension). Choose PDF or SVG.');

            return;
        }

        $total = $exports->codeCount($campaign, $opts);

        if ($total === 0) {
            $this->failTask($this->nothingToExport($opts));

            return;
        }

        // Recomputed rather than carried in the message. The key is what the archive's
        // contents are, so computing it here is what keeps the stored object honest: if a
        // code was added between the request and this run, this renders the new set and
        // stores it under the key that describes the new set.
        $key = $exports->cacheKey($campaign, $opts);
        $path = $exports->path($campaign, $key);
        $partPath = $exports->partPath($campaign, $key);

        if ($exports->exists($path)) {
            // Somebody already paid for this archive. A queue that redelivered the message,
            // and a second request that got in first, both land here, and re-rendering would
            // spend exactly the compute this job exists to stop spending.
            $exports->deletePart($partPath);
            $this->clearCheckpoint();

            $export = $this->record($campaign, $task, $key, $path, $opts, $exports, $exports->disk()->size($path), $total, null);

            Log::info('QR export served from storage without rendering.', [
                'campaign' => $campaign->id,
                'task' => $task->id,
                'path' => $path,
            ]);

            $this->succeed($this->result($export, true));

            return;
        }

        // The application clock rather than microtime, so the budget is measured against the
        // same clock everything else here reads, and a test can move it.
        $startedAt = now();
        $local = $exports->tempZipPath();
        $manifest = null;

        $resumed = $this->resume($exports, $task, $key, $partPath, $local);
        $cursor = $resumed['cursor'];
        $done = $resumed['done'];

        $this->beginTask($done > 0 ? 'Resuming stickers' : 'Rendering stickers', $total);
        $this->progress($done, $total);

        try {
            while (true) {
                $batch = $exports->renderInto(
                    $local,
                    $campaign,
                    $opts,
                    $cursor,
                    $this->batchSize(),
                    function () use (&$done, $total) {
                        $done++;
                        $this->progress($done, $total);
                    },
                );

                if ($batch['rendered'] === 0) {
                    break;
                }

                $cursor = $batch['cursor'];
                $this->progress($done, $total);

                if ($batch['rendered'] < $this->batchSize()) {
                    // A batch short of the limit is the codes running out, so there is no
                    // next one to come back for. Pausing here would spend a requeue, and an
                    // attempt's whole startup, on discovering that.
                    break;
                }

                if (! $this->shouldPause($startedAt)) {
                    continue;
                }

                // Store before recording, always. A checkpoint naming a part that was never
                // written would send the next attempt past work that does not exist, and the
                // gap arrives as an archive quietly short of stickers.
                $exports->putPart($partPath, $local);

                $this->saveCheckpoint([
                    'key' => $key,
                    'part' => $partPath,
                    'cursor' => $cursor,
                    'done' => $done,
                    'entries' => $done,
                    'bytes' => (int) filesize($local),
                ]);

                // Forced past the throttle. This number is what the operator is left looking
                // at until a worker takes the message up again, so it has to be the count
                // the run really reached rather than the last one worth drawing a bar with.
                $this->progress($done, $total, true);
                $this->stage('Paused, resuming shortly');

                Log::info('QR export paused between batches.', [
                    'campaign' => $campaign->id,
                    'task' => $task->id,
                    'rendered' => $done,
                    'of' => $total,
                ]);

                $this->release(self::RESUME_DELAY_SECONDS);

                return;
            }

            if ($done === 0 || ! is_file($local)) {
                // Counted codes a moment ago and rendered none of them, so they went between
                // the two. There is no archive to store, and storing the absence of one would
                // hand the operator a download that opens to nothing.
                $this->failTask('The codes for that export were deleted before it could be rendered.');

                return;
            }

            // The manifests are about to say how many stickers are in here, and everything an
            // operator does with them rests on that number being the truth. It is counted off
            // the archive, so the one thing left to check is that the archive is as long as
            // the run believes: a batch that was written and then lost, across a resume or a
            // storage round trip, is an archive that opens cleanly and is short. Refusing to
            // store it costs a re-render; storing it hands somebody a stack of stickers that
            // is missing codes their manifest promises, which they find out at the printer.
            $entries = $exports->entriesIn($local);

            if ($entries !== $done) {
                $this->failTask(sprintf(
                    'The rendered archive holds %s stickers where %s were rendered, so it was not stored.',
                    $entries === null ? 'no readable' : $entries,
                    $done,
                ));

                return;
            }

            $manifest = $exports->writeManifests($local, $campaign, $opts, $key);

            $exports->store($path, $local);
        } finally {
            // The temp file is the worker's, not the archive. Leaving it behind fills the
            // one directory every other job on the box also writes to.
            @unlink($local);
        }

        // Nothing is resuming into this any more, and the part is the same bytes as the
        // archive that was just stored.
        $exports->deletePart($partPath);
        $this->clearCheckpoint();

        // Read back from the disk it landed on rather than from the temp file: this is the
        // size of what an operator will actually download.
        $bytes = $exports->disk()->size($path);

        $export = $this->record($campaign, $task, $key, $path, $opts, $exports, $bytes, $total, $manifest);

        Log::info('QR export rendered.', [
            'campaign' => $campaign->id,
            'task' => $task->id,
            'codes' => $total,
            'layout' => $exports->grouping($opts),
            'bytes' => $export->bytes,
            'seconds' => now()->getTimestamp() - $startedAt->getTimestamp(),
        ]);

        $this->succeed($this->result($export, false));
    }

    /**
     * Pick up where the last attempt stopped, or decide there is nothing to pick up.
     *
     * Everything here is a reason to start over, and starting over is always safe: the worst
     * it costs is the compute this mechanism was trying to save. Carrying on from a partial
     * that is not what it claims to be is the one outcome that cannot be allowed, because
     * the archive that results is a complete-looking ZIP missing some of its stickers, and
     * the operator finds out at the printer.
     *
     * The checks are on separate things on purpose. The key covers "the settings or the
     * codes changed since". Existence covers "the part was pruned, or went to a disk this
     * worker resolves differently". The byte count covers an upload that was cut off. The
     * entry count covers bytes that are the right length and still not the archive that was
     * written, which is what a half-overwritten object looks like.
     *
     * @return array{cursor: ?int, done: int}
     */
    private function resume(
        QrExportService $exports,
        CampaignTask $task,
        string $key,
        string $partPath,
        string $local,
    ): array {
        $nothing = ['cursor' => null, 'done' => 0];
        $checkpoint = $this->checkpoint();

        if ($checkpoint === null) {
            return $nothing;
        }

        $discard = function (string $why) use ($exports, $partPath, $task, $nothing) {
            Log::info('QR export partial discarded, restarting the batch from the beginning.', [
                'task' => $task->id,
                'reason' => $why,
            ]);

            $exports->deletePart($partPath);
            $this->clearCheckpoint();

            return $nothing;
        };

        if (($checkpoint['key'] ?? null) !== $key) {
            // The part belongs to a different archive: a code was added, the claim URL moved,
            // or the run was re-claimed with other settings. Its own key names its own part,
            // so this one is not ours to delete.
            $this->clearCheckpoint();

            return $nothing;
        }

        $cursor = $checkpoint['cursor'] ?? null;
        $done = (int) ($checkpoint['done'] ?? 0);
        $entries = (int) ($checkpoint['entries'] ?? 0);
        $bytes = (int) ($checkpoint['bytes'] ?? 0);

        if (! is_int($cursor) || $cursor <= 0 || $done <= 0 || $entries <= 0) {
            return $discard('the checkpoint does not describe any finished work');
        }

        if (! $exports->fetchPart($partPath, $local)) {
            return $discard('the partial archive is not on the disk');
        }

        if ((int) filesize($local) !== $bytes) {
            @unlink($local);

            return $discard('the partial archive is not the size it was stored at');
        }

        if ($exports->entriesIn($local) !== $entries) {
            @unlink($local);

            return $discard('the partial archive does not hold the stickers it was recorded with');
        }

        Log::info('QR export resuming from a stored partial.', [
            'task' => $task->id,
            'rendered' => $done,
        ]);

        return ['cursor' => $cursor, 'done' => $done];
    }

    /**
     * Whether to stop here, store what is done and hand the message back.
     *
     * The budget is about the queue's reservation window, not about how long the render is
     * allowed to take. A message the queue stops considering reserved is handed to a second
     * worker while this one is still going, and two workers rendering one archive is the
     * thing the overlap lock then spends its time refusing. Giving the message back before
     * that happens costs one requeue and keeps the run to a single worker throughout.
     */
    private function shouldPause(CarbonInterface $startedAt): bool
    {
        return $this->mayPause()
            && (now()->getTimestamp() - $startedAt->getTimestamp()) >= $this->workBudgetSeconds();
    }

    /**
     * Whether there is a queue behind this run that could hand the message back.
     *
     * Run straight from a test or a console command there is no message, and under the sync
     * driver the "queue" is the request that dispatched it. Releasing in either case would
     * not schedule anything; it would just stop, with the export half rendered and nothing
     * coming to finish it. So those run to completion instead, which is what the caller
     * asked for by running them that way.
     */
    private function mayPause(): bool
    {
        if ($this->job === null) {
            return false;
        }

        return config("queue.connections.{$this->connectionName()}.driver") !== 'sync';
    }

    /**
     * How long one attempt may render for before it gives the message back.
     *
     * Derived from the connection's own reservation window rather than written down as a
     * number, because the two have to agree and only one of them is this feature's to
     * choose. A deployment that lengthens retry_after gets longer attempts and fewer
     * requeues without touching anything here; one still on the framework default gets short
     * attempts, which is the correct response to a short window rather than a misconfiguration
     * to detect. The margin is what pays for the batch still in flight when the budget
     * elapses, plus storing the partial afterwards.
     */
    public function workBudgetSeconds(): int
    {
        $configured = config('cardano.qr_storage.work_budget_seconds');

        // Zero is a real answer here, not an absent one: it means store after every batch,
        // which is the most durable setting and the most round trips. Absent means derive.
        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        $retryAfter = (int) config("queue.connections.{$this->connectionName()}.retry_after", 0);

        // No reservation window to respect (a driver that does not redeliver, or one that
        // does not say). Nothing forces a pause, so the attempt runs until its own timeout.
        return $retryAfter > 0 ? max(20, (int) floor($retryAfter * 0.6)) : $this->timeout;
    }

    private function connectionName(): string
    {
        return $this->job?->getConnectionName()
            ?: (string) ($this->connection ?? config('queue.default'));
    }

    /** How many stickers are rendered between two chances to stop. */
    private function batchSize(): int
    {
        return max(1, (int) config('cardano.qr_storage.chunk_size', 250));
    }

    /**
     * The queue's own hook, called once the run has been given up on.
     *
     * Without this a crashed run leaves the row saying "running" until the stale sweep
     * catches it, and the operator watches a progress bar that will never move. The partial
     * goes too: nothing is coming back for it, and an object left on a paid disk under a key
     * no later run will trust is litter with a storage bill.
     */
    public function failed(Throwable $e): void
    {
        $this->discardPartial();
        $this->failTask($e->getMessage());
    }

    /** Throw away whatever this run left half-written, and forget where it got to. */
    private function discardPartial(): void
    {
        try {
            $part = $this->checkpoint()['part'] ?? null;

            if (is_string($part) && $part !== '') {
                app(QrExportService::class)->deletePart($part);
            }

            $this->clearCheckpoint();
        } catch (Throwable $e) {
            // Tidying up, not the job. A disk that cannot be reached here is very likely the
            // reason the run failed in the first place, and throwing again would replace the
            // operator's explanation with this one.
            Log::warning('QR export could not clear its partial archive.', [
                'task' => $this->task_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write (or replace) the row describing the archive.
     *
     * updateOrCreate on the campaign and key, so regenerating the same export replaces its
     * row instead of leaving a history of one row per attempt at the same thing.
     */
    private function record(
        Campaign $campaign,
        CampaignTask $task,
        string $key,
        string $path,
        array $opts,
        QrExportService $exports,
        int $bytes,
        int $codes,
        ?array $manifest,
    ): QrExport {
        return QrExport::updateOrCreate(
            ['campaign_id' => $campaign->id, 'cache_key' => $key],
            [
                'requested_by' => $task->requested_by,
                'status' => QrExport::STATUS_READY,
                'settings' => $opts,
                // The disk the service resolved, not the one configured: an unusable cloud
                // disk falls back to a working one, and a download next week has to look
                // where the bytes went rather than where they were meant to go.
                'disk' => $exports->diskName(),
                'path' => $path,
                'bytes' => $bytes,
                'codes_total' => $codes,
                // What is inside, so a list can say so without fetching the archive and
                // opening it. Counted off the archive itself when this run built one. A run
                // that found the archive already stored has not opened it and does not
                // describe it from settings: the row written when it was built is the
                // description, and fetching a hundred megabytes from a bucket to re-count
                // what is already recorded would be the opposite of what the cache is for.
                'manifest' => $manifest
                    ?? $campaign->qrExports()->where('cache_key', $key)->value('manifest')
                    ?? $this->manifestFromSettings($opts, $exports, $codes),
                'expires_at' => now()->addDays((int) config('cardano.qr_storage.ttl_days', 7)),
            ],
        );
    }

    /** What the finished run tells the page. */
    private function result(QrExport $export, bool $fromStorage): array
    {
        return [
            'export_id' => $export->id,
            'cache_key' => $export->cache_key,
            'bytes' => $export->bytes,
            'codes' => $export->codes_total,
            // Whether anything was rendered. The page says so, because "ready in under a
            // second" otherwise reads as a render that skipped most of the work.
            'from_storage' => $fromStorage,
        ];
    }

    /**
     * What an export says about itself when nobody opened it.
     *
     * Only reached where an archive is on the disk with no row describing it: one built
     * before the row existed, or one whose row was pruned. The folder breakdown is absent
     * rather than guessed, because it is a fact about a file this run has not read.
     */
    private function manifestFromSettings(array $opts, QrExportService $exports, int $codes): array
    {
        $manifest = [
            'layout' => $exports->grouping($opts),
            'extension' => strtolower($opts['format']),
            'entries' => $codes,
        ];

        if (($scope = $exports->scope($opts)) !== null) {
            $manifest['scope'] = $scope;
        }

        return $manifest;
    }

    /**
     * Why there is nothing to render, in the words of what was asked for.
     *
     * An operator who asked for one partner's stack and is told the campaign has no codes
     * would go looking at the campaign, which is not where the answer is.
     */
    private function nothingToExport(array $opts): string
    {
        return match ($opts['scope'] ?? null) {
            null => 'That campaign has no codes to export.',
            QrExportService::SCOPE_UNASSIGNED => 'Every code on this campaign has been given to a partner, so there are no unassigned codes to export.',
            default => 'That partner has no codes to export.',
        };
    }

    /**
     * What is wrong with these settings, or null when nothing is.
     *
     * The same bounds the request validates against, because a run that renders outside them
     * produces stickers nobody can scan.
     */
    private function settingsProblem(array $opts, Campaign $campaign): ?string
    {
        if (! in_array($opts['format'], QrStickerService::FORMATS, true)) {
            return "This export asks for a '{$opts['format']}' file, which is not a format that can be rendered.";
        }

        if (! in_array($opts['ecc'], QrStickerService::ECC_LEVELS, true)) {
            return "This export asks for error correction level '{$opts['ecc']}', which is not one of the levels a QR code has.";
        }

        if ($opts['size'] < 0.5 || $opts['size'] > 4) {
            return 'This export asks for a sticker outside the range that can be printed.';
        }

        if ($opts['dpi'] < 72 || $opts['dpi'] > 1200) {
            return 'This export asks for a resolution outside the range that can be printed.';
        }

        if (! in_array($opts['group'], QrExportService::GROUPINGS, true)) {
            return "This export asks to be laid out as '{$opts['group']}', which is not a layout an archive has.";
        }

        $scope = $opts['scope'];

        // A partner id in the row is an id some request wrote there, and this run is on a
        // different machine reading a JSON column. One naming another tenant's partner would
        // render nothing at all, because the codes are also scoped to this campaign, but it
        // would say "that partner has no codes" about a partner that is not theirs to ask
        // about. Removed partners are accepted here: a stored export asked for again after
        // its partner was taken off the picker is still an export of the codes it named.
        if ($scope !== null
            && $scope !== QrExportService::SCOPE_UNASSIGNED
            && ! Partner::withTrashed()->where('campaign_id', $campaign->id)->whereKey($scope)->exists()) {
            return 'This export asks for a partner that is not on this campaign.';
        }

        return null;
    }

    /**
     * The export settings this run was asked for.
     *
     * Defaulted individually rather than trusted wholesale: the payload is a JSON column
     * written by a request, and a run that renders with a missing dpi is a run that renders
     * nothing. These are the same defaults the request validates against.
     */
    private function options(?array $payload): array
    {
        $opts = $payload['options'] ?? [];

        return [
            'format' => is_string($opts['format'] ?? null) ? strtolower($opts['format']) : 'pdf',
            'size' => (float) ($opts['size'] ?? 1.0),
            'dpi' => (int) ($opts['dpi'] ?? 203),
            'ecc' => is_string($opts['ecc'] ?? null) ? $opts['ecc'] : 'L',
            'header' => (bool) ($opts['header'] ?? false),
            'footer' => (bool) ($opts['footer'] ?? false),
            // A flat archive unless the row says otherwise, which is what every export
            // written before grouping existed says by saying nothing.
            'group' => is_string($opts['group'] ?? null) ? $opts['group'] : QrExportService::GROUP_FLAT,
            // Null is every code on the campaign. A blank string is the same answer arriving
            // from a form, and reading it as a partner id would render an empty archive.
            'scope' => is_string($opts['scope'] ?? null) && $opts['scope'] !== '' ? $opts['scope'] : null,
        ];
    }
}
