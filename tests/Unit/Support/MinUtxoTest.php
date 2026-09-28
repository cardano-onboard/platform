<?php

namespace Tests\Unit\Support;

use App\Support\MinUtxo;
use Tests\TestCase;

/**
 * The minimum-UTxO arithmetic, against figures worked out by hand from the CBOR the node
 * would receive rather than from what the implementation happens to return.
 *
 * Every expected number below is written as its byte count multiplied by the coefficient,
 * so a change to the model has to be argued for in bytes instead of being absorbed by
 * updating a magic number.
 *
 * Asset names are real ones, hex-encoded as the database stores them. A test that only
 * ever passes a nameless asset would not notice that name length is part of the figure,
 * which is the whole reason a flat floor is wrong.
 */
class MinUtxoTest extends TestCase
{
    /** Mainnet at the time of writing. */
    private const COEFFICIENT = 4310;

    /** A base address: header, payment part, staking part. */
    private const ADDRESS_BYTES = 57;

    private function hex(string $name): string
    {
        return bin2hex($name);
    }

    public function test_an_output_with_no_assets_costs_its_address_and_a_coin(): void
    {
        // 1 array head + 2 byte-string head + 57 address + 9 coin = 69 bytes.
        $this->assertSame(69, MinUtxo::outputBytes([], self::ADDRESS_BYTES));
        $this->assertSame(
            (160 + 69) * self::COEFFICIENT,
            MinUtxo::forBundle([], self::COEFFICIENT, self::ADDRESS_BYTES)
        );
    }

    public function test_one_native_asset_needs_more_than_the_flat_one_ada_floor(): void
    {
        $bundle = [[
            'policy' => str_repeat('ab', 28),
            'asset' => $this->hex('HOSKY'),
            'quantity' => 1,
        ]];

        // value: 1 array + 9 coin + 1 map + 30 policy + 1 map + 6 name + 1 quantity = 49
        // output: 1 + 2 + 57 + 49 = 109
        $this->assertSame(109, MinUtxo::outputBytes($bundle, self::ADDRESS_BYTES));

        $minimum = MinUtxo::forBundle($bundle, self::COEFFICIENT, self::ADDRESS_BYTES);

        $this->assertSame(1_159_390, $minimum);
        $this->assertGreaterThan(
            1_000_000,
            $minimum,
            'A code carrying one token cannot be paid with the flat 1 ADA floor this form used to accept.'
        );
    }

    public function test_a_longer_asset_name_costs_more(): void
    {
        $short = [['policy' => str_repeat('ab', 28), 'asset' => $this->hex('AB'), 'quantity' => 1]];
        $long = [['policy' => str_repeat('ab', 28), 'asset' => $this->hex(str_repeat('N', 32)), 'quantity' => 1]];

        $this->assertSame(
            1_280_070,
            MinUtxo::forBundle($long, self::COEFFICIENT, self::ADDRESS_BYTES)
        );
        $this->assertGreaterThan(
            MinUtxo::forBundle($short, self::COEFFICIENT, self::ADDRESS_BYTES),
            MinUtxo::forBundle($long, self::COEFFICIENT, self::ADDRESS_BYTES)
        );
    }

    public function test_a_second_policy_costs_a_whole_policy_id(): void
    {
        $bundle = [
            ['policy' => str_repeat('11', 28), 'asset' => $this->hex('SUNDAE01'), 'quantity' => 1_000_000],
            ['policy' => str_repeat('22', 28), 'asset' => $this->hex('MINSWAP1'), 'quantity' => 1_000_000],
        ];

        // value: 1 + 9 + 1 map + 2 * (30 policy + 1 map + 9 name + 5 quantity) = 101
        // output: 1 + 2 + 57 + 101 = 161
        $this->assertSame(161, MinUtxo::outputBytes($bundle, self::ADDRESS_BYTES));
        $this->assertSame(
            (160 + 161) * self::COEFFICIENT,
            MinUtxo::forBundle($bundle, self::COEFFICIENT, self::ADDRESS_BYTES)
        );
        $this->assertSame(2, MinUtxo::policyCount($bundle));
    }

    public function test_three_assets_under_one_policy_pay_for_one_policy_id(): void
    {
        $onePolicy = [
            ['policy' => str_repeat('33', 28), 'asset' => $this->hex('AAAA'), 'quantity' => 1],
            ['policy' => str_repeat('33', 28), 'asset' => $this->hex('BBBBBBBBBBBB'), 'quantity' => 250],
            ['policy' => str_repeat('33', 28), 'asset' => $this->hex('CCCCCCCCCCCCCCCCCCCC'), 'quantity' => 1_000_000_000],
        ];

        // value: 1 + 9 + 1 map + 30 policy + 1 map + (5+1) + (13+2) + (21+5) = 89
        // output: 1 + 2 + 57 + 89 = 149
        $this->assertSame(149, MinUtxo::outputBytes($onePolicy, self::ADDRESS_BYTES));
        $this->assertSame(1_331_790, MinUtxo::forBundle($onePolicy, self::COEFFICIENT, self::ADDRESS_BYTES));
        $this->assertSame(1, MinUtxo::policyCount($onePolicy));

        $threePolicies = [
            ['policy' => str_repeat('33', 28), 'asset' => $this->hex('AAAA'), 'quantity' => 1],
            ['policy' => str_repeat('44', 28), 'asset' => $this->hex('BBBBBBBBBBBB'), 'quantity' => 250],
            ['policy' => str_repeat('55', 28), 'asset' => $this->hex('CCCCCCCCCCCCCCCCCCCC'), 'quantity' => 1_000_000_000],
        ];

        $this->assertGreaterThan(
            MinUtxo::forBundle($onePolicy, self::COEFFICIENT, self::ADDRESS_BYTES),
            MinUtxo::forBundle($threePolicies, self::COEFFICIENT, self::ADDRESS_BYTES),
            'Spreading the same three assets over three policies adds two policy ids to the output.'
        );
    }

    public function test_a_larger_quantity_takes_more_bytes(): void
    {
        $policy = str_repeat('ab', 28);
        $name = $this->hex('BIGNUM');

        $small = MinUtxo::forBundle(
            [['policy' => $policy, 'asset' => $name, 'quantity' => 1]],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );
        $huge = MinUtxo::forBundle(
            [['policy' => $policy, 'asset' => $name, 'quantity' => 45_000_000_000_000_000]],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );

        // 1 byte of head for the small quantity, 9 for one that needs all eight payload bytes.
        $this->assertSame(8 * self::COEFFICIENT, $huge - $small);
    }

    public function test_two_rows_naming_the_same_asset_are_one_entry(): void
    {
        $policy = str_repeat('ab', 28);
        $name = $this->hex('SAME');

        $once = MinUtxo::forBundle(
            [['policy' => $policy, 'asset' => $name, 'quantity' => 2]],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );
        $twice = MinUtxo::forBundle(
            [
                ['policy' => $policy, 'asset' => $name, 'quantity' => 1],
                ['policy' => $policy, 'asset' => $name, 'quantity' => 1],
            ],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );

        $this->assertSame($once, $twice, 'The ledger sees one entry whose quantities have been added.');
    }

    public function test_rows_without_a_policy_are_not_assets(): void
    {
        $bundle = [
            ['policy' => null, 'asset' => $this->hex('ORPHAN'), 'quantity' => 5],
            ['policy' => '   ', 'asset' => $this->hex('BLANK'), 'quantity' => 5],
            ['asset' => $this->hex('MISSING'), 'quantity' => 5],
        ];

        $this->assertSame(0, MinUtxo::policyCount($bundle));
        $this->assertSame(
            MinUtxo::forBundle([], self::COEFFICIENT, self::ADDRESS_BYTES),
            MinUtxo::forBundle($bundle, self::COEFFICIENT, self::ADDRESS_BYTES)
        );
    }

    public function test_malformed_input_never_lowers_the_figure(): void
    {
        $policy = str_repeat('ab', 28);

        $clean = MinUtxo::forBundle(
            [['policy' => $policy, 'asset' => $this->hex('AB'), 'quantity' => 1]],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );

        // An odd number of hex characters is half a byte the sender still has to carry.
        $odd = MinUtxo::forBundle(
            [['policy' => $policy, 'asset' => '41424', 'quantity' => 1]],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );
        $this->assertGreaterThanOrEqual($clean, $odd);

        // A negative quantity is nonsense, and is charged for as a zero rather than
        // credited back against the size of the output.
        $negative = MinUtxo::forBundle(
            [['policy' => $policy, 'asset' => $this->hex('AB'), 'quantity' => -500]],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );
        $this->assertSame($clean, $negative);

        // A missing quantity is not a missing asset.
        $absent = MinUtxo::forBundle(
            [['policy' => $policy, 'asset' => $this->hex('AB')]],
            self::COEFFICIENT,
            self::ADDRESS_BYTES
        );
        $this->assertSame($clean, $absent);
    }

    public function test_the_figure_scales_with_the_protocol_parameter(): void
    {
        $bundle = [['policy' => str_repeat('ab', 28), 'asset' => $this->hex('HOSKY'), 'quantity' => 1]];

        $this->assertSame(
            2 * MinUtxo::forBundle($bundle, self::COEFFICIENT, self::ADDRESS_BYTES),
            MinUtxo::forBundle($bundle, 2 * self::COEFFICIENT, self::ADDRESS_BYTES),
            'Doubling coins per byte doubles the minimum, which is why it is read rather than assumed.'
        );
    }

    public function test_a_nonsense_coefficient_does_not_make_an_output_free(): void
    {
        $bundle = [['policy' => str_repeat('ab', 28), 'asset' => $this->hex('HOSKY'), 'quantity' => 1]];

        $this->assertGreaterThan(0, MinUtxo::forBundle($bundle, 0, self::ADDRESS_BYTES));
        $this->assertGreaterThan(0, MinUtxo::forBundle($bundle, -4310, self::ADDRESS_BYTES));
    }

    public function test_cbor_head_widths_change_at_the_documented_boundaries(): void
    {
        $this->assertSame(1, MinUtxo::headBytes(0));
        $this->assertSame(1, MinUtxo::headBytes(23));
        $this->assertSame(2, MinUtxo::headBytes(24));
        $this->assertSame(2, MinUtxo::headBytes(255));
        $this->assertSame(3, MinUtxo::headBytes(256));
        $this->assertSame(3, MinUtxo::headBytes(65535));
        $this->assertSame(5, MinUtxo::headBytes(65536));
        $this->assertSame(5, MinUtxo::headBytes(4294967295));
        $this->assertSame(9, MinUtxo::headBytes(4294967296));
        $this->assertSame(1, MinUtxo::headBytes(-1));
    }

    public function test_the_headroom_and_recommendation_come_from_configuration(): void
    {
        config(['cardano.min_utxo.headroom_lovelace' => 2_000_000]);

        $bundle = [['policy' => str_repeat('ab', 28), 'asset' => $this->hex('HOSKY'), 'quantity' => 1]];

        $this->assertSame(2_000_000, MinUtxo::headroom());
        $this->assertSame(
            MinUtxo::forBundle($bundle, self::COEFFICIENT, self::ADDRESS_BYTES) + 2_000_000,
            MinUtxo::recommended($bundle, self::COEFFICIENT, self::ADDRESS_BYTES)
        );
    }
}
