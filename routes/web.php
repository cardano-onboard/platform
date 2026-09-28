<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CodeController;
use App\Http\Controllers\KnownAssetController;
use App\Http\Controllers\SignedStorageUrlController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', static function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
    ]);
});

// The signed PUT the bulk code upload asks for before it sends the file. Web
// middleware only, as on the hosted deployment: the controller authorises through
// the uploadFiles gate, which answers a signed-out request with a 403 the import
// dialog can explain rather than a redirect it cannot read. An install on the local
// disk has no signed upload to issue and this endpoint says so, which is the message
// the dialog shows.
Route::post('/uploads/signed-url', [SignedStorageUrlController::class, 'store'])
    ->name('uploads.signed-url');

Route::get('/dashboard', static function () {
    return Inertia::render('Dashboard', [
        'campaigns' => \App\Models\Campaign::with(['wallet'])
            ->withCount('codes', 'claims')
            ->get(),
        // The dashboard's campaign creation dialog filters its network selector on
        // this. Without it the dialog falls back to offering every network whatever
        // the deployment accepts.
        'allowed_networks' => config('cardano.allowed_networks'),
        // Where to send an operator who wants a mainnet campaign this deployment won't
        // create: MAINNET_APP_URL, and only while mainnet is excluded, as on the hosted
        // dashboard. The dialog reads it to explain where mainnet went.
        'mainnet_app_url' => in_array('mainnet', config('cardano.allowed_networks'), true)
            ? null
            : config('cardano.mainnet_app_url'),
    ]);
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(static function () {
    // Only the actions the interface reaches. See routes/web.php for why.
    Route::resource('campaigns', CampaignController::class)
        ->except(['index', 'create', 'edit']);
    Route::post('/campaigns/{campaign}/check-claims', [CampaignController::class, 'checkClaims'])
        ->name('campaigns.check-claims');
    Route::post('/campaigns/{campaign}/refund', [CampaignController::class, 'refund'])
        ->name('campaigns.refund');
    Route::get('/campaigns/{campaign}/download-qr', [CampaignController::class, 'downloadQrCodes'])
        ->name('campaigns.download-qr');
    // Asks for a sticker archive to be rendered. It ships here too, and matters more here:
    // the container's PHP gives a web request 120 seconds, which a large campaign's render
    // goes past, and the export dialog calls this route by name.
    Route::post('/campaigns/{campaign}/qr-exports', [CampaignController::class, 'requestQrExport'])
        ->middleware('throttle:qr-exports')
        ->name('campaigns.qr-exports.store');
    // Downloads one archive that has already been built. It ships here for the same reason:
    // the campaign page names this route for every stored export it offers, so without it
    // the page cannot be rendered at all rather than merely losing a button.
    Route::get('/campaigns/{campaign}/qr-exports/{qrExport}/download', [CampaignController::class, 'downloadQrExport'])
        ->name('campaigns.qr-exports.download');
    // The campaign page polls this while a background job runs. It ships here too: a
    // self-hosted box runs the same import on the same worker, and without the route Ziggy
    // throws on a page that calls it.
    Route::get('/campaigns/{campaign}/tasks', [CampaignController::class, 'tasks'])
        ->middleware('throttle:campaign-tasks')
        ->name('campaigns.tasks');
    // What was given away, one asset per row. Real for a self-hoster too: their network
    // fees and their assets are their own accounting even though they pay us nothing.
    Route::get('/campaigns/{campaign}/export-costs', [CampaignController::class, 'exportCosts'])
        ->name('campaigns.export-costs');
    // Onboarding analysis and the claim export. Both are campaign management, both ship
    // with this edition, and CampaignOnboarding.vue calls them by name without asking
    // whether the route exists, so leaving them out breaks the button rather than
    // hiding it.
    Route::post('/campaigns/{campaign}/analyze-onboarding', [CampaignController::class, 'analyzeOnboarding'])
        ->name('campaigns.analyze-onboarding');
    Route::get('/campaigns/{campaign}/export-claims', [CampaignController::class, 'exportClaims'])
        ->name('campaigns.export-claims');
    // Which partners produced claims. An operator report with nothing platform-specific
    // in it: somebody handing codes to their own volunteers wants to know which of them
    // brought people back for the same reason anybody else does.
    Route::get('/campaigns/{campaign}/export-partners', [CampaignController::class, 'exportPartners'])
        ->name('campaigns.export-partners');
    // update carries the reward edit, which is a dialog on the campaign page rather
    // than a page of its own. Editing what a code pays is campaign management and
    // ships with this edition, and Show.vue calls codes.update by name.
    Route::resource('codes', CodeController::class)
        ->only(['store', 'update', 'destroy']);
    // Clearing a campaign's codes, called from the campaign page the same way.
    Route::delete('/campaigns/{campaign}/codes', [CodeController::class, 'bulkDestroy'])
        ->name('campaigns.codes.bulk-destroy');
    // What a reward bundle has to be worth for the chain to accept it, asked of a form
    // that has not been submitted yet. The create and edit dialogs both call it as the
    // operator types, and a self-hosted install has the same chain floor as any other.
    Route::post('/campaigns/{campaign}/min-utxo', [CodeController::class, 'minUtxo'])
        ->name('campaigns.min-utxo');

    // Known-asset registry — the controller ships with the DIY build and the
    // campaign screen calls these endpoints, so they must be routed here too.
    Route::get('/known-assets', [KnownAssetController::class, 'index'])
        ->name('known-assets.index');
    Route::get('/known-assets/lookup', [KnownAssetController::class, 'lookup'])
        ->middleware('throttle:known-assets')
        ->name('known-assets.lookup');
    Route::post('/known-assets/lookup-many', [KnownAssetController::class, 'lookupMany'])
        ->middleware('throttle:known-assets')
        ->name('known-assets.lookup-many');
});

require __DIR__.'/auth.php';
