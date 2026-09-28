<?php

use App\Http\Controllers\CodeApiController;
use App\Http\Controllers\CodeController;
use App\Http\Controllers\PhyrhoseProxyController;
use App\Support\ApiAbilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')
    ->get('/user', static function (Request $request) {
        return $request->user();
    });

Route::post('/claim/v1/{campaign}', [
    CodeController::class,
    'claim',
])
    ->middleware('throttle:claim-api')
    ->name('claim.v1');

// Proxy API — quota-limited setup operations
Route::middleware(['auth:sanctum', 'proxy.quota'])
    ->prefix('v1/proxy')
    ->group(function () {
        Route::post('/bucket', [PhyrhoseProxyController::class, 'createBucket']);
        Route::post('/refund', [PhyrhoseProxyController::class, 'refund']);
    });

// Proxy API — unmetered claim operations (Phyrhose charges 1 ADA/claim)
Route::middleware(['auth:sanctum', 'proxy.log'])
    ->prefix('v1/proxy')
    ->group(function () {
        Route::post('/payment', [PhyrhoseProxyController::class, 'submitPayment']);
        Route::get('/status/{purchaseId}', [PhyrhoseProxyController::class, 'checkStatus']);
        Route::get('/balance', [PhyrhoseProxyController::class, 'getBalance']);
    });

// Code API — the one seam an external event application has into a campaign's codes.
// Each endpoint carries its own ability, so a token minted for one can never reach the
// other, and neither reaches anything a campaign owner's own session can. {code} is a
// plain string, not a bound model: the caller only ever holds the code returned by the
// first endpoint, and Code's route key is its numeric id, which nothing outside this
// application has any reason to know.
Route::middleware(['auth:sanctum', 'abilities:'.ApiAbilities::CODES_CREATE, 'throttle:code-api-create'])
    ->post('/v1/campaigns/{campaign}/codes', [CodeApiController::class, 'store'])
    ->name('api.codes.store');

Route::middleware(['auth:sanctum', 'abilities:'.ApiAbilities::CODES_STATUS, 'throttle:code-api-status'])
    ->get('/v1/campaigns/{campaign}/codes/{code}/status', [CodeApiController::class, 'status'])
    ->name('api.codes.status');
