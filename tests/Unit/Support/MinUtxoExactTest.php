<?php

namespace Tests\Unit\Support;

use App\Support\MinUtxo;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Primitives\AssetBundle;
use Cardano\Transaction\Primitives\MultiAsset;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The exact minimum, recomputed without the code under test.
 *
 * The output is built with the transaction package's own CBOR encoder, then measured the way
 * the ledger measures it (babbageMinUTxOValue): 160 plus the size of the output as it is written
 * into the transaction body. The transaction package writes the post-Alonzo map form
 * {0: address, 1: value}, the form MinUtxo::OUTPUT_FORM prices, so the encoded length is used
 * as it comes, with nothing added or taken off.
 *
 * "Exact" is a two-sided claim, and both sides are asserted: the amount returned pays for its
 * own serialisation, and one lovelace less does not pay for its own.
 */
class MinUtxoExactTest extends TestCase
{
    // CIP-19 test vectors: a base address (57 bytes) and an enterprise address (29 bytes).
    private const BASE = 'addr1qx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer3n0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgse35a3x';

    private const ENTERPRISE = 'addr1vx2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzers66hrl8';

    /** Lovelace the ledger requires of this output, measured from real CBOR. */
    private function required(string $address, int $coin, array $bundles, int $perByte): int
    {
        $assets = $bundles === [] ? null : MultiAsset::of($bundles);
        $output = TransactionOutput::create(
            Address::fromBech32($address)->toBytes(),
            Value::of($coin, $assets)
        );

        return (160 + strlen($output->encode())) * $perByte;
    }

    private function bundle(string $policyByte, array $names, int $quantity = 1): AssetBundle
    {
        return AssetBundle::of(
            hex2bin(str_repeat($policyByte, 28)),
            array_map(static fn ($name) => [$name, (string) $quantity], $names),
        );
    }

    /** The same spec in the shape the application holds a reward. */
    private function rows(array $spec): array
    {
        $rows = [];

        foreach ($spec as [$policyByte, $names, $quantity]) {
            foreach ($names as $name) {
                $rows[] = [
                    'policy' => str_repeat($policyByte, 28),
                    'asset' => bin2hex($name),
                    'quantity' => $quantity,
                ];
            }
        }

        return $rows;
    }

    public static function shapes(): array
    {
        return [
            'enterprise, ada only' => [self::ENTERPRISE, []],
            'base, ada only' => [self::BASE, []],
            'enterprise, one asset' => [self::ENTERPRISE, [['11', ['HOSKY'], 1]]],
            'base, one asset' => [self::BASE, [['11', ['HOSKY'], 1]]],
            'base, one asset, 32 byte name' => [self::BASE, [['11', [str_repeat('N', 32)], 1]]],
            'base, one asset, empty name' => [self::BASE, [['11', [''], 1]]],
            'base, three assets, one policy' => [self::BASE, [['11', ['AAAA', 'BBBBBBBBBBBB', str_repeat('C', 20)], 250]]],
            'enterprise, three policies' => [self::ENTERPRISE, [['11', ['AAAA'], 1], ['22', ['BBBB'], 1_000_000], ['33', [str_repeat('D', 32)], 5_000_000_000]]],
            'base, long names across two policies' => [self::BASE, [['11', [str_repeat('E', 32), str_repeat('F', 31)], 1], ['22', [str_repeat('G', 30)], 70_000]]],
            'base, a quantity past 32 bits' => [self::BASE, [['11', ['BIG'], 45_000_000_000_000_000]]],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_minimum_pays_for_its_own_encoding_and_a_lovelace_less_does_not(string $address, array $spec): void
    {
        foreach ([4310, 4400, 3000, 1] as $perByte) {
            $bundles = array_map(fn ($s) => $this->bundle($s[0], $s[1], $s[2]), $spec);

            $minimum = MinUtxo::forBundle($this->rows($spec), $perByte, MinUtxo::addressBytesFor($address));

            $this->assertSame(
                $minimum,
                $this->required($address, $minimum, $bundles, $perByte),
                'The amount returned has to be exactly what its own serialised size costs.'
            );
            $this->assertLessThan(
                $this->required($address, $minimum - 1, $bundles, $perByte),
                $minimum - 1,
                'One lovelace less has to be refused by the ledger.'
            );
        }
    }

    public function test_the_length_of_a_real_address_is_read_from_the_address(): void
    {
        $this->assertSame(57, MinUtxo::addressBytesFor(self::BASE));
        $this->assertSame(29, MinUtxo::addressBytesFor(self::ENTERPRISE));
    }

    public function test_an_enterprise_recipient_costs_exactly_the_address_difference(): void
    {
        $rows = [['policy' => str_repeat('11', 28), 'asset' => bin2hex('HOSKY'), 'quantity' => 1]];

        $base = MinUtxo::forBundle($rows, 4310, MinUtxo::addressBytesFor(self::BASE));
        $enterprise = MinUtxo::forBundle($rows, 4310, MinUtxo::addressBytesFor(self::ENTERPRISE));

        // 28 fewer address bytes, and the byte string head is 2 bytes for both.
        $this->assertSame(28 * 4310, $base - $enterprise);
    }

    public function test_a_coin_that_crosses_a_width_boundary_is_priced_at_the_width_it_ends_up_with(): void
    {
        // At one lovelace per byte the minimum is a couple of hundred, a two byte coin; at 4310 it
        // is past 65,535 and takes five; the fixed point must use the width of the answer, not
        // of the starting guess.
        $small = MinUtxo::forBundle([], 1, 57);
        $this->assertSame(160 + 3 + 2 + 57 + 2, $small);
        $this->assertSame(3 + 2 + 57 + 2, MinUtxo::outputBytes([], 57, $small));

        $large = MinUtxo::forBundle([], 4310, 57);
        $this->assertSame(3 + 2 + 57 + 5, MinUtxo::outputBytes([], 57, $large));
    }
}
