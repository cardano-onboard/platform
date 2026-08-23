<?php

namespace Database\Factories;

use App\Models\KnownAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnownAssetFactory extends Factory
{
    protected $model = KnownAsset::class;

    public function definition(): array
    {
        $ticker = strtoupper(fake()->unique()->lexify('???'));

        return [
            'ticker' => $ticker,
            'name' => $ticker.' Token',
            'policy_id' => fake()->regexify('[0-9a-f]{56}'),
            'asset_name' => bin2hex($ticker),
            'fingerprint' => 'asset'.fake()->regexify('[0-9a-z]{38}'),
            'decimals' => fake()->randomElement([0, 6]),
            'logo' => null,
            'description' => null,
            'network' => 'mainnet',
            'metadata' => null,
        ];
    }
}
