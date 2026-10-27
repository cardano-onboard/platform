<?php

namespace App\Support;

/**
 * Decides what of an environment value may be shown, and shows only what is proven safe.
 *
 * The policy is to hide unless proven safe. A value is shown only when its variable has a type
 * (see EnvTypes) and the value has exactly the shape that type allows: a switch, a number, one
 * word from a known set, a host name, a short name, an address. Anything else, which includes
 * every free-text setting, every path and every variable nobody has typed, is shown as
 * "set (hidden)", or "unset" when it is empty.
 *
 * Two more checks stand behind that and can only hide more:
 *
 *   by name    a variable whose name says it holds a credential is hidden whatever it holds
 *   by shape   a value that looks like a credential is hidden whatever its type allowed
 *
 * An address is shown only after it is cleaned: its user name and password are removed whatever
 * characters the password holds, only a host name that is safe to show is kept, a path segment
 * is kept only when it is a short plain word, every query value is replaced and so is any query
 * key that is not a short plain word, and any fragment is dropped. An address that cannot be cleaned with confidence is hidden whole.
 */
final class EnvMask
{
    public const HIDDEN = 'set (hidden)';

    public const UNSET = 'unset';

    public const EMPTY = '(empty)';

    /** Shown in place of a part of an address that could be a secret. */
    private const REDACTED = '***';

    /**
     * Names hidden whatever they hold, listed in full so a rename of the stems below can never
     * quietly uncover one of them. It includes the connection strings, which carry a user name
     * and password by design and have no word for a credential in their names.
     */
    public const SECRET_NAMES = [
        'APP_KEY', 'APP_PREVIOUS_KEYS',
        'ADMIN_PASSWORD',
        'PHYRHOSE_MAINNET_JWT', 'PHYRHOSE_PREPROD_JWT', 'PHYRHOSE_PREVIEW_JWT',
        'PROXY_API_TOKEN', 'KOIOS_API_TOKEN', 'NMKR_MAINNET_API_KEY', 'NMKR_PREPROD_API_KEY',
        'DB_USERNAME', 'DB_PASSWORD',
        'DBSYNC_PREPROD_USERNAME', 'DBSYNC_PREPROD_PASSWORD', 'DBSYNC_MAINNET_USERNAME', 'DBSYNC_MAINNET_PASSWORD',
        'REDIS_USERNAME', 'REDIS_PASSWORD',
        'MEMCACHED_USERNAME', 'MEMCACHED_PASSWORD',
        'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAILGUN_SECRET', 'POSTMARK_TOKEN', 'RESEND_KEY', 'SES_KEY', 'SES_SECRET',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN',
        'PUSHER_APP_KEY', 'PUSHER_APP_SECRET', 'ABLY_KEY',
        'LOG_SLACK_WEBHOOK_URL', 'SLACK_BOT_USER_OAUTH_TOKEN',
        'DATABASE_URL', 'DATABASE_PREPROD_URL', 'DATABASE_MAINNET_URL', 'DB_URL', 'REDIS_URL', 'MAIL_URL',
    ];

    /**
     * Words that mark a name as a credential when a name part is the word or ends with it
     * (APIKEY, ACCESSTOKEN), so a compound written without separators is still caught.
     */
    private const STEMS = [
        'KEY', 'KEYS', 'TOKEN', 'TOKENS', 'JWT', 'DSN', 'PASS', 'PWD', 'SALT', 'SEED', 'AUTH', 'PIN', 'OTP',
        'USERNAME', 'USER', 'HOOK', 'LICENSE', 'SIGNATURE',
    ];

    /** Words that mark a name as a credential wherever they appear in it. */
    private const ANYWHERE = [
        'SECRET', 'PASSWORD', 'PASSWD', 'PASSPHRASE', 'PRIVATE', 'MNEMONIC', 'CREDENTIAL', 'BEARER', 'WEBHOOK', 'SIGNING',
    ];

    /**
     * Names the stems above catch that hold a count or a switch, never a credential: a limit per
     * token, how many tokens a request may carry, whether foreign keys are enforced. Their type
     * is a number or a switch, and what they hold is still held to that.
     */
    private const NOT_A_CREDENTIAL = '/(_RATE_PER_TOKEN|_TOKEN_QUANTITY|_TOKENS_PER_CODE|_MAX_PER_PASS|_FOREIGN_KEYS)$/';

    /** The most digits a number may have, and so how large it can be. */
    private const NUMBER_DIGITS = 12;

    /** Whether a variable's name says it holds a credential. */
    public static function isSecretName(string $name): bool
    {
        $name = strtoupper($name);

        if (in_array($name, self::SECRET_NAMES, true)) {
            return true;
        }

        if (preg_match(self::NOT_A_CREDENTIAL, $name) === 1) {
            return false;
        }

        foreach (self::ANYWHERE as $word) {
            if (str_contains($name, $word)) {
                return true;
            }
        }

        foreach (explode('_', $name) as $part) {
            foreach (self::STEMS as $stem) {
                if ($part === $stem || (strlen($part) > strlen($stem) && str_ends_with($part, $stem))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What may be shown of a variable's value.
     *
     * @param  string|null  $type  The variable's type (see EnvTypes). Null hides every value.
     * @return array{text: string, hidden: bool}
     */
    public static function show(string $name, mixed $value, ?string $type): array
    {
        $secret = self::isSecretName($name);

        if ($value === null || $value === '' || $value === []) {
            return ['text' => $secret ? self::UNSET : self::EMPTY, 'hidden' => $secret];
        }

        $text = $secret || $type === null ? null : self::prove($type, $value);

        // The shape checks can only take a value away: what the type allows is still hidden
        // when it looks like a credential.
        if ($text === null || self::isSecretValue($text)) {
            return ['text' => self::HIDDEN, 'hidden' => true];
        }

        return ['text' => $text, 'hidden' => false];
    }

    /**
     * Whether a string looks like a credential, whatever the variable is called or typed.
     */
    public static function isSecretValue(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        // An address is judged by cleaning it, not by its shape.
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value) === 1) {
            return false;
        }

        // The same value with the quoting and punctuation a paste leaves around it taken off.
        $bare = trim($value, " \t\r\n\"'`<>()[]{}");

        foreach (array_unique([$value, $bare, rtrim($bare, '.,;:')]) as $candidate) {
            if (self::looksSecretWhole($candidate)) {
                return true;
            }
        }

        // A credential inside a longer string, such as a sentence or a list.
        foreach (preg_split('/[\s,;|]+/', $bare) ?: [] as $word) {
            $word = trim($word, "\"'`<>()[]{}.:");

            if ($word !== $value && strlen($word) >= 12 && self::looksSecretWord($word)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ types

    /**
     * The text to show for a value of a type, or null when the value does not have the shape the
     * type allows.
     */
    private static function prove(string $type, mixed $value): ?string
    {
        if (str_starts_with($type, 'list:')) {
            $elements = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
            $shown = [];

            foreach ($elements as $element) {
                if (! is_scalar($element)) {
                    return null;
                }

                if (trim((string) $element) === '') {
                    continue;
                }

                // Each element is proven on its own, so a list is as safe as its least safe element.
                $text = self::prove(substr($type, 5), is_string($element) ? trim($element) : $element);

                if ($text === null || self::isSecretValue($text)) {
                    return null;
                }

                $shown[] = $text;
            }

            return $shown === [] ? null : implode(',', $shown);
        }

        if (! is_scalar($value)) {
            return null;
        }

        if (str_starts_with($type, 'enum:')) {
            return is_string($value) && in_array($value, explode('|', substr($type, 5)), true) ? $value : null;
        }

        return match ($type) {
            'bool' => self::bool($value),
            'int' => self::number($value, false),
            'float' => self::number($value, true),
            'port' => self::port($value),
            'host' => is_string($value) && self::isHost($value, false) ? $value : null,
            'hostport' => is_string($value) && self::isHostPort($value, false) ? $value : null,
            'cookiehost' => is_string($value) && self::isCookieHost($value) ? $value : null,
            'id' => self::identifier($value),
            'email' => is_string($value) ? self::email($value) : null,
            'url' => is_string($value) ? self::cleanUrl($value) : null,
            default => null,
        };
    }

    private static function bool(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $text = is_string($value) ? strtolower($value) : (is_int($value) ? (string) $value : '');

        return in_array($text, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true) ? $text : null;
    }

    private static function number(mixed $value, bool $fraction): ?string
    {
        if (is_int($value) || is_float($value)) {
            $whole = is_int($value) || floor($value) === $value;

            return is_finite((float) $value) && abs((float) $value) < 10 ** self::NUMBER_DIGITS && ($fraction || $whole)
                ? (string) $value
                : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $pattern = $fraction
            ? '/^-?\d{1,'.self::NUMBER_DIGITS.'}(\.\d{1,6})?$/'
            : '/^-?\d{1,'.self::NUMBER_DIGITS.'}$/';

        return preg_match($pattern, $value) === 1 ? $value : null;
    }

    private static function port(mixed $value): ?string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');

        return preg_match('/^\d{1,5}$/', $text) === 1 && (int) $text <= 65535 ? $text : null;
    }

    private static function identifier(mixed $value): ?string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');

        return preg_match('/^[a-z0-9._-]{1,32}$/', $text) === 1 ? $text : null;
    }

    private static function email(string $value): ?string
    {
        if (preg_match('/^([a-z0-9._+-]{1,40})@(.+)$/', $value, $m) !== 1) {
            return null;
        }

        return self::isHost($m[2]) ? $value : null;
    }

    // ------------------------------------------------------------------ hosts and addresses

    /**
     * Whether a string is a host name or an IP address that is safe to show: lower case, made of
     * labels that are plain names.
     *
     * A host that is the value of a host setting is judged loosely, because the names a managed
     * database or cache gives out are long and carry digits: a label is refused only when it is
     * long enough to be a key (40 characters) or is hexadecimal of the length of one (16 digits,
     * hyphens apart). A host inside an address is judged strictly, since a key is often put there
     * as a label of the host name: a label of 24 characters, or of 10 with two digits, is refused.
     */
    private static function isHost(string $host, bool $strict = true): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        // Digits and dots that are not an address are not a name.
        if (preg_match('/^[\d.]+$/', $host) === 1) {
            return false;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }

            $bare = str_replace('-', '', $label);

            if ($strict
                ? strlen($label) >= 24 || (strlen($label) >= 10 && preg_match_all('/\d/', $label) >= 2)
                : strlen($label) >= 40 || (strlen($bare) >= 16 && preg_match('/^[0-9a-f]+$/', $bare) === 1)) {
                return false;
            }
        }

        return true;
    }

    /** A host, with a port after it when there is one. */
    private static function isHostPort(string $value, bool $strict = true): bool
    {
        if (str_starts_with($value, '[')) {
            return preg_match('/^(\[[^\]]+\])(?::(\d{1,5}))?$/', $value, $m) === 1
                && (! isset($m[2]) || (int) $m[2] <= 65535)
                && self::isHost($m[1], $strict);
        }

        // An IPv6 address written without brackets and without a port, as the default list has.
        if (substr_count($value, ':') > 1) {
            return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if (str_contains($value, ':')) {
            [$host, $port] = explode(':', $value, 2);

            return preg_match('/^\d{1,5}$/', $port) === 1 && (int) $port <= 65535 && self::isHost($host, $strict);
        }

        return self::isHost($value, $strict);
    }

    /** A host that may begin with one dot, as a cookie domain does. */
    private static function isCookieHost(string $value): bool
    {
        $bare = ltrim($value, '.');

        return strlen($value) - strlen($bare) <= 1 && self::isHost($bare, false);
    }

    /**
     * An address with everything that could be a secret taken out, or null when that cannot be
     * done with confidence.
     */
    private static function cleanUrl(string $raw): ?string
    {
        $url = trim($raw);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1 || preg_match('~^[a-z][a-z0-9+.\-]{0,15}://~', $url) !== 1) {
            return null;
        }

        // Everything between the scheme and the last @ is user information, and a password may
        // hold any character, a slash, a question mark or a hash among them.
        $url = (string) preg_replace('~(\b[a-z][a-z0-9+.\-]*://)[^\s]*@~i', '$1', $url);

        [$scheme, $rest] = explode('://', $url, 2);

        // A fragment is dropped.
        $rest = explode('#', $rest, 2)[0];
        $query = '';

        if (str_contains($rest, '?')) {
            [$rest, $query] = explode('?', $rest, 2);
        }

        $slash = strpos($rest, '/');
        $authority = $slash === false ? $rest : substr($rest, 0, $slash);
        $path = $slash === false ? '' : substr($rest, $slash);

        if (! self::isHostPort($authority)) {
            return null;
        }

        // A path segment survives only as a short plain word, or a version such as v1, and not
        // when the word is a key of a service that issues them.
        $segments = array_map(
            fn (string $segment) => $segment === '' || (preg_match('/^(v\d{1,2}|[a-z][a-z_-]{0,23}(\.[a-z]{2,4})?)$/', $segment) === 1 && ! self::looksSecretWord($segment)) ? $segment : self::REDACTED,
            explode('/', $path),
        );

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

            // A key survives only as a short lower case word, so a bare key that is really a
            // token goes with the values.
            $key = preg_match('/^[a-z][a-z_.-]{0,23}$/', $key) === 1 && ! self::looksSecretWord($key) ? $key : self::REDACTED;

            // Every value goes, whatever it looks like.
            $pairs[] = $value === null ? $key : $key.'='.self::REDACTED;
        }

        return $scheme.'://'.$authority.implode('/', $segments).($pairs === [] ? '' : '?'.implode('&', $pairs));
    }

    // ------------------------------------------------------------------ shape

    private static function looksSecretWhole(string $value): bool
    {
        if (str_contains($value, '-----BEGIN')) {
            return true;
        }

        // A signing key's text envelope.
        if (preg_match('/(SigningKey|cborHex)/i', $value) === 1) {
            return true;
        }

        // user:password@host with no scheme.
        if (preg_match('/^[^\s:@\/]+:[^\s@\/]*@\S+$/', $value) === 1) {
            return true;
        }

        // A header or a setting that carries a credential: password=..., token: ..., Bearer ....
        if (preg_match('/(pass(word|wd)?|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|authorization)\s*[=:]\s*(?!\*\*\*)\S/i', $value) === 1
            || preg_match('/^(bearer|basic)\s+\S{8,}/i', $value) === 1) {
            return true;
        }

        // Twelve or more words of letters in a row, joined by spaces or commas, is a recovery phrase.
        $words = array_values(array_filter(preg_split('/[\s,;]+/', $value) ?: [], fn (string $w) => $w !== ''));

        if (count($words) >= 12 && count(array_filter($words, fn (string $w) => preg_match('/^[A-Za-z]{3,10}$/', $w) === 1)) === count($words)) {
            return true;
        }

        return self::looksSecretWord($value);
    }

    /** A single word with no spaces: a key, a token or an encoded blob. */
    private static function looksSecretWord(string $word): bool
    {
        // An application key, or a signing key in any of its text encodings.
        if (preg_match('/^base64:/i', $word) === 1 || preg_match('/(ed25519e?_[sx]?sk|_xprv)/i', $word) === 1) {
            return true;
        }

        // The header and payload of a signed token, or a value that is three long encoded parts.
        if (preg_match('/^eyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}(\.[A-Za-z0-9_-]*)?$/', $word) === 1
            || preg_match('/^[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}$/', $word) === 1) {
            return true;
        }

        // A random identifier, or the token of a service that issues them.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $word) === 1
            || preg_match('/^\d{1,10}\|[A-Za-z0-9]{20,}$/', $word) === 1
            || preg_match('/^key-[0-9a-zA-Z]{16,}$/', $word) === 1) {
            return true;
        }

        // A service's own key format.
        if (preg_match('/^((sk|rk|pk)[-_](live|test|proj|ant)[-_A-Za-z0-9]{8,}|xox[abprs]-[A-Za-z0-9-]{8,}|gh[pousr]_[A-Za-z0-9]{16,}|github_pat_\w{16,}|glpat-[\w-]{12,}|(AKIA|ASIA)[0-9A-Z]{12,}|AIza[\w-]{20,}|SG\.[\w-]{16,}\.[\w-]{16,}|ya29\.[\w-]{16,}|npm_[A-Za-z0-9]{16,})$/', $word) === 1) {
            return true;
        }

        // Hexadecimal of the length of a hash, an id or a key.
        if (preg_match('/^(0x)?[0-9a-fA-F]{16,}$/', $word) === 1) {
            return true;
        }

        // A signing key or an extended private key in its text encoding.
        if (preg_match('/^([a-z0-9]+_)*(sk|xsk|skey|xprv)1[a-z0-9]{20,}$/i', $word) === 1) {
            return true;
        }

        // A long run of encoded characters that mixes letters and digits.
        return self::encodedBlob($word, 24);
    }

    /**
     * Whether a string of at least $length characters is made only of the characters base64 and
     * its URL form use, and mixes upper case, lower case and digits, which a word, a path or a
     * host name does not.
     */
    private static function encodedBlob(string $text, int $length): bool
    {
        return strlen($text) >= $length
            && preg_match('/^[A-Za-z0-9+\/_=-]+$/', $text) === 1
            && preg_match('/[A-Z]/', $text) === 1
            && preg_match('/[a-z]/', $text) === 1
            && preg_match('/\d/', $text) === 1;
    }
}
