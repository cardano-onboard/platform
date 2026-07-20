<?php

namespace App\Http\Controllers;

use App\Models\KnownAsset;
use App\Models\Reward;
use App\Services\KoiosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KnownAssetController extends Controller
{
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
     * Resolve asset metadata from Koios by policy id + asset name (hex).
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'policy' => ['required', 'string', 'size:56', 'regex:/^[0-9a-fA-F]+$/'],
            'asset_name' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]*$/'],
            'network' => ['nullable', Rule::in(['mainnet', 'preprod', 'preview'])],
        ]);

        $policy = strtolower($validated['policy']);
        $assetName = strtolower($validated['asset_name'] ?? '');
        $network = $validated['network'] ?? 'mainnet';

        // Registry-first: serve from our synced table when we know the token.
        $known = KnownAsset::where('policy_id', $policy)
            ->where('asset_name', $assetName)
            ->where('network', $network)
            ->first();

        if ($known) {
            return response()->json($known);
        }

        // Fallback: query Koios directly for a token we haven't cached yet, then persist
        // it so subsequent lookups are served locally.
        $info = $this->koios->assetInfo($policy, $assetName, $network);

        if (! $info) {
            return response()->json(['message' => 'Asset not found in the registry for this network.'], 404);
        }

        $known = KnownAsset::updateOrCreate(
            [
                'policy_id' => $info['policy_id'] ?? $policy,
                'asset_name' => $info['asset_name'] ?? $assetName,
                'network' => $network,
            ],
            [
                'ticker' => $info['ticker'] ?? null,
                'name' => $info['name'] ?? null,
                'fingerprint' => $info['fingerprint'] ?? null,
                'decimals' => $info['decimals'] ?? 0,
                'logo' => $info['logo'] ?? null,
                'description' => $info['description'] ?? null,
            ]
        );

        return response()->json($known);
    }

    /**
     * Persist a known asset to the shared registry (deduped on policy + asset + network).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'policy_id' => ['required', 'string', 'size:56', 'regex:/^[0-9a-fA-F]+$/'],
            'asset_name' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]*$/'],
            'ticker' => 'nullable|string|max:32',
            'name' => 'nullable|string|max:255',
            'fingerprint' => 'nullable|string|max:64',
            'decimals' => 'nullable|integer|min:0|max:32',
            'logo' => 'nullable|string',
            'description' => 'nullable|string|max:1000',
            'network' => ['nullable', Rule::in(['mainnet', 'preprod', 'preview'])],
        ]);

        $asset = KnownAsset::updateOrCreate(
            [
                'policy_id' => strtolower($validated['policy_id']),
                'asset_name' => strtolower($validated['asset_name'] ?? ''),
                'network' => $validated['network'] ?? 'mainnet',
            ],
            [
                'ticker' => $validated['ticker'] ?? null,
                'name' => $validated['name'] ?? null,
                'fingerprint' => $validated['fingerprint'] ?? null,
                'decimals' => $validated['decimals'] ?? 0,
                'logo' => $validated['logo'] ?? null,
                'description' => $validated['description'] ?? null,
            ]
        );

        return response()->json($asset, $asset->wasRecentlyCreated ? 201 : 200);
    }
}
