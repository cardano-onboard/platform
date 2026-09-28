<?php

namespace App\Http\Controllers;

use App\Contracts\TransactionBackend;
use App\Jobs\AnalyzeCampaignOnboarding;
use App\Jobs\CheckClaims;
use App\Jobs\CreateCampaignBucket;
use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignClaimClient;
use App\Models\CampaignTask;
use App\Models\CampaignWalletInsight;
use App\Models\Partner;
use App\Models\QrExport;
use App\Models\Wallet;
use App\Rules\CardanoAddress;
use App\Services\AssetDisplay;
use App\Services\CampaignAlerts;
use App\Services\CampaignCosts;
use App\Services\CreditLedger;
use App\Services\MinUtxoService;
use App\Services\OnboardingAnalysisService;
use App\Services\PartnerConversionService;
use App\Services\QrExportService;
use App\Services\QrStickerService;
use App\Support\MinUtxo;
use App\Support\Pricing;
use App\Support\QrExportFolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignController extends Controller
{
    /**
     * The page component show() renders. Also the name a partial reload of that page
     * sends back in its X-Inertia-Partial-Component header, which is how show() tells
     * a reload of one panel from a fresh page load.
     */
    private const SHOW_COMPONENT = 'Campaign/Show';

    public function __construct(private TransactionBackend $backend)
    {
        $this->authorizeResource(Campaign::class, 'campaign');
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
            'network' => ['required', Rule::in(config('cardano.allowed_networks'))],
            'txn_msg' => 'nullable|string|max:64',
            'nmkr_api_key' => 'nullable|string|max:255',
            'max_codes' => 'nullable|integer|min:1|max:1000000',
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

        // Assigned rather than mass-assigned, the same reason the spend limit and the
        // alert settings sit outside $fillable: nothing a request carries should be able
        // to reach a campaign-wide guard except through a rule that named it.
        if (array_key_exists('max_codes', $validated)) {
            $campaign->max_codes = $validated['max_codes'];
            $campaign->save();
        }

        if ($campaign->id) {
            CreateCampaignBucket::dispatch($campaign->id);
        }

        return to_route('campaigns.show', $campaign->id);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Campaign $campaign, CampaignCosts $costs, CampaignAlerts $alerts, CreditLedger $ledger, MinUtxoService $minUtxo, OnboardingAnalysisService $onboarding, PartnerConversionService $conversion, AssetDisplay $assetDisplay): Response|RedirectResponse
    {
        // Provisioning a bucket is an action rather than a page prop, so it stays out of
        // the closures below. Inside one, whether a campaign ever got provisioned would
        // depend on which props the browser happened to ask for, which is not something
        // the browser knows it is deciding. It runs on a full page load only: a partial
        // reload is the same page a full load already opened, and that load already
        // queued the job, so repeating it once per poll only queues duplicates.
        //
        // Released rather than left to its destructor, which would otherwise run after
        // the request that created it. See the note in CodeController::claim.
        if (! $this->isPartialReload($request) && ! $this->walletFor($campaign)) {
            $pending = CreateCampaignBucket::dispatch($campaign->id);
            unset($pending);
        }

        // The bucket balance is an HTTP call to the transaction backend. Resolved at
        // most once per request however many props ask for it, and not at all when none
        // of them does.
        $bucket = null;
        $balance = function () use ($campaign, &$bucket): array {
            if ($bucket !== null) {
                return $bucket;
            }

            $wallet = $this->walletFor($campaign);

            return $bucket = $wallet
                ? $wallet->resolveBackend()->getBalance($wallet->address, $campaign->network)
                : [];
        };

        // Walked once for the two props that need it. A regular closure and a reference,
        // not an arrow function, which captures by value and would memoize into a copy.
        $totals = null;
        $rewardTotals = function () use ($campaign, &$totals): array {
            return $totals ??= $this->rewardTotals($campaign);
        };

        // The chain's floor for each code, kept apart from the reward totals above
        // because the coefficient behind it is a backend call and the funding panel has
        // no need of one. Walked once for the two props that read it.
        $minimums = null;
        $codeMinimums = function () use ($campaign, $minUtxo, &$minimums): array {
            return $minimums ??= $this->attachCodeMinimums($campaign, $minUtxo);
        };

        // Every prop that costs a query, a walk over the codes or a backend call is a
        // closure, because Inertia resolves a closure prop only when the request asks
        // for that prop. A full page load asks for all of them and behaves as it always
        // has; a partial reload for one panel pays for that panel alone.
        return Inertia::render(self::SHOW_COMPONENT, [
            'campaign' => function () use ($campaign, $rewardTotals, $codeMinimums): array {
                // Attaches min_utxo to each code, which the codes table reads to mark a
                // code the chain will never accept. Memoized with the panel's counts.
                $codeMinimums();

                return $this->campaignPayload($campaign, $rewardTotals());
            },
            'stats' => fn () => $this->buildStats($campaign),
            'claim_url' => fn () => $campaign->claimUrl(),
            'encoded_claim_url' => fn () => urlencode($campaign->claimUrl()),
            'balance' => fn () => $balance(),
            'wallet_pending' => fn () => $this->walletFor($campaign) === null,
            // Backend mismatch: the wallet was created under a different backend than the
            // one this deployment is configured with.
            'backend_mismatch' => function () use ($campaign): bool {
                $wallet = $this->walletFor($campaign);

                if (! $wallet) {
                    return false;
                }

                return (config('cardano.transaction_backend') ?? 'null') !== ($wallet->backend ?? 'phyrhose');
            },
            'wallet_backend' => function () use ($campaign): ?string {
                $wallet = $this->walletFor($campaign);

                return $wallet ? ($wallet->backend ?? 'phyrhose') : null;
            },
            'max_file_size' => config('cardano.max_file_size', 10 * 1024 * 1024),
            // Who this campaign hands codes out through. Live rows only, so a partner that
            // was removed is off the picker while the codes it was given keep its name.
            'partners' => fn () => $campaign->partners()
                ->orderBy('name')
                ->get(['id', 'name', 'kind']),
            // What the picker starts on. Generating another batch for the same person is
            // the repeated action at a booth, and "Unassigned" is the one answer that
            // cannot be corrected afterwards, so it is not the one to default to.
            'default_partner_id' => fn () => Partner::lastUsedOn($campaign),
            // Which of those partners produced claims, with a partner who produced none
            // shown as a row of zeros rather than left out of it.
            //
            // A closure, because Inertia resolves a closure prop only when the request
            // asks for that prop. The page polls itself while a campaign is running, and
            // a reload for one panel must not pay for a grouped query over every code on
            // the campaign that nothing on screen is waiting for.
            'partner_conversion' => fn () => $conversion->for($campaign),
            'allowed_networks' => config('cardano.allowed_networks'),
            // Gates the PNG option in the QR export modal — raster output needs GD,
            // which is not compiled into every PHP runtime.
            'gd_available' => QrStickerService::pngSupported(),
            'onboarding' => fn () => $this->onboardingPanel($campaign, $request, $onboarding),
            // Who this campaign's codes can be exported for, and how many each of them has.
            // A closure like the panels below it: a partial reload asking for the codes
            // table should not pay for a grouped count as well.
            'export_scopes' => fn () => $this->exportScopes($campaign),
            // Background work the operator is waiting on, and the clock the page starts
            // polling from. A closure, so a partial reload that asks only for the codes
            // does not pay for this query as well.
            'tasks' => fn () => $this->taskPanel($campaign),
            // The sticker archives already built for this campaign. A closure for the same
            // reason as the tasks above, and the prop a finished render names in its
            // reloads(), so the panel offering the download appears without the page being
            // reloaded and without anything polling this endpoint to find out.
            'qr_exports' => fn () => $this->qrExportPanel($campaign),
            'funding' => fn () => $this->fundingPanel($campaign, $rewardTotals()),
            // The chain's minimum for what these codes carry, and how many of them are
            // under it. See the note where this is built.
            'min_utxo' => fn () => $this->minUtxoPanel($campaign, $minUtxo, $codeMinimums()),
            // Whether this campaign should be telling its operator it is running short,
            // and the settings that decide it. Only while it is actually running: before
            // it starts there is nothing to be short of, and after it ends a warning
            // about a shortfall is noise arriving after the fact.
            'alerts' => fn () => $alerts->for($campaign, $balance()),
            // What this campaign may spend and what it has spent, for the control that
            // sets the cap. A closure like the rest: it reads the ledger, which a partial
            // reload for another panel has no reason to pay for.
            'spending' => fn () => $this->spendingPanel($campaign, $ledger),
            // What this campaign cost its operator, split into what they paid us, what
            // the chain took, and what they gave away. Read from what each claim
            // recorded rather than from a rate times a count, so a claim taken under an
            // earlier rate still reports the rate it was charged.
            'costs' => fn () => $this->withCachedAssetMeta($costs->for($campaign), $campaign, $assetDisplay),
            // Display metadata for every reward asset on this campaign that is already
            // cached, keyed by subject, so a reload shows names and decimals without a
            // lookup request per asset. The page asks only about the ones missing here.
            'asset_meta' => fn () => (object) $assetDisplay->cached(
                DB::table('rewards')
                    ->join('codes', 'codes.id', '=', 'rewards.code_id')
                    ->where('codes.campaign_id', $campaign->id)
                    ->distinct()
                    ->get(['rewards.policy_hex', 'rewards.asset_hex'])
                    ->map(fn ($row) => [$row->policy_hex, $row->asset_hex ?? ''])
                    ->all(),
                $campaign->network,
            ),
            // Which wallet software this campaign's claimants used. Withheld while the
            // campaign is still running, because the tally moves one claim at a time and
            // this page names claimants; an administrator sees it early for integration
            // work. See App\Models\CampaignClaimClient::campaignShares.
            'wallet_clients' => fn () => CampaignClaimClient::campaignShares(
                $campaign,
                // The early view belongs to whoever runs the deployment, which is only a
                // different person from the campaign's operator where there is a platform
                // to run. The self-hosted build ships a reduced route table without the
                // metrics view, and there the operator is the seeded admin, so the flag
                // would otherwise hand them the very per-claim sequence the end date
                // exists to withhold.
                Auth::user()?->is_admin === true && Route::has('admin.metrics'),
            ),
        ]);
    }

    /**
     * Whether this request is an Inertia partial reload of the campaign page.
     *
     * The test is Inertia's own, Inertia\Response::isPartial, so it cannot disagree with
     * the set of props Inertia is about to resolve.
     */
    private function isPartialReload(Request $request): bool
    {
        return $request->header('X-Inertia-Partial-Component') === self::SHOW_COMPONENT;
    }

    /**
     * The campaign's wallet. The relation is loaded at most once per request, so the
     * props that need it do not each cost a query.
     */
    private function walletFor(Campaign $campaign): ?Wallet
    {
        return $campaign->loadMissing('wallet')->wallet;
    }

    /**
     * What the campaign still owes across every unclaimed use of every code, and how
     * many of those uses are left.
     */
    private function rewardTotals(Campaign $campaign): array
    {
        $campaign->loadMissing(['codes', 'codes.rewards']);

        $tokens = ['lovelace' => 0];
        $remainingClaims = 0;

        foreach ($campaign->codes as $code) {
            $remaining = max(0, $code->uses - $code->claims_count);

            $remainingClaims += $remaining;
            $tokens['lovelace'] += $code->lovelace * $remaining;

            foreach ($code->rewards as $reward_token) {
                $token_id = $reward_token['policy_hex'].'.'.$reward_token['asset_hex'];
                if (! isset($tokens[$token_id])) {
                    $tokens[$token_id] = 0;
                }
                $tokens[$token_id] += $reward_token['quantity'] * $remaining;
            }
            // Rewards are intentionally left attached so the codes table can show
            // per-code reward details. Reward::$hidden strips sensitive columns.
        }

        return [
            'tokens' => $tokens,
            'remaining_claims' => $remainingClaims,
        ];
    }

    /**
     * What every code on this campaign has to be worth for the chain to accept it.
     *
     * The quote is attached to the code so the table can mark one that will never be
     * paid, and the still-claimable ones are counted so the panel above the table can say
     * how many there are without an operator opening every row. The quote comes from the
     * same calculation that validates a new code, so the two cannot disagree.
     *
     * @return array{below_minimum: int, tight: int}
     */
    private function attachCodeMinimums(Campaign $campaign, MinUtxoService $minUtxo): array
    {
        $campaign->loadMissing(['codes', 'codes.rewards']);

        $belowMinimum = 0;
        $tight = 0;

        foreach ($campaign->codes as $code) {
            $quote = $minUtxo->forCode($code);
            $code->min_utxo = [
                'min_lovelace' => $quote['min_lovelace'],
                'recommended_lovelace' => $quote['recommended_lovelace'],
                'state' => $quote['state'],
                'warning' => $quote['warning'],
            ];

            if (max(0, $code->uses - $code->claims_count) > 0) {
                $belowMinimum += $quote['state'] === 'below_minimum' ? 1 : 0;
                $tight += $quote['state'] === 'tight' ? 1 : 0;
            }
        }

        return ['below_minimum' => $belowMinimum, 'tight' => $tight];
    }

    /**
     * The chain's own floor, and how many still-claimable codes sit under or on it.
     *
     * A code below its minimum is not a funding shortfall that more ADA in the bucket
     * would fix: it cannot be paid at any balance, and the operator has to change the
     * code. That is why it is reported separately from the top-up figure.
     *
     * @param  array{below_minimum: int, tight: int}  $counts
     */
    private function minUtxoPanel(Campaign $campaign, MinUtxoService $minUtxo, array $counts): array
    {
        return [
            'coins_per_utxo_byte' => $minUtxo->coinsPerUtxoByte($campaign->network),
            'headroom_lovelace' => MinUtxo::headroom(),
            'below_minimum_codes' => $counts['below_minimum'],
            'tight_codes' => $counts['tight'],
        ];
    }

    /**
     * What this campaign may spend, and what it has spent, for the control that sets it.
     *
     * `applies` is false wherever a limit could not do anything, and the page leaves the
     * control out rather than showing one that cannot bite. A path that takes its fee
     * from the campaign's own bucket has already been paid by the time a claim is served,
     * and a deployment that is not charging has no spend to cap; both are exactly the
     * cases CreditLedger::holdReasonFor returns from before it ever reads the limit.
     *
     * The figures are in credits, which is the unit the operator types and reads. The
     * column holds millionths, and converting here keeps that the endpoint's business
     * rather than the page's.
     */
    private function spendingPanel(Campaign $campaign, CreditLedger $ledger): array
    {
        $path = Pricing::pathFor($campaign);
        $applies = Pricing::billingEnabled()
            && Pricing::isConfigured($path)
            && ! Pricing::collectsInBand($path);

        return [
            'applies' => $applies,
            'limit_credits' => $campaign->spend_limit_micro === null
                ? null
                : Pricing::microToCredits((int) $campaign->spend_limit_micro),
            'spent_credits' => $applies
                ? Pricing::microToCredits($ledger->spentMicro($campaign))
                : '0',
        ];
    }

    /**
     * Puts each single-asset policy's cached display metadata on its costs row, as `meta`.
     * A row without the key has nothing cached and the page looks it up; a null `meta` is
     * an asset Koios does not know, which the page does not ask about again.
     */
    private function withCachedAssetMeta(array $costs, Campaign $campaign, AssetDisplay $assetDisplay): array
    {
        $pairs = collect($costs['policies'])
            ->filter(fn (array $policy) => $policy['asset_hex'] !== null)
            ->map(fn (array $policy) => [$policy['policy_hex'], $policy['asset_hex']])
            ->values()
            ->all();

        $known = $assetDisplay->cached($pairs, $campaign->network);

        foreach ($costs['policies'] as &$policy) {
            $subject = strtolower($policy['policy_hex'].($policy['asset_hex'] ?? ''));
            if ($policy['asset_hex'] !== null && array_key_exists($subject, $known)) {
                $policy['meta'] = $known[$subject];
            }
        }

        return $costs;
    }

    /**
     * The campaign itself, its codes and its claims, as the page reads them.
     */
    private function campaignPayload(Campaign $campaign, array $totals): array
    {
        $campaign->loadMissing([
            'codes',
            'codes.rewards',
            // Who each batch was generated for, so the codes table can name it. Trashed
            // partners included: removing a partner takes it off the picker, it does not
            // take its name off the codes it was handed. Without withTrashed the soft
            // delete would blank the attribution on every code that partner was given,
            // which is the one thing the soft delete exists to prevent.
            'codes.partner' => fn ($query) => $query->withTrashed()->select(['id', 'name']),
            'claims',
        ]);
        $campaign->rewards = $totals['tokens'];

        $wallet = $this->walletFor($campaign);

        $campaignData = $campaign->only([
            'id', 'name', 'description', 'start_date', 'end_date',
            'one_per_wallet', 'network', 'txn_msg', 'nmkr_api_key', 'rewards',
            // Lifecycle status accessor — the frontend gates ended-campaign actions
            // (disable Add/Import Codes and Top Up) on this. Without it every such
            // control stays enabled on an ended campaign.
            'status',
            // The alert settings as they are stored, not as they are in force. The edit
            // dialog seeds its fields from these, and a threshold that has never been set
            // has to arrive as null so the box shows the default as a placeholder rather
            // than writing that default back the first time anything else is saved.
            'alerts_enabled', 'alert_threshold_claims',
            // How many codes this campaign may ever hold. Null reads as no limit, the
            // same absence-means-unlimited convention alert_threshold_claims uses.
            'max_codes',
        ]);

        $campaignData['wallet'] = $wallet === null ? null : [
            'address' => $wallet->address,
        ];
        $campaignData['codes'] = $campaign->codes;
        $campaignData['claims'] = $campaign->claims;
        $campaignData['codes_count'] = $campaign->codes->count();
        $campaignData['claims_count'] = $campaign->claims->count();

        return $campaignData;
    }

    /**
     * What still has to be in the bucket, split into what it is for.
     *
     * The top-up figure has always included the fees as well as the rewards, so a full
     * bucket covers every code that can still be claimed. It was one number though, and
     * an operator asked to send ADA could not see how much of it was going to claimants,
     * how much to the chain and how much to us. They are three different things and the
     * last of them does not exist on every deployment.
     *
     * Network fees and what was given away are real for everybody, including a
     * self-hoster running campaigns for their own project, and the tax reasoning is the
     * same for them. Only the platform fee depends on this deployment charging at all,
     * and that line is withheld rather than shown as zero.
     */
    private function fundingPanel(Campaign $campaign, array $totals): array
    {
        $inBandFee = Pricing::inBandCharge(Pricing::pathFor($campaign))['revenue_lovelace'];
        $networkFee = (int) config('cardano.network_fee_lovelace');
        $remainingClaims = $totals['remaining_claims'];

        return [
            'remaining_claims' => $remainingClaims,
            'reward_lovelace' => (int) $totals['tokens']['lovelace'],
            'network_fee_lovelace' => $remainingClaims * $networkFee,
            'platform_fee_lovelace' => $inBandFee === null ? null : $remainingClaims * $inBandFee,
        ];
    }

    /**
     * Set or clear what this campaign may spend.
     *
     * Its own endpoint rather than another field on the campaign edit form, because this
     * is a funding question rather than a campaign setting, and because the form it
     * belongs beside is the one an operator opens when they are looking at what the
     * campaign has already cost.
     */
    public function spendLimit(Request $request, Campaign $campaign, CreditLedger $ledger): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            // Typed in credits and stored in millionths. Six places is the whole of the
            // column's precision, so a seventh is refused rather than quietly dropped.
            'spend_limit_credits' => ['nullable', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,6'],
        ]);

        // Nothing sent, or an empty box, is how an operator lifts a limit. This endpoint
        // has one field and exists for nothing else, so absent and blank mean the same.
        $typed = $validated['spend_limit_credits'] ?? null;

        $was = $campaign->spend_limit_micro === null ? null : (int) $campaign->spend_limit_micro;

        // Assigned rather than mass-assigned, for the reason the alert settings are: this
        // sits outside $fillable with the money columns, and nothing a request carries
        // should be able to reach it except through a rule that named it.
        $campaign->spend_limit_micro = $typed === null ? null : Pricing::creditsToMicro($typed);
        $campaign->save();

        $now = $campaign->spend_limit_micro === null ? null : (int) $campaign->spend_limit_micro;
        $released = 0;

        // A limit that has been raised or lifted has to let go of what it was holding.
        // Without this the queue waits for the next credit grant to notice, and the
        // operator who just raised the limit sees nothing happen. A first limit on a
        // campaign that had none is a ceiling coming down, so nothing is released.
        if ($campaign->user !== null && $now !== $was
            && ($now === null || ($was !== null && $now > $was))) {
            $released = $ledger->release($campaign->user);
        }

        Log::info('Campaign spend limit set.', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
            'limit_micro' => $now,
            'released' => $released,
        ]);

        return back()->with('message', $released > 0
            ? "Spend limit updated. {$released} waiting claim(s) released."
            : 'Spend limit updated.');
    }

    /**
     * Onboarding analysis for the campaign page: the headline metrics plus the per-wallet
     * rows behind them.
     *
     * Stake keys are truncated for display. The full key is a public on-chain identifier
     * and the campaign owner paid these wallets, so it is not secret, but there is no
     * reason for the page to carry hundreds of them when the panel only needs to show
     * which wallet a row refers to.
     */
    private function onboardingPanel(Campaign $campaign, Request $request, OnboardingAnalysisService $onboarding): array
    {
        $analysis = $campaign->analysis;
        $scope = $this->onboardingScope($request);

        return [
            'status' => $analysis?->status,
            'summary' => $analysis?->summary,
            'completed_at' => $analysis?->completed_at?->toIso8601String(),
            // Whether the stored result came from a run that could tell a wallet which
            // delegated from a wallet which transacted. A result from before that could be
            // told apart carries an activation figure that counted both, so the panel has
            // to say the answer is not known rather than show the old number or a zero.
            'windowed' => (bool) $analysis?->isWindowed(),
            // How many wallets the last run went to read and could not. A provider that
            // fails leaves holes, and a hole is not a zero: the panel says so rather than
            // presenting a result with wallets missing from it as a reading of the
            // campaign.
            'unread_wallets' => (int) ($analysis?->unread_wallets ?? 0),
            'error' => $analysis?->error,
            'eligible_claims' => $campaign->claims()
                ->whereNotNull('claims.transaction_hash')
                ->count(),
            'total_claims' => $campaign->claims()->count(),
            // Claims the analysis cannot see yet and a status check still can: the gap
            // between what the panel would measure and what the campaign holds. Counted
            // live rather than read from the last run, because it is what is true now
            // that decides whether running the analysis is worth anything.
            'pending_claims' => $campaign->claims()->awaitingConfirmation()->count(),
            'scope' => $this->onboardingScopePayload($campaign, $scope, $onboarding),
            'wallets' => $campaign->walletInsights()
                ->when($scope['applied'], fn ($query) => $query
                    ->whereNotNull('claimed_at')
                    ->when($scope['from_at'], fn ($q) => $q->where('claimed_at', '>=', $scope['from_at']))
                    ->when($scope['to_at'], fn ($q) => $q->where('claimed_at', '<=', $scope['to_at'])))
                ->orderByDesc('is_new')
                ->orderByDesc('activity_count')
                ->get()
                ->map(fn ($w) => [
                    'stake_key' => $w->stake_key,
                    'stake_key_short' => Str::limit($w->stake_key, 12, '…').Str::substr($w->stake_key, -6),
                    // Null where no run could read the wallet's history. The table shows
                    // that as unknown: a wallet nobody could look up is not an established
                    // wallet, and filing it as one is the failure this column exists to
                    // avoid.
                    'is_new' => $w->is_new,
                    // Null, not false, for a row no windowed run has read. The table shows
                    // those as unknown, because a wallet nobody has looked at since the
                    // correction is not a wallet that was looked at and did nothing.
                    'windowed' => $w->isWindowed(),
                    'activity_count' => $w->isWindowed() ? (int) $w->activity_count : null,
                    'delegation_events' => $w->isWindowed() ? (int) $w->delegation_events : null,
                    'first_activity_days' => $w->first_activity_seconds === null
                        ? null
                        : (int) floor($w->first_activity_seconds / CampaignWalletInsight::DAY),
                    'first_delegation_days' => $w->first_delegation_seconds === null
                        ? null
                        : (int) floor($w->first_delegation_seconds / CampaignWalletInsight::DAY),
                    'delegated' => $w->delegated,
                    'is_operator' => $w->is_operator,
                    'prior_tx_count' => $w->prior_tx_count,
                    'self_initiated_count' => $w->self_initiated_count,
                    'claimed_at' => $w->claimed_at?->toIso8601String(),
                ]),
        ];
    }

    /**
     * The date range the panel was asked for.
     *
     * Both ends are optional and either may be given alone. A value that is not a date,
     * or a range that ends before it starts, is reported rather than quietly dropped: a
     * panel that answers a question nobody asked is worse than one that says it could
     * not.
     *
     * @return array{from: ?string, to: ?string, from_at: ?Carbon, to_at: ?Carbon, applied: bool, error: ?string}
     */
    private function onboardingScope(Request $request): array
    {
        $from = trim((string) $request->query('onboarding_from', ''));
        $to = trim((string) $request->query('onboarding_to', ''));

        $scope = [
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
            'from_at' => null,
            'to_at' => null,
            'applied' => false,
            'error' => null,
        ];

        if ($from === '' && $to === '') {
            return $scope;
        }

        // Whole days, in the deployment's own timezone: a range is given as two dates and
        // the day named as the end belongs inside it.
        $scope['from_at'] = $from !== '' ? $this->parseScopeDate($from)?->startOfDay() : null;
        $scope['to_at'] = $to !== '' ? $this->parseScopeDate($to)?->endOfDay() : null;

        if (($from !== '' && ! $scope['from_at']) || ($to !== '' && ! $scope['to_at'])) {
            return ['error' => 'That is not a date the range can be read from.'] + $scope;
        }

        if ($scope['from_at'] && $scope['to_at'] && $scope['from_at']->greaterThan($scope['to_at'])) {
            return ['error' => 'The end of the range is before its start.'] + $scope;
        }

        $scope['applied'] = true;

        return $scope;
    }

    /**
     * A date, or nothing. The round trip back through the format is what rejects the
     * values PHP would otherwise roll over for you: 2026-02-31 parses happily and comes
     * back as 3 March, which is not the range anybody asked for.
     */
    private function parseScopeDate(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * What the panel needs to show a scoped result beside the whole-campaign one.
     *
     * The scoped summary is recomputed from the stored per-wallet rows on every request
     * and never written anywhere. The difference between the two is the informative part:
     * a campaign whose event claimants went on to transact and whose later claimants did
     * not reads as one indifferent average when the two are measured together.
     *
     * @param  array{from: ?string, to: ?string, from_at: ?Carbon, to_at: ?Carbon, applied: bool, error: ?string}  $scope
     */
    private function onboardingScopePayload(Campaign $campaign, array $scope, OnboardingAnalysisService $onboarding): array
    {
        // One pass for all four figures. This runs on every load of the campaign page,
        // scoped or not, and four separate aggregates over the same rows would be four
        // trips for one answer.
        $rows = $campaign->walletInsights()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when claimed_at is null then 1 else 0 end) as undated')
            ->selectRaw('min(claimed_at) as first_at')
            ->selectRaw('max(claimed_at) as last_at')
            ->first();

        return [
            'from' => $scope['from'],
            'to' => $scope['to'],
            'applied' => $scope['applied'],
            'error' => $scope['error'],
            'summary' => $scope['applied']
                ? $onboarding->summarizeWindow($campaign, $scope['from_at'], $scope['to_at'])
                : null,
            'wallets_total' => (int) ($rows->total ?? 0),
            // A row with no claim timestamp cannot be placed in any range, so it is absent
            // from every scoped result. Counted here rather than left to vanish.
            'wallets_undated' => (int) ($rows->undated ?? 0),
            // The days the campaign actually has rows for, so the fields can offer the
            // range that exists instead of the whole calendar.
            'bounds' => [
                'first' => $rows?->first_at ? Carbon::parse($rows->first_at)->format('Y-m-d') : null,
                'last' => $rows?->last_at ? Carbon::parse($rows->last_at)->format('Y-m-d') : null,
            ],
        ];
    }

    /**
     * What background work is running on this campaign, for the page's poller.
     *
     * The campaign page itself is expensive: it loads codes, claims and rewards and calls
     * the transaction backend for a balance. Polling it would turn a running import into a
     * balance call every few seconds, so the poller comes here instead, where the answer is
     * one indexed query and a few hundred bytes.
     *
     * The response carries every unfinished run plus anything that finished since the stamp
     * the client sends, so a job that starts and finishes between two polls is still
     * reported rather than vanishing. The client sends back the `now` from its last
     * response: the server's clock is the only one both sides agree on, and a browser clock
     * a minute fast would otherwise step straight over the completion it is waiting for.
     */
    public function tasks(Campaign $campaign, Request $request): JsonResponse
    {
        // authorizeResource() in the constructor covers the seven resource methods, and this
        // is not one of them. Without this line any signed-in account could read what
        // another tenant's campaign is doing.
        $this->authorize('view', $campaign);

        $since = $this->pollWindow($request->query('since'));

        $tasks = $campaign->tasks()
            ->where(static function ($query) use ($since) {
                $query->whereIn('status', CampaignTask::ACTIVE_STATUSES)
                    ->orWhere(static function ($query) use ($since) {
                        $query->whereIn('status', CampaignTask::TERMINAL_STATUSES)
                            ->where('completed_at', '>=', $since);
                    });
            })
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'now' => now()->toIso8601String(),
            'tasks' => $tasks->map(static fn (CampaignTask $task) => $task->toPollPayload())->values(),
        ]);
    }

    /**
     * How far back one poll may ask for finished runs.
     *
     * Whatever arrives in the query string is a string off the network, so it is parsed
     * rather than trusted: anything unreadable means "since now", which reports the runs
     * still going and nothing else. A stamp from the future would hide the completion the
     * client is waiting for, and one from last year would drag the whole history back on
     * every poll, so both ends are clamped.
     */
    private function pollWindow(mixed $since): Carbon
    {
        $now = now();
        $floor = $now->copy()->subDay();

        if (! is_string($since) || trim($since) === '') {
            return $now;
        }

        try {
            $parsed = Carbon::parse(trim($since));
        } catch (\Throwable) {
            return $now;
        }

        return match (true) {
            $parsed->gt($now) => $now,
            $parsed->lt($floor) => $floor,
            default => $parsed,
        };
    }

    /**
     * The task state the page renders with, and the clock its first poll asks from.
     *
     * Unfinished runs only. A run that finished before the page was drawn is already in the
     * data the page is showing, so reporting it would only make the client fetch again what
     * it has just been given.
     */
    private function taskPanel(Campaign $campaign): array
    {
        return [
            'now' => now()->toIso8601String(),
            'items' => $campaign->tasks()
                ->active()
                ->orderBy('created_at')
                ->get()
                ->map(static fn (CampaignTask $task) => $task->toPollPayload())
                ->values(),
        ];
    }

    /**
     * Queue an onboarding analysis run for this campaign.
     *
     * A run confirms outstanding claims before it measures anything, so a campaign whose
     * claims have not been checked since the venue is worth running rather than refusing:
     * the claims it cannot see yet are the ones the run is about to confirm.
     *
     * Claiming the task row is what decides whether this run happens. A second request while
     * one is in flight used to queue a job that WithoutOverlapping threw away further down
     * the queue, which told the operator an analysis had started and then produced nothing;
     * now the refusal happens here, where there is somebody to tell.
     */
    public function analyzeOnboarding(Campaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $eligible = $campaign->claims()->whereNotNull('claims.transaction_hash')->count();
        $pending = $campaign->claims()->awaitingConfirmation()->count();

        if ($eligible === 0 && $pending === 0) {
            return back()->with('message', 'There are no confirmed claims to analyze yet.');
        }

        $task = CampaignTask::claim(
            $campaign,
            AnalyzeCampaignOnboarding::TASK_TYPE,
            AnalyzeCampaignOnboarding::dedupeKey(),
            ['eligible_claims' => $eligible, 'pending_claims' => $pending],
            Auth::id(),
        );

        if (! $task) {
            return back()->with('message',
                'An analysis is already running for this campaign. Its progress is in the Onboarding panel.');
        }

        // Written after the claim, not before. The analysis row is what the panel shows once
        // the run is over and nothing is being polled, so moving it back to pending for a
        // run that was refused would erase a finished result to describe a run that never
        // started.
        CampaignAnalysis::updateOrCreate(
            ['campaign_id' => $campaign->id],
            ['status' => CampaignAnalysis::STATUS_PENDING, 'error' => null]
        );

        AnalyzeCampaignOnboarding::dispatch($campaign->id, $task->id);

        Log::info('Onboarding analysis queued.', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
            'eligible_claims' => $eligible,
            'pending_claims' => $pending,
            'task_id' => $task->id,
        ]);

        return back()->with('message', $pending > 0
            ? "Checking {$pending} unconfirmed claim(s) first, then analyzing. Results appear here when it finishes."
            : 'Onboarding analysis started. Results appear here when it finishes.');
    }

    /**
     * Every asset handed out, one row each.
     *
     * The page collapses a policy that minted a serial per recipient, because a
     * thousand-claim NFT drop is a thousand assets of quantity one and listing them
     * buries the rest of the statement. The full sheet still has to exist, because that
     * is the one an accountant wants.
     */
    public function exportCosts(Campaign $campaign, CampaignCosts $costs): StreamedResponse
    {
        $this->authorize('view', $campaign);

        $filename = Str::slug($campaign->name ?: 'campaign').'-assets-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($campaign, $costs) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['policy_id', 'asset_hex', 'asset_name', 'quantity']);

            foreach ($costs->assetRows($campaign) as $row) {
                fputcsv($out, [
                    $row['policy_hex'],
                    $row['asset_hex'],
                    $row['asset_name'],
                    $row['quantity'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * A claim's paid tokens as one cell: `policy.asset=quantity`, semicolon separated.
     *
     * Hex on both sides, because that is what identifies an asset on chain and a ticker is
     * a registry's opinion that can change. An empty list is an empty cell: the claim was
     * paid ADA and nothing else.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int}>  $tokens
     */
    private static function rewardTokenList(array $tokens): string
    {
        return implode('; ', array_map(
            static fn ($token) => ($token['policy'] ?? '').'.'.($token['asset'] ?? '').'='.($token['quantity'] ?? 0),
            $tokens,
        ));
    }

    /**
     * The per-partner conversion report as a sheet.
     *
     * Built from the same call the panel is built from, so the file an organiser mails
     * round cannot say something different from the screen they took it off.
     *
     * Every row the panel shows is written, including the partners who produced nothing.
     * Filtering them out of the export would undo on the way to the spreadsheet exactly
     * what the report exists to show, and the column saying so is there so a reader can
     * sort on it rather than having to notice a zero.
     */
    public function exportPartners(Campaign $campaign, PartnerConversionService $conversion): StreamedResponse
    {
        $this->authorize('view', $campaign);

        $rows = $conversion->for($campaign)['rows'];
        $filename = Str::slug($campaign->name ?: 'campaign').'-partners-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'partner', 'kind', 'partner_status', 'codes_issued', 'codes_claimed',
                'claims', 'claim_rate_pct', 'produced_nothing',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['name'],
                    $row['kind'],
                    self::partnerStatus($row),
                    $row['codes'],
                    $row['codes_claimed'],
                    $row['claims'],
                    // Blank rather than 0 where no codes were issued, matching the panel.
                    // A rate of zero is a result and an empty cell is the absence of one.
                    $row['claim_rate'] === null ? '' : $row['claim_rate'],
                    $row['produced_nothing'] ? 'yes' : 'no',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * What a conversion row is, where that is not simply a partner the campaign still
     * has: codes nobody was assigned, or a partner since removed from the picker.
     *
     * @param  array<string, mixed>  $row
     */
    private static function partnerStatus(array $row): string
    {
        if ($row['is_unassigned']) {
            return 'unassigned';
        }

        return $row['removed'] ? 'removed' : 'live';
    }

    /**
     * Export the addresses that claimed from this campaign, with the partner whose codes
     * they claimed on and their onboarding classification where the analysis has been run.
     *
     * The partner is the reason this file answers a question the page cannot: which of the
     * people handing codes out at an event brought the wallets that turned up. Reading it
     * off a campaign that used partners is the whole point of recording the attribution.
     *
     * Streamed rather than built in memory: a large campaign is tens of thousands of rows,
     * and the export is exactly the kind of thing someone runs on the biggest campaign
     * they have.
     */
    public function exportClaims(Campaign $campaign): StreamedResponse
    {
        $this->authorize('view', $campaign);

        $insights = $campaign->walletInsights()->get()->keyBy('stake_key');
        $filename = Str::slug($campaign->name ?: 'campaign').'-claims-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($campaign, $insights) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                // partner sits with the code it belongs to rather than at the end of the
                // row: it is a property of the code that was handed out, and the columns
                // after status are the onboarding analysis, which is a different subject.
                'address', 'stake_key', 'code', 'partner', 'claimed_at', 'transaction_hash', 'status',
                // What each claim was actually paid, from the claim rather than from the
                // code. A code's reward can be changed while it is in circulation, so
                // reading it back would report today's configuration for a payment made
                // under an earlier one. Blank where nothing was recorded, which is a
                // claim that has not been sent and a claim taken before this was kept.
                'reward_lovelace', 'reward_tokens',
                // activity and delegation are separate columns because they are separate
                // answers: a wallet that registered a stake key and delegated put itself
                // into that transaction as an input and still did nothing with its tokens.
                'wallet_classification', 'prior_tx_count', 'own_transactions',
                'days_to_first_activity', 'delegated_after_claim', 'days_to_first_delegation',
                'delegated', 'pool_id',
            ]);

            $campaign->claims()
                // partner_id is selected because the partner is loaded through it, and the
                // partner is loaded withTrashed because removing one takes it off the
                // picker without taking its name off the codes it handed out. A claim on
                // one of those codes still came through that partner.
                ->with([
                    'code:id,code,partner_id',
                    'code.partner' => fn ($query) => $query->withTrashed()->select(['id', 'name']),
                ])
                ->orderBy('claims.created_at')
                ->chunk(500, function ($claims) use ($out, $insights) {
                    foreach ($claims as $claim) {
                        $insight = $insights->get($claim->stake_key);
                        $paid = $claim->rewardPaid();

                        fputcsv($out, [
                            $claim->address,
                            $claim->stake_key,
                            $claim->code?->code,
                            // Blank where the batch was generated for nobody in
                            // particular, which is a real answer and the only one every
                            // code predating partners can give.
                            $claim->code?->partner?->name,
                            $claim->created_at?->toIso8601String(),
                            $claim->transaction_hash,
                            $claim->status,
                            $paid['lovelace'] ?? null,
                            $paid === null ? null : self::rewardTokenList($paid['tokens']),
                            // Blank rather than a guess when the analysis has not been run,
                            // and blank again where it ran and the chain read failed: an
                            // empty cell is honest, "established" would not be.
                            $insight?->isClassified() ? ($insight->is_new ? 'new' : 'established') : '',
                            $insight?->prior_tx_count,
                            // Blank where no run has read this wallet since delegation was
                            // excluded from activity. An empty cell is unknown; a 0 would
                            // say the wallet was looked at and had done nothing.
                            $insight?->isWindowed() ? $insight->activity_count : '',
                            self::daysOrBlank($insight?->isWindowed() ? $insight->first_activity_seconds : null),
                            $insight?->isWindowed()
                                ? ($insight->first_delegation_seconds !== null ? 'yes' : 'no')
                                : '',
                            self::daysOrBlank($insight?->isWindowed() ? $insight->first_delegation_seconds : null),
                            $insight?->delegated === null ? '' : ($insight->delegated ? 'yes' : 'no'),
                            $insight?->pool_id,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * A whole number of days from a recorded offset, or a blank cell.
     *
     * Blank means the thing never happened or was never observed. Zero means it happened
     * on the day of the claim, which is a different statement, so the two never share a
     * representation.
     */
    private static function daysOrBlank(?int $seconds): string
    {
        return $seconds === null
            ? ''
            : (string) (int) floor($seconds / CampaignWalletInsight::DAY);
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
        $campaign->loadMissing(['codes', 'claims']);

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
            // Theirs to set. An operator who has funded a bucket and wants to leave the
            // campaign alone is not helped by being chased about it, and somebody running
            // an event wants to hear long before the last claim fails.
            'alerts_enabled' => 'nullable|boolean',
            'alert_threshold_claims' => 'nullable|integer|min:0|max:100000',
            // Editable whatever the campaign's claim history, unlike the network and the
            // one-per-wallet switch below: lowering it, even under the count of codes the
            // campaign already holds, refuses only what would be created next, not
            // anything already made.
            'max_codes' => 'nullable|integer|min:1|max:1000000',
        ];

        $hasClaims = $campaign->claims()->exists();

        if (! $hasClaims) {
            // The campaign's current network stays selectable even when the deployment
            // no longer allows it. The allowlist governs what this deployment accepts
            // for new work; without this, narrowing it would block every other edit to
            // a campaign that already exists on the excluded network.
            $networks = array_values(array_unique(array_filter(array_merge(
                config('cardano.allowed_networks'),
                [$campaign->network]
            ))));

            $rules['network'] = ['required', Rule::in($networks)];
            $rules['one_per_wallet'] = 'nullable|boolean';
        }

        $validated = $request->validate($rules);

        // Assigned rather than mass-assigned, because these sit outside $fillable with
        // the money columns for the same reason: nothing a request carries should be able
        // to reach them except through a rule that named them.
        if (array_key_exists('alerts_enabled', $validated)) {
            $campaign->alerts_enabled = (bool) $validated['alerts_enabled'];
        }

        if (array_key_exists('alert_threshold_claims', $validated)) {
            $campaign->alert_threshold_claims = $validated['alert_threshold_claims'];
        }

        if (array_key_exists('max_codes', $validated)) {
            $campaign->max_codes = $validated['max_codes'];
        }

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
     *
     * The run reports itself through a campaign task row, so the page shows it while it
     * works and reloads the claims once it has finished. Claiming the row is what decides
     * whether a run happens: a second press while one is in flight is refused here, where
     * there is somebody to tell, rather than queued behind it.
     */
    public function checkClaims(Campaign $campaign): RedirectResponse
    {
        $this->authorize('update', $campaign);

        Log::info('CheckClaims button: triggered', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
        ]);

        // The same definition the check itself uses, so the number in the message is the
        // number of claims the job will actually ask about.
        $pendingCount = $campaign->claims()->awaitingConfirmation()->count();

        Log::info('CheckClaims button: pending claims counted', [
            'campaign_id' => $campaign->id,
            'pending_count' => $pendingCount,
        ]);

        if ($pendingCount > 0) {
            $task = CampaignTask::claim(
                $campaign,
                CheckClaims::TASK_TYPE,
                CheckClaims::dedupeKey(),
                ['pending_claims' => $pendingCount],
                Auth::id(),
            );

            if (! $task) {
                Log::info('CheckClaims button: a check is already running', [
                    'campaign_id' => $campaign->id,
                ]);

                return back()->with('message',
                    'A claim check is already running for this campaign. Its progress is shown on this page.');
            }

            try {
                CheckClaims::dispatch($campaign->id, $task->id);
                Log::info('CheckClaims button: job dispatched', [
                    'campaign_id' => $campaign->id,
                    'pending_count' => $pendingCount,
                    'task_id' => $task->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('CheckClaims button: dispatch failed', [
                    'campaign_id' => $campaign->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);

                // Nothing is running for this row, so it is closed rather than left saying
                // "queued" and holding off the next press until the stale sweep. Only while
                // it is still unfinished: a run that did start and failed has already
                // written its own reason.
                CampaignTask::whereKey($task->id)
                    ->whereIn('status', CampaignTask::ACTIVE_STATUSES)
                    ->update([
                        'status' => CampaignTask::STATUS_FAILED,
                        'error' => 'The claim check could not be queued.',
                        'completed_at' => now(),
                    ]);

                return back()->with('message', 'Failed to trigger claim check. Please try again.');
            }

            return back()->with('message', "Checking {$pendingCount} pending claim(s). The claims on this page update when the check finishes.");
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
     * Download a campaign's sticker archive.
     *
     * Serves the archive when it has been built. When it has not, the render is queued and
     * the operator is sent back to the campaign page to wait. This request used to render
     * inline, which held the browser open for the length of the render and, on a runtime
     * with an execution limit, was killed partway with nothing stored and nothing said.
     *
     * Format, size, DPI, ECC and the optional header (expiration) and footer (code) captions
     * are chosen in the export dialog. See \App\Services\QrStickerService.
     */
    public function downloadQrCodes(Request $request, Campaign $campaign, QrExportService $exports)
    {
        $this->authorize('view', $campaign);

        $opts = $this->qrExportOptions($request, $campaign);

        if ($exports->codeCount($campaign, $opts) === 0) {
            return back()->with('message', $this->nothingToExport($opts));
        }

        $key = $exports->cacheKey($campaign, $opts);
        $path = $exports->path($campaign, $key);

        if ($exports->exists($path)) {
            return $exports->respond($path, $this->qrExportFilename($campaign, $opts));
        }

        // The archive is not on the disk. A row still calling itself ready is advertising a
        // download that would 404, so it is corrected here rather than left for the next
        // sweep to notice.
        $campaign->qrExports()->ready()->where('cache_key', $key)->first()?->markExpired();

        return $this->queueQrExport($campaign, $opts, $key);
    }

    /**
     * Ask for a campaign's sticker archive to be built.
     *
     * Authorized as a read of the campaign, the same as the download it leads to: it asks for
     * a different rendering of codes the caller can already see, and changes nothing about
     * the campaign.
     */
    public function requestQrExport(Request $request, Campaign $campaign, QrExportService $exports): RedirectResponse
    {
        $this->authorize('view', $campaign);

        $opts = $this->qrExportOptions($request, $campaign);

        if ($exports->codeCount($campaign, $opts) === 0) {
            $refusal = $this->nothingToExport($opts);

            return to_route('campaigns.show', $campaign)
                ->with('message', $refusal)
                ->with('qr_export', ['status' => 'refused', 'message' => $refusal]);
        }

        $key = $exports->cacheKey($campaign, $opts);

        if ($exports->exists($exports->path($campaign, $key))) {
            // Already built. Rendering it again would spend the compute twice for an archive
            // that is sitting there ready to download. The dialog is told 'ready' rather than
            // 'queued' so it offers the download straight away: showing a progress bar for
            // work nobody is doing, and taking it away again a moment later, reads as a
            // render that skipped most of the job.
            return to_route('campaigns.show', $campaign)
                ->with('message', 'That export is ready to download.')
                ->with('qr_export', [
                    'status' => 'ready',
                    'cache_key' => $key,
                    'message' => 'That export is ready to download.',
                ] + $this->qrExportDownload($campaign, $key, $opts));
        }

        return $this->queueQrExport($campaign, $opts, $key);
    }

    /**
     * Serve one stored sticker archive.
     *
     * Addressed by the export rather than by settings, because that is what the campaign
     * page and the export dialog are holding once a render has finished: the row knows the
     * disk its bytes went to and the exact object they are, where re-deriving a key from
     * settings answers "an archive of the codes as they are now", which after one code was
     * added is a different archive that has not been built.
     */
    public function downloadQrExport(Campaign $campaign, QrExport $qrExport, QrExportService $exports)
    {
        // The same read the campaign page is, and the same one the settings-addressed
        // download performs. Route model binding will happily hand over any export whose id
        // is guessed, so the campaign it belongs to is checked rather than assumed.
        $this->authorize('view', $campaign);

        if ($qrExport->campaign_id !== $campaign->id) {
            abort(404);
        }

        if (! $qrExport->isReady() || $qrExport->path === null || ! $exports->exists($qrExport->path, $qrExport->disk)) {
            // The row advertises a download that would 404. Correcting it here rather than
            // leaving it for the next sweep keeps the page from offering it again.
            if ($qrExport->isReady()) {
                $qrExport->markExpired();
            }

            return back()->with('message', 'That export is no longer stored. Ask for it again and it will be rebuilt.');
        }

        return $exports->respond(
            $qrExport->path,
            $this->qrExportFilename($campaign, is_array($qrExport->settings) ? $qrExport->settings : []),
            $qrExport->disk,
        );
    }

    /**
     * Where an archive that exists right now can be fetched from.
     *
     * Prefers the row, which names one archive for as long as it is stored. Falls back to
     * the settings-addressed download for the case where the bytes are on the disk and the
     * row describing them is not: a pruned row, or an archive built before the row existed.
     * That URL is only correct while the codes are unchanged, which is exactly the moment it
     * is handed out.
     *
     * @return array{export_id: ?string, download_url: string}
     */
    private function qrExportDownload(Campaign $campaign, string $key, array $opts): array
    {
        $export = $campaign->qrExports()->ready()->where('cache_key', $key)->first();

        if ($export) {
            return [
                'export_id' => $export->id,
                'download_url' => route('campaigns.qr-exports.download', [$campaign->id, $export->id]),
            ];
        }

        return [
            'export_id' => null,
            'download_url' => route('campaigns.download-qr', [$campaign->id] + $opts),
        ];
    }

    /**
     * The archives this campaign has ready, for the panel that offers them.
     *
     * Only what is downloadable. An expired row is history rather than an offer, and putting
     * it on the page would be a button that explains itself only after being pressed.
     */
    private function qrExportPanel(Campaign $campaign): array
    {
        // Resolved once for the whole list rather than per row, and including removed
        // partners: an archive built for a partner that has since been taken off the picker
        // is still on the disk and still theirs.
        $partners = $campaign->partners()->withTrashed()->pluck('name', 'id');

        return $campaign->qrExports()
            ->ready()
            ->where(static function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(static fn (QrExport $export) => [
                'id' => $export->id,
                'bytes' => (int) $export->bytes,
                'codes_total' => (int) $export->codes_total,
                'settings' => $export->settings,
                // Who the archive is for, where it is not the whole campaign. Two exports of
                // one campaign now differ in more than their settings, and a list that
                // cannot tell one partner's stack from another's is a list of rows that all
                // read the same and download different files.
                'scope_label' => match ($scope = $export->settings['scope'] ?? null) {
                    null => null,
                    QrExportService::SCOPE_UNASSIGNED => QrExportFolders::UNASSIGNED_LABEL,
                    default => $partners[$scope] ?? 'A removed partner',
                },
                // Whether it is one folder per partner or every sticker at the top level.
                'layout' => $export->manifest['layout'] ?? QrExportService::GROUP_FLAT,
                'expires_at' => $export->expires_at?->toIso8601String(),
                'created_at' => $export->created_at?->toIso8601String(),
                'download_url' => route('campaigns.qr-exports.download', [$campaign->id, $export->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * Claim the run and queue it, or say why it was not queued.
     *
     * The claim is what decides whether anything is dispatched. Two requests arriving
     * together find one task row and only one of them takes it, so an operator who
     * double-clicks, or two people working on the same campaign, pay for one render.
     */
    private function queueQrExport(Campaign $campaign, array $opts, string $key): RedirectResponse
    {
        // An archive already generated stays downloadable for as long as it is stored, but
        // no compute is spent building new ones for codes that can no longer be claimed (the
        // claim endpoint rejects them with ERROR_EXPIRED).
        if ($campaign->hasEnded()) {
            $refusal = 'This campaign has ended, so new QR exports can\'t be generated. '
                .'Any set you already downloaded remains available for your records.';

            return back()
                ->with('message', $refusal)
                ->with('qr_export', ['status' => 'refused', 'message' => $refusal]);
        }

        $task = CampaignTask::claim(
            $campaign,
            GenerateQrExport::TASK_TYPE,
            GenerateQrExport::dedupeKey($key),
            ['options' => $opts],
            Auth::id(),
        );

        if (! $task) {
            // Somebody else's run holds the row. The export the caller asked for is on its
            // way, and the run they should be watching is that one, so they are pointed at it
            // rather than told nothing happened.
            $running = $campaign->tasks()
                ->active()
                ->where('type', GenerateQrExport::TASK_TYPE)
                ->where('dedupe_key', GenerateQrExport::dedupeKey($key))
                ->first();

            return to_route('campaigns.show', $campaign)
                ->with('message', 'That export is already being prepared. Progress is shown on this page.')
                ->with('qr_export', [
                    'status' => 'queued',
                    'cache_key' => $key,
                    'task_id' => $running?->id,
                    'message' => 'That export is already being prepared.',
                ]);
        }

        GenerateQrExport::dispatch($campaign->id, $task->id);

        Log::info('QR export queued.', [
            'campaign_id' => $campaign->id,
            'user_id' => Auth::id(),
            'task_id' => $task->id,
            'format' => $opts['format'],
        ]);

        return to_route('campaigns.show', $campaign)
            ->with('message', 'Preparing your QR export. Progress is shown on this page, and the download is offered when it is ready.')
            ->with('qr_export', [
                'status' => 'queued',
                'cache_key' => $key,
                'task_id' => $task->id,
                'message' => 'Preparing your QR export.',
            ]);
    }

    /**
     * Who a campaign's codes can be exported for, with the count behind each choice.
     *
     * Live partners only, plus the codes nobody was given. A removed partner is off the
     * picker for the same reason it is off every other one: it is not somebody an operator
     * can hand a stack to any more. Its codes are still in the campaign's own export and
     * still filed under its name there.
     *
     * The counts are the point of showing this at all. "Vendor A (100)" is what tells an
     * operator the stack they are about to print is the stack they meant, before they spend
     * the render rather than after.
     *
     * @return list<array{value: string, label: string, codes: int}>
     */
    private function exportScopes(Campaign $campaign): array
    {
        // One grouped query rather than a count per partner. Through the query builder, so
        // the counts the Code model attaches to every query are not run alongside it.
        $counts = DB::table('codes')
            ->where('campaign_id', $campaign->id)
            ->selectRaw('partner_id, count(*) as codes')
            ->groupBy('partner_id')
            ->get();

        $byPartner = [];
        $unassigned = 0;
        $total = 0;

        foreach ($counts as $row) {
            $total += (int) $row->codes;

            if ($row->partner_id === null) {
                $unassigned = (int) $row->codes;

                continue;
            }

            $byPartner[(string) $row->partner_id] = (int) $row->codes;
        }

        $scopes = [[
            'value' => 'all',
            'label' => 'All codes',
            'codes' => $total,
        ]];

        foreach ($campaign->partners()->orderBy('name')->get(['id', 'name']) as $partner) {
            $scopes[] = [
                'value' => (string) $partner->id,
                'label' => (string) $partner->name,
                'codes' => $byPartner[(string) $partner->id] ?? 0,
            ];
        }

        if ($unassigned > 0) {
            $scopes[] = [
                'value' => QrExportService::SCOPE_UNASSIGNED,
                'label' => QrExportFolders::UNASSIGNED_LABEL,
                'codes' => $unassigned,
            ];
        }

        return $scopes;
    }

    /**
     * What the downloaded file is called.
     *
     * Named for what is in it, because an operator exporting three partners one after another
     * ends up with three files in one downloads folder, and three identical names with
     * numbers after them is not a stack anybody can tell apart. The partner's name goes
     * through the same slug as its folder does, so the file and the folder inside it match.
     */
    private function qrExportFilename(Campaign $campaign, array $opts): string
    {
        $scope = $opts['scope'] ?? null;
        $name = 'qrcodes-'.Str::slug($campaign->name);

        if ($scope === QrExportService::SCOPE_UNASSIGNED) {
            return $name.'-'.QrExportFolders::UNASSIGNED.'.zip';
        }

        if (is_string($scope) && $scope !== '') {
            $folder = QrExportFolders::forCampaign($campaign)->folderFor($scope);

            return $name.'-'.$folder.'.zip';
        }

        return $name.'.zip';
    }

    /** Why an export was refused, in the words of what was asked for. */
    private function nothingToExport(array $opts): string
    {
        return match ($opts['scope'] ?? null) {
            null => 'There are no codes to export yet.',
            QrExportService::SCOPE_UNASSIGNED => 'Every code on this campaign has been given to a partner, so there are no unassigned codes to export.',
            default => 'That partner has no codes to export yet.',
        };
    }

    /**
     * The export settings a request asks for, refusing the combinations that cannot be made.
     *
     * Shared by the request and the download so the two cannot drift: a setting one accepts
     * and the other rejects would be an export that can be built and never fetched.
     */
    private function qrExportOptions(Request $request, Campaign $campaign): array
    {
        $validated = $request->validate([
            'format' => ['nullable', Rule::in(QrStickerService::FORMATS)],
            'size' => ['nullable', 'numeric', 'between:0.5,4'],
            'dpi' => ['nullable', 'integer', 'between:72,1200'],
            'ecc' => ['nullable', Rule::in(QrStickerService::ECC_LEVELS)],
            'header' => ['nullable', 'boolean'],
            'footer' => ['nullable', 'boolean'],
            'group' => ['nullable', Rule::in(QrExportService::GROUPINGS)],
            'scope' => ['nullable', 'string'],
        ]);

        $format = $validated['format'] ?? 'pdf';
        $size = (float) ($validated['size'] ?? 1.0);
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

        return [
            'format' => $format,
            'size' => $size,
            'dpi' => (int) ($validated['dpi'] ?? 203),
            'ecc' => $validated['ecc'] ?? 'L',
            'header' => $wantHeader,
            'footer' => $wantFooter,
            'group' => $validated['group'] ?? QrExportService::GROUP_FLAT,
            'scope' => $this->qrExportScope($request, $campaign),
        ];
    }

    /**
     * Which codes the request asked for: all of them, one partner's, or the unassigned ones.
     *
     * A partner id is checked against this campaign rather than merely for existing. Route
     * binding gives the caller the campaign; the id in the body is theirs to choose, and an
     * id from somebody else's campaign has to fail validation rather than quietly select no
     * codes, which would answer "that partner has no codes" about a partner that is not
     * theirs to ask about.
     */
    private function qrExportScope(Request $request, Campaign $campaign): ?string
    {
        $scope = $request->input('scope');

        if (! is_string($scope) || $scope === '' || $scope === 'all') {
            return null;
        }

        if ($scope === QrExportService::SCOPE_UNASSIGNED) {
            return $scope;
        }

        $request->validate(['scope' => [Partner::assignableRule($campaign)]]);

        return $scope;
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
