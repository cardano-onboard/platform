<?php

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    $campaignIds = Campaign::whereHas('claims', function ($query) {
        $query->whereNotNull('transaction_id')
              ->whereNull('transaction_hash')
              ->where('status', '!=', 'failed');
    })->pluck('id');

    foreach ($campaignIds as $campaignId) {
        CheckClaims::dispatch($campaignId);
    }
})->everyFiveMinutes()->name('check-pending-claims')->withoutOverlapping();
