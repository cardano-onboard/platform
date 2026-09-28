<?php

namespace App\Services;

use App\Cardano\AssetName;
use App\Models\Campaign;
use App\Support\Pricing;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What a campaign cost the operator, and what it handed out.
 *
 * Read from what each claim recorded rather than from a rate multiplied by a count. A
 * claim taken under a previous rate was charged that rate, and a figure reconstructed
 * from today's would be wrong for it while looking authoritative. Claims that recorded no
 * charge are counted apart rather than folded in as zero, because a cost nobody wrote down
 * is not a cost of nothing.
 *
 * Meant to be readable by somebody doing their accounts, so the kinds of money are kept
 * apart. The platform fee is what they paid us and exists only where this deployment
 * charges at all. The network fee is what the chain took and is real for everybody,
 * including a self-hoster running campaigns for their own project. The rewards are what
 * they gave away.
 */
class CampaignCosts
{
    /**
     * Above this many distinct assets under one policy, the detail is worth an export
     * rather than a table. An NFT drop mints a serial per recipient, so a thousand-claim
     * campaign is a thousand assets of quantity one, and listing them on the page buries
     * everything else on it.
     */
    private const COLLAPSE_ABOVE = 1;

    public function for(Campaign $campaign): array
    {
        // Built from the join rather than from $campaign->claims(), which is a
        // has-many-through and carries codes.campaign_id into the select as its through
        // key. Adding aggregates beside it leaves a non-aggregated column in an
        // aggregated query, which SQLite tolerates and MySQL rejects under
        // ONLY_FULL_GROUP_BY. Nothing here needs the relation; it needs the rows.
        $money = DB::table('claims')
            ->join('codes', 'codes.id', '=', 'claims.code_id')
            ->where('codes.campaign_id', $campaign->id)
            ->selectRaw('COUNT(*) as claims')
            ->selectRaw('SUM(revenue_lovelace) as platform_fee')
            ->selectRaw('SUM(network_fee_lovelace) as network_fee')
            ->selectRaw('SUM(CASE WHEN network_fee_lovelace IS NULL THEN 1 ELSE 0 END) as unrecorded')
            ->first();

        $policies = $this->rewards($campaign)
            ->groupBy('rewards.policy_hex')
            ->selectRaw('rewards.policy_hex')
            ->selectRaw('COUNT(DISTINCT rewards.asset_hex) as assets')
            ->selectRaw('SUM(rewards.quantity) as quantity')
            ->orderByDesc('quantity')
            ->get()
            ->map(function ($row) use ($campaign) {
                // Named only where one policy carries one asset. A policy minting a
                // serial per recipient has no single name to show, and picking one of
                // them to stand for the rest would be a lie about what was given away.
                // The hex goes with the name so the page can look up the token's ticker,
                // decimals and logo, without which a fungible token's quantity is shown
                // in base units.
                $assetHex = (int) $row->assets <= self::COLLAPSE_ABOVE
                    ? $this->soleAssetHex($campaign, $row->policy_hex)
                    : null;

                return [
                    'policy_hex' => $row->policy_hex,
                    'assets' => (int) $row->assets,
                    'quantity' => (string) $row->quantity,
                    'asset_hex' => $assetHex,
                    'asset_name' => $assetHex === null ? null : self::readableAssetName($assetHex),
                ];
            })
            ->all();

        return [
            'claims' => (int) ($money->claims ?? 0),
            'claims_unrecorded' => (int) ($money->unrecorded ?? 0),
            // Null rather than zero where this deployment does not charge. A self-hoster
            // pays us nothing, and a line reading zero invites the question of what it
            // would otherwise have been.
            'platform_fee_lovelace' => Pricing::isConfigured() ? (int) ($money->platform_fee ?? 0) : null,
            'network_fee_lovelace' => (int) ($money->network_fee ?? 0),
            'reward_lovelace' => (int) DB::table('claims')
                ->join('codes', 'codes.id', '=', 'claims.code_id')
                ->where('codes.campaign_id', $campaign->id)
                ->sum('codes.lovelace'),
            'policies' => $policies,
            'distinct_assets' => (int) $this->rewards($campaign)
                ->distinct()
                ->count('rewards.asset_hex'),
        ];
    }

    /**
     * Every asset handed out, one row each, for an export.
     *
     * Yielded rather than returned, because the case this exists for is the one where
     * there are too many rows to put on a page, and holding them all in memory to write
     * them out one at a time helps nobody.
     */
    public function assetRows(Campaign $campaign): \Generator
    {
        $rows = $this->rewards($campaign)
            ->groupBy('rewards.policy_hex', 'rewards.asset_hex')
            ->selectRaw('rewards.policy_hex, rewards.asset_hex, SUM(rewards.quantity) as quantity')
            ->orderBy('rewards.policy_hex')
            ->orderBy('rewards.asset_hex')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                'policy_hex' => $row->policy_hex,
                'asset_hex' => $row->asset_hex,
                'asset_name' => self::readableAssetName($row->asset_hex),
                'quantity' => (string) $row->quantity,
            ];
        }
    }

    /** Rewards actually handed out, which is once per claim rather than once per code. */
    private function rewards(Campaign $campaign): QueryBuilder
    {
        return DB::table('claims')
            ->join('codes', 'codes.id', '=', 'claims.code_id')
            ->join('rewards', 'rewards.code_id', '=', 'codes.id')
            ->where('codes.campaign_id', $campaign->id);
    }

    private function soleAssetHex(Campaign $campaign, string $policy): ?string
    {
        return $this->rewards($campaign)
            ->where('rewards.policy_hex', $policy)
            ->value('rewards.asset_hex');
    }

    /**
     * An asset name is hex on chain and readable to a person about half the time. Where it
     * decodes to something printable it is worth showing; where it does not, the hex is
     * the only honest answer.
     *
     * A name governed by CIP-0068 begins with a four-byte label rather than with the name
     * itself, so what is readable is read from behind that label and the label is shown in
     * front of it. Judging the label as though it were part of the name fails on its first
     * byte and hides text that is sitting right behind it.
     */
    private static function readableAssetName(?string $hex): ?string
    {
        return AssetName::fromHex($hex)->display();
    }
}
