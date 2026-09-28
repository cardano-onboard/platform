<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksCampaignTask;
use App\Models\Campaign;
use App\Models\Partner;
use App\Services\CodeCapacity;
use App\Support\StorageDisk;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProcessUploadedCodes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksCampaignTask;

    /** What this run is called on the campaign page. */
    public const TASK_TYPE = 'codes-import';

    public function maxFileSize(): int
    {
        return config('cardano.max_file_size', 10 * 1024 * 1024);
    }

    public function maxCodes(): int
    {
        return config('cardano.max_codes', 10000);
    }

    /**
     * One run per uploaded file rather than one per campaign.
     *
     * Two different files should import side by side; the same file posted twice should
     * not, because the second post is a double click rather than a second import. Hashed
     * because an upload key is longer than the column and reveals where the file went.
     */
    public static function dedupeKey(string $filePath): string
    {
        return hash('sha256', $filePath);
    }

    /**
     * What a finished import changes on the campaign page.
     *
     * The codes live inside the campaign prop rather than at the top level, and the charts
     * count them, so those two are what the page has to fetch again. Everything else on
     * the page, the balance call included, stays put.
     *
     * @return list<string>
     */
    public static function reloads(): array
    {
        return ['campaign', 'stats'];
    }

    public function __construct(
        public string $campaign_id,
        public string $file_path,
        public ?string $task_id = null,
        // Who the imported batch is being handed to, chosen when the import was started.
        // Nullable, which is the named choice "Unassigned" and is what every import from
        // before partners existed carries.
        public ?string $partner_id = null,
    ) {}

    public function handle(): void
    {
        $this->beginTask('Reading the file');

        // The bulk upload lands wherever the signed-upload endpoint put it, which is the
        // app's default disk, and that disk is not always called "s3": a host that injects
        // its own bucket exposes it under whatever disk name the bucket was created with.
        // Hard-coding "s3" here broke both those hosts (no such bucket configured) and
        // self-hosted installs (no AWS_* at all) with a null-bucket TypeError.
        $disk = Storage::disk(StorageDisk::resolve());

        $maxFileSize = $this->maxFileSize();
        $fileSize = $disk->size($this->file_path);
        if ($fileSize > $maxFileSize) {
            Log::error('Uploaded codes file exceeds max size.', [
                'campaign' => $this->campaign_id,
                'file_size' => $fileSize,
                'max_size' => $maxFileSize,
            ]);

            $this->failTask(sprintf(
                'That file is %s and the limit is %s.',
                $this->megabytes($fileSize),
                $this->megabytes($maxFileSize),
            ));

            return;
        }

        $import_content = $disk->json($this->file_path);

        if (! is_array($import_content)) {
            Log::error('Uploaded codes file has invalid JSON structure.', [
                'campaign' => $this->campaign_id,
            ]);

            $this->failTask('That file is not the JSON object this import expects.');

            return;
        }

        $maxCodes = $this->maxCodes();
        if (count($import_content) > $maxCodes) {
            Log::error('Uploaded codes file exceeds max code count.', [
                'campaign' => $this->campaign_id,
                'code_count' => count($import_content),
                'max_codes' => $maxCodes,
            ]);

            $this->failTask(sprintf(
                'That file holds %s codes and the limit is %s.',
                number_format(count($import_content)),
                number_format($maxCodes),
            ));

            return;
        }

        $campaign = Campaign::find($this->campaign_id);

        if (! $campaign) {
            Log::error('Codes import skipped: campaign not found.', [
                'campaign' => $this->campaign_id,
            ]);

            $this->failTask('That campaign no longer exists.');

            return;
        }

        $total = count($import_content);
        $capacity = app(CodeCapacity::class);
        $partnerId = $this->resolvePartnerId();

        // The campaign's own cap on how many codes it may ever hold — not $maxCodes above,
        // which is how many rows one uploaded file may contain. Null for an uncapped
        // campaign, where nothing below ever reads it. Otherwise a running estimate of the
        // campaign's own row count, read once here rather than recounted for every row:
        // most rows in a large capped import are nowhere near the limit, and asking the
        // database to prove that again each time is the exact per-row cost this exists to
        // avoid. The estimate is never what refuses a row — it only decides whether this
        // row's insert bothers CodeCapacity's own locked, authoritative count at all. Once
        // it says the campaign is within one row of its cap, every row from there on goes
        // through that count, so the actual limit is always enforced against the
        // database's real state, not this number.
        $codeCap = $campaign->max_codes !== null ? (int) $campaign->max_codes : null;
        $runningTotal = $codeCap !== null ? $campaign->codes()->count() : null;

        $imported_codes = 0;
        $imported_tokens = 0;
        $skipped = 0;
        $read = 0;

        $this->stage('Creating codes');
        $this->progress(0, $total);

        foreach ($import_content as $codeString => $data) {
            $read++;
            $this->progress($read, $total);

            if (! is_array($data) || ! isset($data['lovelaces']) || ! is_numeric($data['lovelaces'])) {
                Log::warning('Skipping invalid code entry.', ['code' => $codeString, 'campaign' => $this->campaign_id]);
                $skipped++;

                continue;
            }

            // A row whose code string this campaign already has is a re-run of a file
            // already (partly) imported, not a new row. It is skipped before the cap is
            // ever asked about it, so it consumes none of the campaign's remaining room:
            // counting it would refuse a re-run near the cap for rows that would never
            // actually be inserted.
            if ($campaign->codes()->where('code', $codeString)->exists()) {
                $skipped++;

                continue;
            }

            $create = function () use ($campaign, $codeString, $partnerId, $data) {
                $code = $campaign->codes()->create([
                    'code' => $codeString,
                    'partner_id' => $partnerId,
                    'perWallet' => 1,
                    'uses' => 1,
                    'lovelace' => (int) $data['lovelaces'],
                ]);

                $rowTokens = 0;

                foreach ($data as $token => $quantity) {
                    if ($token === 'lovelaces') {
                        continue;
                    }

                    $parts = explode('.', $token);
                    if (count($parts) !== 2) {
                        Log::warning('Skipping token with invalid format.', ['token' => $token, 'campaign' => $this->campaign_id]);

                        continue;
                    }

                    [$policy_hex, $asset_hex] = $parts;

                    if (! ctype_xdigit($policy_hex) || ! ctype_xdigit($asset_hex)) {
                        Log::warning('Skipping token with non-hex identifiers.', ['token' => $token, 'campaign' => $this->campaign_id]);

                        continue;
                    }

                    $code->rewards()->create(compact('policy_hex', 'asset_hex', 'quantity'));
                    $rowTokens++;
                }

                return [$code, $rowTokens];
            };

            try {
                if ($codeCap !== null && $runningTotal < $codeCap - 1) {
                    // Comfortably under the cap by the running estimate: the code and its
                    // rewards still need to land together, which the transaction alone
                    // guarantees, but nothing here is worth locking the campaign row or
                    // asking the database to recount it for.
                    [$code, $rowTokens] = DB::transaction($create);
                } else {
                    // Either uncapped (CodeCapacity itself takes no lock for that) or
                    // within one row of the cap, where only the database's own locked
                    // count can say whether this row still fits: two imports racing for
                    // the same remaining room cannot both see space for their next row
                    // and both write it, because each row is its own reservation rather
                    // than one reservation for the whole file.
                    [$code, $rowTokens] = $capacity->reserve($campaign, 1, $create);
                }

                if ($codeCap !== null) {
                    $runningTotal++;
                }
            } catch (ValidationException $e) {
                // The campaign's own cap is full. Nothing about to happen in this run
                // frees up room, so every row after this one would fail exactly the same
                // way; the rest of the file is counted as skipped here rather than
                // attempted one row at a time.
                $remaining = $total - $read + 1;

                Log::error('Uploaded codes import stopped: the campaign code cap is full.', [
                    'campaign' => $this->campaign_id,
                    'max_codes' => $campaign->max_codes,
                    'imported' => $imported_codes,
                    'remaining' => $remaining,
                ]);

                // Reported as a failure rather than folded into a success with the count
                // buried in $result: a log line the operator never reads is indistinguishable
                // from an import that quietly worked, and the campaign page already has
                // nowhere it shows a completed run's result, only a failed one's message.
                $this->failTask(sprintf(
                    'Stopped after %s of %s codes: the campaign is capped at %s and reached it. The remaining %s rows were not imported.',
                    number_format($imported_codes),
                    number_format($total),
                    number_format((int) $campaign->max_codes),
                    number_format($remaining),
                ));

                return;
            } catch (Exception $e) {
                $skipped++;

                continue;
            }

            $imported_codes++;
            $imported_tokens += $rowTokens;
        }

        Log::debug('Done importing codes!', [
            'campaign' => $this->campaign_id,
            'codes' => $imported_codes,
            'tokens' => $imported_tokens,
            'partner' => $partnerId,
        ]);

        // The skipped count is reported rather than buried in the log, because an import
        // that silently drops half its rows looks exactly like one that worked.
        $this->succeed([
            'codes' => $imported_codes,
            'tokens' => $imported_tokens,
            'skipped' => $skipped,
        ]);
    }

    /** A byte count as an operator would say it. */
    private function megabytes(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1).' MB';
    }

    /**
     * The partner to stamp on every imported code, checked again here rather than trusted
     * from the payload.
     *
     * A queued job carries whatever it was dispatched with, and it runs later on a
     * different machine, so the id is re-checked against this campaign before it is
     * written. One that belongs to another campaign is dropped and the import goes ahead
     * unassigned: refusing the whole file would lose ten thousand codes over an
     * attribution the operator can add to the next batch.
     *
     * Trashed partners count. Deleting one between starting an import and the worker picking
     * it up does not change who the codes were handed to, and codes keep pointing at a
     * soft-deleted partner by design.
     */
    private function resolvePartnerId(): ?string
    {
        if ($this->partner_id === null) {
            return null;
        }

        $belongsHere = Partner::withTrashed()
            ->whereKey($this->partner_id)
            ->where('campaign_id', $this->campaign_id)
            ->exists();

        if ($belongsHere) {
            return $this->partner_id;
        }

        Log::warning('Ignoring a partner that does not belong to this campaign.', [
            'campaign' => $this->campaign_id,
            'partner' => $this->partner_id,
        ]);

        return null;
    }
}
