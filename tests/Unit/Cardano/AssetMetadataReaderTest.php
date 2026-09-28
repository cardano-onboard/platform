<?php

namespace Tests\Unit\Cardano;

use App\Cardano\AssetMetadataReader;
use PHPUnit\Framework\TestCase;

/**
 * Rows are shaped like Koios asset_info answers, including the two read live on mainnet
 * for USDM (CIP-0068 333 and the registry) and an NFTxLV 2023 NFT (CIP-0025 version 1).
 */
class AssetMetadataReaderTest extends TestCase
{
    private const POLICY = 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad';

    private static function bytes(string $text): array
    {
        return ['bytes' => bin2hex($text)];
    }

    private static function entry(string $key, array $value): array
    {
        return ['k' => self::bytes($key), 'v' => $value];
    }

    /** The (100) reference datum for a 333 token, as Koios returns it. */
    private static function ftDatum(array $entries, int $version = 1): array
    {
        return [
            'constructor' => 0,
            'fields' => [
                ['map' => $entries],
                ['int' => $version],
                ['constructor' => 0, 'fields' => []],
            ],
        ];
    }

    public function test_the_off_chain_registry_is_read_first(): void
    {
        $info = AssetMetadataReader::read([
            'policy_id' => self::POLICY,
            'asset_name' => '0014df105553444d',
            'asset_name_ascii' => 'USDM',
            'token_registry_metadata' => ['name' => 'USDM', 'ticker' => 'USDM', 'decimals' => 6, 'logo' => 'iVBOR'],
            'cip68_metadata' => ['333' => self::ftDatum([self::entry('ticker', self::bytes('WRONG'))])],
        ]);

        $this->assertSame('registry', $info['source']);
        $this->assertSame('USDM', $info['ticker']);
        $this->assertSame(6, $info['decimals']);
        $this->assertSame('iVBOR', $info['logo']);
    }

    // CIP-0068 §333: name, ticker and decimals in the reference datum's first field.
    public function test_a_333_token_without_a_registry_entry_reads_its_reference_datum(): void
    {
        $info = AssetMetadataReader::read([
            'policy_id' => self::POLICY,
            'asset_name' => '0014df10'.bin2hex('Coin'),
            'asset_name_ascii' => 'Coin',
            'token_registry_metadata' => null,
            'cip68_metadata' => ['333' => self::ftDatum([
                self::entry('name', self::bytes('Coin Token')),
                self::entry('ticker', self::bytes('COIN')),
                self::entry('decimals', ['int' => 6]),
                self::entry('logo', self::bytes('ipfs://Qm')),
            ])],
        ]);

        $this->assertSame('cip68', $info['source']);
        $this->assertSame('Coin Token', $info['name']);
        $this->assertSame('COIN', $info['ticker']);
        $this->assertSame(6, $info['decimals']);
        // A URI would mean hotlinking third-party storage, so it is not read as a logo.
        $this->assertNull($info['logo']);
    }

    // CIP-0068 version 4: "721" => policy => asset name without its label.
    public function test_a_version_4_datum_is_read_through_its_nested_map(): void
    {
        $name = bin2hex('Budz1');

        $info = AssetMetadataReader::read([
            'policy_id' => self::POLICY,
            'asset_name' => '000de140'.$name,
            'asset_name_ascii' => null,
            'cip68_metadata' => ['222' => self::ftDatum([
                self::entry('721', ['map' => [[
                    'k' => ['bytes' => self::POLICY],
                    'v' => ['map' => [
                        ['k' => ['bytes' => bin2hex('Other')], 'v' => ['map' => [self::entry('name', self::bytes('Not this one'))]]],
                        ['k' => ['bytes' => $name], 'v' => ['map' => [self::entry('name', self::bytes('Budz #1'))]]],
                    ]],
                ]]]),
            ], 4)],
        ]);

        $this->assertSame('cip68', $info['source']);
        $this->assertSame('Budz #1', $info['name']);
    }

    public function test_a_datum_under_a_label_the_token_does_not_carry_is_ignored(): void
    {
        $info = AssetMetadataReader::read([
            'policy_id' => self::POLICY,
            'asset_name' => bin2hex('Plain'),
            'asset_name_ascii' => 'Plain',
            'cip68_metadata' => ['333' => self::ftDatum([self::entry('ticker', self::bytes('X'))])],
        ]);

        $this->assertSame('chain', $info['source']);
        $this->assertNull($info['ticker']);
        $this->assertSame('Plain', $info['name']);
    }

    // CIP-0025 version 1: the minting transaction names many assets; read only this one.
    public function test_a_cip25_version_1_nft_is_named_from_its_own_entry(): void
    {
        $policy = '12ee2b385da7440849df6c3c4a77af15e8f3cec46f533b22269e7fdf';

        $info = AssetMetadataReader::read([
            'policy_id' => $policy,
            'asset_name' => bin2hex('NFTxLV23aeoniumskyGateway286'),
            'asset_name_ascii' => 'NFTxLV23aeoniumskyGateway286',
            'token_registry_metadata' => null,
            'cip68_metadata' => null,
            'minting_tx_metadata' => ['721' => [$policy => [
                'NFTxLV23aeoniumskyGateway054' => ['name' => 'Gateway to NFTxLV 2023 054/200'],
                'NFTxLV23aeoniumskyGateway286' => [
                    'name' => 'Gateway to NFTxLV 2023 286/200',
                    'description' => ['A commemorative NFT ', 'celebrating NFTxLV 2023'],
                ],
            ]]],
        ]);

        $this->assertSame('cip25', $info['source']);
        $this->assertSame('Gateway to NFTxLV 2023 286/200', $info['name']);
        $this->assertSame('A commemorative NFT celebrating NFTxLV 2023', $info['description']);
        $this->assertSame(0, $info['decimals']);
    }

    // CIP-0025 version 2 keys the asset by its raw bytes, and Koios reads metadata from
    // db-sync, which writes a bytes key as "0x" followed by its hex.
    public function test_a_cip25_version_2_nft_is_found_by_its_0x_hex_keys(): void
    {
        $hex = 'ff00'.bin2hex('x');

        $info = AssetMetadataReader::read([
            'policy_id' => self::POLICY,
            'asset_name' => $hex,
            'asset_name_ascii' => null,
            'minting_tx_metadata' => ['721' => ['version' => 2, '0x'.self::POLICY => ['0x'.$hex => ['name' => 'Binary']]]],
        ]);

        $this->assertSame('cip25', $info['source']);
        $this->assertSame('Binary', $info['name']);
    }

    public function test_an_asset_with_no_metadata_keeps_its_ascii_name(): void
    {
        $info = AssetMetadataReader::read([
            'policy_id' => self::POLICY,
            'asset_name' => bin2hex('Bare'),
            'asset_name_ascii' => 'Bare',
            'minting_tx_metadata' => ['674' => ['msg' => ['hi']]],
        ]);

        $this->assertSame('chain', $info['source']);
        $this->assertSame('Bare', $info['name']);
    }
}
