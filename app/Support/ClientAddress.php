<?php

namespace App\Support;

/**
 * The key a per-source rate limit counts a client address under.
 *
 * An IPv4 address is its own key. An IPv6 address is reduced to its /64, because that is
 * the smallest block one network is normally given: a single machine on one network can use
 * any of its 2^64 addresses, so counting each address separately would let it start again
 * with a new one at every limit.
 */
final class ClientAddress
{
    public static function key(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // An IPv4 address carried in an IPv6 one counts as that IPv4 address: mapped
        // (::ffff:a.b.c.d), compatible (::a.b.c.d) and NAT64 (64:ff9b::a.b.c.d). The two
        // addresses ::1 and :: are not IPv4 addresses and stay IPv6 ones.
        $prefix = substr($packed, 0, 12);
        $embedded = substr($packed, 12);

        if ($prefix === str_repeat("\0", 10)."\xff\xff"
            || $prefix === "\x00\x64\xff\x9b".str_repeat("\0", 8)
            || ($prefix === str_repeat("\0", 12) && strcmp($embedded, "\x00\x00\x00\x01") > 0)) {
            return inet_ntop($embedded);
        }

        return bin2hex(substr($packed, 0, 8)).'::/64';
    }
}
