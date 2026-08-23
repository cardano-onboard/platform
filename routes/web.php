<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CodeController;
use App\Http\Controllers\KnownAssetController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', static function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
    ]);
});

Route::get('/dashboard', static function () {
    return Inertia::render('Dashboard', [
        'campaigns' => \App\Models\Campaign::with(['wallet'])
            ->withCount('codes', 'claims')
            ->get(),
    ]);
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(static function () {
    Route::resource('campaigns', CampaignController::class);
    Route::post('/campaigns/{campaign}/check-claims', [CampaignController::class, 'checkClaims'])
        ->name('campaigns.check-claims');
    Route::post('/campaigns/{campaign}/refund', [CampaignController::class, 'refund'])
        ->name('campaigns.refund');
    Route::get('/campaigns/{campaign}/download-qr', [CampaignController::class, 'downloadQrCodes'])
        ->name('campaigns.download-qr');
    Route::resource('codes', CodeController::class);

    // Known-asset registry — the controller ships with the DIY build and the
    // campaign screen calls these endpoints, so they must be routed here too.
    Route::get('/known-assets', [KnownAssetController::class, 'index'])
        ->name('known-assets.index');
    Route::get('/known-assets/lookup', [KnownAssetController::class, 'lookup'])
        ->name('known-assets.lookup');
    Route::post('/known-assets', [KnownAssetController::class, 'store'])
        ->name('known-assets.store');
});

require __DIR__.'/auth.php';
