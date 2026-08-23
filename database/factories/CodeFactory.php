<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Code;
use Illuminate\Database\Eloquent\Factories\Factory;

class CodeFactory extends Factory
{
    protected $model = Code::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'code' => Code::generateCode(),
            'perWallet' => 1,
            'uses' => 1,
            'lovelace' => 2000000,
        ];
    }
}
