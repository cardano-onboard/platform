<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\CreditTransaction;
use App\Models\User;

/**
 * What a credit is worth, and what a claim costs.
 *
 * Deliberately thin, and deliberately without opinions about balances. It reads the
 * configured paths and answers questions about price; spending is the ledger's job.
 *
 * Nothing here recomputes a charge that has already been made. Every figure it returns
 * is meant to be written down at the moment it applies, and a later change to a rate or
 * a tier leaves what was already recorded alone.
 */
final class Pricing
{
    /** One credit, in millionths. */
    public const MICRO = CreditTransaction::MICRO;

    /**
     * Which path an account or a campaign buys on.
     *
     * A campaign may override its account, for the customer running both a Cardano drop
     * and a dollar-priced event. Neither being set falls back to the configured default,
     * so no account needs a backfill to keep working.
     */
    public static function pathFor(User|Campaign|null $subject): string
    {
        $default = (string) (config('pricing.default_path') ?? 'ada');

        if ($subject instanceof Campaign) {
            return $subject->billing_path
                ?? $subject->user?->billing_path
                ?? $default;
        }

        return $subject?->billing_path ?? $default;
    }

    /**
     * Whether this deployment charges at all.
     *
     * Separate from whether a path has rates. Rates are what the platform sells at; this
     * is whether this installation is taking money. A staging box has the first and not
     * the second, and holding its claims for a fee nobody meant to collect would make it
     * useless for the testing it exists for.
     */
    public static function billingEnabled(): bool
    {
        return (bool) config('pricing.enabled', false);
    }

    /**
     * Whether this path's fee is taken from the campaign's own funding bucket as each
     * claim is paid, rather than from a prepaid balance.
     *
     * Where it is, a credit must not also be debited: the customer has already paid at
     * the moment the claim was sent, and taking a credit as well charges them twice.
     */
    public static function collectsInBand(string $path): bool
    {
        return config("pricing.paths.{$path}.collection") === 'in_band';
    }

    /**
     * What a claim costs the campaign's own bucket on a path that collects in band, split
     * into what is ours and what goes to the chain.
     *
     * Empty on a path that does not collect this way, and empty where no rates are
     * recorded, because a cost nobody wrote down is not a cost of zero.
     *
     * @return array{revenue_lovelace: ?int}
     */
    public static function inBandCharge(string $path): array
    {
        if (! self::collectsInBand($path)) {
            return ['revenue_lovelace' => null];
        }

        $rates = (array) config("pricing.paths.{$path}.in_band_rates", []);

        return [
            'revenue_lovelace' => isset($rates['platform_fee_lovelace']) ? (int) $rates['platform_fee_lovelace'] : null,
        ];
    }

    /**
     * Whether a campaign's billing path and its transaction backend can both be true.
     *
     * A path billed in credits cannot run on a backend that takes its own fee from the
     * bucket. The claim would be paid for twice: once by the customer's credit, and again
     * out of the float we funded for them. Nobody is overcharged, so nothing complains,
     * and the margin simply disappears.
     */
    public static function backendAgreesWithPath(?string $backend, string $path): bool
    {
        if ($backend === null || self::collectsInBand($path)) {
            return true;
        }

        return ! in_array($backend, (array) config('pricing.in_band_backends', []), true);
    }

    /**
     * Whether a path may be used, and why not where it may not.
     *
     * Three conditions, kept apart on purpose. A path can be switched off, or switched on
     * with nowhere for the money to go, or switched on with a merchant and no rates, and
     * those are three different things for whoever has to fix it. Returns null when the
     * path is usable.
     */
    public static function pathUnavailableReason(string $path): ?string
    {
        $config = (array) config("pricing.paths.{$path}");

        if ($config === []) {
            return 'This deployment has no such billing path.';
        }

        if (($config['enabled'] ?? false) !== true) {
            return "The {$path} path is switched off for this deployment.";
        }

        if (($config['requires_merchant'] ?? false) && blank($config['merchant'] ?? null)) {
            return "The {$path} path has no merchant connected, so there is nowhere for the money to go.";
        }

        if (! self::isConfigured($path)) {
            return "The {$path} path has no rates, so nothing can be priced on it.";
        }

        return null;
    }

    public static function pathEnabled(string $path): bool
    {
        return self::pathUnavailableReason($path) === null;
    }

    /** The paths a customer may actually be put on, which is what a form should offer. */
    public static function availablePaths(): array
    {
        return array_values(array_filter(
            array_keys((array) config('pricing.paths', [])),
            static fn (string $path) => self::pathEnabled($path),
        ));
    }

    /** Whether anything can be sold on a path at all. */
    public static function isConfigured(?string $path = null): bool
    {
        if ($path === null) {
            foreach (array_keys((array) config('pricing.paths', [])) as $known) {
                if (self::isConfigured($known)) {
                    return true;
                }
            }

            return false;
        }

        return (array) config("pricing.paths.{$path}.tiers", []) !== [];
    }

    /**
     * What one credit costs in a pack of this size, in the path's own smallest unit.
     *
     * The tier belongs to the purchase. A pack of 2,500 is bought at its rate and every
     * credit in it keeps that value for as long as it exists, so nothing is re-rated at
     * settlement and there is no question of a band being marginal or repricing anything.
     *
     * Null where the path has no rates, which is the honest answer for a price that has
     * not been decided rather than a guess borrowed from the other path.
     */
    public static function unitPrice(string $path, int $credits): int|string|null
    {
        $tiers = (array) config("pricing.paths.{$path}.tiers", []);

        if ($tiers === [] || $credits < 1) {
            return null;
        }

        ksort($tiers);
        $price = null;

        foreach ($tiers as $from => $rate) {
            if ($credits >= (int) $from) {
                $price = $rate;
            }
        }

        return $price;
    }

    /**
     * What the nth paid claim of a campaign pays on a path that collects in band and builds its
     * own transactions, in lovelace.
     *
     * The tiers apply marginally here. The claim's ordinal alone decides its rate: the claims below
     * the second tier's first ordinal pay the first tier, and so on up the schedule, whatever the campaign goes on to
     * pay. Nothing is repriced afterwards, so a rate is stamped on the claim when it is paid and
     * left there. A pack of credits takes the tier of the pack instead, which is unitPrice().
     *
     * Null where the path has no rates or there is no such ordinal. The schedule has to start at
     * claim 1; scheduleProblem() says when it does not.
     */
    public static function marginalRate(string $path, int $ordinal): ?int
    {
        $tiers = (array) config("pricing.paths.{$path}.tiers", []);

        if ($tiers === [] || $ordinal < 1) {
            return null;
        }

        ksort($tiers);
        $rate = null;

        foreach ($tiers as $from => $tierRate) {
            if ($ordinal >= (int) $from) {
                $rate = (int) $tierRate;
            }
        }

        return $rate;
    }

    /**
     * What the claims after the first $paid, $count of them, pay between them: the marginal
     * schedule summed over those ordinals.
     *
     * Summed a tier at a time rather than a claim at a time, because the funding estimate asks it
     * for every remaining claim of a campaign that may hold a million codes.
     */
    public static function marginalTotal(string $path, int $paid, int $count): ?int
    {
        $tiers = (array) config("pricing.paths.{$path}.tiers", []);

        if ($tiers === []) {
            return null;
        }

        if ($count < 1) {
            return 0;
        }

        ksort($tiers);
        $starts = array_map('intval', array_keys($tiers));
        $rates = array_map('intval', array_values($tiers));
        $first = $paid + 1;
        $last = $paid + $count;
        $total = 0;

        foreach ($starts as $i => $start) {
            $from = max($start, $first);
            $to = isset($starts[$i + 1]) ? min($starts[$i + 1] - 1, $last) : $last;

            if ($to >= $from) {
                $total += ($to - $from + 1) * $rates[$i];
            }
        }

        return $total;
    }

    /**
     * Why a path's tier schedule cannot price every claim, or null when it can.
     *
     * A claim's rate is the tier its ordinal falls in, so the schedule has to begin at claim 1 and
     * hold whole, non-negative lovelace. A schedule that starts later leaves the first claims with
     * no rate, and a claim with no rate cannot be paid in a payout that collects the fee.
     */
    public static function scheduleProblem(string $path): ?string
    {
        $tiers = (array) config("pricing.paths.{$path}.tiers", []);

        if ($tiers === []) {
            return null;
        }

        ksort($tiers);

        if ((int) array_key_first($tiers) !== 1) {
            return sprintf('The tiers for the %s path start at claim %d, and the first tier has to start at claim 1.', $path, (int) array_key_first($tiers));
        }

        foreach ($tiers as $rate) {
            if (! is_int($rate) || $rate < 0) {
                return sprintf('A tier rate for the %s path is %s, and a rate is a whole number of lovelace of at least 0.', $path, json_encode($rate));
            }
        }

        return null;
    }

    /** What a whole pack costs, or null where the path is unpriced. */
    public static function packPrice(string $path, int $credits): int|string|null
    {
        $unit = self::unitPrice($path, $credits);

        if ($unit === null) {
            return null;
        }

        return self::currencyOf($path) === 'usd'
            ? bcmul((string) $unit, (string) $credits, 6)
            : (int) $unit * $credits;
    }

    public static function currencyOf(string $path): string
    {
        return (string) (config("pricing.paths.{$path}.currency") ?? $path);
    }

    /**
     * What one claim consumes, in millionths of a credit.
     *
     * One credit covers a claim, because a credit is defined as the largest a claim can
     * cost. Each asset beyond the first adds a fraction, which is what the extra minimum
     * UTxO actually costs rather than another whole claim. Charging a full credit per
     * asset would erase the margin an organiser makes reselling a slot in the drop.
     */
    public static function claimCostMicro(int $assets): int
    {
        $extra = max(0, $assets - 1);

        return self::MICRO + ($extra * (int) config('pricing.per_extra_asset_micro', 0));
    }

    /**
     * A figure typed in credits, as the millionths the columns hold.
     *
     * Read as a decimal string rather than multiplied as a float, because a credit is
     * millionths and binary floating point cannot hold them. 1.005 times a million is
     * 1004999.9999999999 in a float, and a cast would store a limit one millionth below
     * the one that was typed.
     *
     * Anything past the sixth decimal place is dropped rather than rounded, because
     * rounding a ceiling upwards authorizes spending nobody asked for. Callers validate
     * the precision first, so a figure that arrives here has nothing to drop.
     */
    public static function creditsToMicro(int|float|string $credits): int
    {
        $value = ltrim(trim((string) $credits), '+');
        $negative = str_starts_with($value, '-');

        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        // Prefixed with a zero so an empty side of the point reads as none of it rather
        // than as a cast of the empty string.
        $micro = (int) ('0'.$whole) * self::MICRO
            + (int) ('0'.substr(str_pad($fraction, 6, '0'), 0, 6));

        return $negative ? -$micro : $micro;
    }

    /**
     * Millionths as the figure an operator would recognise, in credits.
     *
     * Trailing zeros go, so a limit of a hundred and fifty credits reads as 150 rather
     * than 150.000000 in the box the operator typed it into.
     */
    public static function microToCredits(int $micro): string
    {
        $magnitude = abs($micro);
        $fraction = rtrim(str_pad((string) ($magnitude % self::MICRO), 6, '0', STR_PAD_LEFT), '0');

        return ($micro < 0 ? '-' : '')
            .intdiv($magnitude, self::MICRO)
            .($fraction === '' ? '' : '.'.$fraction);
    }
}
