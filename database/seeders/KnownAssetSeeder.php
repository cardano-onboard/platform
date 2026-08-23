<?php

namespace Database\Seeders;

use App\Models\KnownAsset;
use Illuminate\Database\Seeder;

class KnownAssetSeeder extends Seeder
{
    /**
     * Seed the shared registry with well-known native assets so users can add common
     * reward tokens by ticker without knowing policy/asset hex — mainnet tokens plus a
     * couple of preprod tokens so testnet demos resolve names/decimals out of the box.
     * The scheduled assets:sync-registry command keeps the full registry current.
     * Values verified against the Koios token registry.
     */
    public function run(): void
    {
        $assets = [
            ['network' => 'mainnet', 'ticker' => 'HOSKY', 'name' => 'HOSKY Token', 'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235', 'asset_name' => '484f534b59', 'decimals' => 0],
            ['network' => 'mainnet', 'ticker' => 'MIN', 'name' => 'Minswap', 'policy_id' => '29d222ce763455e3d7a09a665ce554f00ac89d2e99a1a83d267170c6', 'asset_name' => '4d494e', 'decimals' => 6],
            ['network' => 'mainnet', 'ticker' => 'SUNDAE', 'name' => 'SUNDAE', 'policy_id' => '9a9693a9a37912a5097918f97918d15240c92ab729a0b7c4aa144d77', 'asset_name' => '53554e444145', 'decimals' => 6],
            ['network' => 'mainnet', 'ticker' => 'iUSD', 'name' => 'iUSD', 'policy_id' => 'f66d78b4a3cb3d37afa0ec36461e51ecbde00f26c8f0a68f94b69880', 'asset_name' => '69555344', 'decimals' => 6],
            ['network' => 'mainnet', 'ticker' => 'DJED', 'name' => 'Djed USD', 'policy_id' => '8db269c3ec630e06ae29f74bc39edd1f87c819f1056206e879a1cd61', 'asset_name' => '446a65644d6963726f555344', 'decimals' => 6],
            ['network' => 'mainnet', 'ticker' => 'USDM', 'name' => 'USDM', 'policy_id' => 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad', 'asset_name' => '0014df105553444d', 'decimals' => 6],
            ['network' => 'preprod', 'ticker' => 'tDRIP', 'name' => 'tDRIP', 'policy_id' => '698a6ea0ca99f315034072af31eaac6ec11fe8558d3f48e9775aab9d', 'asset_name' => '7444524950', 'decimals' => 6],
            ['network' => 'preprod', 'ticker' => 'tUSDM', 'name' => 'tUSDM', 'policy_id' => '16a55b2a349361ff88c03788f93e1e966e5d689605d044fef722ddde', 'asset_name' => '0014df10745553444d', 'decimals' => 6],
        ];

        foreach ($assets as $asset) {
            KnownAsset::updateOrCreate(
                [
                    'policy_id' => $asset['policy_id'],
                    'asset_name' => $asset['asset_name'],
                    'network' => $asset['network'],
                ],
                [
                    'ticker' => $asset['ticker'],
                    'name' => $asset['name'],
                    'decimals' => $asset['decimals'],
                ]
            );
        }
    }
}
