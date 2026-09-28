<?php

namespace App\Support;

/**
 * Every ability a Sanctum token may be minted with, named once.
 *
 * A route guards itself with one of these strings, and the api:token command checks
 * against the same list before it mints anything, so a typo on either side is caught
 * rather than quietly minting a token that matches no route, or guarding a route with an
 * ability nothing can ever be given.
 */
final class ApiAbilities
{
    /** Create one code on a campaign through the code API. */
    public const CODES_CREATE = 'codes:create';

    /** Read a code's claim status through the code API. */
    public const CODES_STATUS = 'codes:status';

    /**
     * Claim on an attendee's behalf from an external caller's own backend, rather than
     * from the attendee's own device. A claim carrying this ability is rate limited by the
     * token itself instead of the shared IP and campaign limits every other claim uses,
     * and may name the claiming client with a forwarded header instead of its own user
     * agent.
     */
    public const CODES_CLAIM = 'codes:claim';

    /** @return list<string> */
    public static function known(): array
    {
        return [self::CODES_CREATE, self::CODES_STATUS, self::CODES_CLAIM];
    }
}
