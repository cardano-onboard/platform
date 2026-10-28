<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The beta notice bar is opt-in: a host that does not set BETA_BANNER shows none, and a
 * host that sets it to true still does.
 */
class BetaBannerDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_config_file_declares_the_banner_off_when_the_variable_is_unset(): void
    {
        $this->assertNull(env('BETA_BANNER'));
        $this->assertFalse((require base_path('config/cardano.php'))['beta_banner']);
    }

    public function test_a_host_with_the_variable_unset_shows_no_banner(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('beta_banner', false));
    }

    public function test_a_host_that_turns_the_banner_on_shows_it(): void
    {
        config(['cardano.beta_banner' => true]);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('beta_banner', true));
    }
}
