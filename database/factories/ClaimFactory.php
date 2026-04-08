<?php

namespace Database\Factories;

use App\Models\Claim;
use App\Models\Code;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClaimFactory extends Factory
{
    protected $model = Claim::class;

    public function definition(): array
    {
        return [
            'code_id'     => Code::factory(),
            'address'     => 'addr_test1qz' . fake()->regexify('[a-z0-9]{50}'),
            'stake_key'   => 'stake_test1uz' . fake()->regexify('[a-z0-9]{50}'),
        ];
    }

    public function withTransaction(): static
    {
        return $this->state([
            'transaction_id'   => fake()->uuid(),
            'transaction_hash' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'transaction_id'   => fake()->uuid(),
            'transaction_hash' => fake()->sha256(),
            'status'           => 'completed',
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status'      => 'failed',
            'retry_count' => 5,
        ]);
    }
}
