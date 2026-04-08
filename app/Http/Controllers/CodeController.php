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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        if (!$campaign || $campaign->user_id !== Auth::user()->id) {
            return to_route('dashboard');
        }

        if ($request->uploadedCodes) {

            $request->validate([
                'file_key' => 'required|string|regex:/^[a-zA-Z0-9\/_\-\.]+$/|max:500',
            ]);

            ProcessUploadedCodes::dispatch($campaign->id, $request->file_key);

            $request->session()
                ->flash("flash",
                    "Codes file has been queued for import. Please allow a few minutes for import to complete.");

            return to_route('campaigns.show', $request->campaign_id);
        }

        $validated = $request->validate([
            'lovelace'            => 'integer|required|min:1000000|max:45000000000000000',
            'perWallet'           => 'integer|required|min:0',
            'uses'                => 'integer|required|min:1',
            'tokens'              => 'nullable|array',
            'tokens.*.policy_id'  => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.token_id'   => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.quantity'   => 'required_with:tokens|integer|min:1',
            'nmkr_project_uid'   => 'nullable|string|max:255',
            'nmkr_count_nft'     => 'nullable|integer|min:0|max:100',
        ]);

        $code = $campaign->codes()
            ->create([
                'code' => Str::ulid(),
                'perWallet' => $validated['perWallet'],
                'uses' => $validated['uses'],
                'lovelace' => $validated['lovelace'],
                'nmkr_project_uid' => $validated['nmkr_project_uid'] ?? null,
                'nmkr_count_nft' => $validated['nmkr_count_nft'] ?? 0,
            ]);

        Log::info('Code created.', ['code_id' => $code->id, 'campaign_id' => $campaign->id]);

        if ($code->id) {
            foreach ($validated['tokens'] ?? [] as $token) {
                $code->rewards()
                    ->create([
                        'policy_hex' => $token['policy_id'],
                        'asset_hex' => $token['token_id'],
                        'quantity' => $token['quantity'],
                    ]);
            }

            return to_route('campaigns.show', $request->campaign_id);

        }
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
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * @throws ClaimException
     */
    public function claim(Request $request, Campaign $campaign)
    {
        Log::info('Claim Request', [
            'campaign_id' => $campaign->id,
            'has_code' => !empty($request->code ?? $request->claim_code),
            'has_address' => !empty($request->address),
        ]);

        $provided_code = $request->code ?? $request->claim_code;

        if (!$provided_code) {
            throw new ClaimException('ERROR_MISSING_CODE');
        }

        if (!$request->address || !preg_match('/^addr(_test)?1[023456789acdefghjklmnpqrstuvwxyz]{53,103}$/', $request->address)) {
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

        if (!$code) {
            throw new ClaimException('ERROR_NOT_FOUND');
        }

        $t_now = time();
        $t_start = strtotime($campaign->start_date . ' 00:00:00 UTC');
        $t_end = strtotime($campaign->end_date . ' 23:59:59 UTC');

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
            if (!$lock->get()) {
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

            if ($code->claims_count) {
                $my_claims = $code->claims()
                    ->where([
                        'stake_key' => $decoded_address['stakeAddress'],
                    ])
                    ->count();

                if ($code->perWallet && $code->perWallet <= $my_claims) {
                    $my_claim = $code->claims()
                        ->where([
                            'stake_key' => $decoded_address['stakeAddress'],
                        ])
                        ->first();
                    if ($my_claim) {
                        if ($my_claim->transaction_id) {

                            if (is_numeric($my_claim->transaction_id) && $my_claim->transaction_hash === null) {
                                Log::debug("Lookup the transaction hash here!");
                                $txn_status = $phyrhose->get('firehose/purchaseStatus?purchaseId=' . $my_claim->transaction_id)
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
                                                ->delay(now()->addMinutes(config('cardano.push_delay')));
                                            break;
                                        default:
                                            Log::error("Unknown Phyrhose txn status: {$status['status']}", ['phyrhose_response' => $txn_status]);
                                            break;
                                    }
                                }
                                Log::debug("Phyrhose status:", compact('txn_status'));
                            }

                            return [
                                'code' => 202,
                                'status' => 'claimed',
                                'lovelaces' => (string)$code->lovelace,
                                'tx_hash' => $my_claim->transaction_hash ?? '',
                                'tokens' => $tokens->toArray($request),
                            ];
                        } else {

                            Log::debug("Dispatching processing job", ['campaign_id' => $campaign->id]);

                            try {
                                $dateTime = Carbon::now();

                                $dispatch_result = ProcessClaims::dispatch($campaign->id)
                                    ->delay($dateTime->addMinutes(config('cardano.push_delay')));

                                Log::info("Job dispatched?", ['result' => $dispatch_result]);
                            } catch (Throwable $e) {
                                Log::error("Could not dispatch job?", ['error' => $e]);
                            }

                            return [
                                'code' => 201,
                                'status' => 'queued',
                                'lovelaces' => (string)$code->lovelace,
                                'queue_position' => 0,
                                'tokens' => $tokens->toArray($request),
                            ];

                        }
                    }
                }
            } else {

                $the_claim = $code->claims()
                    ->create($address_details);

                // TODO: Check if the code has an NMKR NFT Claim
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
                                    'metadata' => $token_metadata->{'721'}->{$policyId}->{$assetName}
                                ];
                                $nft_data[] = $token_details;
                                Log::info("Minted Token", ['policy_id' => $policyId, 'asset_hex' => $assetHex]);
                            }
                        } catch (Throwable $e) {
                            Log::error("Could not get minted token data...", ['error' => $e]);
                        }
                    }

                    $the_claim->nmkr_mint_status = $nmkr_mint_status;
                    $the_claim->nmkr_mint_body = $nmkr_mint_body;
                    $the_claim->save();

                    Log::info("NMKR mint processed.", [
                        'claim_id' => $the_claim->id,
                        'project_uid' => $code->nmkr_project_uid,
                        'nft_count' => $code->nmkr_count_nft,
                        'mint_status' => $nmkr_mint_status,
                    ]);
                }

                Log::debug("Dispatching processing job", ['campaign_id' => $campaign->id]);
                $dateTime = Carbon::now();

                $dispatch_result = ProcessClaims::dispatch($campaign->id)
                    ->delay($dateTime->addMinutes(config('cardano.push_delay')));

                Log::debug("Dispatch results?", ['result' => $dispatch_result]);

                $response = [
                    'code' => 200,
                    'status' => 'accepted',
                    'lovelaces' => (string)$code->lovelace,
                    'queue_position' => 0,
                    'tokens' => $tokens->toArray($request),
                    'nfts' => $nft_data,
                ];

                Log::debug("Sending claim response...", compact('response'));

                return $response;

            }
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
                        Log::debug("Lookup the transaction hash here!");
                        switch ($campaign->network) {
                            case 'mainnet':
                                $phyrhose = Http::mainnet_phyrhose();
                                break;
                            case 'preprod':
                                $phyrhose = Http::preprod_phyrhose();
                                break;
                            default:
                                Log::error("Unknown campaign network!", compact('campaign'));
                                throw new Exception('Invalid network!');
                        }
                        $txn_status = $phyrhose->get('firehose/purchaseStatus?purchaseId=' . $my_claim->transaction_id)
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
                                        ->delay(now()->addMinutes(config('cardano.push_delay')));
                                    break;
                            }
                        }
                        Log::debug("Phyrhose status:", compact('txn_status'));
                    }

                    $response = [
                        'code' => 202,
                        'status' => 'claimed',
                        'lovelaces' => (string)$code->lovelace,
                        //                        'tx_hash'   => $my_claim->transaction_id,
                        // TODO: Return blank for now until we can tie the internal batch_id to an actual on-chain txn hash
                        // Current Phyrhose Transaction ID: 01HGTR1J1PEV8YABWNYX1F7G8K
                        'tx_hash' => $my_claim->transaction_hash ?? '',
                        'tokens' => $tokens->toArray($request),
                    ];

                    return $response;
                } else {

                    Log::debug("Dispatching processing job", ['campaign_id' => $campaign->id]);

                    try {
                        $dateTime = Carbon::now();

                        $dispatch_result = ProcessClaims::dispatch($campaign->id)
                            ->delay($dateTime->addMinutes(config('cardano.push_delay')));

                        Log::info("Job dispatched?", ['result' => $dispatch_result]);
                    } catch (Throwable $e) {
                        Log::error("Could not dispatch job?", ['error' => $e]);
                    }

                    $response = [
                        'code' => 201,
                        'status' => 'queued',
                        'lovelaces' => (string)$code->lovelace,
                        'queue_position' => 0,
                        'tokens' => $tokens->toArray($request),
                    ];

                    return $response;

                }
            } else {
                throw new ClaimException('ERROR_ALREADY_CLAIMED');
            }

        }

        return [
            'campaign' => $campaign,
            'code' => $code,
            'request' => $request->all(),
            'error' => config('errorcodes.ERROR_NOT_FOUND'),
        ];
    }
}
