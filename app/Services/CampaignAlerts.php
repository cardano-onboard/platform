<?php

namespace App\Services;

use App\Models\Campaign;
use App\Support\Pricing;

/**
 * Whether a campaign should be telling its operator that it is running short.
 *
 * Short means the same thing on both billing paths and is asked in the same unit: how
 * many more people can claim before this stops working. On a path that pays from the
 * campaign's own bucket that is a question about ADA; on one that spends credit it is a
 * question about the balance. An operator does not care which, so neither does this.
 */
class CampaignAlerts
{
    /** Claims still servable when nothing has told us otherwise. */
    private const DEFAULT_THRESHOLD = 10;

    public function __construct(private CreditLedger $ledger) {}

    /**
     * @return array{
     *     enabled: bool, threshold: int, claims_remaining: ?int,
     *     held: int, alerting: bool, reason: ?string
     * }
     */
    /**
     * @param  array  $bucket  The wallet's live UTxOs, as the campaign page already
     *                         fetched them. Passed in rather than fetched again, because
     *                         reading a balance is a call over the network and asking for
     *                         it twice on one page load is a cost with no answer attached.
     */
    public function for(Campaign $campaign, array $bucket = []): array
    {
        $threshold = (int) ($campaign->alert_threshold_claims ?? self::DEFAULT_THRESHOLD);
        $enabled = (bool) ($campaign->alerts_enabled ?? true);
        $held = $campaign->claims()->whereNotNull('held_reason')->count();
        $remaining = $this->claimsRemaining($campaign, $bucket);

        return [
            'enabled' => $enabled,
            'threshold' => $threshold,
            'claims_remaining' => $remaining,
            'held' => $held,
            'alerting' => $this->alerting($campaign, $enabled, $threshold, $remaining, $held),
            'reason' => $this->reason($remaining, $held, $threshold),
        ];
    }

    /**
     * Only while the campaign is actually running.
     *
     * Before it starts there is nothing to be short of yet, and an operator funding a
     * bucket over the week before an event does not want to be chased through it. After
     * it ends nothing more will be claimed, so a shortfall has stopped mattering and a
     * warning about it is noise arriving after the fact.
     */
    private function alerting(Campaign $campaign, bool $enabled, int $threshold, ?int $remaining, int $held): bool
    {
        if (! $enabled || $campaign->status !== 'active') {
            return false;
        }

        return $held > 0 || ($remaining !== null && $remaining <= $threshold);
    }

    private function reason(?int $remaining, int $held, int $threshold): ?string
    {
        if ($held > 0) {
            return 'held';
        }

        if ($remaining !== null && $remaining <= $threshold) {
            return $remaining <= 0 ? 'exhausted' : 'low';
        }

        return null;
    }

    /**
     * How many more claims this campaign can serve.
     *
     * Billing only changes the answer where it actually decides what a claim takes out
     * of a prepaid account: a path that is enabled, priced, and collects from a balance
     * rather than the campaign's own bucket. Everywhere else — billing off, the path not
     * priced yet, or a path that collects in band — a claim is still paid for out of the
     * bucket, so the bucket is what answers the question. That includes the self-hosted
     * edition, which ships with no pricing configuration at all: nothing there is ever
     * enabled or priced, so every self-hosted campaign is answered from its own bucket.
     *
     * Null where the question genuinely cannot be answered: a credit-billed campaign
     * with no account to check a balance against, or a bucket path with no still-
     * claimable code to price a claim from.
     */
    private function claimsRemaining(Campaign $campaign, array $bucket): ?int
    {
        $path = Pricing::pathFor($campaign);

        if (Pricing::billingEnabled() && Pricing::isConfigured($path) && ! Pricing::collectsInBand($path)) {
            return $campaign->user === null
                ? null
                : intdiv(
                    max(0, $this->ledger->balanceMicro($campaign->user)),
                    max(1, Pricing::claimCostMicro(1))
                );
        }

        // The bucket pays for everything on this path: the reward, the chain and the fee.
        // The cheapest remaining code is what decides how many more claims are certain,
        // because the next person to claim might be holding it.
        $balance = array_sum(array_map(
            static fn ($utxo) => (int) ($utxo['lovelace'] ?? 0),
            $bucket,
        ));
        $perClaim = $this->dearestClaimCost($campaign);

        return $perClaim > 0 ? intdiv(max(0, $balance), $perClaim) : null;
    }

    /**
     * What the most expensive still-claimable code would take out of the bucket.
     *
     * The dearest rather than the average, because an alert exists to be early. A bucket
     * that covers the average and not the dearest will fail on somebody, and telling the
     * operator afterwards is what this is meant to avoid.
     */
    private function dearestClaimCost(Campaign $campaign): int
    {
        $fees = (int) config('cardano.network_fee_lovelace')
            + (int) (Pricing::inBandCharge(Pricing::pathFor($campaign))['revenue_lovelace'] ?? 0);

        // Read from the codes the page already loaded, which carry their claim counts.
        // There is no claims_count column to query against; it is a counted relation.
        $dearest = (int) $campaign->codes
            ->filter(static fn ($code) => (int) $code->claims_count < (int) $code->uses)
            ->max('lovelace');

        return $dearest + $fees;
    }
}
