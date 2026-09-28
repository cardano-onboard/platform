<?php

namespace App\Models;

use App\Support\ClaimClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-campaign tally of the client software accepted claims arrived from.
 *
 * A counter, not a log. There is one row per campaign and client shorthand, and nothing on
 * it points at a claim, so no claimant's wallet can be recovered from it.
 *
 * The primary key is composite, so this model reads and aggregates only. Writes go through
 * tally() below, which uses the query builder.
 *
 * No timestamps and no surrogate key, deliberately. See the migration for why.
 */
class CampaignClaimClient extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'campaign_claim_clients';

    protected $guarded = [];

    protected $casts = [
        'claims' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Claims by client shorthand across every campaign, highest first.
     *
     * This is the figure the tally exists for: which wallets are worth supporting, measured
     * across the whole platform rather than inside one campaign.
     */
    public static function platformTotals(): Collection
    {
        return static::query()
            ->selectRaw('client, SUM(claims) as total')
            ->groupBy('client')
            ->orderByDesc('total')
            ->get()
            ->mapWithKeys(static fn ($row) => [$row->client => (int) $row->total]);
    }

    /**
     * One campaign's breakdown, or null when the campaign is too small to break down.
     *
     * A campaign with a single claim names that claimant's wallet whatever the schema looks
     * like, because whoever reads this can also read who claimed the campaign. The floor is
     * the control for that case, and it is worth being clear about what it does not do: it
     * cannot make a breakdown non-identifying where every claim came from the same client,
     * and no schema can. A campaign below the floor still counts toward the platform
     * totals above.
     */
    public static function campaignBreakdown(Campaign|string $campaign): ?Collection
    {
        $campaignId = $campaign instanceof Campaign ? $campaign->id : $campaign;

        $rows = static::query()
            ->where('campaign_id', $campaignId)
            ->orderByDesc('claims')
            ->pluck('claims', 'client')
            ->map(static fn ($claims) => (int) $claims);

        return $rows->sum() >= (int) config('analytics.client_breakdown_floor', 10)
            ? $rows
            : null;
    }

    /**
     * One campaign's breakdown as display shares, for that campaign's own operator.
     *
     * Two conditions, guarding two different things.
     *
     * The floor guards a small campaign. Below it, a breakdown showing a single claim
     * against a single client names the wallet that claimant used, to a reader who can
     * also see who claimed.
     *
     * The end date guards a running one, which the floor cannot. Above the floor every
     * accepted claim moves exactly one client's count by one, and this page lists
     * claimants, so an operator refreshing it attributes each new claim as it lands.
     * Waiting for the redemption window to close removes that sequence rather than
     * slowing it down. Nothing subtler is available: the tally carries no timestamps by
     * design, so it cannot be lagged or filtered to exclude recent claims.
     *
     * $live lifts the end date and never the floor. It exists for an administrator
     * looking at wallet behaviour while integrating or debugging, and a campaign
     * operator is never given it.
     *
     * Shares rather than counts because the operator's question is proportion. That is a
     * presentation choice and not a control: the campaign's claim total is on the same
     * page, so a count can be recovered from a share by anyone who wants one.
     *
     * @return array{state: string, floor: int, clients: ?array}
     */
    public static function campaignShares(Campaign $campaign, bool $live = false): array
    {
        $floor = (int) config('analytics.client_breakdown_floor', 10);
        $rows = static::campaignBreakdown($campaign);

        if ($rows === null) {
            return ['state' => 'too_few', 'floor' => $floor, 'clients' => null];
        }

        $ended = $campaign->hasEnded();

        if (! $ended && ! $live) {
            return ['state' => 'running', 'floor' => $floor, 'clients' => null];
        }

        $total = $rows->sum();

        return [
            // 'early' is 'ready' seen by an administrator before the window closed. The
            // page says so, because a figure an operator could not have seen yet should
            // not look like one they could.
            'state' => $ended ? 'ready' : 'early',
            'floor' => $floor,
            'clients' => $rows->map(static fn (int $claims, string $client) => [
                'client' => $client,
                'wallet' => ClaimClient::walletFor($client),
                'share' => round($claims / $total * 100, 1),
            ])->values()->all(),
        ];
    }

    /**
     * Add one to a campaign's tally for a client, creating the row on first sight.
     *
     * Increment first and insert only on a miss, so two concurrent claims of the same
     * campaign and client cannot lose a count to a read-then-write race. A unique violation
     * on the insert means another request created the row first, and the retry picks it up.
     */
    public static function tally(string $campaignId, string $client): void
    {
        if (self::incrementRow($campaignId, $client) > 0) {
            return;
        }

        try {
            DB::table('campaign_claim_clients')->insert([
                'campaign_id' => $campaignId,
                'client' => $client,
                'claims' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            self::incrementRow($campaignId, $client);
        }
    }

    private static function incrementRow(string $campaignId, string $client): int
    {
        return DB::table('campaign_claim_clients')
            ->where('campaign_id', $campaignId)
            ->where('client', $client)
            ->increment('claims');
    }
}
