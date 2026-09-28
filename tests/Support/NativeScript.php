<?php

namespace Tests\Support;

use CardanoPhp\Bech32\Bech32;
use InvalidArgumentException;

/**
 * A native script, in the only form a freeze can be checked against: exact CBOR
 * bytes, a script hash, and an address.
 *
 * This lives in tests/ and not in app/ on purpose. Nothing in the application
 * builds a native script, and nothing should until the shape frozen in
 * NativeScriptShapesTest has had a second reader. What is being frozen is a
 * byte layout, and a test is where a byte layout is held.
 *
 * Encoding follows the Shelley-MA ledger CDDL:
 *
 *   native_script  = script_pubkey / script_all / script_any / script_n_of_k
 *                  / invalid_before / invalid_hereafter
 *   script_pubkey  = (0, addr_keyhash)
 *   script_all     = (1, [* native_script])
 *   script_any     = (2, [* native_script])
 *   script_n_of_k  = (3, int, [* native_script])
 *   invalid_before = (4, slot_no)      cardano-cli calls this "after"
 *   invalid_hereafter = (5, slot_no)   cardano-cli calls this "before"
 *
 * Every head is written in the shortest form that holds the value, and every
 * array is definite length. Both match what the ledger emits for scripts of this
 * size, and both change the hash if they are wrong.
 */
final class NativeScript
{
    /** A key hash is 28 bytes, written as 56 lowercase hex characters. */
    private const KEY_HASH = '/^[0-9a-f]{56}$/';

    private function __construct(
        private readonly string $type,
        private readonly array $scripts = [],
        private readonly ?string $keyHash = null,
        private readonly ?int $slot = null,
        private readonly ?int $required = null,
    ) {}

    public static function sig(string $keyHashHex): self
    {
        if (! preg_match(self::KEY_HASH, $keyHashHex)) {
            throw new InvalidArgumentException(
                'A key hash must be exactly 56 lowercase hex characters, got: '.$keyHashHex
            );
        }

        return new self('sig', keyHash: $keyHashHex);
    }

    public static function all(self ...$scripts): self
    {
        return new self('all', self::atLeastOne($scripts, 'all'));
    }

    public static function any(self ...$scripts): self
    {
        return new self('any', self::atLeastOne($scripts, 'any'));
    }

    public static function atLeast(int $required, self ...$scripts): self
    {
        $scripts = self::atLeastOne($scripts, 'atLeast');

        if ($required < 1 || $required > count($scripts)) {
            throw new InvalidArgumentException(
                'atLeast requires between 1 and '.count($scripts).' signatures, got: '.$required
            );
        }

        return new self('atLeast', $scripts, required: $required);
    }

    /** Valid only in slots strictly below $slot. Encodes as invalid_hereafter, tag 5. */
    public static function before(int $slot): self
    {
        return new self('before', slot: self::slot($slot));
    }

    /** Valid only in slots at or above $slot. Encodes as invalid_before, tag 4. */
    public static function after(int $slot): self
    {
        return new self('after', slot: self::slot($slot));
    }

    /** The structure cardano-cli reads from a --script-file, ready for json_encode. */
    public function toArray(): array
    {
        return match ($this->type) {
            'sig' => ['type' => 'sig', 'keyHash' => $this->keyHash],
            'before', 'after' => ['type' => $this->type, 'slot' => $this->slot],
            'atLeast' => [
                'type' => 'atLeast',
                'required' => $this->required,
                'scripts' => array_map(fn (self $s) => $s->toArray(), $this->scripts),
            ],
            default => [
                'type' => $this->type,
                'scripts' => array_map(fn (self $s) => $s->toArray(), $this->scripts),
            ],
        };
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** The serialized script, as raw bytes. */
    public function cbor(): string
    {
        return match ($this->type) {
            'sig' => self::array(self::uint(0), self::bytes(hex2bin($this->keyHash))),
            'all' => self::array(self::uint(1), $this->cborScriptList()),
            'any' => self::array(self::uint(2), $this->cborScriptList()),
            'atLeast' => self::array(self::uint(3), self::uint($this->required), $this->cborScriptList()),
            'after' => self::array(self::uint(4), self::uint($this->slot)),
            'before' => self::array(self::uint(5), self::uint($this->slot)),
        };
    }

    public function cborHex(): string
    {
        return bin2hex($this->cbor());
    }

    /**
     * blake2b-224 over the language tag byte 0x00 followed by the script CBOR.
     * The tag separates native scripts from the Plutus versions, which use 0x01
     * upwards over the same hash. Dropping it yields a hash that is wrong and
     * still looks like a script hash.
     */
    public function hash(): string
    {
        return sodium_crypto_generichash("\x00".$this->cbor(), '', 28);
    }

    public function hashHex(): string
    {
        return bin2hex($this->hash());
    }

    /**
     * The address this script pays to.
     *
     * With no stake key hash this is an enterprise address, CIP-19 type 7, whose
     * only input is the script. With one it is a base address, CIP-19 type 1,
     * which is a different address for the same script.
     */
    public function address(string $network, ?string $stakeKeyHashHex = null): string
    {
        $networkId = match ($network) {
            'mainnet' => 1,
            'preprod', 'preview', 'testnet' => 0,
            default => throw new InvalidArgumentException('Unknown network: '.$network),
        };

        if ($stakeKeyHashHex === null) {
            $payload = chr(0x70 | $networkId).$this->hash();
        } else {
            if (! preg_match(self::KEY_HASH, $stakeKeyHashHex)) {
                throw new InvalidArgumentException(
                    'A stake key hash must be exactly 56 lowercase hex characters, got: '.$stakeKeyHashHex
                );
            }

            $payload = chr(0x10 | $networkId).$this->hash().hex2bin($stakeKeyHashHex);
        }

        return Bech32::encode(
            $networkId === 1 ? 'addr' : 'addr_test',
            Bech32::hexToByteArray(bin2hex($payload))
        );
    }

    /**
     * The key hashes that can satisfy this script without a time bound, in the
     * order they appear.
     *
     * This is the property the two variants exist to express: the unbounded
     * signer is whoever owns the money. Reading it off the JSON is easy to get
     * wrong once the nesting is more than one level deep, which is exactly where
     * a mistake would not be noticed.
     */
    public function unboundedSigners(): array
    {
        $unbounded = [];
        $bound = [];
        $this->collectSigners(false, $unbounded, $bound);

        return $unbounded;
    }

    /** The key hashes that can only sign inside a time bound, in the order they appear. */
    public function timeBoundSigners(): array
    {
        $unbounded = [];
        $bound = [];
        $this->collectSigners(false, $unbounded, $bound);

        return $bound;
    }

    /** True when every way of satisfying this sub-script involves a time bound. */
    private function isTimeBound(): bool
    {
        return match ($this->type) {
            'sig' => false,
            'before', 'after' => true,
            // Every branch has to be satisfied, so one bounded branch binds all of them.
            'all' => array_reduce($this->scripts, fn (bool $c, self $s) => $c || $s->isTimeBound(), false),
            // The branches are alternatives, so an unbounded one escapes the bound.
            'any' => array_reduce($this->scripts, fn (bool $c, self $s) => $c && $s->isTimeBound(), true),
            // Bounded unless enough unbounded branches remain to meet the threshold.
            'atLeast' => count(array_filter($this->scripts, fn (self $s) => ! $s->isTimeBound())) < $this->required,
        };
    }

    /**
     * @param  string[]  $unbounded
     * @param  string[]  $bound
     */
    private function collectSigners(bool $bounded, array &$unbounded, array &$bound): void
    {
        if ($this->type === 'sig') {
            if ($bounded) {
                $bound[] = $this->keyHash;
            } else {
                $unbounded[] = $this->keyHash;
            }

            return;
        }

        if ($this->type === 'before' || $this->type === 'after') {
            return;
        }

        $childrenAreBounded = $bounded || ($this->type !== 'any' && $this->isTimeBound());

        foreach ($this->scripts as $script) {
            $script->collectSigners($childrenAreBounded, $unbounded, $bound);
        }
    }

    /** @param  self[]  $scripts */
    private static function atLeastOne(array $scripts, string $type): array
    {
        if ($scripts === []) {
            throw new InvalidArgumentException($type.' needs at least one sub-script');
        }

        return $scripts;
    }

    private static function slot(int $slot): int
    {
        if ($slot < 0) {
            throw new InvalidArgumentException('A slot number cannot be negative, got: '.$slot);
        }

        return $slot;
    }

    private function cborScriptList(): string
    {
        return self::array(...array_map(fn (self $s) => $s->cbor(), $this->scripts));
    }

    /** A CBOR head: the major type in the top three bits, the argument in the shortest form that holds it. */
    private static function head(int $major, int $argument): string
    {
        $base = $major << 5;

        return match (true) {
            $argument < 24 => chr($base | $argument),
            $argument <= 0xFF => chr($base | 24).chr($argument),
            $argument <= 0xFFFF => chr($base | 25).pack('n', $argument),
            $argument <= 0xFFFFFFFF => chr($base | 26).pack('N', $argument),
            default => chr($base | 27).pack('J', $argument),
        };
    }

    private static function uint(int $value): string
    {
        return self::head(0, $value);
    }

    private static function bytes(string $raw): string
    {
        return self::head(2, strlen($raw)).$raw;
    }

    private static function array(string ...$items): string
    {
        return self::head(4, count($items)).implode('', $items);
    }
}
