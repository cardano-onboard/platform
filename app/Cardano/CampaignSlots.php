<?php

namespace App\Cardano;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Turning a date into the slot number a script can be written against.
 *
 * A campaign's expiry goes inside its script and therefore inside its address, so this is one of
 * the few calculations here that cannot be corrected later. A slot that is wrong by an hour is a
 * campaign whose authorisation ends at the wrong time; a slot that is wrong by a era is a campaign
 * nobody can spend from.
 *
 * The conversion is anchored on the chain's current tip rather than on written-down era
 * boundaries. Both are arithmetic from a known point, and the difference is what happens when the
 * chain changes: a tip is read fresh every time and cannot go stale, while a table of era starts
 * and slot lengths is right until a hard fork and silently wrong afterwards, in exactly the way
 * that ends up inside an address.
 *
 * Anchoring on the tip carries its own assumption, which is that a slot stays one second for as
 * long as the gap being measured. That has held for every era since Shelley and would change only
 * at a hard fork. The assumption is bounded rather than hoped for: a date further out than the
 * horizon is refused, because past that the honest answer is that nobody knows what slot it will
 * be.
 */
final class CampaignSlots
{
    /**
     * Days between a campaign's end and its authorisation expiring: payments confirm, unchecked
     * claims are checked, and what is left goes back.
     *
     * A constant and not configuration. The expiry it produces is written into the campaign's
     * script and so into its address, and a mistyped environment variable would put a different
     * expiry into every address a deployment creates.
     */
    public const GRACE_DAYS = 90;

    public function __construct(private readonly ChainData $chain) {}

    /**
     * The slot a moment falls in.
     *
     * @throws RuntimeException where the moment is further out than the anchor can answer for
     */
    public function slotAt(string $network, DateTimeInterface $when): int
    {
        $tip = $this->chain->tip($network);

        $seconds = $when->getTimestamp() - $tip['time'];

        if ($seconds < 0) {
            throw new RuntimeException(sprintf(
                'A campaign cannot expire in the past. The chain is at slot %d and the date given is %s.',
                $tip['slot'],
                $when->format(DateTimeInterface::ATOM),
            ));
        }

        $horizon = $this->horizonSeconds();

        if ($seconds > $horizon) {
            throw new RuntimeException(sprintf(
                'That date is %d days past the chain tip and this converts up to %d. Beyond that a slot is a '
                .'guess about whether the chain still runs a slot a second, and a guess would be written into '
                .'an address that cannot be changed afterwards.',
                intdiv($seconds, 86400),
                intdiv($horizon, 86400),
            ));
        }

        return $tip['slot'] + $seconds;
    }

    /**
     * The slot a campaign's authorisation ends at.
     *
     * The campaign's own end date plus the grace window that settlement needs: payments confirm,
     * unchecked claims are checked, and what is left goes back. One derived date ends signing,
     * sweeping and metadata correction together, which is the answer somebody doing procurement
     * wants: after it the platform can do nothing to the campaign at all.
     */
    public function expiryFor(string $network, DateTimeInterface $endDate): int
    {
        return $this->slotAt($network, $this->expiryDate($endDate));
    }

    /**
     * The moment a campaign's authorisation ends, from its end date.
     *
     * Its own method because the cap on an end date is the same arithmetic read backwards, and two
     * implementations of one relationship is how they come to disagree.
     */
    public function expiryDate(DateTimeInterface $endDate): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($endDate)
            ->modify(sprintf('+%d days', self::GRACE_DAYS));
    }

    private function horizonSeconds(): int
    {
        // Creation never asks for more than the authorisation window, which already includes the
        // grace. The grace and a margin are added on top so that a campaign created at the very
        // limit, a moment after the tip was read, still converts.
        $days = (int) config('campaign_script.authorisation_days', 180)
            + self::GRACE_DAYS;

        return max(1, $days + 30) * 86400;
    }
}
