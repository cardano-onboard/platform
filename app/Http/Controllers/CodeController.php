<?php

namespace App\Http\Controllers;

use App\Exceptions\ClaimException;
use App\Http\Resources\TokenCollection;
use App\Jobs\ProcessClaims;
use App\Jobs\ProcessUploadedCodes;
use App\Models\Campaign;
use App\Models\Code;
use CardanoPhp\Bech32\Bech32;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CodeController extends Controller
{
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
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $campaign = Campaign::find($request->campaign_id);
        if (! $campaign || $campaign->user_id !== Auth::user()->id) {
            return to_route('dashboard');
        }

        // Codes added after the redemption window closes can never be claimed (the claim
        // endpoint rejects ended campaigns with ERROR_EXPIRED), so refuse both single
        // creation and bulk import. The UI disables these actions; this guards a direct POST.
        if ($campaign->hasEnded()) {
            return back()->with('message',
                'This campaign has ended, so codes can no longer be added. '
                .'Extend the end date to add more.');
        }

        if ($request->uploadedCodes) {

            $request->validate([
                'file_key' => 'required|string|regex:/^[a-zA-Z0-9\/_\-\.]+$/|max:500',
            ]);

            ProcessUploadedCodes::dispatch($campaign->id, $request->file_key);

            $request->session()
                ->flash('message',
                    'Codes file has been queued for import. Please allow a few minutes for import to complete.');

            return to_route('campaigns.show', $request->campaign_id);
        }

        $validated = $request->validate([
            'quantity' => 'nullable|integer|min:1|max:500',
            'lovelace' => 'integer|required|min:1000000|max:45000000000000000',
            'perWallet' => 'integer|required|min:0',
            'uses' => 'integer|required|min:1',
            'tokens' => 'nullable|array',
            'tokens.*.policy_id' => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.token_id' => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.quantity' => 'required_with:tokens|integer|min:1',
            'nmkr_project_uid' => 'nullable|string|max:255',
            'nmkr_count_nft' => 'nullable|integer|min:0|max:100',
        ]);

        $quantity = (int) ($validated['quantity'] ?? 1);

        for ($i = 0; $i < $quantity; $i++) {
            $code = $campaign->codes()
                ->create([
                    'code' => Code::generateUniqueCode($campaign->id),
                    'perWallet' => $validated['perWallet'],
                    'uses' => $validated['uses'],
                    'lovelace' => $validated['lovelace'],
                    'nmkr_project_uid' => $validated['nmkr_project_uid'] ?? null,
                    'nmkr_count_nft' => $validated['nmkr_count_nft'] ?? 0,
                ]);

            if ($code->id) {
                foreach ($validated['tokens'] ?? [] as $token) {
                    $code->rewards()
                        ->create([
                            'policy_hex' => $token['policy_id'],
                            'asset_hex' => $token['token_id'],
                            'quantity' => $token['quantity'],
                        ]);
                }
            }
        }

        Log::info('Codes created.', ['quantity' => $quantity, 'campaign_id' => $campaign->id]);

        return to_route('campaigns.show', $request->campaign_id);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Delete a single code.
     *
     * A code that has been claimed is not deletable. Its claims are the record that
     * someone was paid, and the campaign's own reporting is built on them, so removing
     * the code would either orphan that history or quietly erase it.
     */
    public function destroy(string $id): RedirectResponse
    {
        $code = Code::with('campaign')->findOrFail($id);
        $campaign = $code->campaign;

        $this->authorize('update', $campaign);

        if ($code->claims()->exists()) {
            return back()->with('message', 'That code has already been claimed, so it cannot be deleted.');
        }

        $this->deleteCodes(collect([$code]));

        Log::info('Code deleted.', [
            'campaign_id' => $campaign->id,
            'code_id' => $code->id,
            'user_id' => Auth::id(),
        ]);

        return back()->with('message', 'Code deleted.');
    }

    /**
     * Delete several codes at once, so a batch generated with the wrong reward can be
     * cleared instead of being deleted one row at a time.
     *
     * Two ways to say what to delete: an explicit list of code IDs, or every unclaimed
     * code in the campaign. The second exists because the realistic case is a bulk import
     * of several hundred codes that were all wrong, and selecting those by hand through a
     * paginated table is not a workflow.
     *
     * Claimed codes are never deleted. They are reported as skipped rather than failing
     * the whole request, so clearing a batch that saw a couple of early claims still works.
     */
    public function bulkDestroy(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'codes' => 'required_without:all_unclaimed|array',
            'codes.*' => 'integer',
            'all_unclaimed' => 'nullable|boolean',
        ]);

        // Scoped to the campaign, so an ID belonging to someone else's campaign cannot be
        // deleted by passing it in the list.
        $query = $campaign->codes()->withCount('claims');

        if (! $request->boolean('all_unclaimed')) {
            $query->whereIn('id', $validated['codes'] ?? []);
        }

        $codes = $query->get();
        $deletable = $codes->where('claims_count', 0);
        $skipped = $codes->count() - $deletable->count();

        if ($deletable->isEmpty()) {
            return back()->with('message', $skipped > 0
                ? 'Nothing deleted: every code selected has already been claimed.'
                : 'Nothing deleted: no matching codes were found.');
        }

        $this->deleteCodes($deletable);

        Log::info('Codes deleted in bulk.', [
            'campaign_id' => $campaign->id,
            'deleted' => $deletable->count(),
            'skipped_claimed' => $skipped,
            'user_id' => Auth::id(),
        ]);

        $message = $deletable->count().' code(s) deleted.';
        if ($skipped > 0) {
            $message .= " {$skipped} already claimed and kept.";
        }

        return back()->with('message', $message);
    }

    /**
     * Rewards are removed with their codes in one transaction. The rewards table has a
     * plain foreign key rather than a cascade, so leaving them behind fails the delete
     * outright; doing both in a transaction means a partial delete cannot leave a code
     * stripped of its rewards but still claimable.
     *
     * @param  \Illuminate\Support\Collection<int, Code>  $codes
     */
    private function deleteCodes($codes): void
    {
        $ids = $codes->pluck('id')->all();

        DB::transaction(static function () use ($ids) {
            \App\Models\Reward::whereIn('code_id', $ids)->delete();
            Code::whereIn('id', $ids)->delete();
        });
    }

    /**
     * @throws ClaimException
     */
    public function claim(Request $request, Campaign $campaign)
    {
        Log::info('Claim Request', [
            'campaign_id' => $campaign->id,
            'has_code' => ! empty($request->code ?? $request->claim_code),
            'has_address' => ! empty($request->address),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        // Helper to consistently log every claim response we send back to the client.
        // This makes it easy to verify response shape against the wallet/dApp specification.
        $logResponse = function (array $payload) use ($campaign) {
            Log::info('Claim Response', [
                'campaign_id' => $campaign->id,
                'http_status' => $payload['code'] ?? null,
                'status' => $payload['status'] ?? null,
                'response_keys' => array_keys($payload),
                'response' => $payload,
            ]);

            return $payload;
        };

        $provided_code = $request->code ?? $request->claim_code;

        if (! $provided_code) {
            throw new ClaimException('ERROR_MISSING_CODE');
        }

        if (! $request->address || ! preg_match('/^addr(_test)?1[023456789acdefghjklmnpqrstuvwxyz]{53,103}$/', $request->address)) {
            throw new ClaimException('ERROR_INVALID_ADDRESS');
        }

        try {
            $decoded_address = Bech32::decodeCardanoAddress($request->address);
        } catch (Exception $exception) {
            Log::error('Address decode failed.', ['campaign_id' => $campaign->id]);
            throw new ClaimException('ERROR_INVALID_ADDRESS');
        }

        $address_details = [
            'address' => $decoded_address['address'],
            'stake_key' => $decoded_address['stakeAddress'],
        ];

        if ($campaign->network === 'mainnet') {
            if ($decoded_address['networkId'] == 0) {
                Log::error('Address from wrong network?', [
                    'network' => $campaign->network,
                    'campaign' => $campaign,
                    'addressNetworkId' => $decoded_address['networkId'],
                    'decoded_address' => $decoded_address,
                ]);
                throw new ClaimException('ERROR_INVALID_NETWORK');
            }
            $phyrhose = Http::mainnet_phyrhose();
            $nmkr = Http::mainnet_nmkr();
        } else {
            if ($decoded_address['networkId'] == 1) {
                Log::error('Address from wrong network?', [
                    'network' => $campaign->network,
                    'campaign' => $campaign,
                    'addressNetworkId' => $decoded_address['networkId'],
                    'decoded_address' => $decoded_address,
                ]);
                throw new ClaimException('ERROR_INVALID_NETWORK');
            }
            $phyrhose = Http::preprod_phyrhose();
            $nmkr = Http::preprod_nmkr();
        }

        $code = Code::where([
            'code' => $provided_code,
            'campaign_id' => $campaign->id,
        ])
            ->with('rewards')
            ->first();

        if (! $code) {
            throw new ClaimException('ERROR_NOT_FOUND');
        }

        $t_now = time();
        $t_start = strtotime($campaign->start_date.' 00:00:00 UTC');
        $t_end = strtotime($campaign->end_date.' 23:59:59 UTC');

        $date_now = date('Y-m-d H:i:s', $t_now);
        $date_start = date('Y-m-d H:i:s', $t_start);
        $date_end = date('Y-m-d H:i:s', $t_end);

        if ($t_now < $t_start) {
            throw new ClaimException('ERROR_TOO_EARLY');
        }

        if ($t_now > $t_end) {
            throw new ClaimException('ERROR_EXPIRED');
        }

        $tokens = new TokenCollection($code->rewards);

        if ($campaign->one_per_wallet) {
            $lock = Cache::lock("claim:{$campaign->id}:{$decoded_address['stakeAddress']}", 10);
            if (! $lock->get()) {
                throw new ClaimException('ERROR_ALREADY_CLAIMED');
            }

            try {
                $did_claim = Code::where(['campaign_id' => $campaign->id])
                    ->whereHas('claims', static function ($query) use ($decoded_address) {
                        $query->where('stake_key', $decoded_address['stakeAddress']);
                    })
                    ->first();

                if ($did_claim) {
                    throw new ClaimException('ERROR_ALREADY_CLAIMED');
                }
            } catch (ClaimException $e) {
                $lock->release();
                throw $e;
            }
        }

        if ($code->claims_count === 0 || $code->uses === 0 || $code->claims_count < $code->uses) {

            $my_claims = $code->claims_count
                ? $code->claims()
                    ->where([
                        'stake_key' => $decoded_address['stakeAddress'],
                    ])
                    ->count()
                : 0;

            if ($code->perWallet && $code->perWallet <= $my_claims) {
                $my_claim = $code->claims()
                    ->where([
                        'stake_key' => $decoded_address['stakeAddress'],
                    ])
                    ->latest('id')
                    ->first();
                if ($my_claim) {
                    if ($my_claim->transaction_id) {

                        if (is_numeric($my_claim->transaction_id) && $my_claim->transaction_hash === null) {
                            Log::debug('Lookup the transaction hash here!');
                            $txn_status = $phyrhose->get('firehose/purchaseStatus?purchaseId='.$my_claim->transaction_id)
                                ->json();
                            if (($txn_status['status'] ?? null) === 'ok' && isset($txn_status['data'][1])) {
                                $status = $txn_status['data'][1];
                                switch ($status['status']) {
                                    case 'completed':
                                        $my_claim->transaction_hash = $status['txId'];
                                        $my_claim->save();
                                        break;
                                    case 'timeout':
                                        Log::error("Have a timeout status for {$code->code} with Claim ID: {$my_claim->id}. Set the transaction_id to null and try again?");
                                        $my_claim->transaction_id = null;
                                        $my_claim->save();
                                        ProcessClaims::dispatch($campaign->id)
                                            ->delay((int) config('cardano.push_delay', 5) * 60);
                                        break;
                                    default:
                                        Log::error("Unknown Phyrhose txn status: {$status['status']}", ['phyrhose_response' => $txn_status]);
                                        break;
                                }
                            }
                            Log::debug('Phyrhose status:', compact('txn_status'));
                        }

                        return $logResponse([
                            'code' => 202,
                            'status' => 'claimed',
                            'lovelaces' => (string) $code->lovelace,
                            'tx_hash' => $my_claim->transaction_hash ?? '',
                            'tokens' => $tokens->toArray($request),
                        ]);
                    } else {

                        Log::debug('Dispatching processing job', ['campaign_id' => $campaign->id]);

                        try {
                            $dispatch_result = ProcessClaims::dispatch($campaign->id)
                                ->delay((int) config('cardano.push_delay', 5) * 60);

                            Log::info('Job dispatched?', ['result' => $dispatch_result]);
                        } catch (Throwable $e) {
                            Log::error('Could not dispatch job?', ['error' => $e]);
                        }

                        return $logResponse([
                            'code' => 201,
                            'status' => 'queued',
                            'lovelaces' => (string) $code->lovelace,
                            'queue_position' => 0,
                            'tokens' => $tokens->toArray($request),
                        ]);

                    }
                }

                throw new ClaimException('ERROR_ALREADY_CLAIMED');
            }

            $the_claim = $code->claims()
                ->create($address_details);

            $nft_data = null;
            if ($code->nmkr_project_uid && $code->nmkr_count_nft) {
                $nft_data = [];
                $nmkr->withToken($campaign->nmkr_api_key);

                $nmkr_mint_response = $nmkr->get("MintAndSendRandom/{$code->nmkr_project_uid}/{$code->nmkr_count_nft}/{$address_details['address']}");
                $nmkr_mint_body = $nmkr_mint_response->json();
                $nmkr_mint_status = $nmkr_mint_response->status();

                if ($nmkr_mint_status === 200) {
                    try {
                        foreach ($nmkr_mint_body['sendedNft'] as $sentToken) {
                            $token_data = $nmkr->get("GetNftDetailsById/{$sentToken['uid']}")->json();
                            $token_metadata = json_decode($token_data['metadata']);
                            Log::info('Minted Token Data', ['policy_id' => $token_data['policyid'] ?? null, 'asset_name' => $token_data['assetname'] ?? null]);

                            $policyId = $token_data['policyid'];
                            $assetHex = $token_data['assetname'];
                            $assetName = hex2bin($assetHex);

                            $token_details = [
                                'policy_id' => $policyId,
                                'asset_id' => $assetHex,
                                'metadata' => $token_metadata->{'721'}->{$policyId}->{$assetName},
                            ];
                            $nft_data[] = $token_details;
                            Log::info('Minted Token', ['policy_id' => $policyId, 'asset_hex' => $assetHex]);
                        }
                    } catch (Throwable $e) {
                        Log::error('Could not get minted token data...', ['error' => $e]);
                    }
                }

                $the_claim->nmkr_mint_status = $nmkr_mint_status;
                $the_claim->nmkr_mint_body = $nmkr_mint_body;
                $the_claim->save();

                Log::info('NMKR mint processed.', [
                    'claim_id' => $the_claim->id,
                    'project_uid' => $code->nmkr_project_uid,
                    'nft_count' => $code->nmkr_count_nft,
                    'mint_status' => $nmkr_mint_status,
                ]);
            }

            Log::debug('Dispatching processing job', ['campaign_id' => $campaign->id]);

            $dispatch_result = ProcessClaims::dispatch($campaign->id)
                ->delay((int) config('cardano.push_delay', 5) * 60);

            Log::debug('Dispatch results?', ['result' => $dispatch_result]);

            $response = [
                'code' => 200,
                'status' => 'accepted',
                'lovelaces' => (string) $code->lovelace,
                'queue_position' => 0,
                'tokens' => $tokens->toArray($request),
                'nfts' => $nft_data,
            ];

            return $logResponse($response);
        }

        if ($code->claims_count >= $code->uses) {
            $my_claim = $code->claims()
                ->where([
                    'stake_key' => $decoded_address['stakeAddress'],
                ])
                ->first();

            if ($my_claim) {
                if ($my_claim->transaction_id) {

                    if (is_numeric($my_claim->transaction_id) && $my_claim->transaction_hash === null) {
                        Log::debug('Lookup the transaction hash here!');
                        switch ($campaign->network) {
                            case 'mainnet':
                                $phyrhose = Http::mainnet_phyrhose();
                                break;
                            case 'preprod':
                                $phyrhose = Http::preprod_phyrhose();
                                break;
                            default:
                                Log::error('Unknown campaign network!', compact('campaign'));
                                throw new Exception('Invalid network!');
                        }
                        $txn_status = $phyrhose->get('firehose/purchaseStatus?purchaseId='.$my_claim->transaction_id)
                            ->json();
                        if ($txn_status['status'] === 'ok') {
                            $status = $txn_status['data'][1];
                            switch ($status['status']) {
                                case 'completed':
                                    $my_claim->transaction_hash = $status['txId'];
                                    $my_claim->save();
                                    break;
                                case 'timeout':
                                    Log::error("Have a timeout status for {$code->code} with Claim ID: {$my_claim->id}. Set the transaction_id to null and try again?");
                                    $my_claim->transaction_id = null;
                                    $my_claim->save();
                                    ProcessClaims::dispatch($campaign->id)
                                        ->delay((int) config('cardano.push_delay', 5) * 60);
                                    break;
                            }
                        }
                        Log::debug('Phyrhose status:', compact('txn_status'));
                    }

                    $response = [
                        'code' => 202,
                        'status' => 'claimed',
                        'lovelaces' => (string) $code->lovelace,
                        //                        'tx_hash'   => $my_claim->transaction_id,
                        // TODO: Return blank for now until we can tie the internal batch_id to an actual on-chain txn hash
                        // Current Phyrhose Transaction ID: 01HGTR1J1PEV8YABWNYX1F7G8K
                        'tx_hash' => $my_claim->transaction_hash ?? '',
                        'tokens' => $tokens->toArray($request),
                    ];

                    return $logResponse($response);
                } else {

                    Log::debug('Dispatching processing job', ['campaign_id' => $campaign->id]);

                    try {
                        $dispatch_result = ProcessClaims::dispatch($campaign->id)
                            ->delay((int) config('cardano.push_delay', 5) * 60);

                        Log::info('Job dispatched?', ['result' => $dispatch_result]);
                    } catch (Throwable $e) {
                        Log::error('Could not dispatch job?', ['error' => $e]);
                    }

                    $response = [
                        'code' => 201,
                        'status' => 'queued',
                        'lovelaces' => (string) $code->lovelace,
                        'queue_position' => 0,
                        'tokens' => $tokens->toArray($request),
                    ];

                    return $logResponse($response);

                }
            } else {
                throw new ClaimException('ERROR_ALREADY_CLAIMED');
            }

        }

        // Reached the bottom of the claim flow without matching any state.
        // This indicates a code that exists but doesn't fit any expected branch.
        Log::warning('Claim fell through to fallback response', [
            'campaign_id' => $campaign->id,
            'code_id' => $code->id ?? null,
            'claims_count' => $code->claims_count ?? null,
            'uses' => $code->uses ?? null,
        ]);

        return $logResponse(config('errorcodes.ERROR_NOT_FOUND'));
    }
}
