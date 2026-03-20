<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'address'     => 'addr_test1qz' . fake()->regexify('[a-z0-9]{50}'),
            'key'         => fake()->uuid(),
            'backend'     => 'null',
        ];
    }
}
