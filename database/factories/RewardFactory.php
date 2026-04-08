<?php

namespace Database\Factories;

use App\Models\Code;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardFactory extends Factory
{
    protected $model = Reward::class;

    public function definition(): array
    {
        return [
            'code_id'    => Code::factory(),
            'policy_hex' => fake()->regexify('[0-9a-f]{56}'),
            'asset_hex'  => bin2hex(fake()->word()),
            'quantity'   => fake()->numberBetween(1, 100),
        ];
    }
}
