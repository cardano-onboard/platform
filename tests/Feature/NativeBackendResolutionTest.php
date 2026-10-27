<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\NativeBackend;
use Tests\TestCase;

/**
 * Whether a deployment may resolve the backend that holds its own keys.
 *
 * A feature test rather than a unit one because both answers depend on configuration and on the
 * container building a backend with real collaborators, and neither exists without an application.
 */
class NativeBackendResolutionTest extends TestCase
{
    public function test_it_resolves_when_it_is_the_configured_backend(): void
    {
        config([
            'campaign_script.owner_key_hashes' => [str_repeat('1', 56), str_repeat('2', 56)],
            'campaign_script.wrapping_key_version' => 1,
            'campaign_script.wrapping_keys' => [1 => base64_encode(random_bytes(32))],
        ]);

        $this->assertInstanceOf(NativeBackend::class, AppServiceProvider::resolveBackend('native'));
    }
}
