<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KoiosService
{
    /**
     * Resolve on-chain + token-registry metadata for a single native asset
     * via Koios `asset_info`. Returns normalized data, or null when the asset
     * is unknown / the request fails.
     */
    public function assetInfo(string $policyId, string $assetName, string $network = 'mainnet'): ?array
    {
        $policyId = strtolower(trim($policyId));
        $assetName = strtolower(trim($assetName));
        $subject = $policyId.$assetName;

        return Cache::remember(
            "koios:asset_info:{$network}:{$subject}",
            now()->addHours(24),
            function () use ($policyId, $assetName, $network) {
                try {
                    $response = Http::koios($network)->post('asset_info', [
                        '_asset_list' => [[$policyId, $assetName]],
                    ]);

                    if (! $response->successful()) {
                        Log::warning('Koios asset_info request failed', [
                            'network' => $network,
                            'status' => $response->status(),
                        ]);

                        return null;
                    }

                    $asset = $response->json()[0] ?? null;

                    return $asset ? $this->normalize($asset) : null;
                } catch (\Throwable $e) {
                    Log::error('Koios asset_info error: '.$e->getMessage());

                    return null;
                }
            }
        );
    }

    /**
     * Fetch one page of the curated token registry (`asset_token_registry`) — the
     * source for the periodic known-assets sync. Returns raw Koios rows.
     */
    /**
     * @return array|null Rows for the page (an empty array means end-of-registry), or
     *                    null if the request kept failing — so callers can distinguish a
     *                    genuine end from a transient failure and not truncate the sync.
     */
    public function tokenRegistryPage(int $offset, int $limit = 1000, string $network = 'mainnet'): ?array
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $response = Http::koios($network)->get('asset_token_registry', [
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

                if ($response->successful()) {
                    return $response->json() ?: []; // [] = genuine end of the registry
                }

                Log::warning('Koios asset_token_registry non-200', [
                    'network' => $network, 'offset' => $offset,
                    'status' => $response->status(), 'attempt' => $attempt,
                ]);
            } catch (\Throwable $e) {
                Log::error("Koios asset_token_registry error (attempt {$attempt}): ".$e->getMessage());
            }

            sleep($attempt); // linear backoff — rides out rate limits (429) on later pages
        }

        return null; // hard failure after retries
    }

    /**
     * Normalize a Koios asset_info row into the shape the app uses everywhere.
     */
    private function normalize(array $asset): array
    {
        $registry = $asset['token_registry_metadata'] ?? [];

        return [
            'policy_id' => $asset['policy_id'] ?? null,
            'asset_name' => $asset['asset_name'] ?? null,
            'asset_name_ascii' => $asset['asset_name_ascii'] ?? null,
            'fingerprint' => $asset['fingerprint'] ?? null,
            'name' => $registry['name'] ?? ($asset['asset_name_ascii'] ?? null),
            'ticker' => $registry['ticker'] ?? null,
            'decimals' => (int) ($registry['decimals'] ?? 0),
            'logo' => $registry['logo'] ?? null,
            'description' => $registry['description'] ?? null,
        ];
    }
}
