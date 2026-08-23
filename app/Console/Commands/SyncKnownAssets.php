<?php

namespace App\Console\Commands;

use App\Models\KnownAsset;
use App\Services\KoiosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync the local known-assets registry from the Koios token registry.
 *
 * Runs on a schedule (see routes/console.php) so reward/asset metadata — names,
 * tickers, and especially decimals — is served from our own table instead of hitting
 * Koios on every page view. Live Koios lookups are then only a fallback for tokens the
 * registry doesn't know yet.
 *
 *   php artisan assets:sync-registry [--network=mainnet] [--limit=1000]
 */
class SyncKnownAssets extends Command
{
    /** Networks with a Koios token registry that campaigns use. */
    private const ALL_NETWORKS = ['mainnet', 'preprod', 'preview'];

    protected $signature = 'assets:sync-registry
        {--network=all : Network to sync (mainnet|preprod|preview), or "all"}
        {--limit=1000 : Page size for the Koios request}
        {--pages=0 : Max pages to fetch per network (0 = all; a safety cap for the scheduled job)}';

    protected $description = 'Fetch the latest known tokens from the Koios token registry into the known_assets table';

    public function handle(KoiosService $koios): int
    {
        $requested = $this->option('network');
        $networks = $requested === 'all' ? self::ALL_NETWORKS : [$requested];
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $maxPages = max(0, (int) $this->option('pages'));

        $total = 0;
        foreach ($networks as $network) {
            $total += $this->syncNetwork($koios, $network, $limit, $maxPages);
        }

        $this->info("✔ Synced {$total} known token(s) across: ".implode(', ', $networks).'.');

        return self::SUCCESS;
    }

    private function syncNetwork(KoiosService $koios, string $network, int $limit, int $maxPages): int
    {
        $offset = 0;
        $upserted = 0;
        $page = 0;

        $this->info("Syncing known tokens from Koios ({$network})...");

        do {
            if ($maxPages > 0 && $page >= $maxPages) {
                $this->warn("Reached --pages={$maxPages} cap; stopping.");
                break;
            }
            $page++;
            $rows = $koios->tokenRegistryPage($offset, $limit, $network);
            if ($rows === null) {
                // Hard failure after retries — stop but make the truncation loud rather
                // than silently reporting a "successful" partial sync.
                $this->error("  Koios failed at offset {$offset}; {$network} sync is INCOMPLETE (got {$upserted} so far).");
                Log::error('assets:sync-registry incomplete', ['network' => $network, 'stopped_at_offset' => $offset, 'upserted' => $upserted]);
                break;
            }
            if (empty($rows)) {
                break; // genuine end of the registry
            }

            $now = now();
            $batch = [];
            foreach ($rows as $r) {
                $policy = strtolower($r['policy_id'] ?? '');
                if ($policy === '') {
                    continue;
                }
                $batch[] = [
                    'policy_id' => $policy,
                    'asset_name' => strtolower($r['asset_name'] ?? ''),
                    'network' => $network,
                    'ticker' => $r['ticker'] ?? null,
                    'name' => $r['name'] ?? ($r['asset_name_ascii'] ?? null),
                    'decimals' => (int) ($r['decimals'] ?? 0),
                    'description' => isset($r['description']) ? mb_substr((string) $r['description'], 0, 1000) : null,
                    // Logos are large base64 blobs; keep the table lean and fetch a logo
                    // on demand via the single-asset lookup fallback when a token is viewed.
                    'metadata' => json_encode(['url' => $r['url'] ?? null, 'ascii' => $r['asset_name_ascii'] ?? null]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($batch) {
                KnownAsset::upsert(
                    $batch,
                    ['policy_id', 'asset_name', 'network'],
                    ['ticker', 'name', 'decimals', 'description', 'metadata', 'updated_at']
                );
                $upserted += count($batch);
            }

            $this->line("  offset {$offset}: ".count($rows).' rows');
            $offset += $limit;
            usleep(400_000); // be polite to the public endpoint between pages
        } while (count($rows) === $limit);

        Log::info('assets:sync-registry completed', ['network' => $network, 'upserted' => $upserted]);
        $this->line("  {$network}: {$upserted} token(s)");

        return $upserted;
    }
}
