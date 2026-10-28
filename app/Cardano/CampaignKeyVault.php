<?php

namespace App\Cardano;

use RuntimeException;
use SensitiveParameter;

/**
 * Encrypting a campaign's signing key so that it survives the things that happen to applications.
 *
 * The obvious way to do this is the framework's encrypted cast, and it is wrong here. That cast
 * uses the application key, and rotating the application key is a documented, routine, one-command
 * operation that an operator is entitled to perform. Doing so would make every campaign secret
 * unreadable at once, and a campaign whose secret cannot be read is a campaign whose funds cannot
 * be moved by anyone holding only the platform's branch. The framework's own guidance is to rotate
 * that key if it is ever exposed, so the failure is not hypothetical: it is what happens when the
 * documented response to an incident is followed.
 *
 * So campaign secrets are wrapped under their own secret, configured separately, never in the
 * database, and versioned. The version is stored beside every ciphertext rather than assumed,
 * which buys two things. A secret written under an older wrapping key still reads after a rotation,
 * so a rotation does not have to be atomic across every row. And a rotation can be verified as
 * finished by counting rows rather than by somebody asserting it is.
 *
 * The wrapping is authenticated: a ciphertext that has been altered fails to open rather than
 * opening to something else. That matters more here than usual, because the plaintext is a signing
 * key and a silently corrupted one produces signatures that verify against nothing.
 */
final class CampaignKeyVault
{
    /**
     * The envelope's own version, which is not the wrapping key's version.
     *
     * This names the construction, so that a later change of algorithm can be told apart from a
     * change of key and both can be migrated independently.
     */
    private const ENVELOPE = 'v1';

    /**
     * Wrap a secret under the current wrapping key.
     *
     * @return array{ciphertext: string, version: int}
     */
    public function wrap(#[SensitiveParameter] string $secret): array
    {
        $version = $this->currentVersion();
        $key = $this->keyFor($version);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($secret, $nonce, $key);

        sodium_memzero($key);

        return [
            // The nonce travels with the ciphertext because it must be unique per message and is
            // not secret. Reusing one under the same key is what breaks this construction, so it
            // is generated per call and never stored anywhere it could be picked up again.
            'ciphertext' => implode(':', [self::ENVELOPE, base64_encode($nonce), base64_encode($box)]),
            'version' => $version,
        ];
    }

    /**
     * Read a secret back, under whichever wrapping key it was written with.
     */
    public function unwrap(string $ciphertext, int $version): string
    {
        [$envelope, $nonce, $box] = $this->parse($ciphertext);

        if ($envelope !== self::ENVELOPE) {
            throw new RuntimeException(sprintf(
                'This campaign secret was written by envelope %s and this build knows %s.',
                $envelope,
                self::ENVELOPE,
            ));
        }

        $key = $this->keyFor($version);
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        sodium_memzero($key);

        if ($plain === false) {
            throw new RuntimeException(
                'A campaign secret did not open. Either it was altered, or it was written under a different '
                .'wrapping key than the version stored beside it claims.'
            );
        }

        return $plain;
    }

    /**
     * Move a secret from an older wrapping key to the current one.
     *
     * The old key has to still be configured for this to work, which is the reason wrapping keys
     * are kept by version rather than replaced. A rotation is therefore: add the new key, point
     * the current version at it, re-wrap every row, and only then remove the old key.
     *
     * @return array{ciphertext: string, version: int}
     */
    public function rewrap(string $ciphertext, int $fromVersion): array
    {
        $secret = $this->unwrap($ciphertext, $fromVersion);

        try {
            return $this->wrap($secret);
        } finally {
            sodium_memzero($secret);
        }
    }

    public function currentVersion(): int
    {
        $version = (int) config('campaign_script.wrapping_key_version', 0);

        if ($version < 1) {
            throw new RuntimeException(
                'No current campaign wrapping key version is configured, so a campaign secret cannot be written.'
            );
        }

        return $version;
    }

    /**
     * Whether this deployment can hold campaign secrets at all.
     *
     * Asked before a campaign is created rather than while one is being created, because the
     * failure to configure a wrapping key is recoverable and a half-created campaign is not.
     */
    public function isConfigured(): bool
    {
        try {
            $key = $this->keyFor($this->currentVersion());
            sodium_memzero($key);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function keyFor(int $version): string
    {
        $configured = config('campaign_script.wrapping_keys', []);

        $raw = $configured[$version] ?? null;

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(sprintf(
                'Campaign wrapping key version %d is not configured. A secret written under it cannot be read '
                .'until it is, and removing an old version before every row has been re-wrapped is what makes '
                .'that permanent.',
                $version,
            ));
        }

        $key = base64_decode($raw, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(sprintf(
                'Campaign wrapping key version %d is not %d base64-encoded bytes. A key of the wrong length is '
                .'refused rather than padded or hashed into shape, because either would make the mistake '
                .'invisible and permanent.',
                $version,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }

        return $key;
    }

    /**
     * @return array{string, string, string}
     */
    private function parse(string $ciphertext): array
    {
        $parts = explode(':', $ciphertext, 3);

        if (count($parts) !== 3) {
            throw new RuntimeException('A campaign secret is not in the envelope format this build writes.');
        }

        [$envelope, $nonce, $box] = $parts;

        $nonce = base64_decode($nonce, true);
        $box = base64_decode($box, true);

        if ($nonce === false || $box === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('A campaign secret is malformed and was not decrypted.');
        }

        return [$envelope, $nonce, $box];
    }
}
