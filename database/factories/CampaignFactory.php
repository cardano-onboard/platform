<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'name'           => fake()->unique()->words(3, true),
            'description'    => fake()->sentence(),
            'start_date'     => now()->subDay()->toDateString(),
            'end_date'       => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
            'network'        => 'preprod',
            'txn_msg'        => null,
        ];
    }

    public function mainnet(): static
    {
        return $this->state(['network' => 'mainnet']);
    }

    public function onePerWallet(): static
    {
        return $this->state(['one_per_wallet' => true]);
    }

    public function expired(): static
    {
        return $this->state([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date'   => now()->subDay()->toDateString(),
        ]);
    }

    public function future(): static
    {
        return $this->state([
            'start_date' => now()->addDay()->toDateString(),
            'end_date'   => now()->addMonth()->toDateString(),
        ]);
    }
}
