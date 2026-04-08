<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CodeController;
use Illuminate\Foundation\Application;
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
})
     ->middleware(['auth'])
     ->name('dashboard');

Route::middleware('auth')
     ->group(static function () {
         Route::resource('campaigns', CampaignController::class);
         Route::post('/campaigns/{campaign}/check-claims', [CampaignController::class, 'checkClaims'])
              ->name('campaigns.check-claims');
         Route::post('/campaigns/{campaign}/refund', [CampaignController::class, 'refund'])
              ->name('campaigns.refund');
         Route::resource('codes', CodeController::class);
     });

require __DIR__ . '/auth.php';
