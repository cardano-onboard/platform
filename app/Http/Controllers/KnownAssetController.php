<?php

namespace App\Http\Controllers;

use App\Models\KnownAsset;
use App\Models\Reward;
use App\Services\AssetDisplay;
use App\Services\KoiosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KnownAssetController extends Controller
{
    /** Assets one lookupMany request may ask about. */
    public const BATCH_LIMIT = 50;

    public function __construct(private readonly KoiosService $koios) {}

    /**
     * Search the shared known-asset registry by ticker/name, scoped to a network.
     * Powers the reward-token autocomplete so users pick "HOSKY" without hex.
     *
     * Results are ranked by real usage — how many times each token has been added as a
     * reward across all codes — so popular tokens (HOSKY, USDM, …) surface above the
     * thousands of obscure liquidity-pool tokens in the registry. Ties break on ticker.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:64',
            'network' => ['nullable', Rule::in(['mainnet', 'preprod', 'preview'])],
        ]);

        $network = $validated['network'] ?? 'mainnet';

        // Rank by how often each token has been used as a reward, so popular tokens
        // (HOSKY, USDM, …) surface above obscure LP tokens. This is used only for the
        // ORDER — the count is never selected or returned, so SaaS users can't see how
        // others have used tokens. Ties break on ticker.
        $usageOrder = Reward::query()
            ->selectRaw('count(*)')
            ->whereColumn('rewards.policy_hex', 'known_assets.policy_id')
            ->whereColumn('rewards.asset_hex', 'known_assets.asset_name');

        $assets = KnownAsset::query()
            ->where('network', $network)
            ->when(! empty($validated['q']), function ($query) use ($validated) {
                $term = '%'.$validated['q'].'%';
                $query->where(function ($q) use ($term) {
                    $q->where('ticker', 'like', $term)
                        ->orWhere('name', 'like', $term);
                });
            })
            ->orderByDesc($usageOrder)
            ->orderBy('ticker')
            ->limit(25)
            ->get();

        return response()->json($assets);
    }

    /**
     * Display metadata for up to BATCH_LIMIT assets in one request, keyed by subject
     * (policy id + asset name hex), null for an asset Koios does not know.
     *
     * The campaign page arrives with whatever is already cached (AssetDisplay::cached) and
     * asks here only for the rest. One request per asset, each waiting on its own Koios
     * call, held every PHP worker at once on a campaign with a distinct NFT per code and
     * timed out the host for everybody.
     */
    public function lookupMany(Request $request, AssetDisplay $assets): JsonResponse
    {
        $validated = $request->validate([
            'network' => ['nullable', Rule::in(['mainnet', 'preprod', 'preview'])],
            'assets' => ['required', 'array', 'min:1', 'max:'.self::BATCH_LIMIT],
            // Each pair is checked by AssetDisplay::wellFormed, which leaves a malformed one
            // out of the Koios call and out of the answer rather than refusing the batch:
            // one stored reward with an odd-length name would otherwise fail the other 49
            // on every load.
            'assets.*.policy' => ['required', 'string', 'max:128'],
            'assets.*.asset_name' => ['nullable', 'string', 'max:128'],
        ]);

        $pairs = array_map(
            fn (array $asset) => [$asset['policy'], $asset['asset_name'] ?? ''],
            $validated['assets'],
        );

        return response()->json((object) $assets->resolve($pairs, $validated['network'] ?? 'mainnet'));
    }

    /**
     * Resolve one asset's metadata by policy id + asset name (hex), for the reward form.
     *
     * Registry-first. Anything else is read from Koios and cached; only an off-chain
     * registry token is written to known_assets, so CIP-0068 and CIP-0025 metadata never
     * become permanent and nothing reaches the shared table except from the chain's
     * registry.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'policy' => ['required', 'string', 'size:56', 'regex:/^[0-9a-fA-F]+$/'],
            'asset_name' => ['nullable', 'string', 'max:64', 'regex:/^(?:[0-9a-fA-F]{2})*$/'],
            'network' => ['nullable', Rule::in(['mainnet', 'preprod', 'preview'])],
        ]);

        $policy = strtolower($validated['policy']);
        $assetName = strtolower($validated['asset_name'] ?? '');
        $network = $validated['network'] ?? 'mainnet';

        $known = KnownAsset::where('policy_id', $policy)
            ->where('asset_name', $assetName)
            ->where('network', $network)
            ->first();

        if ($known && AssetDisplay::logoChecked($known)) {
            return response()->json($known);
        }

        $info = $this->koios->assetInfo($policy, $assetName, $network);

        if (! $info) {
            return $known
                ? response()->json($known)
                : response()->json(['message' => 'Asset not found on this network.'], 404);
        }

        return response()->json(AssetDisplay::remember($info, $network) ?? [
            'policy_id' => $info['policy_id'] ?? $policy,
            'asset_name' => $info['asset_name'] ?? $assetName,
            'fingerprint' => $info['fingerprint'] ?? null,
            'description' => $info['description'] ?? null,
        ] + AssetDisplay::display($info));
    }
}
