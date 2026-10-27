<?php

namespace App\Support;

use App\Models\Campaign;

/**
 * Whether a native campaign's payouts collect the platform fee, and at what rate.
 *
 * The other backends take their fee inside the service that holds the funds, and the platform
 * only records it. A campaign that builds its own transactions has nothing taking it, so each
 * payout has to pay it into the platform's fee output itself. That is a decision made once per
 * campaign from four facts, and every one has to hold:
 *
 *  - pricing is configured: the deployment has rates at all, which the self-hosted edition does
 *    not, because its publish removes the file that holds them;
 *  - billing is enabled, so a development box or a staging host with rates in the file but no
 *    one to bill does not collect a fee nobody meant to take;
 *  - the campaign's path collects in band, which is what says the bucket pays the fee rather
 *    than a prepaid credit;
 *  - the campaign's backend is native.
 *
 * Anywhere that stamps, estimates or budgets the fee asks here, so those places cannot disagree
 * about which campaigns pay it.
 */
final class PlatformFee
{
    /**
     * Whether a campaign on this path and backend collects the fee in its own payouts.
     */
    public static function required(string $path, ?string $backend): bool
    {
        return $backend === 'native'
            && Pricing::billingEnabled()
            && Pricing::isConfigured($path)
            && Pricing::collectsInBand($path);
    }

    public static function collects(Campaign $campaign): bool
    {
        return self::required(Pricing::pathFor($campaign), $campaign->wallet?->backend);
    }

    /**
     * The least lovelace an ADA-only output can hold, at this cost per byte, for an address of this
     * many bytes: the fee address's by default.
     *
     * One figure for everything that has to fit it: every fees output the payouts make or
     * leave behind. Priced in the longer of the two output forms the ledger
     * accepts, so it is never short of what the ledger requires.
     */
    public static function outputMinimum(int $coinsPerUtxoByte, int $addressBytes = MinUtxo::ENTERPRISE_ADDRESS_BYTES): int
    {
        return MinUtxo::forBundle([], $coinsPerUtxoByte, $addressBytes);
    }

    /**
     * The words an operator is told when a native campaign is refused for want of the fee key.
     */
    public static function missingKeyMessage(): string
    {
        $message = 'This deployment collects a platform fee on every native campaign payout, and the platform fee '
            .'key does not exist yet.';

        return $message.' A campaign cannot be created without it, because every payout it makes would have no '
            .'address to pay the fee to.';
    }

    /**
     * Why the first claim could not open the campaign's fees output, or null when it can.
     *
     * The first fees output holds exactly the first claim's fee, and the ledger refuses an output
     * under its minimum. Claim 1 always pays the first tier, so that rate has to cover the minimum
     * on its own. Checked before anything is built, with the setting named, because the
     * alternative is a payout that is refused by the node after its claims were marked as sent.
     */
    public static function firstTierShortfall(string $path, int $minimumLovelace): ?string
    {
        $rate = (int) Pricing::marginalRate($path, 1);

        if ($rate >= $minimumLovelace) {
            return null;
        }

        return sprintf(
            'The first platform fee tier, the one for claim 1 in pricing.paths.%s.tiers, is %d lovelace and the '
            .'ledger minimum for the fees output it opens is %d. The first claim of a campaign could not be paid '
            .'until the first tier is at least that.',
            $path,
            $rate,
            $minimumLovelace,
        );
    }
}
