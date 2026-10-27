<?php

namespace App\Support;

use CardanoPhp\Bech32\Bech32;

/**
 * The minimum ADA a Cardano output must carry, worked out from what is in it.
 *
 * Every output on Cardano has to hold at least enough ADA to pay for the space it takes
 * up in the ledger. That floor is not a constant: it rises with the number of policies,
 * the number of assets and the length of every asset name, because all of those are bytes
 * somebody has to store. An output configured below its own floor cannot be submitted at
 * all, so a code carrying a token and a flat 1 ADA is a payment that will never leave.
 *
 * The rule, from the Babbage-era ledger (CIP-55, and babbageMinUTxOValue in
 * cardano-ledger's Cardano.Ledger.Babbage.TxOut, which Conway uses unchanged):
 *
 *     minimum = (160 + size of the serialised output in bytes) * coinsPerUtxoByte
 *
 * The 160 is the ledger's constant charge for the UTxO map entry, and the coefficient is
 * a protocol parameter, read through the transaction backend rather than hard-coded,
 * because it can be changed by a governance action and has been.
 *
 * The size is that of the output as it appears on the wire in the submitted transaction
 * body, because the Babbage and Conway ledgers decode each body output as a sized output
 * and price the bytes consumed from the wire, not a re-serialisation. An output with no
 * datum and no reference script can be written two ways: the legacy two-element array
 * [address, value], or the post-Alonzo map {0: address, 1: value}. The map form is two
 * bytes longer (a map head and two keys where the array has one head). Which form is
 * priced is a choice made in OUTPUT_FORM below: an output written in a longer form than
 * it was priced for is refused as too small, so the form has to be the one the builder
 * that submits the transaction actually emits. Sizing the output means counting that CBOR
 * here rather than by serialising a real transaction, so the numbers are deterministic
 * and testable without a chain:
 *
 *   - the output is the address and the value, in the form OUTPUT_FORM names;
 *   - an address is a byte string; its length is exact when the address is known and
 *     otherwise the configured stand-in, because a base address is 57 bytes and an
 *     enterprise one is 29;
 *   - a value with no assets is just the coin;
 *   - a value with assets is an array of the coin and a map of policy to a map of asset
 *     name to quantity, which is where policy count, name lengths and quantities all
 *     enter the figure.
 *
 * The coin is sized at its own encoding, which makes the minimum a fixed point: the
 * output has to hold a coin, the coin is written in one, two, three, five or nine bytes
 * depending on its size, and the bytes it takes are part of what the coin has to pay for.
 * forBundle() returns the smallest coin that pays for its own encoding, so an output
 * holding it is accepted and an output holding one lovelace less is not.
 *
 * NMKR-minted NFTs are not counted. They are sent by NMKR in NMKR's own transaction, to
 * the claimant directly, and carry their own minimum in that transaction rather than in
 * the output this application builds.
 *
 * One asset with a five-byte name to a base address, on mainnet at 4310 coins per byte,
 * comes out at 1,150,770 lovelace. The flat floor of 1 ADA is below that, which is the
 * bug this exists to make visible.
 */
final class MinUtxo
{
    /**
     * The wire form an output is priced in: the post-Alonzo map {0: address, 1: value}.
     *
     * The transaction backend writes every output of its payment transactions in the map
     * form: all 69 mainnet claim transactions checked (for example 4664306d4bef...7264,
     * 9642d26f172f...7d98 and 8ddc43e28328...5f69 in the set) decode to map outputs,
     * attendee payouts included. The map form is also the longer of the two, so pricing
     * for it can only ask for more than the ledger needs of an array-form output, never
     * less. Pricing for the array form would leave a code short of the minimum by
     * two bytes of coinsPerUtxoByte (8,620 lovelace at 4310) under a builder that writes
     * maps, and that payment is refused as too small.
     */
    public const OUTPUT_FORM = self::FORM_MAP;

    /** The legacy two-element array [address, value]. */
    public const FORM_ARRAY = 'array';

    /** The post-Alonzo map {0: address, 1: value}: two bytes longer than the array. */
    public const FORM_MAP = 'map';

    /** Fixed ledger overhead in bytes, added to the serialised output size. */
    public const OVERHEAD_BYTES = 160;

    /** A policy id is a blake2b-224 hash: 28 bytes, always. */
    public const POLICY_BYTES = 28;

    /** Header byte, payment credential and stake credential. */
    public const BASE_ADDRESS_BYTES = 57;

    /** Header byte and payment credential. Also the length of a reward address. */
    public const ENTERPRISE_ADDRESS_BYTES = 29;

    /**
     * Bytes a CBOR head occupies for a value or length of this size.
     *
     * The same table covers an unsigned integer, the length of a byte string and the
     * entry count of a map, because CBOR encodes all three the same way.
     */
    public static function headBytes(int $value): int
    {
        $value = max(0, $value);

        return match (true) {
            $value < 24 => 1,
            $value < 256 => 2,
            $value < 65536 => 3,
            $value < 4294967296 => 5,
            default => 9,
        };
    }

    /**
     * Bytes of a serialised multi-asset value, coin included, with the coin written as
     * the amount it actually holds.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     */
    public static function valueBytes(array $assets, int $lovelace): int
    {
        $coin = self::headBytes($lovelace);

        $grouped = self::groupByPolicy($assets);

        if ($grouped === []) {
            return $coin;
        }

        // [coin, {policy: {name: quantity}}]
        $bytes = 1 + $coin + self::headBytes(count($grouped));

        foreach ($grouped as $names) {
            $bytes += self::headBytes(self::POLICY_BYTES) + self::POLICY_BYTES;
            $bytes += self::headBytes(count($names));

            foreach ($names as $name => $quantity) {
                $nameBytes = self::hexBytes((string) $name);
                $bytes += self::headBytes($nameBytes) + $nameBytes;
                $bytes += self::headBytes($quantity);
            }
        }

        return $bytes;
    }

    /**
     * Bytes of the whole serialised output: the address and the value, the coin sized at
     * the lovelace it holds.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     */
    public static function outputBytes(array $assets, ?int $addressBytes, int $lovelace): int
    {
        $addressBytes = max(0, $addressBytes ?? self::addressBytes());

        // The array is one head byte. The map is a head byte plus the keys 0 and 1.
        $frame = self::OUTPUT_FORM === self::FORM_MAP ? 3 : 1;

        return $frame
            + self::headBytes($addressBytes) + $addressBytes
            + self::valueBytes($assets, $lovelace);
    }

    /**
     * The smallest lovelace an output holding this bundle can carry: exactly the ledger
     * minimum, with no rounding and no allowance.
     *
     * The coin's own encoding is part of what it pays for, so this walks to the fixed
     * point: size the output with the coin at its narrowest, price it, size it again with
     * the coin that came out, and stop when the price no longer changes. Each pass can
     * only widen the coin, never narrow it, so it stops after at most five.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     */
    public static function forBundle(array $assets, ?int $coinsPerUtxoByte = null, ?int $addressBytes = null): int
    {
        $coinsPerUtxoByte = max(1, $coinsPerUtxoByte ?? self::defaultCoinsPerUtxoByte());

        $coin = 0;

        for ($pass = 0; $pass < 8; $pass++) {
            $required = (self::OVERHEAD_BYTES + self::outputBytes($assets, $addressBytes, $coin)) * $coinsPerUtxoByte;

            if ($required === $coin) {
                return $coin;
            }

            $coin = $required;
        }

        // Unreachable: the coin has five widths and every pass that changes it widens it.
        throw new \LogicException('The minimum UTxO did not settle.');
    }

    /** How many distinct policies are in the bundle. */
    public static function policyCount(array $assets): int
    {
        return count(self::groupByPolicy($assets));
    }

    /**
     * The length in bytes of a bech32 address, which is what its output pays for.
     *
     * Exact, not assumed: a base address is 57, an enterprise or reward address 29, and a
     * pointer or Byron address is whatever it is.
     *
     * @throws \InvalidArgumentException when the string is not a decodable bech32 address
     */
    public static function addressBytesFor(string $address): int
    {
        try {
            [, $words] = Bech32::decode(strtolower($address));

            return intdiv(strlen(Bech32::byteArrayToHex($words)), 2);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Not a bech32 address: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The address length assumed when the recipient is not known yet, which is the case
     * for a code created before anyone has claimed it. Once the address is known, the
     * exact length is used instead.
     */
    public static function addressBytes(): int
    {
        return max(1, (int) config('cardano.min_utxo.address_bytes', 57));
    }

    public static function defaultCoinsPerUtxoByte(): int
    {
        return max(1, (int) config('cardano.min_utxo.coins_per_utxo_byte', 4310));
    }

    /**
     * Collapse a reward list into policy => asset name => quantity.
     *
     * Two rewards naming the same asset are one entry in the value, and their quantities
     * add, because that is how the ledger would see them. Rows with no policy are dropped:
     * an asset has to belong to one, and a row without it is not an asset at all.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     * @return array<string, array<string, int>>
     */
    private static function groupByPolicy(array $assets): array
    {
        $grouped = [];

        foreach ($assets as $asset) {
            $policy = is_string($asset['policy'] ?? null) ? trim($asset['policy']) : '';

            if ($policy === '') {
                continue;
            }

            $name = is_string($asset['asset'] ?? null) ? trim($asset['asset']) : '';
            $quantity = (int) ($asset['quantity'] ?? 0);

            $grouped[$policy][$name] = ($grouped[$policy][$name] ?? 0) + max(0, $quantity);
        }

        return $grouped;
    }

    /**
     * How many bytes a hex-encoded asset name is on the wire.
     *
     * Rounded up, so a string with an odd number of characters is charged for the byte it
     * is half of rather than silently costing nothing. Bad input makes the figure larger,
     * never smaller.
     */
    private static function hexBytes(string $hex): int
    {
        return (int) ceil(strlen($hex) / 2);
    }
}
