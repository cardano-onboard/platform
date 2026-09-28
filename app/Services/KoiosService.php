<?php

namespace App\Services;

use App\Cardano\AssetMetadataReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KoiosService
{
    /** The request body limit of the Koios public tier, in bytes. */
    private const DEFAULT_BODY_BYTES = 5120;

    /** Below this a configured body limit is taken as a mistake and the default used. */
    private const MIN_BODY_BYTES = 256;

    /**
     * Display metadata for one native asset, from the off-chain registry, a CIP-0068
     * reference datum or CIP-0025 minting metadata (see AssetMetadataReader). Null when
     * Koios does not know the asset or the request fails.
     */
    public function assetInfo(string $policyId, string $assetName, string $network = 'mainnet'): ?array
    {
        $subject = strtolower(trim($policyId)).strtolower(trim($assetName));

        return $this->assetInfoMany([[$policyId, $assetName]], $network)[$subject] ?? null;
    }

    /**
     * What the cache already holds for these assets, without calling Koios. Keyed by
     * subject; an asset with nothing cached is absent, one known to be missing is null.
     *
     * @param  list<array{0: string, 1: string}>  $assets
     * @return array<string, array|null>
     */
    public function cachedAssetInfo(array $assets, string $network = 'mainnet'): array
    {
        $keys = [];
        foreach ($assets as [$policyId, $assetName]) {
            $subject = strtolower(trim($policyId)).strtolower(trim($assetName));
            $keys[self::assetCacheKey($network, $subject)] = $subject;
            $keys[self::missingCacheKey($network, $subject)] = $subject;
        }

        $results = [];
        foreach (Cache::many(array_keys($keys)) as $key => $value) {
            if ($value === null) {
                continue;
            }
            $subject = $keys[$key];
            if (str_starts_with($key, 'koios:asset_meta_missing:')) {
                $results[$subject] ??= null;
            } else {
                $results[$subject] = $value;
            }
        }

        return $results;
    }

    /**
     * asset_info for many assets, keyed by subject (policy id + asset name hex). An asset
     * Koios does not know maps to null.
     *
     * The assets go in as few calls as fit under Koios's request size limit (see
     * bodySizedChunks), one after another, and a call that fails leaves out only its own
     * assets.
     *
     * A campaign that hands out a distinct NFT per code has hundreds of reward assets, and
     * asking for each in its own request held one PHP worker per asset on a Koios round
     * trip until the host stopped answering anything else. Each answer is cached for as
     * long as its source can be trusted to hold (ttlFor), and a miss is remembered briefly so a page that keeps
     * asking about an unknown asset does not keep reaching Koios for it. A failed call
     * caches nothing, so an outage is not remembered as every asset being unknown.
     *
     * @param  list<array{0: string, 1: string}>  $assets  [policy id, asset name hex] pairs
     * @return array<string, array|null> an asset left out when its Koios call failed
     */
    public function assetInfoMany(array $assets, string $network = 'mainnet'): array
    {
        $results = [];
        $wanted = [];

        foreach ($assets as [$policyId, $assetName]) {
            $policyId = strtolower(trim($policyId));
            $assetName = strtolower(trim($assetName));
            $subject = $policyId.$assetName;

            if (($cached = Cache::get(self::assetCacheKey($network, $subject))) !== null) {
                $results[$subject] = $cached;
            } elseif (Cache::has(self::missingCacheKey($network, $subject))) {
                $results[$subject] = null;
            } else {
                $wanted[$subject] = [$policyId, $assetName];
            }
        }

        if ($wanted === []) {
            return $results;
        }

        foreach (self::bodySizedChunks($wanted) as $chunk) {
            $answered = $this->askAssetInfo($chunk, $network);
            // Koios could not be reached: the next call would wait out the same timeout.
            if ($answered === null) {
                break;
            }
            $results += $answered;
        }

        return $results;
    }

    /**
     * One asset_info call for assets none of which are cached. Keyed by subject; an asset
     * Koios does not know maps to null, and every asset is left out when Koios refused the
     * call. Null when Koios could not be reached at all.
     *
     * @param  array<string, array{0: string, 1: string}>  $wanted
     * @return array<string, array|null>|null
     */
    private function askAssetInfo(array $wanted, string $network): ?array
    {
        $results = [];

        try {
            // Bounded by KOIOS_TIMEOUT: this runs inside a web request, and a Koios call
            // that never answers would otherwise hold the worker for the client's default.
            $response = Http::koios($network)
                ->timeout(max(1, (int) config('cardano.koios.timeout', 10)))
                ->post('asset_info', [
                    '_asset_list' => array_values($wanted),
                ]);
        } catch (\Throwable $e) {
            Log::error('Koios asset_info batch error: '.$e->getMessage());

            return null;
        }

        $rows = $response->json();

        if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows)) {
            Log::warning('Koios asset_info batch request failed', [
                'network' => $network,
                'status' => $response->status(),
            ]);

            return [];
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $subject = strtolower(($row['policy_id'] ?? '').($row['asset_name'] ?? ''));
            if (isset($wanted[$subject])) {
                $results[$subject] = AssetMetadataReader::read($row);
                Cache::put(self::assetCacheKey($network, $subject), $results[$subject], self::ttlFor($results[$subject]['source']));
                unset($wanted[$subject]);
            }
        }

        foreach (array_keys($wanted) as $subject) {
            $results[$subject] = null;
            Cache::put(self::missingCacheKey($network, $subject), true, now()->addMinutes(10));
        }

        return $results;
    }

    /**
     * Splits an asset list into runs whose asset_info request body stays below
     * KOIOS_MAX_BODY_BYTES. Koios refuses a larger body outright with a 413, so fifty NFTs
     * with 28-byte names, about 6 KB, failed as one call and came back as nothing.
     *
     * @param  array<string, array{0: string, 1: string}>  $wanted
     * @return list<array<string, array{0: string, 1: string}>>
     */
    private static function bodySizedChunks(array $wanted): array
    {
        // A blank or nonsensical KOIOS_MAX_BODY_BYTES would otherwise send one asset per
        // call, which is the flood of requests this batching exists to prevent.
        $limit = (int) config('cardano.koios.max_body_bytes', 5120);
        if ($limit < self::MIN_BODY_BYTES) {
            $limit = self::DEFAULT_BODY_BYTES;
        }
        // The body is {"_asset_list":[...]}: the wrapper, then the pairs, comma separated.
        $wrapper = strlen(json_encode(['_asset_list' => []]));

        $chunks = [];
        $chunk = [];
        $size = $wrapper;

        foreach ($wanted as $subject => $pair) {
            $pairSize = strlen(json_encode($pair));
            // Koios wants the body below the limit, so a body of exactly the limit is refused.
            if ($chunk !== [] && $size + 1 + $pairSize >= $limit) {
                $chunks[] = $chunk;
                $chunk = [];
                $size = $wrapper;
            }
            $size += ($chunk === [] ? 0 : 1) + $pairSize;
            $chunk[$subject] = $pair;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    public function protocolParameters(string $network = 'mainnet'): ?array
    {
        return Cache::remember(
            "koios:epoch_params:{$network}",
            now()->addHours(6),
            function () use ($network) {
                try {
                    $response = Http::koios($network)->get('epoch_params', ['limit' => 1]);

                    if (! $response->successful()) {
                        Log::warning('Koios epoch_params request failed', [
                            'network' => $network,
                            'status' => $response->status(),
                        ]);

                        return null;
                    }

                    $coinsPerByte = (int) ($response->json('0.coins_per_utxo_size') ?? 0);

                    if ($coinsPerByte <= 0) {
                        Log::warning('Koios epoch_params carried no usable coins_per_utxo_size', [
                            'network' => $network,
                        ]);

                        return null;
                    }

                    return ['coins_per_utxo_byte' => $coinsPerByte];
                } catch (\Throwable $e) {
                    Log::error('Koios epoch_params error: '.$e->getMessage());

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
    /**
     * Whether a decoded list actually holds rows.
     *
     * A list on its own is not enough. A provider under load answers 200 with a list of
     * strings carrying a message, and a caller that reads an element as an array then finds
     * nothing in it, skips the row, and takes the short page for the end of the registry.
     * The list is the shape; the elements are the content, and both have to be right before
     * an answer is data.
     *
     * @param  list<mixed>  $rows
     */
    private static function everyElementIsARow(array $rows): bool
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }
        }

        return true;
    }

    public function tokenRegistryPage(int $offset, int $limit = 1000, string $network = 'mainnet'): ?array
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $response = Http::koios($network)->get('asset_token_registry', [
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

                if ($response->successful()) {
                    $rows = $response->json();

                    // A 200 whose body is not a list of rows is not an empty page. A
                    // proxy or a CDN interstitial answers 200 with something that does
                    // not decode, and reading that as the end of the registry truncates
                    // the sync at whatever page the outage happened on, silently, which
                    // is the one outcome the null return above exists to prevent.
                    if (is_array($rows) && array_is_list($rows) && self::everyElementIsARow($rows)) {
                        return $rows; // [] = genuine end of the registry
                    }

                    Log::warning('Koios asset_token_registry answered 2xx with something other than rows', [
                        'network' => $network, 'offset' => $offset, 'attempt' => $attempt,
                    ]);
                } else {
                    Log::warning('Koios asset_token_registry non-200', [
                        'network' => $network, 'offset' => $offset,
                        'status' => $response->status(), 'attempt' => $attempt,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("Koios asset_token_registry error (attempt {$attempt}): ".$e->getMessage());
            }

            sleep($attempt); // linear backoff — rides out rate limits (429) on later pages
        }

        return null; // hard failure after retries
    }

    private static function assetCacheKey(string $network, string $subject): string
    {
        return "koios:asset_meta:v1:{$network}:{$subject}";
    }

    private static function missingCacheKey(string $network, string $subject): string
    {
        return "koios:asset_meta_missing:v1:{$network}:{$subject}";
    }

    /**
     * How long an asset's metadata is cached, by where it came from. CIP-0025 metadata
     * changes only when the policy mints the asset again, which most policies can no
     * longer do, so it is kept for a long time but still expires. A CIP-0068 datum moves
     * whenever its reference NFT's script allows, so it is re-read daily. Registry tokens
     * are also written to known_assets; an asset with no metadata at all may gain some.
     */
    private static function ttlFor(string $source): \DateTimeInterface
    {
        return match ($source) {
            AssetMetadataReader::SOURCE_CIP25 => now()->addDays(max(1, (int) config('cardano.asset_metadata.cip25_cache_days', 365))),
            AssetMetadataReader::SOURCE_CIP68 => now()->addHours(max(1, (int) config('cardano.asset_metadata.cip68_cache_hours', 24))),
            default => now()->addHours(24),
        };
    }
}
