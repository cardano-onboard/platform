<?php

namespace App\Http\Controllers;

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use App\Models\Code;
use App\Models\Reward;
use App\Services\CodeCapacity;
use App\Services\CreditLedger;
use App\Services\MinUtxoService;
use App\Support\Pricing;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The one seam an external event application needs into a campaign's codes: make one on
 * demand, keyed by the caller's own identity for an attendee, and ask what happened to it.
 *
 * Reached only with a Sanctum token scoped to exactly these two abilities. Everything an
 * operator can do through the campaign page — bulk generation, editing a reward, deleting a
 * code — stays there; this exists for a caller that has no session and no dashboard, and
 * that needs to ask for a code the moment an attendee needs one instead of them all being
 * made in advance.
 */
class CodeApiController extends Controller
{
    /**
     * Create one code, or hand back the one already made for this reference.
     *
     * The reference is the caller's own key for whoever this code is for — an attendee, a
     * station, a claim slot — and it is what makes a retried request safe. A lost response
     * followed by a second POST of the same reference must never make a second code: the
     * lookup for an existing one happens before anything is written, the code and its
     * rewards are created in one transaction so a failed reward insert leaves no code
     * behind, and the campaign's unique index on (campaign_id, reference) is the backstop
     * for two requests that arrive at the same moment and both pass that lookup. Because the
     * index has to be the backstop of record, the duplicate it catches is answered from a
     * fresh read taken after the failed insert's own transaction has rolled back, never from
     * inside it: a read still inside that transaction's snapshot can predate the very row it
     * is trying to find.
     *
     * A repeated reference is only a retry when it asks for the same thing. One sent again
     * with different lovelace or a different token set is a second, different request that
     * happens to reuse a name already spent, and answering it with the first code would
     * hand the caller a code that does not match what they just asked for; that is refused
     * as a conflict instead.
     *
     * Validated as the campaign page validates a code it creates by hand: the 1 ADA floor on
     * lovelace, and the same rule after it, that the amount stored is the higher of what was
     * sent and the exact minimum for the tokens. Sending 1 ADA with a token is accepted and
     * stored at the minimum rather than refused. A retry is answered from what was already
     * made, and a protocol parameter that has risen since never turns a replay of the same
     * request into a conflict. Everything else about the request is still validated whether
     * it is a retry or not.
     */
    public function store(
        Request $request,
        string $campaign,
        MinUtxoService $minUtxo,
        CreditLedger $ledger,
        CodeCapacity $capacity
    ): JsonResponse {
        $campaign = $this->ownedCampaign($request, $campaign);

        $reference = $request->validate([
            'reference' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])['reference'];

        // Looked up before the rest of the request is validated, specifically so the
        // live-parameter minimum below can be skipped for it. A reference this campaign
        // already has a code for is a retry, not a new request, and the only validation
        // that still has to run against it is the request's own shape.
        $existing = Code::with('rewards')
            ->where('campaign_id', $campaign->id)
            ->where('reference', $reference)
            ->first();

        $bundle = MinUtxoService::assetsFromRequest(
            is_array($request->input('tokens')) ? $request->input('tokens') : []
        );

        $maxTokens = (int) config('cardano.code_api.max_tokens_per_code', 20);
        $maxQuantity = (int) config('cardano.code_api.max_token_quantity', 1_000_000);

        $validated = $request->validate([
            'lovelace' => ['integer', 'required', 'min:1000000', 'max:45000000000000000'],
            'tokens' => ['nullable', 'array', 'max:'.$maxTokens],
            'tokens.*.policy_id' => ['required_with:tokens', 'regex:'.Reward::POLICY_HEX],
            'tokens.*.token_id' => ['required_with:tokens', 'regex:'.Reward::ASSET_HEX],
            'tokens.*.quantity' => ['required_with:tokens', 'integer', 'min:1', 'max:'.$maxQuantity],
        ]);

        $lovelace = (int) $validated['lovelace'];
        $tokens = $validated['tokens'] ?? [];

        if ($existing) {
            return $this->existingOrConflict($existing, $lovelace, $tokens, $minUtxo->effectiveLovelace($bundle, $campaign->network, $lovelace));
        }

        // Stored at the higher of what was sent and the exact minimum for the tokens, the
        // same rule the campaign page applies, so a caller sending 1 ADA with a token gets
        // a code that can actually be paid.
        $stored = $minUtxo->effectiveLovelace($bundle, $campaign->network, $lovelace);

        // Same guard the campaign page's own code creation applies: nothing added after the
        // redemption window closes can ever be claimed.
        if ($campaign->hasEnded()) {
            throw ValidationException::withMessages([
                'campaign' => ['This campaign has ended, so codes can no longer be added.'],
            ]);
        }

        // What this code would cost the campaign's own funding once it is claimed, checked
        // against the same spend limit and credit balance a claim is judged against. A code
        // made past what the campaign can pay for is a promise nobody funded, and this
        // deployment's caller has no dashboard to notice the shortfall the way an operator
        // reading the campaign page would.
        $holdReason = $ledger->holdReasonFor($campaign, Pricing::claimCostMicro(count($tokens)));

        if ($holdReason !== null) {
            throw ValidationException::withMessages([
                'campaign' => [$this->capMessage($holdReason)],
            ]);
        }

        // One code, one identity, one claim: the shape every code made through this endpoint
        // takes. perWallet and uses are not asked for because they are not this caller's to
        // set — an event's own code creation is one at a time, on demand, and the campaign
        // page remains where a bulk or a many-use code is made.
        //
        // The capacity check, the code and its rewards all run inside one transaction: the
        // campaign row is locked for the length of it, so two requests racing for the last
        // code under the operator's cap cannot both see room and both write, and a reward
        // insert that fails leaves no orphaned code behind.
        try {
            $code = $capacity->reserve($campaign, 1, function () use ($campaign, $reference, $stored, $tokens) {
                $code = $campaign->codes()->create([
                    'code' => Code::generateUniqueCode($campaign->id),
                    'reference' => $reference,
                    'perWallet' => 1,
                    'uses' => 1,
                    'lovelace' => $stored,
                ]);

                foreach ($tokens as $token) {
                    $code->rewards()->create([
                        'policy_hex' => $token['policy_id'],
                        'asset_hex' => $token['token_id'],
                        'quantity' => $token['quantity'],
                    ]);
                }

                return $code;
            });
        } catch (QueryException $e) {
            // The unique index on (campaign_id, reference) refused the row. For this insert
            // that means a second request for the same reference won the race between this
            // one's lookup above and its write, so the answer is what that request made, not
            // a 500 for a reference that is simply taken. Read fresh, after the failed
            // attempt's own transaction has rolled back: a read still inside that
            // transaction's snapshot is not guaranteed to see a row a concurrent transaction
            // only just committed. Whether one exists now decides it: anything else the
            // database refused is not this, and is rethrown.
            $existing = Code::with('rewards')
                ->where('campaign_id', $campaign->id)
                ->where('reference', $reference)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->existingOrConflict($existing, $lovelace, $tokens, $stored);
        }

        // The reference is the caller's own identifier for a person, so it is deliberately
        // not logged; the code id and the campaign say which row this was without it.
        Log::info('Code created via the code API.', [
            'campaign_id' => $campaign->id,
            'code_id' => $code->id,
        ]);

        return response()->json(['code' => $code->code], 201);
    }

    /**
     * What has happened to one code: whether it has been claimed, by which wallet, and
     * where the delivery of its reward has got to.
     *
     * $code is the code's own string, looked up scoped to the campaign, never the row's
     * numeric id: the only thing an external caller ever holds is what the creation
     * endpoint handed back, which is the string. Binding this route parameter to the Code
     * model by its id column would work by accident on an id that happens to still look
     * like the leading digits of a code string and fail everywhere else, and on a database
     * whose comparison coerces a string operand to a number for an integer column, it would
     * not even fail: 'WHERE id = ?' with a code string that starts with a digit can compare
     * true against a completely different code's row.
     *
     * Everything it reports comes off the claim a wallet made, not off the
     * code's own configuration — a code carries what it will pay, a claim carries what
     * actually happened. `claimed` goes true the moment a wallet's claim is accepted, which
     * is before the reward has necessarily reached the chain; `status`, `held_reason` and
     * `transaction_hash` are how a caller polling this tells "claimed, not yet sent",
     * "claimed, waiting on funding" and "claimed and landed" apart, so a claim held for lack
     * of credit does not sit reading as merely pending forever. Where a code has been
     * claimed more than once — not possible for a code this endpoint made, but possible for
     * one edited by hand afterwards — the most recent claim is what is reported, because it
     * is the current state of the code.
     *
     * A hash is only recorded when somebody asks the transaction backend, which does not
     * call back. So a request about a claim that has been sent and not yet confirmed queues
     * a check for its campaign, and answers with what the claim says now without waiting
     * for it. The check is debounced per campaign, so a hall full of attendees polling at
     * once queues one check per window rather than one per request, and the poll after it
     * finishes carries the hash.
     */
    public function status(Request $request, string $campaign, string $code): JsonResponse
    {
        $campaign = $this->ownedCampaign($request, $campaign);

        $found = Code::where('campaign_id', $campaign->id)
            ->where('code', $code)
            ->first();

        abort_unless($found !== null, 404);

        $claim = $found->claims()->latest('id')->first();

        if ($claim === null) {
            return response()->json([
                'claimed' => false,
                'stake_key' => null,
                'address' => null,
                'status' => null,
                'held_reason' => null,
                'transaction_hash' => null,
                'claimed_at' => null,
            ]);
        }

        if ($claim->isAwaitingConfirmation()) {
            CheckClaims::requestSoon($campaign->id);
        }

        return response()->json([
            'claimed' => true,
            'stake_key' => $claim->stake_key,
            'address' => $claim->address,
            'status' => $claim->status,
            'held_reason' => $claim->held_reason,
            'transaction_hash' => $claim->transaction_hash,
            'claimed_at' => optional($claim->created_at)->toIso8601String(),
        ]);
    }

    /**
     * What a caller needs to confirm it is pointed at the right live campaign: who it is,
     * which network, when it runs, whether claims are open this moment and, when they are
     * not, why, and the tokens its codes pay out.
     *
     * A miss is the 404 ownedCampaign() answers; its body's code is what lets a caller tell
     * it from a host that has no such route.
     *
     * The state is the campaign's own status: active, upcoming, ended or draft. Claims are
     * open only while it is active; any other state is also the reason they are not.
     * Tokens are the distinct policy, asset and quantity combinations on the campaign's
     * codes. Nothing here touches the chain or a wallet key; the one address is the campaign's funding wallet, which its owner already sees on the campaign page.
     */
    public function show(Request $request, string $campaign): JsonResponse
    {
        $found = $this->ownedCampaign($request, $campaign);

        $status = $found->status;

        $tokens = Reward::query()
            ->join('codes', 'codes.id', '=', 'rewards.code_id')
            ->where('codes.campaign_id', $found->id)
            ->select('rewards.policy_hex', 'rewards.asset_hex', 'rewards.quantity')
            ->distinct()
            ->orderBy('rewards.policy_hex')
            ->orderBy('rewards.asset_hex')
            ->orderBy('rewards.quantity')
            ->get()
            ->map(static fn ($reward) => [
                'policy_id' => $reward->policy_hex,
                'token_id' => $reward->asset_hex,
                'quantity' => (int) $reward->quantity,
            ])
            ->values()
            ->all();

        return response()->json([
            'id' => $found->id,
            'name' => $found->name,
            'network' => $found->network,
            'start_date' => $found->start_date ? \Carbon\Carbon::parse($found->start_date)->toDateString() : null,
            'end_date' => $found->end_date ? \Carbon\Carbon::parse($found->end_date)->toDateString() : null,
            'status' => $status,
            'claims_open' => $status === 'active',
            'claims_closed_reason' => $status === 'active' ? null : $status,
            'funding_address' => $found->wallet?->address,
            'tokens' => $tokens,
        ]);
    }

    /**
     * The campaign named in the URL, only when it belongs to the token's owner.
     *
     * The route parameter is a plain string and the lookup is scoped to the owner before
     * anything is read, so a campaign that does not exist and one that belongs to another
     * account are the same miss with the same answer: a 404 whose body carries the code
     * `campaign_not_found`. Binding the model first would answer an unknown id and a foreign
     * one through different paths, and a caller could tell a live campaign from a missing
     * one.
     */
    private function ownedCampaign(Request $request, string $id): Campaign
    {
        $campaign = Campaign::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if ($campaign === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'No campaign with this id is available to this token.',
                'code' => 'campaign_not_found',
            ], 404));
        }

        return $campaign;
    }

    /**
     * The response for a reference that already has a code: the same code, when the request
     * asks for the same thing it asked for before, and a conflict when it does not.
     */
    private function existingOrConflict(Code $existing, int $lovelace, array $tokens, int $effective): JsonResponse
    {
        if ($this->matches($existing, $lovelace, $tokens, $effective)) {
            return response()->json(['code' => $existing->code]);
        }

        return response()->json([
            'message' => 'This reference already has a code with a different lovelace amount or token set.',
            'code' => $existing->code,
        ], 409);
    }

    /**
     * Whether an existing code was made from this lovelace amount and token set.
     *
     * Order carries no meaning in a token list — the caller is describing a set of rewards,
     * not a sequence — so both sides are sorted the same way before comparing, and a policy
     * or asset id typed in a different case is still the same id.
     *
     * @param  array<int, array{policy_id: string, token_id: string, quantity: int}>  $tokens
     */
    private function matches(Code $existing, int $lovelace, array $tokens, int $effective): bool
    {
        // A code made from this request holds the amount sent, or the minimum for its
        // tokens when that was higher. The minimum is read from today's parameters, so a
        // code raised under an older, lower one is still recognised: the amount sent was
        // below it, and the stored amount is above what was sent.
        $stored = (int) $existing->lovelace;

        if ($stored !== $lovelace && $stored !== $effective && ! ($lovelace < $stored && $lovelace < $effective)) {
            return false;
        }

        $requested = $this->tokenSignature($tokens);

        $stored = $this->tokenSignature($existing->rewards->map(static fn ($reward) => [
            'policy_id' => $reward->policy_hex,
            'token_id' => $reward->asset_hex,
            'quantity' => $reward->quantity,
        ])->all());

        return $requested === $stored;
    }

    /**
     * @param  array<int, array{policy_id: string, token_id: string, quantity: int}>  $tokens
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function tokenSignature(array $tokens): array
    {
        $signature = array_map(static fn (array $token) => [
            strtolower((string) $token['policy_id']),
            strtolower((string) $token['token_id']),
            (int) $token['quantity'],
        ], $tokens);

        sort($signature);

        return $signature;
    }

    /** What to tell a caller whose code would outrun what the campaign is funded for. */
    private function capMessage(string $reason): string
    {
        return match ($reason) {
            CreditLedger::HELD_SPEND_LIMIT => 'This campaign has reached the spend limit its operator set. Raise the limit before creating more codes.',
            CreditLedger::HELD_NO_CREDIT => 'This campaign has no credit to fund another code.',
            default => 'This campaign cannot currently fund this code.',
        };
    }
}
