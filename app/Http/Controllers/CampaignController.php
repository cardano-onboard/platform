<?php

namespace App\Http\Controllers;

use App\Contracts\TransactionBackend;
use App\Jobs\CheckClaims;
use App\Jobs\CreateCampaignBucket;
use App\Models\Campaign;
use App\Models\Wallet;
use App\Rules\CardanoAddress;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller {

    public function __construct(private TransactionBackend $backend) {
        $this->authorizeResource(Campaign::class, 'campaign');
    }

    /**
     * Display a listing of the resource.
     */
    public function index() {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response {
        return Inertia::render('Campaign/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse {

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255', Rule::unique('campaigns', 'name')
                                ->where('user_id', Auth::user()->id)],
            'description'    => 'nullable|string|max:1000',
            'start_date'     => 'required|date',
            'end_date'       => 'required|date|after_or_equal:start_date',
            'one_per_wallet' => 'nullable|boolean',
            'network'        => 'required|in:preprod,preview,mainnet',
            'txn_msg'        => 'nullable|string|max:64',
            'nmkr_api_key'   => 'nullable|string|max:255',
        ]);

        $campaign = Campaign::create([
            'user_id'        => Auth::user()->id,
            'name'           => strip_tags($validated['name']),
            'description'    => strip_tags($validated['description'] ?? ''),
            'start_date'     => $validated['start_date'],
            'end_date'       => $validated['end_date'],
            'one_per_wallet' => $validated['one_per_wallet'] ?? false,
            'network'        => $validated['network'],
            'txn_msg'        => strip_tags($validated['txn_msg'] ?? ''),
            'nmkr_api_key'   => $validated['nmkr_api_key'] ?? null,
        ]);

        if ($campaign->id) {
            CreateCampaignBucket::dispatch($campaign->id);
        }

        return to_route('campaigns.show', $campaign->id);
    }

    /**
     * Display the specified resource.
     */
    public function show(Campaign $campaign): Response|RedirectResponse {

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
                $token_id = $reward_token['policy_hex'] . '.' . $reward_token['asset_hex'];
                if (!isset($reward_tokens[$token_id])) {
                    $reward_tokens[$token_id] = 0;
                }
                $reward_tokens[$token_id] += $reward_token['quantity'] * max(0, $code->uses - $code->claims_count);
            }
            unset($code->rewards);
        }
        $campaign->rewards = $reward_tokens;

        $walletPending = !$campaign->wallet;
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
        if (!$walletPending) {
            $currentBackend = config('cardano.transaction_backend') ?? 'null';
            $walletBackendName = $campaign->wallet->backend ?? 'phyrhose';
            $backendMismatch = $currentBackend !== $walletBackendName;
        }

        $campaignData = $campaign->only([
            'id', 'name', 'description', 'start_date', 'end_date',
            'one_per_wallet', 'network', 'txn_msg', 'nmkr_api_key', 'rewards',
        ]);
        $campaignData['wallet'] = $walletPending ? null : [
            'address' => $campaign->wallet->address,
        ];
        $campaignData['codes'] = $campaign->codes;
        $campaignData['claims'] = $campaign->claims;
        $campaignData['codes_count'] = $campaign->codes->count();
        $campaignData['claims_count'] = $campaign->claims->count();

        return Inertia::render('Campaign/Show', [
            'campaign'          => $campaignData,
            'claim_url'         => route('claim.v1', $campaign),
            'encoded_claim_url' => urlencode(route('claim.v1', $campaign)),
            'balance'           => $balance,
            'wallet_pending'    => $walletPending,
            'backend_mismatch'  => $backendMismatch,
            'wallet_backend'    => $walletPending ? null : ($campaign->wallet->backend ?? 'phyrhose'),
            'max_file_size'     => config('cardano.max_file_size', 10 * 1024 * 1024),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Campaign $campaign) {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Campaign $campaign): RedirectResponse {

        $rules = [
            'name'        => [
                'required',
                Rule::unique('campaigns', 'name')
                    ->where('user_id', Auth::user()->id)
                    ->ignore($campaign->id),
            ],
            'description' => 'nullable|string|max:1000',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date',
            'txn_msg'       => 'nullable|string|max:64',
            'nmkr_api_key'  => 'nullable|string|max:255',
        ];

        $hasClaims = $campaign->claims()->exists();

        if (!$hasClaims) {
            $rules['network']        = 'required|in:preprod,preview,mainnet';
            $rules['one_per_wallet'] = 'nullable|boolean';
        }

        $validated = $request->validate($rules);

        $campaign->name        = strip_tags($validated['name']);
        $campaign->description = strip_tags($validated['description'] ?? $campaign->description);
        $campaign->start_date  = $validated['start_date'];
        $campaign->end_date    = $validated['end_date'];
        $campaign->txn_msg      = strip_tags($validated['txn_msg'] ?? $campaign->txn_msg);
        $campaign->nmkr_api_key = $validated['nmkr_api_key'] ?? $campaign->nmkr_api_key;

        if (!$hasClaims) {
            $campaign->network        = $validated['network'] ?? $campaign->network;
            $campaign->one_per_wallet = $request->boolean('one_per_wallet');
        }

        $campaign->save();

        return to_route('campaigns.show', $campaign->id)
            ->with('message', 'Campaign updated successfully.');
    }

    /**
     * Manually trigger a check on pending claims for a campaign.
     */
    public function checkClaims(Campaign $campaign): RedirectResponse {
        $this->authorize('update', $campaign);

        $pendingCount = $campaign->claims()
                                 ->whereNotNull('transaction_id')
                                 ->whereNull('transaction_hash')
                                 ->where('status', '!=', 'failed')
                                 ->count();

        if ($pendingCount > 0) {
            CheckClaims::dispatch($campaign->id);
            return back()->with('message', "Checking {$pendingCount} pending claim(s). Refresh in a moment to see updates.");
        }

        return back()->with('message', 'No pending claims to check.');
    }

    /**
     * Trigger a refund of remaining bucket contents.
     */
    public function refund(Request $request, Campaign $campaign): RedirectResponse {
        $this->authorize('update', $campaign);

        $campaign->load('wallet');

        $validated = $request->validate([
            'address' => ['required', 'string', new CardanoAddress($campaign->network)],
        ]);

        $walletBackend = $campaign->wallet->resolveBackend();

        $success = $walletBackend->refund(
            $campaign->wallet->key,
            $validated['address'],
            $campaign->network
        );

        if ($success) {
            Log::info("Refund initiated successfully.", [
                'campaign_id' => $campaign->id,
            ]);
            return back()->with('message', 'Refund initiated successfully. Tokens will be sent to the specified address.');
        }

        Log::error("Refund request failed.", [
            'campaign_id' => $campaign->id,
        ]);

        return back()->with('message', 'Refund request failed. Please try again or contact support.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Campaign $campaign): RedirectResponse {
        Campaign::where('id', $campaign->id)
                ->where('user_id', Auth::user()->id)
                ->delete();

        return to_route('dashboard');
    }
}
