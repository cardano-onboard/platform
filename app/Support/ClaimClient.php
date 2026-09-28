<?php

namespace App\Support;

/**
 * Classifies the HTTP client a claim arrived from, and nothing else about it.
 *
 * Four wallets tested against a live mainnet claim on 8 September 2026 each presented an
 * unmistakable client library: Lace okhttp, Eternl Dalvik, VESPR axios, and Begin a plain
 * Android WebView. Matching the library is enough, so nothing here inspects the operating
 * system, the device, or a browser build.
 *
 * The wallet is a guess and is stored as one. The mapping holds only while no two wallets
 * share a client library, and okhttp names Lace today only because no other tested wallet
 * used okhttp. The WebView rule is the weakest of the four, since any wallet claiming inside
 * an Android WebView produces the same marker. That is what the catalogue of raw strings is
 * for: a client that is unrecognised, or that has quietly become ambiguous, stays
 * identifiable after the fact.
 */
final class ClaimClient
{
    /**
     * A request that carried no user agent at all, as distinct from one that carried a
     * string no rule matched. Keeping them apart is the difference between "a client we
     * have not seen before" and "something that is not a wallet".
     */
    public const ABSENT = 'absent';

    public const UNKNOWN = 'unknown';

    /**
     * Longest user agent retained. A user agent is attacker-controlled and unbounded, and
     * nothing beyond this length has ever carried signal. The value is truncated before it
     * is hashed so the fingerprint and the stored string always agree.
     */
    public const MAX_LENGTH = 512;

    /**
     * Ordered needle => [client shorthand, wallet guess]. First match wins.
     *
     * Every needle names a client library or a WebView marker. None names a version, which
     * is deliberate: Begin's Chrome build moved from 140 to 151 between two tests six weeks
     * apart, and the '; wv)' rule survived it where a version-anchored rule would not have.
     */
    private const RULES = [
        '; wv)' => ['webview', 'begin'],
        'okhttp/' => ['okhttp', 'lace'],
        'Dalvik/' => ['dalvik', 'eternl'],
        'axios/' => ['axios', 'vespr'],
    ];

    private function __construct(
        public readonly ?string $userAgent,
        public readonly string $client,
        public readonly ?string $wallet,
    ) {}

    public static function from(?string $userAgent): self
    {
        $userAgent = $userAgent === null ? null : mb_substr(trim($userAgent), 0, self::MAX_LENGTH);

        if ($userAgent === null || $userAgent === '') {
            return new self(null, self::ABSENT, null);
        }

        foreach (self::RULES as $needle => [$client, $wallet]) {
            if (str_contains($userAgent, $needle)) {
                return new self($userAgent, $client, $wallet);
            }
        }

        return new self($userAgent, self::UNKNOWN, null);
    }

    /**
     * Display names for the wallet guesses. Casing cannot be derived from the stored
     * shorthand, because VESPR writes itself in capitals and the others do not.
     */
    private const WALLET_NAMES = [
        'lace' => 'Lace',
        'eternl' => 'Eternl',
        'vespr' => 'VESPR',
        'begin' => 'Begin',
    ];

    /**
     * The wallet a stored client shorthand most likely belongs to, ready to display.
     *
     * Read from the same rules the classifier matches on, so a rule change moves both at
     * once and the tally can never disagree with the catalogue about what okhttp means.
     *
     * Null where the shorthand names no wallet, which covers 'unknown' and 'absent'. The
     * caller shows the shorthand in that case, because "okhttp" is a fact and a blank is
     * not.
     */
    public static function walletFor(string $client): ?string
    {
        foreach (self::RULES as [$shorthand, $wallet]) {
            if ($shorthand === $client) {
                return self::WALLET_NAMES[$wallet] ?? $wallet;
            }
        }

        return null;
    }

    /**
     * Content-addressed key for the catalogue. A hash rather than a surrogate id because a
     * surrogate id would record insertion order, and insertion order is claim order.
     */
    public function fingerprint(): ?string
    {
        return $this->userAgent === null ? null : hash('sha256', $this->userAgent);
    }

    /**
     * Whether this client is worth writing to the catalogue. A request with no user agent
     * has no string to catalogue.
     */
    public function hasUserAgent(): bool
    {
        return $this->userAgent !== null;
    }
}
