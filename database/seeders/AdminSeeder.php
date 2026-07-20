<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => config('admin.email', 'admin@onboard.ninja')],
            [
                'name' => 'Admin',
                'password' => bcrypt(config('admin.password', 'password')),
                'email_verified_at' => now(),
            ]
        );
    }
}
