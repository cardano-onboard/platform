<?php

namespace App\Support;

/**
 * The minimum ADA a Cardano output must carry, worked out from what is in it.
 *
 * Every output on Cardano has to hold at least enough ADA to pay for the space it takes
 * up in the ledger. That floor is not a constant: it rises with the number of policies,
 * the number of assets and the length of every asset name, because all of those are bytes
 * somebody has to store. An output configured below its own floor cannot be submitted at
 * all, so a code carrying a token and a flat 1 ADA is a payment that will never leave.
 *
 * The rule, from the Babbage-era ledger:
 *
 *     minimum = (overhead + size of the serialised output in bytes) * coinsPerUtxoByte
 *
 * The overhead is 160 bytes and the coefficient is a protocol parameter, read through the
 * transaction backend rather than hard-coded, because it can be changed by a governance
 * action and has been.
 *
 * Sizing the output means counting the CBOR the node would receive. That is done here
 * rather than by serialising a real transaction, so the numbers are deterministic and
 * testable without a chain:
 *
 *   - the output is an array of two things, the address and the value;
 *   - an address is a byte string, and the length assumed for it is configurable because
 *     a base address with a staking part is longer than an enterprise one;
 *   - a value with no assets is just the coin;
 *   - a value with assets is an array of the coin and a map of policy to a map of asset
 *     name to quantity, which is where policy count, name lengths and quantities all
 *     enter the figure.
 *
 * The coin is sized at its widest encoding rather than at the amount being configured.
 * Sizing it at the amount would be circular, since the amount is what this is being used
 * to decide, and the error runs in the safe direction: four bytes, about 0.017 ADA.
 *
 * NMKR-minted NFTs are not counted. They are sent by NMKR in NMKR's own transaction, to
 * the claimant directly, and carry their own minimum in that transaction rather than in
 * the output this application builds.
 *
 * One asset with a five-byte name, on mainnet at 4310 coins per byte, comes out at
 * 1,159,390 lovelace. The flat floor of 1 ADA is below that, which is the bug this
 * exists to make visible.
 */
final class MinUtxo
{
    /** Fixed ledger overhead in bytes, added to the serialised output size. */
    public const OVERHEAD_BYTES = 160;

    /** A policy id is a blake2b-224 hash: 28 bytes, always. */
    public const POLICY_BYTES = 28;

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
     * Bytes of a serialised multi-asset value, coin included.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     */
    public static function valueBytes(array $assets): int
    {
        // The widest an unsigned integer gets: one head byte plus eight of payload.
        $coin = 9;

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
     * Bytes of the whole serialised output: the address and the value.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     */
    public static function outputBytes(array $assets, ?int $addressBytes = null): int
    {
        $addressBytes = max(0, $addressBytes ?? self::addressBytes());

        return 1
            + self::headBytes($addressBytes) + $addressBytes
            + self::valueBytes($assets);
    }

    /**
     * The minimum lovelace an output holding this bundle must carry.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     */
    public static function forBundle(array $assets, ?int $coinsPerUtxoByte = null, ?int $addressBytes = null): int
    {
        $coinsPerUtxoByte = max(1, $coinsPerUtxoByte ?? self::defaultCoinsPerUtxoByte());

        return (self::OVERHEAD_BYTES + self::outputBytes($assets, $addressBytes)) * $coinsPerUtxoByte;
    }

    /**
     * How much above the minimum a recipient should be given.
     *
     * An output sitting exactly on its floor is stuck: spending it means paying a fee out
     * of ADA the output is not allowed to drop below, so the only way to move the token is
     * to bring ADA from somewhere else. For somebody whose first wallet is the one they
     * opened at the booth, somewhere else does not exist.
     */
    public static function headroom(): int
    {
        return max(0, (int) config('cardano.min_utxo.headroom_lovelace', 1_000_000));
    }

    /** The minimum plus the headroom: what a code should actually be set to. */
    public static function recommended(array $assets, ?int $coinsPerUtxoByte = null, ?int $addressBytes = null): int
    {
        return self::forBundle($assets, $coinsPerUtxoByte, $addressBytes) + self::headroom();
    }

    /** How many distinct policies are in the bundle. */
    public static function policyCount(array $assets): int
    {
        return count(self::groupByPolicy($assets));
    }

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
