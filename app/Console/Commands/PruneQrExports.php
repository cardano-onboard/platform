<?php

namespace App\Console\Commands;

use App\Services\QrExportService;
use Illuminate\Console\Command;

class PruneQrExports extends Command
{
    protected $signature = 'qr:prune-exports {--days= : Override the max age in days}';

    protected $description = 'Delete cached QR export bundles older than the configured TTL';

    public function handle(QrExportService $exports): int
    {
        $days = (int) ($this->option('days') ?: config('cardano.qr_storage.ttl_days', 7));
        $disk = $exports->disk();
        $base = trim(config('cardano.qr_storage.path', 'qr-exports'), '/');
        $cutoff = now()->subDays($days)->getTimestamp();

        $deleted = 0;
        foreach ($disk->allFiles($base) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} QR export bundle(s) older than {$days} day(s) from [{$exports->diskName()}].");

        return self::SUCCESS;
    }
}
