<?php

namespace App\Http\Controllers;

use App\Contracts\TransactionBackend;
use App\Jobs\CheckClaims;
use App\Jobs\CreateCampaignBucket;
use App\Models\Campaign;
use App\Models\Wallet;
use App\Rules\CardanoAddress;
use App\Services\QrExportService;
use App\Services\QrStickerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function __construct(private TransactionBackend $backend)
    {
        $this->authorizeResource(Campaign::class, 'campaign');
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Campaign/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('campaigns', 'name')
                ->where('user_id', Auth::user()->id)],
            'description' => 'nullable|string|max:1000',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'one_per_wallet' => 'nullable|boolean',
            'network' => 'required|in:preprod,preview,mainnet',
            'txn_msg' => 'nullable|string|max:64',
            'nmkr_api_key' => 'nullable|string|max:255',
        ]);

        $campaign = Campaign::create([
            'user_id' => Auth::user()->id,
            'name' => strip_tags($validated['name']),
            'description' => strip_tags($validated['description'] ?? ''),
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'one_per_wallet' => $validated['one_per_wallet'] ?? false,
            'network' => $validated['network'],
            'txn_msg' => strip_tags($validated['txn_msg'] ?? ''),
            'nmkr_api_key' => $validated['nmkr_api_key'] ?? null,
        ]);

        if ($campaign->id) {
            CreateCampaignBucket::dispatch($campaign->id);
        }

        return to_route('campaigns.show', $campaign->id);
    }

    /**
     * Display the specified resource.
     */
    public function show(Campaign $campaign): Response|RedirectResponse
    {

        $campaign->load([
            'wallet',
            'codes',
            'codes.rewards',
            'claims',
        ]);

        $reward_tokens = [
            'lovelace' => 0,
        ];

        foreach ($campaign->codes as $code) {
            $reward_tokens['lovelace'] += $code->lovelace * max(0, $code->uses - $code->claims_count);
            foreach ($code->rewards as $reward_token) {
                $token_id = $reward_token['policy_hex'].'.'.$reward_token['asset_hex'];
                if (! isset($reward_tokens[$token_id])) {
                    $reward_tokens[$token_id] = 0;
                }
                $reward_tokens[$token_id] += $reward_token['quantity'] * max(0, $code->uses - $code->claims_count);
            }
            // Rewards are intentionally left attached so the codes table can show
            // per-code reward details. Reward::$hidden strips sensitive columns.
        }
        $campaign->rewards = $reward_tokens;

        $walletPending = ! $campaign->wallet;
        $balance = [];

        if ($walletPending) {
            // Self-healing: if no wallet exists, ensure a provisioning job is queued
            CreateCampaignBucket::dispatch($campaign->id);
        } else {
            $walletBackend = $campaign->wallet->resolveBackend();
            $balance = $walletBackend->getBalance($campaign->wallet->address, $campaign->network);
        }

        // Detect backend mismatch — wallet was created under a different backend than current config
        $backendMismatch = false;
        if (! $walletPending) {
            $currentBackend = config('cardano.transaction_backend') ?? 'null';
            $walletBackendName = $campaign->wallet->backend ?? 'phyrhose';
            $backendMismatch = $currentBackend !== $walletBackendName;
        }

        $campaignData = $campaign->only([
            'id', 'name', 'description', 'start_date', 'end_date',
            'one_per_wallet', 'network', 'txn_msg', 'nmkr_api_key', 'rewards',
            // Lifecycle status accessor — the frontend gates ended-campaign actions
            // (disable Add/Import Codes and Top Up) on this. Without it every such
            // control stays enabled on an ended campaign.
            'status',
        ]);
        $campaignData['wallet'] = $walletPending ? null : [
            'address' => $campaign->wallet->address,
        ];
        $campaignData['codes'] = $campaign->codes;
        $campaignData['claims'] = $campaign->claims;
        $campaignData['codes_count'] = $campaign->codes->count();
        $campaignData['claims_count'] = $campaign->claims->count();

        return Inertia::render('Campaign/Show', [
            'campaign' => $campaignData,
            'stats' => $this->buildStats($campaign),
            'claim_url' => $campaign->claimUrl(),
            'encoded_claim_url' => urlencode($campaign->claimUrl()),
            'balance' => $balance,
            'wallet_pending' => $walletPending,
            'backend_mismatch' => $backendMismatch,
            'wallet_backend' => $walletPending ? null : ($campaign->wallet->backend ?? 'phyrhose'),
            'max_file_size' => config('cardano.max_file_size', 10 * 1024 * 1024),
            // Gates the PNG option in the QR export modal — raster output needs GD,
            // which is present on the Vapor/Lambda runtime but not on every host.
            'gd_available' => \App\Services\QrStickerService::pngSupported(),
        ]);
    }

    /**
     * Build campaign performance stats for the charts/reports view.
     *
     * Aggregates are computed server-side because Claim::$created_at is hidden
     * from JSON serialization (so claims-over-time cannot be derived client-side).
     * Utilization buckets mirror filteredCodes() in resources/js/Pages/Campaign/Show.vue.
     */
    private function buildStats(Campaign $campaign): array
    {
        $claimsOverTime = [];
        $cumulative = 0;
        $byDay = $campaign->claims
            ->groupBy(fn ($claim) => $claim->created_at->format('Y-m-d'))
            ->sortKeys();

        foreach ($byDay as $date => $claims) {
            $count = $claims->count();
            $cumulative += $count;
            $claimsOverTime[] = [
                'date' => $date,
                'count' => $count,
                'cumulative' => $cumulative,
            ];
        }

        $claimed = 0;
        $unclaimed = 0;
        $utilization = [
            'total' => 0,
            'claimed' => 0,
            'unclaimed' => 0,
            'available' => 0,
            'exhausted' => 0,
        ];

        foreach ($campaign->codes as $code) {
            $uses = (int) $code->uses;
            $claimsCount = (int) $code->claims_count;

            $claimed += $claimsCount;
            $unclaimed += max(0, $uses - $claimsCount);

            $utilization['total']++;
            $utilization[$claimsCount > 0 ? 'claimed' : 'unclaimed']++;
            if ($uses === 0 || $claimsCount < $uses) {
                $utilization['available']++;
            }
            if ($uses > 0 && $claimsCount >= $uses) {
                $utilization['exhausted']++;
            }
        }

        return [
            'claims_over_time' => $claimsOverTime,
            'claimed_vs_unclaimed' => [
                'claimed' => $claimed,
                'unclaimed' => $unclaimed,
            ],
            'code_utilization' => $utilization,
        ];
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Campaign $campaign)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Campaign $campaign): RedirectResponse
    {

        $rules = [
            'name' => [
                'required',
                Rule::unique('campaigns', 'name')
                    ->where('user_id', Auth::user()->id)
                    ->ignore($campaign->id),
            ],
            'description' => 'nullable|string|max:1000',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'txn_msg' => 'nullable|string|max:64',
            'nmkr_api_key' => 'nullable|string|max:255',
        ];

        $hasClaims = $campaign->claims()->exists();

        if (! $hasClaims) {
            $rules['network'] = 'required|in:preprod,preview,mainnet';
            $rules['one_per_wallet'] = 'nullable|boolean';
        }

        $validated = $request->validate($rules);

        $campaign->name = strip_tags($validated['name']);
        $campaign->description = strip_tags($validated['description'] ?? $campaign->description);
        $campaign->start_date = $validated['start_date'];
        $campaign->end_date = $validated['end_date'];
        $campaign->txn_msg = strip_tags($validated['txn_msg'] ?? $campaign->txn_msg);
        $campaign->nmkr_api_key = $validated['nmkr_api_key'] ?? $campaign->nmkr_api_key;

        if (! $hasClaims) {
            $campaign->network = $validated['network'] ?? $campaign->network;
            $campaign->one_per_wallet = $request->boolean('one_per_wallet');
        }

        $campaign->save();

        return to_route('campaigns.show', $campaign->id)
            ->with('message', 'Campaign updated successfully.');
    }

    /**
     * Manually trigger a check on pending claims for a campaign.
     */
    public function checkClaims(Campaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        Log::info('CheckClaims button: triggered', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
        ]);

        $pendingCount = $campaign->claims()
            ->whereNotNull('transaction_id')
            ->whereNull('transaction_hash')
            ->where('status', '!=', 'failed')
            ->count();

        Log::info('CheckClaims button: pending claims counted', [
            'campaign_id' => $campaign->id,
            'pending_count' => $pendingCount,
        ]);

        if ($pendingCount > 0) {
            try {
                CheckClaims::dispatch($campaign->id);
                Log::info('CheckClaims button: job dispatched', [
                    'campaign_id' => $campaign->id,
                    'pending_count' => $pendingCount,
                ]);
            } catch (\Throwable $e) {
                Log::error('CheckClaims button: dispatch failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);

                return back()->with('message', 'Failed to trigger claim check. Please try again.');
            }

            return back()->with('message', "Checking {$pendingCount} pending claim(s). Refresh in a moment to see updates.");
        }

        Log::info('CheckClaims button: no pending claims', [
            'campaign_id' => $campaign->id,
        ]);

        return back()->with('message', 'No pending claims to check.');
    }

    /**
     * Trigger a refund of remaining bucket contents.
     */
    public function refund(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $campaign->load('wallet');

        Log::info('Refund: request received', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
            'destination_address' => $request->input('address'),
            'has_wallet' => $campaign->wallet !== null,
            'wallet_backend' => $campaign->wallet?->backend,
            'campaign_network' => $campaign->network,
        ]);

        $validated = $request->validate([
            'address' => ['required', 'string', new CardanoAddress($campaign->network)],
        ]);

        $walletBackend = $campaign->wallet->resolveBackend();

        Log::info('Refund: resolved backend, submitting', [
            'campaign_id' => $campaign->id,
            'backend_class' => get_class($walletBackend),
            'wallet_key' => $campaign->wallet->key,
            'destination_address' => $validated['address'],
            'network' => $campaign->network,
        ]);

        $success = $walletBackend->refund(
            $campaign->wallet->key,
            $validated['address'],
            $campaign->network
        );

        if ($success) {
            Log::info('Refund: initiated successfully', [
                'campaign_id' => $campaign->id,
                'destination_address' => $validated['address'],
            ]);

            return back()->with('message', 'Refund initiated successfully. Tokens will be sent to the specified address.');
        }

        Log::error('Refund: request failed', [
            'campaign_id' => $campaign->id,
            'destination_address' => $validated['address'],
            'network' => $campaign->network,
        ]);

        return back()->with('message', 'Refund request failed. Please try again or contact support.');
    }

    /**
     * Download QR codes for all campaign codes as a ZIP file.
     *
     * Format/size/DPI/ECC and optional header (expiration) + footer (code) captions
     * are chosen in the export modal — see \App\Services\QrStickerService.
     */
    public function downloadQrCodes(Request $request, Campaign $campaign, QrStickerService $stickers, QrExportService $exports)
    {
        $this->authorize('view', $campaign);

        $validated = $request->validate([
            'format' => ['nullable', Rule::in(QrStickerService::FORMATS)],
            'size' => ['nullable', 'numeric', 'between:0.5,4'],
            'dpi' => ['nullable', 'integer', 'between:72,1200'],
            'ecc' => ['nullable', Rule::in(QrStickerService::ECC_LEVELS)],
            'header' => ['nullable', 'boolean'],
            'footer' => ['nullable', 'boolean'],
        ]);

        $format = $validated['format'] ?? 'pdf';
        $size = (float) ($validated['size'] ?? 1.0);
        $dpi = (int) ($validated['dpi'] ?? 203);
        $ecc = $validated['ecc'] ?? 'L';
        $wantHeader = $request->boolean('header');
        $wantFooter = $request->boolean('footer');

        if ($format === 'png' && ! QrStickerService::pngSupported()) {
            throw ValidationException::withMessages([
                'format' => 'PNG export is unavailable on this server (missing GD extension). Choose PDF or SVG.',
            ]);
        }

        // A header + footer both fit only on a large-enough sticker; below the
        // threshold the QR would shrink too far to scan. Allow at most one caption.
        if ($wantHeader && $wantFooter && $size < QrStickerService::MIN_SIZE_BOTH_CAPTIONS) {
            throw ValidationException::withMessages([
                'footer' => 'A '.rtrim(rtrim(number_format($size, 2), '0'), '.').'" sticker is too small for both a header and footer. '
                    .'Use just one, or a sticker at least '.QrStickerService::MIN_SIZE_BOTH_CAPTIONS.'".',
            ]);
        }

        $codes = $campaign->codes()->get();

        if ($codes->isEmpty()) {
            return back()->with('message', 'No codes to download.');
        }

        $zipFileName = 'qrcodes-'.Str::slug($campaign->name).'.zip';

        // Idempotent cache: generate once per (settings + codes/expiration), reuse after.
        $opts = compact('format', 'size', 'dpi', 'ecc') + ['header' => $wantHeader, 'footer' => $wantFooter];
        $storedPath = $exports->path($campaign, $exports->cacheKey($campaign, $opts));

        if (! $exports->exists($storedPath)) {
            // An already-generated bundle is always downloadable, but we won't spend
            // compute generating NEW exports for a campaign whose codes can no longer be
            // claimed (the claim endpoint rejects them with ERROR_EXPIRED).
            if ($campaign->hasEnded()) {
                return back()->with('message',
                    'This campaign has ended, so new QR exports can\'t be generated. '
                    .'Any set you already downloaded remains available for your records.');
            }

            $claimBaseUrl = $campaign->claimUrl();
            $headerText = $wantHeader && $campaign->end_date ? 'Expires '.$campaign->end_date : null;
            $ext = $stickers->extension($format);

            if (! is_dir(storage_path('app/tmp'))) {
                mkdir(storage_path('app/tmp'), 0755, true);
            }
            $tmpZip = storage_path('app/tmp/'.Str::uuid().'.zip');

            $zip = new \ZipArchive;
            $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            foreach ($codes as $code) {
                $claimUri = 'web+cardano://claim/v1?faucet_url='.urlencode($claimBaseUrl).'&code='.$code->code;
                $footerText = $wantFooter ? $code->code : null;
                $zip->addFromString(
                    $code->code.'.'.$ext,
                    $stickers->render($claimUri, $format, $size, $dpi, $ecc, $headerText, $footerText),
                );
            }
            $zip->close();

            $exports->store($storedPath, $tmpZip);
            @unlink($tmpZip);
        }

        return $exports->respond($storedPath, $zipFileName);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Campaign $campaign): RedirectResponse
    {
        Campaign::where('id', $campaign->id)
            ->where('user_id', Auth::user()->id)
            ->delete();

        return to_route('dashboard');
    }
}
