<?php

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    Log::info('Scheduler: check-pending-claims tick started');

    $campaignIds = Campaign::whereHas('claims', function ($query) {
        $query->whereNotNull('transaction_id')
            ->whereNull('transaction_hash')
            ->whereNotIn('status', ['failed', 'completed']);
    })->pluck('id');

    Log::info('Scheduler: check-pending-claims found campaigns', [
        'campaign_count' => $campaignIds->count(),
        'campaign_ids' => $campaignIds->toArray(),
    ]);

    $dispatched = 0;
    $errors = 0;

    foreach ($campaignIds as $campaignId) {
        try {
            CheckClaims::dispatch($campaignId);
            $dispatched++;
        } catch (\Throwable $e) {
            $errors++;
            Log::error('Scheduler: failed to dispatch CheckClaims', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    Log::info('Scheduler: check-pending-claims tick completed', [
        'dispatched' => $dispatched,
        'errors' => $errors,
    ]);
})->everyFiveMinutes()->name('check-pending-claims')->withoutOverlapping();

// Refresh the local known-assets registry from Koios so token metadata (names,
// tickers, decimals) is served from our table and Koios is only a fallback.
Schedule::command('assets:sync-registry')
    ->everySixHours()
    ->name('sync-known-assets')
    ->withoutOverlapping()
    ->runInBackground();

// Prune cached QR export bundles past their TTL. S3 deployments can instead use a
// native bucket lifecycle rule on the qr-exports/ prefix and disable this if desired.
Schedule::command('qr:prune-exports')
    ->daily()
    ->name('prune-qr-exports')
    ->withoutOverlapping()
    ->runInBackground();
