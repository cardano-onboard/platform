<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Decides which connecting addresses may supply X-Forwarded-For, -Proto, -Host and -Port.
 *
 * A trusted proxy may append to X-Forwarded-For, because the client address is read from
 * the right and stops at the first address that is not trusted. It must set or replace
 * X-Forwarded-Proto and set or strip X-Forwarded-Host and X-Forwarded-Port: Symfony reads
 * those from the first value without walking the chain, so a proxy that only appends passes
 * a client's own value through.
 *
 * X-Forwarded-Prefix is left out on purpose. Nothing here is served under a path prefix,
 * and Symfony prepends the header to the URL root unchecked, so a value such as
 * "@other.example" turns every generated link, password reset links included, into one that
 * browsers send to another host.
 *
 * The list is read from config('security.trusted_proxies') on every request. The
 * withMiddleware callback in bootstrap/app.php runs before configuration is loaded, so a
 * value passed to trustProxies() there could only come from env(), which returns null once
 * the configuration is cached.
 */
class TrustProxies extends Middleware
{
    /**
     * Loopback and private ranges, with the IPv4-mapped form of each, which is how a
     * dual-stack socket reports an IPv4 peer.
     */
    public const PRIVATE_RANGES = [
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
        '::ffff:127.0.0.1',
        '::ffff:10.0.0.0/104',
        '::ffff:172.16.0.0/108',
        '::ffff:192.168.0.0/112',
    ];

    protected $headers = Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Reads a TRUSTED_PROXIES value. The value is either exactly '*', or a comma separated
     * list whose entries are IPv4 or IPv6 addresses or CIDR ranges with a prefix from 1 to the
     * address maximum, written without leading zeros. Any other entry is dropped and listed
     * under 'rejected': '*' inside a list, a host name, REMOTE_ADDR and PRIVATE_SUBNETS all
     * mean something to Symfony that "exactly these addresses" does not, and a /0 range
     * trusts more than '*' does, because Symfony then reads the leftmost forwarded address.
     * An entry with an embedded dotted quad needs a prefix of at least 96, since a shorter
     * one covers far more than the IPv4 range it appears to name.
     *
     * Each IPv4 entry also gets its IPv4-mapped IPv6 form, because a peer on a dual-stack
     * socket arrives as ::ffff:a.b.c.d and an IPv4 range never matches that.
     *
     * @return array{star: bool, proxies: array<int, string>, rejected: array<int, string>}
     */
    public static function parse(?string $setting): array
    {
        $setting = trim((string) $setting);

        if ($setting === '*') {
            return ['star' => true, 'proxies' => [], 'rejected' => []];
        }

        $proxies = [];
        $rejected = [];

        foreach (array_filter(array_map('trim', explode(',', $setting)), 'strlen') as $entry) {
            $accepted = static::accept($entry);

            if ($accepted === null) {
                $rejected[] = $entry;
            } else {
                array_push($proxies, ...$accepted);
            }
        }

        return ['star' => false, 'proxies' => $proxies, 'rejected' => $rejected];
    }

    /**
     * The entries to trust for one list entry, or null when it is not acceptable.
     *
     * @return array<int, string>|null
     */
    private static function accept(string $entry): ?array
    {
        $parts = explode('/', $entry);

        if (count($parts) > 2) {
            return null;
        }

        [$address] = $parts;
        $prefix = $parts[1] ?? null;

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ipv4 = true;
            $max = 32;
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $ipv4 = false;
            $max = 128;
        } else {
            return null;
        }

        if ($prefix !== null) {
            if (preg_match('/^(0|[1-9]\d{0,2})$/', $prefix) !== 1 || (int) $prefix < 1 || (int) $prefix > $max) {
                return null;
            }
        }

        // An embedded dotted quad in an IPv6 entry.
        if (! $ipv4 && str_contains($address, '.') && $prefix !== null && (int) $prefix < 96) {
            return null;
        }

        if (! $ipv4) {
            return [$entry];
        }

        return [$entry, '::ffff:'.$address.'/'.($prefix === null ? 128 : (int) $prefix + 96)];
    }

    /**
     * A list set with TrustProxies::at() wins, as it does in the framework. Otherwise a blank
     * setting is the private ranges. A setting with no valid entry in it trusts nothing, the
     * same as a list that names nothing the connecting address matches.
     *
     * @return array<int, string>|string
     */
    protected function proxies()
    {
        if (static::$alwaysTrustProxies) {
            return static::$alwaysTrustProxies;
        }

        $parsed = static::parse(config('security.trusted_proxies', ''));

        if ($parsed['star']) {
            return '*';
        }

        if ($parsed['proxies'] === [] && $parsed['rejected'] === []) {
            return self::PRIVATE_RANGES;
        }

        return $parsed['proxies'];
    }

    /**
     * An empty list means trust nothing. The framework would read it as "not set" and fall
     * back to its own platform detection, which trusts every address.
     */
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        if ($this->proxies() === []) {
            return;
        }

        parent::setTrustedProxyIpAddresses($request);
    }
}
