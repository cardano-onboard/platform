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
                // Wait for the rejection itself rather than pausing and checking the path.
                // Staying on /login is also what happens when the submit never reaches the
                // page at all, so the path alone lets this pass while proving nothing.
                ->waitForText('These credentials do not match our records.')
                ->assertPathIs('/login')
                ->assertDontSee('Your Campaigns');
        });
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            // The user-menu activator is the only tonal button in the app bar.
            $activator = '.v-app-bar .v-btn--variant-tonal';
            $menu = '.v-overlay__content .v-list';
            // Identified by its mdi-logout icon rather than its label, which is translated.
            $logout = '.v-overlay__content .v-list-item:has(.mdi-logout)';

            $browser->loginAs($user)
                ->visit('/dashboard')
                // Wait for the Vue app to mount before touching the app bar, because a
                // bare assertSee does not wait.
                ->waitForText('Your Campaigns')
                ->waitFor($activator);

            // Establish that the browser still delivers synthesised input before asking it
            // to open the menu. A session that has stopped doing so accepts the click
            // without error and the page never sees it, which otherwise surfaces here as a
            // user menu that will not open.
            $this->assertBrowserReceivesInput($browser);

            $browser->click($activator)
                ->waitFor($menu)
                ->click($logout)
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
