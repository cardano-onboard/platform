<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client-supplied X-Forwarded-Prefix must never reach the URL root. Symfony prepends a
 * trusted prefix to the base URL without checking it, so "@evil.example" made every link
 * the app generated resolve to that host in a browser. The password reset link, which only
 * the hosted edition sends, is covered by Auth\PasswordResetForwardedPrefixTest.
 */
class ForwardedPrefixTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forwarded_prefix_does_not_change_the_links_on_a_page(): void
    {
        $this->get('http://localhost/login', ['X-Forwarded-Prefix' => '@evil.example'])
            ->assertOk()
            ->assertDontSee('evil.example', false);
    }

    public function test_a_forwarded_scheme_is_still_honoured(): void
    {
        $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertHeader('Strict-Transport-Security');
    }
}
