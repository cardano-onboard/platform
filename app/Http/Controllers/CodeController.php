<?php

namespace App\Http\Controllers;

use App\Cardano\AssetName;
use App\Exceptions\ClaimException;
use App\Http\Resources\TokenCollection;
use App\Jobs\ProcessClaims;
use App\Jobs\ProcessUploadedCodes;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\Partner;
use App\Services\ClaimClientRecorder;
use App\Services\CodeCapacity;
use App\Services\CreditLedger;
use App\Services\MinUtxoService;
use App\Support\ClaimClient;
use App\Support\ClaimToken;
use App\Support\Pricing;
use CardanoPhp\Bech32\Bech32;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CodeController extends Controller
{
    /**
     * Said by the validation rule and by the constraint violation behind it, so the
     * operator reads one answer whichever of the two refused the name.
     */
    private const NAME_TAKEN = 'This campaign already has a partner with that name.';

    /**
     * Carries the claiming client's own user agent when an external caller makes a claim
     * on an attendee's behalf. Every such claim otherwise arrives with that caller's own
     * backend's user agent, which would erase which wallet the attendee actually used, so
     * a claim authorised by codes:claim may forward the attendee-side value here instead.
     * Ignored on any other claim, tokened or not.
     */
    private const CLAIM_CLIENT_HEADER = 'X-Claim-Client-User-Agent';

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, MinUtxoService $minUtxo, CodeCapacity $capacity)
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

        // A bulk import and a hand-made batch are both code generation, so both carry who
        // the batch is for. The rules are merged into each branch's own validation rather
        // than run first, so a request that fails on anything else cannot leave a partner
        // behind that no code was ever generated for.
        if ($request->uploadedCodes) {

            $validated = $request->validate(array_merge([
                'file_key' => 'required|string|regex:/^[a-zA-Z0-9\/_\-\.]+$/|max:500',
            ], $this->partnerRules($request, $campaign)), $this->partnerMessages());

            // Claiming the task is what decides whether this import runs, so that a second
            // post of the same file while the first is still working dispatches nothing
            // instead of importing it twice.
            $task = CampaignTask::claim(
                $campaign,
                ProcessUploadedCodes::TASK_TYPE,
                ProcessUploadedCodes::dedupeKey($validated['file_key']),
                ['file' => basename($validated['file_key'])],
                Auth::id(),
            );

            if (! $task) {
                return back()->with('message',
                    'That file is already being imported. Progress is on the campaign page.');
            }

            // The partner is resolved after the claim, so a refused second post of the
            // same file cannot create a partner for an import that is not going to run.
            ProcessUploadedCodes::dispatch(
                $campaign->id,
                $validated['file_key'],
                $task->id,
                $this->partnerFor($request, $campaign),
            );

            $request->session()
                ->flash('message',
                    'Codes file has been queued for import. Progress is shown on this page.');

            return to_route('campaigns.show', $request->campaign_id);
        }

        $bundle = MinUtxoService::assetsFromRequest(
            is_array($request->input('tokens')) ? $request->input('tokens') : []
        );

        $validated = $request->validate(array_merge([
            'quantity' => 'nullable|integer|min:1|max:500',
            'lovelace' => [
                'integer', 'required', 'min:1000000', 'max:45000000000000000',
                $minUtxo->minimumRule($bundle, $campaign->network),
            ],
            'perWallet' => 'integer|required|min:0',
            'uses' => 'integer|required|min:1',
            'tokens' => 'nullable|array',
            'tokens.*.policy_id' => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.token_id' => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.quantity' => 'required_with:tokens|integer|min:1',
            'nmkr_project_uid' => 'nullable|string|max:255',
            'nmkr_count_nft' => 'nullable|integer|min:0|max:100',
        ], $this->partnerRules($request, $campaign)), $this->partnerMessages());

        $partnerId = $this->partnerFor($request, $campaign);

        $quantity = (int) ($validated['quantity'] ?? 1);

        // The capacity check and the whole batch run inside one transaction: the campaign
        // row is locked for the length of it, so a second request creating codes for the
        // same campaign cannot slip in between the count and the write, and a batch that
        // fails partway leaves none of itself behind.
        $capacity->reserve($campaign, $quantity, function () use ($campaign, $validated, $partnerId, $quantity) {
            for ($i = 0; $i < $quantity; $i++) {
                $code = $campaign->codes()
                    ->create([
                        'code' => Code::generateUniqueCode($campaign->id),
                        'partner_id' => $partnerId,
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
        });

        Log::info('Codes created.', [
            'quantity' => $quantity,
            'campaign_id' => $campaign->id,
            'partner_id' => $partnerId,
        ]);

        $quote = $minUtxo->quote($bundle, $campaign->network, (int) $validated['lovelace']);
        $redirect = to_route('campaigns.show', $request->campaign_id);

        return $quote['warning'] === null
            ? $redirect
            : $redirect->with('message', $quote['warning']);
    }

    /**
     * Change what a code pays out, without deleting it and printing a new sticker.
     *
     * Edits apply forward. A claim records what it was actually paid at the moment the
     * payment was submitted, so a code with ten uses and three claims against it can have
     * its reward changed without touching what those three were given. That is what makes
     * this safe on a code that is already in circulation, and it is why the snapshot on
     * claims had to exist first.
     *
     * The rewards are replaced rather than reconciled row by row. A reward row means
     * nothing on its own: nothing refers to it, and what a claimant received is on the
     * claim. Replacing them is one statement whose outcome is obvious, where matching them
     * up would be several whose outcome depends on the order they ran in.
     */
    public function update(Request $request, string $id, MinUtxoService $minUtxo): RedirectResponse
    {
        $code = Code::with(['campaign', 'rewards'])->findOrFail($id);
        $campaign = $code->campaign;

        $this->authorize('update', $campaign);

        // Same reasoning as creating one. Nothing can be claimed after the window closes,
        // so an edit here changes nothing that will ever be paid, and telling somebody
        // their change was saved would be false.
        if ($campaign->hasEnded()) {
            return back()->with('message',
                'This campaign has ended, so its rewards can no longer be changed. '
                .'Extend the end date first.');
        }

        $bundle = MinUtxoService::assetsFromRequest(
            is_array($request->input('tokens')) ? $request->input('tokens') : []
        );

        $validated = $request->validate([
            'lovelace' => [
                'integer', 'required', 'min:1000000', 'max:45000000000000000',
                $minUtxo->minimumRule($bundle, $campaign->network),
            ],
            'tokens' => 'nullable|array',
            'tokens.*.policy_id' => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.token_id' => 'required_with:tokens|string|regex:/^[0-9a-fA-F]+$/',
            'tokens.*.quantity' => 'required_with:tokens|integer|min:1',
            'nmkr_project_uid' => 'nullable|string|max:255',
            'nmkr_count_nft' => 'nullable|integer|min:0|max:100',
        ]);

        // Claims that have been accepted and not yet submitted. They will be paid the new
        // reward, because nothing has gone out for them yet and the snapshot is written
        // when it does. The operator is told how many, because those claimants have
        // already been shown what they were promised.
        $pending = $code->claims()->whereNull('transaction_id')->count();
        $settled = $code->claims()->whereNotNull('transaction_id')->count();
        // Zero uses means unlimited, so there is no "remaining" to report at all. Cast
        // rather than compared strictly: the column comes back as a string on MySQL.
        $remaining = (int) $code->uses === 0
            ? null
            : max(0, (int) $code->uses - ($pending + $settled));

        DB::transaction(static function () use ($code, $validated) {
            $code->rewards()->delete();

            $code->update([
                'lovelace' => $validated['lovelace'],
                'nmkr_project_uid' => $validated['nmkr_project_uid'] ?? null,
                'nmkr_count_nft' => $validated['nmkr_count_nft'] ?? 0,
            ]);

            foreach ($validated['tokens'] ?? [] as $token) {
                $code->rewards()->create([
                    'policy_hex' => $token['policy_id'],
                    'asset_hex' => $token['token_id'],
                    'quantity' => $token['quantity'],
                ]);
            }
        });

        Log::info('Code rewards changed.', [
            'campaign_id' => $campaign->id,
            'code_id' => $code->id,
            'user_id' => Auth::id(),
            'lovelace' => $validated['lovelace'],
            'token_count' => count($validated['tokens'] ?? []),
            'pending_claims' => $pending,
        ]);

        return to_route('campaigns.show', $campaign->id)
            ->with('message', $this->editMessage(
                $minUtxo->quote($bundle, $campaign->network, (int) $validated['lovelace']),
                $pending,
                $settled,
                $remaining,
            ));
    }

    /**
     * What to tell an operator who has just changed a reward.
     *
     * Everything worth saying is about who the change reaches, and each sentence is said
     * only when it is about something that exists. Claims already sent keep what they were
     * paid, which is worth stating where there are some and is noise where there are none.
     * Claims accepted and not yet sent will get the new reward, which is a surprise worth
     * naming. A code with no uses left reaches nobody at all.
     */
    private function editMessage(array $quote, int $pending, int $settled, ?int $remaining): string
    {
        $parts = ['Rewards updated.'];

        if ($settled > 0) {
            $parts[] = $settled === 1
                ? '1 claim has already been paid from this code and keeps what it was sent.'
                : "{$settled} claims have already been paid from this code and keep what they were sent.";
        }

        if ($pending > 0) {
            $parts[] = $pending === 1
                ? '1 claim has been accepted and not yet sent, and will be paid the new reward.'
                : "{$pending} claims have been accepted and not yet sent, and will be paid the new reward.";
        }

        if ($remaining === 0) {
            $parts[] = 'This code has no uses left, so nobody else can claim it.';
        }

        if ($quote['warning'] !== null) {
            $parts[] = $quote['warning'];
        }

        return implode(' ', $parts);
    }

    /**
     * What a reward bundle has to be worth, asked while somebody is still typing it.
     *
     * The create and edit forms need this before anything is saved, and the answer has to
     * be the same one validation will use, so it is computed here rather than reimplemented
     * in the browser. The bundle is what it depends on, not the amount, so a form asks once
     * per change to the token list and compares the typed amount locally.
     *
     * Scoped to a campaign for the network and for authorisation. Nothing is written.
     */
    public function minUtxo(Request $request, Campaign $campaign, MinUtxoService $minUtxo)
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'lovelace' => 'nullable|integer|min:0',
            'tokens' => 'nullable|array|max:100',
            'tokens.*.policy_id' => 'nullable|string|max:64|regex:/^[0-9a-fA-F]*$/',
            'tokens.*.token_id' => 'nullable|string|max:64|regex:/^[0-9a-fA-F]*$/',
            'tokens.*.quantity' => 'nullable|integer|min:0',
        ]);

        return response()->json($minUtxo->quote(
            MinUtxoService::assetsFromRequest($validated['tokens'] ?? []),
            $campaign->network,
            isset($validated['lovelace']) ? (int) $validated['lovelace'] : null,
        ));
    }

    /**
     * Validation for who a batch of codes is being generated for.
     *
     * Three things the request can say, and each means something different: an id, which is
     * a partner the operator picked; a name, which is one they are adding as they generate;
     * and nothing at all, which is the named choice "Unassigned" and is stored as null.
     *
     * The id is checked against a rule scoped to this campaign, so an id belonging to
     * somebody else's campaign fails validation instead of being stored. Without the scope
     * the endpoint would answer differently for an id that exists and one that does not,
     * which turns a form that only writes this campaign's codes into a way to enumerate
     * other tenants' partners.
     *
     * The name is checked for uniqueness only when it is what the request is acting on. A
     * picked id with a stale name still in the payload would otherwise be refused over a
     * clash that changes nothing.
     */
    private function partnerRules(Request $request, Campaign $campaign): array
    {
        $namingRules = ['nullable', 'string', 'max:80'];

        if (blank($request->input('partner_id'))) {
            $namingRules[] = Partner::uniqueNameRule($campaign);
        }

        return [
            'partner_id' => ['nullable', 'string', Partner::assignableRule($campaign)],
            'partner_name' => $namingRules,
            'partner_kind' => ['nullable', 'string', Rule::in(Partner::KINDS)],
        ];
    }

    /** @return array<string, string> */
    private function partnerMessages(): array
    {
        return [
            'partner_id.exists' => 'That partner does not belong to this campaign.',
            'partner_name.unique' => self::NAME_TAKEN,
            'partner_kind.in' => 'That is not one of the partner types.',
        ];
    }

    /**
     * The partner id to stamp on the batch, creating the partner when the operator named
     * one that does not exist yet.
     *
     * Call only once the request has passed validation: it writes a row.
     *
     * There is no retrospective assignment anywhere, deliberately. A code attributed to a
     * partner after its claims are in was not necessarily handed out by them.
     */
    private function partnerFor(Request $request, Campaign $campaign): ?string
    {
        $id = $request->input('partner_id');

        if (filled($id)) {
            return (string) $id;
        }

        $name = trim((string) $request->input('partner_name', ''));

        if ($name === '') {
            return null;
        }

        $kind = $request->input('partner_kind');

        try {
            $partner = $campaign->partners()->create([
                'name' => $name,
                'kind' => filled($kind) ? (string) $kind : null,
            ]);
        } catch (QueryException $e) {
            // The unique index refused the row. For this insert that means another request
            // took the name between this one validating and writing, so the operator gets
            // the answer the validation rule would have given them rather than a 500 for a
            // name that is simply taken. Whether the name is now held decides it: anything
            // else the database refused is not this, and is rethrown.
            if (! $campaign->partners()->where('name', $name)->exists()) {
                throw $e;
            }

            throw ValidationException::withMessages(['partner_name' => self::NAME_TAKEN]);
        }

        Log::info('Partner created.', [
            'campaign_id' => $campaign->id,
            'partner_id' => $partner->id,
            'kind' => $partner->kind,
            'user_id' => Auth::id(),
        ]);

        return $partner->id;
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
    public function claim(Request $request, Campaign $campaign, ClaimClientRecorder $clients, CreditLedger $ledger)
    {
        // Resolved once and reused for both the rate limit already applied by the
        // claim-api middleware and the client this claim is recorded under below. Null for
        // every ordinary attendee claim; set only when the request carries a Sanctum token
        // scoped to codes:claim for this campaign.
        $claimToken = ClaimToken::resolve($request, $campaign);
        $forwardedClient = $request->header(self::CLAIM_CLIENT_HEADER);

        Log::info('Claim Request', [
            'campaign_id' => $campaign->id,
            'has_code' => ! empty($request->code ?? $request->claim_code),
            'has_address' => ! empty($request->address),
            'user_agent' => $request->userAgent(),
            // Only worth recording when it can actually be used: without codes:claim the
            // header is never looked at again below, so logging it here would keep an
            // arbitrary, unauthenticated, attacker-controlled string in every claim's log
            // line for nothing. Cut to the same length ClaimClient itself ever keeps, so
            // the log line cannot carry more of it than the tally ever will.
            'claim_client_header' => $claimToken !== null
                ? mb_substr((string) $forwardedClient, 0, ClaimClient::MAX_LENGTH)
                : null,
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

        // CIP-0019 header types 0 to 3 are base addresses: a payment part and a staking part.
        // Pointer (4, 5), enterprise (6, 7) and Byron (8) addresses have no staking credential
        // and are refused, answered with CIP-0099's invalidaddress status and a message naming
        // the reason, because CIP-0099 has no status yet for a valid address of a refused type.
        if (! in_array((int) ($decoded_address['addressType'] ?? -1), [0, 1, 2, 3], true)) {
            throw new ClaimException('ERROR_ADDRESS_TYPE');
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

            // The charge is force-filled rather than passed through create(), so the money
            // columns are never mass-assignable and cannot be set by anything a claimant
            // sends. What is stamped here is what applied at this moment, and a later rate
            // change leaves it alone.
            //
            // A claim that cannot be paid for is still accepted and still recorded. It is
            // held rather than refused, because the alternative is turning somebody away at
            // a booth for a billing state they can neither see nor fix, and the claimant is
            // told what they are always told: accepted. Delivery has always been
            // asynchronous, so nothing about their experience changes except the wait. The
            // operator is the one who has to act, and the campaign page tells them.
            $costMicro = Pricing::claimCostMicro($code->assetCount());
            $holdReason = $ledger->holdReasonFor($campaign, $costMicro);

            $the_claim = $code->claims()
                ->make($address_details);
            $the_claim->forceFill(array_merge(
                ['held_reason' => $holdReason],
                // On a path that collects in band the charge has already happened, out of
                // this campaign's own bucket, so what it cost is recorded here rather
                // than debited anywhere. Nulls on every other path, where the cost is the
                // credit and the bucket pays only for what goes on chain.
                Pricing::inBandCharge(Pricing::pathFor($campaign)),
                // Stamped whoever is running this. The chain charges a self-hoster the
                // same as anybody, and they need it to account for what a campaign spent.
                ['network_fee_lovelace' => (int) config('cardano.network_fee_lovelace')],
            ))->save();

            if ($holdReason === null) {
                $ledger->debit($the_claim, $campaign, $costMicro);
            } else {
                Log::warning('Claim held: the campaign cannot pay for it.', [
                    'campaign_id' => $campaign->id,
                    'reason' => $holdReason,
                    'cost_micro' => $costMicro,
                ]);
            }

            // Which client software the claim arrived from, counted per campaign and never
            // against this claim. Only accepted claims are counted, which is why the call
            // sits here rather than at the top of the method beside the request log.
            //
            // A claim authorised by codes:claim arrives from that external caller's own
            // backend, so its own user agent names that backend rather than the wallet the
            // attendee actually used. Only such a claim may substitute the forwarded header
            // for it; without the ability the header is never looked at, so nothing an
            // unauthenticated caller sends can pollute the tally.
            $clientUserAgent = ($claimToken !== null && filled($forwardedClient))
                ? $forwardedClient
                : $request->userAgent();

            $clients->record($campaign, $clientUserAgent);

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

                            $token_details = [
                                'policy_id' => $policyId,
                                'asset_id' => $assetHex,
                                'metadata' => self::mintedTokenMetadata($token_metadata, $policyId, $assetHex),
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

            // Dispatched and then released deliberately. ProcessClaims::dispatch()
            // returns an object that queues the job when it is destroyed rather than
            // when it is called, so left to itself it can run after the request, or
            // after the test, that created it has finished. Unsetting the only
            // reference runs the destructor here, where the application is still
            // standing.
            $pending = ProcessClaims::dispatch($campaign->id)
                ->delay((int) config('cardano.push_delay', 5) * 60);
            unset($pending);

            $response = [
                'code' => 200,
                'status' => 'accepted',
                'lovelaces' => (string) $code->lovelace,
                'queue_position' => 0,
                'tokens' => $tokens->toArray($request),
            ];

            // `nfts` is not part of CIP-99. It was added while integrating NMKR minting so a
            // wallet could show a claimant what they were about to receive before it existed
            // on chain, and it is the only response field with no published definition. Send
            // it only when there is something to send, rather than an explicit null that no
            // implementer has any way to interpret.
            if ($nft_data !== null) {
                $response['nfts'] = $nft_data;
            }

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

    /**
     * The metadata a freshly minted token carries, read from under the name the standard
     * files it under.
     *
     * A minter's `721` map is keyed by the asset name as text. Where that name carries a
     * CIP-0067 label, the key is the name with the label removed: CIP-0068 says to match
     * "without the `asset_name_label` prefix" when reading nested metadata, and the CDDL
     * for each of its sub-standards spells the field as "Asset name encoded as bytes
     * (without label prefix)". Looking up the whole decoded name instead finds nothing,
     * and finding nothing here is silent, so the claim went out carrying a token with no
     * metadata and nothing said why.
     *
     * There is deliberately no second attempt under the whole name. A label's first byte
     * is NUL for every label below 4096, which is all of them anyone has registered, and
     * a JSON object with a NUL in a property name does not decode at all. So for a
     * labelled token the whole name is not a key a minter could use even if it wanted to,
     * and an attempt to read one would be an unreachable branch rather than a kindness.
     */
    private static function mintedTokenMetadata(mixed $metadata, string $policyId, string $assetHex): mixed
    {
        $byPolicy = $metadata->{'721'}->{$policyId} ?? null;
        $name = AssetName::fromHex($assetHex)->nameBytes();

        return is_object($byPolicy) && $name !== '' && isset($byPolicy->{$name})
            ? $byPolicy->{$name}
            : null;
    }
}
