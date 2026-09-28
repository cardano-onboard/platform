<?php

namespace Tests\Support;

use InvalidArgumentException;

/**
 * The frozen slot derivation for a campaign's native script expiry.
 *
 * A campaign's script expiry is its end date, read at 23:59:59 UTC, plus ninety
 * days, converted to a slot number through the network's era summary. The
 * 23:59:59 UTC reading is the convention the claim path already uses, at
 * app/Http/Controllers/CodeController.php line 296.
 *
 * Like NativeScript this lives in tests/ because it is a freeze, not a feature.
 */
final class CampaignExpiry
{
    /** Days between the end of the campaign and the script expiry. */
    public const GRACE_DAYS = 90;

    /** The last second of the campaign's end date, as a Unix timestamp. */
    public static function endInstant(string $endDate): int
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $endDate, $parts)) {
            throw new InvalidArgumentException('An end date must be written YYYY-MM-DD, got: '.$endDate);
        }

        // strtotime rolls 2027-02-29 forward to 1 March rather than refusing it,
        // which would silently move the expiry by a day and with it the address.
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Not a real date: '.$endDate);
        }

        $instant = strtotime($endDate.' 23:59:59 UTC');

        if ($instant === false) {
            throw new InvalidArgumentException('Not a real date: '.$endDate);
        }

        return $instant;
    }

    /** The script expiry instant: the end of the campaign plus ninety days. */
    public static function expiryInstant(string $endDate): int
    {
        return self::endInstant($endDate) + self::GRACE_DAYS * 86400;
    }

    /**
     * The expiry as a slot number.
     *
     * $anchorSlot and $anchorTime come from the network's era summary: the first
     * slot of the era the expiry falls in, and the Unix time that slot begins.
     * $slotLength is that era's seconds per slot.
     */
    public static function expirySlot(string $endDate, int $anchorSlot, int $anchorTime, int $slotLength = 1): int
    {
        if ($slotLength < 1) {
            throw new InvalidArgumentException('A slot cannot be shorter than a second, got: '.$slotLength);
        }

        $elapsed = self::expiryInstant($endDate) - $anchorTime;

        if ($elapsed < 0) {
            throw new InvalidArgumentException(
                'The expiry falls before the era anchor, so this is the wrong era summary entry.'
            );
        }

        // A remainder means the expiry lands inside a slot rather than on one.
        // Rounding either way moves the address, so refuse instead.
        if ($elapsed % $slotLength !== 0) {
            throw new InvalidArgumentException(
                'The expiry does not land on a slot boundary: '.$elapsed.' seconds is not a multiple of '.$slotLength
            );
        }

        return $anchorSlot + intdiv($elapsed, $slotLength);
    }
}
