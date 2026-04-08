<?php

namespace App\Jobs;

use App\Models\Campaign;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedCodes implements ShouldQueue {

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function maxFileSize(): int
    {
        return config('cardano.max_file_size', 10 * 1024 * 1024);
    }

    public function maxCodes(): int
    {
        return config('cardano.max_codes', 10000);
    }

    public function __construct(public string $campaign_id, public string $file_path) {}

    public function handle(): void {
        $disk = Storage::disk('s3');

        $maxFileSize = $this->maxFileSize();
        $fileSize = $disk->size($this->file_path);
        if ($fileSize > $maxFileSize) {
            Log::error("Uploaded codes file exceeds max size.", [
                'campaign' => $this->campaign_id,
                'file_size' => $fileSize,
                'max_size' => $maxFileSize,
            ]);
            return;
        }

        $import_content = $disk->json($this->file_path);

        if (!is_array($import_content)) {
            Log::error("Uploaded codes file has invalid JSON structure.", [
                'campaign' => $this->campaign_id,
            ]);
            return;
        }

        $maxCodes = $this->maxCodes();
        if (count($import_content) > $maxCodes) {
            Log::error("Uploaded codes file exceeds max code count.", [
                'campaign' => $this->campaign_id,
                'code_count' => count($import_content),
                'max_codes' => $maxCodes,
            ]);
            return;
        }

        $campaign = Campaign::find($this->campaign_id);

        $imported_codes  = 0;
        $imported_tokens = 0;

        foreach ($import_content as $code => $data) {
            if (!is_array($data) || !isset($data['lovelaces']) || !is_numeric($data['lovelaces'])) {
                Log::warning("Skipping invalid code entry.", ['code' => $code, 'campaign' => $this->campaign_id]);
                continue;
            }

            try {
                $code = $campaign->codes()
                                 ->create([
                                     'code'      => $code,
                                     'perWallet' => 1,
                                     'uses'      => 1,
                                     'lovelace'  => (int) $data['lovelaces'],
                                 ]);
            } catch (Exception $e) {
                continue;
            }

            if (!$code) {
                Log::error("Could not create code.", ['campaign' => $this->campaign_id]);
                continue;
            }

            foreach ($data as $token => $quantity) {
                if ($token === 'lovelaces') {
                    continue;
                }

                $parts = explode('.', $token);
                if (count($parts) !== 2) {
                    Log::warning("Skipping token with invalid format.", ['token' => $token, 'campaign' => $this->campaign_id]);
                    continue;
                }

                [$policy_hex, $asset_hex] = $parts;

                if (!ctype_xdigit($policy_hex) || !ctype_xdigit($asset_hex)) {
                    Log::warning("Skipping token with non-hex identifiers.", ['token' => $token, 'campaign' => $this->campaign_id]);
                    continue;
                }

                $code->rewards()
                     ->create(compact('policy_hex', 'asset_hex', 'quantity'));

                $imported_tokens++;
            }
            $imported_codes++;
        }

        Log::debug("Done importing codes!", [
            'campaign' => $this->campaign_id,
            'codes'    => $imported_codes,
            'tokens'   => $imported_tokens,
        ]);
    }
}
