<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AuthTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_login_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->logout()
                ->visit('/login')
                ->assertSee('LOG IN')
                ->assertSee('Email')
                ->assertSee('Password');
        });
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->logout()
                ->visit('/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'password')
                ->click('.v-card-actions button[type="submit"]')
                ->waitForLocation('/dashboard')
                ->assertPathIs('/dashboard')
                ->assertSee('Your Campaigns');
        });
    }

    public function test_user_cannot_login_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->logout()
                ->visit('/login')
                ->type('input[type="email"]', $user->email)
                ->type('input[type="password"]', 'wrong-password')
                ->click('.v-card-actions button[type="submit"]')
                ->pause(2000)
                ->assertPathIs('/login')
                ->assertDontSee('Your Campaigns');
        });
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->assertSee('Your Campaigns')
                // Open user menu dropdown
                ->click('.v-app-bar .v-btn--variant-tonal')
                ->waitFor('.v-overlay__content .v-list')
                ->pause(300)
                // Click the Log Out item — identified by its mdi-logout icon
                ->click('.v-overlay__content .v-list-item:has(.mdi-logout)')
                ->waitForLocation('/')
                ->assertPathIs('/');
        });
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->logout()
                ->visit('/dashboard')
                ->waitForLocation('/login')
                ->assertPathIs('/login');
        });
    }
}
