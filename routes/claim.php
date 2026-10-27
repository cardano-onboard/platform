<?php

use App\Http\Controllers\CodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Claim Subdomain Routes
|--------------------------------------------------------------------------
|
| Registered only when config('cardano.claim_domain') is set (see bootstrap/app.php).
| Provides a short, dedicated claim endpoint — https://<claim_domain>/v1/{campaign} —
| so the QR deep-link payload is shorter (and the QR less dense) than the default
| /api/claim/v1/{campaign} route, which remains registered for backwards compatibility.
|
*/

Route::post('/v1/{campaign}', [CodeController::class, 'claim'])
    ->middleware('throttle:claim-api')
    ->name('claim.v1.short');

// The page a recipient opens in a desktop browser, at the same address as the endpoint
// above so it posts to the host it was served from.
Route::get('/v1/{campaign}', [CodeController::class, 'claimPage'])
    ->middleware(['web', 'throttle:claim-page'])
    ->name('claim.page.short');
