<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The limit on failed sign-ins per source, across every address that source tries. The
 * per-address lockout already stops one account being guessed at; without this, one
 * source could move on to the next address each time it was locked out of the last.
 */
class LoginIpLimitTest extends TestCase
{
    use RefreshDatabase;

    private function failFrom(string $ip, int $n, array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders($headers)
            ->from('/login')
            ->post('/login', ['email' => "nobody{$n}@example.com", 'password' => 'wrong-password']);
    }

    private function signInFrom(string $ip, User $user, string $password = 'password', array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders($headers)
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    public function test_one_source_may_fail_thirty_sign_ins_an_hour_across_different_addresses(): void
    {
        for ($n = 1; $n <= 30; $n++) {
            $this->failFrom('203.0.113.20', $n)->assertSessionHasErrors('email');
        }

        $refused = $this->failFrom('203.0.113.20', 31);

        $refused->assertStatus(429);
        $refused->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $refused->headers->get('Retry-After'));
    }

    public function test_the_limit_comes_from_configuration(): void
    {
        config(['auth.login_limits.failed_per_ip_per_hour' => 3]);

        for ($n = 1; $n <= 3; $n++) {
            $this->failFrom('203.0.113.21', $n)->assertSessionHasErrors('email');
        }

        $this->failFrom('203.0.113.21', 4)->assertStatus(429);
    }

    public function test_a_different_source_is_unaffected(): void
    {
        config(['auth.login_limits.failed_per_ip_per_hour' => 3]);
        $user = User::factory()->create();

        for ($n = 1; $n <= 4; $n++) {
            $this->failFrom('203.0.113.22', $n);
        }

        $this->failFrom('203.0.113.22', 5)->assertStatus(429);

        $this->signInFrom('203.0.113.23', $user)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_a_source_past_the_limit_cannot_sign_in_even_with_the_right_password(): void
    {
        config(['auth.login_limits.failed_per_ip_per_hour' => 3]);
        $user = User::factory()->create();

        for ($n = 1; $n <= 3; $n++) {
            $this->failFrom('203.0.113.24', $n);
        }

        $this->signInFrom('203.0.113.24', $user)->assertStatus(429);
        $this->assertGuest();
    }

    public function test_successful_sign_ins_do_not_count_toward_the_limit(): void
    {
        config(['auth.login_limits.failed_per_ip_per_hour' => 3]);
        $user = User::factory()->create();

        for ($i = 0; $i < 8; $i++) {
            $this->signInFrom('203.0.113.25', $user)->assertRedirect(route('dashboard', absolute: false));
            $this->post('/logout');
        }

        // Three failures are still allowed after eight successes.
        for ($n = 1; $n <= 3; $n++) {
            $this->failFrom('203.0.113.25', $n)->assertSessionHasErrors('email');
        }
    }

    public function test_a_success_does_not_give_back_failures_already_counted(): void
    {
        config(['auth.login_limits.failed_per_ip_per_hour' => 3]);
        $user = User::factory()->create();

        $this->failFrom('203.0.113.26', 1);
        $this->failFrom('203.0.113.26', 2);
        $this->signInFrom('203.0.113.26', $user)->assertRedirect(route('dashboard', absolute: false));
        $this->post('/logout');
        $this->failFrom('203.0.113.26', 3);

        $this->failFrom('203.0.113.26', 4)->assertStatus(429);
    }

    public function test_a_form_posted_through_inertia_gets_the_wait_on_its_email_field(): void
    {
        config(['auth.login_limits.failed_per_ip_per_hour' => 2]);
        $headers = ['X-Inertia' => 'true'];

        $this->failFrom('203.0.113.27', 1, $headers);
        $this->failFrom('203.0.113.27', 2, $headers);

        $refused = $this->failFrom('203.0.113.27', 3, $headers);

        $refused->assertStatus(302);
        $refused->assertSessionHasErrors(['email' => 'Too many failed sign-in attempts. Try again in 60 minutes.']);
        $this->assertGuest();
    }

    public function test_the_per_address_lockout_still_applies_below_the_source_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->signInFrom('203.0.113.28', $user, 'wrong-password');
        }

        $locked = $this->signInFrom('203.0.113.28', $user);

        $locked->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_addresses_in_one_ipv6_network_share_the_limit_and_another_network_does_not(): void
    {
        config(['auth.login_limits.failed_per_ip_per_hour' => 3]);
        $user = User::factory()->create();

        $this->failFrom('2001:db8:9:1::1', 1);
        $this->failFrom('2001:db8:9:1:aaaa::2', 2);
        $this->failFrom('2001:db8:9:1:ffff:ffff:ffff:ffff', 3);

        $this->failFrom('2001:db8:9:1:1234::5', 4)->assertStatus(429);

        $this->signInFrom('2001:db8:9:2::1', $user)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    /**
     * The five-attempt lockout is for one account being guessed at, from one client address.
     * Somebody else in the same IPv6 network must not be able to keep it locked.
     */
    public function test_a_neighbour_in_the_same_ipv6_network_cannot_lock_an_address(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->signInFrom('2001:db8:9:1::bad', $user, 'wrong-password');
        }

        $this->signInFrom('2001:db8:9:1::bad', $user)->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->signInFrom('2001:db8:9:1:aaaa::1', $user)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    public function test_the_cache_never_holds_the_address_that_was_tried(): void
    {
        $this->failFrom('203.0.113.29', 1);

        $store = Cache::store(config('cache.limiter'))->getStore();
        $keys = array_keys((fn () => $this->storage)->call($store));

        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertStringNotContainsString('nobody1@example.com', $key);
            $this->assertStringNotContainsString(hash('sha256', 'nobody1@example.com'), $key);
        }
    }
}
