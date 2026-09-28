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
        $admin = User::updateOrCreate(
            ['email' => config('admin.email', 'admin@onboard.ninja')],
            [
                'name' => 'Admin',
                'password' => bcrypt(config('admin.password', 'password')),
                'email_verified_at' => now(),
            ]
        );

        // Force-filled because is_admin is kept out of $fillable, so that registration
        // cannot grant it. Seeding is the one place that is entitled to set it.
        $admin->forceFill(['is_admin' => true])->save();
    }
}
