<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'name' => $this->faker->unique()->company(),
            'kind' => $this->faker->randomElement(Partner::KINDS),
            'notes' => null,
        ];
    }
}
