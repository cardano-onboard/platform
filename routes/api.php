<?php

use App\Http\Controllers\CodeController;
use App\Http\Controllers\PhyrhoseProxyController;
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
