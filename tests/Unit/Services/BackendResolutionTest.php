<?php

namespace Tests\Unit\Services;

use App\Providers\AppServiceProvider;
use App\Services\NullBackend;
use App\Services\PhyrhoseBackend;
use App\Services\ProxyBackend;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BackendResolutionTest extends TestCase
{
    public function test_null_string_resolves_to_null_backend(): void
    {
        $this->assertInstanceOf(NullBackend::class, AppServiceProvider::resolveBackend('null'));
    }

    public function test_php_null_resolves_to_null_backend(): void
    {
        $this->assertInstanceOf(NullBackend::class, AppServiceProvider::resolveBackend(null));
    }

    public function test_empty_string_resolves_to_null_backend(): void
    {
        $this->assertInstanceOf(NullBackend::class, AppServiceProvider::resolveBackend(''));
    }

    public function test_proxy_resolves_to_proxy_backend(): void
    {
        $this->assertInstanceOf(ProxyBackend::class, AppServiceProvider::resolveBackend('proxy'));
    }

    public function test_phyrhose_resolves_to_phyrhose_backend(): void
    {
        $this->assertInstanceOf(PhyrhoseBackend::class, AppServiceProvider::resolveBackend('phyrhose'));
    }

    /**
     * An unrecognised name used to resolve to the custodial backend, which made every
     * misconfiguration silent. The dangerous case is a deployment naming a backend that does
     * not exist yet: it would custody funds through the very backend it was trying to stop
     * using, and Wallet::resolveBackend() stamps that choice onto each wallet for its life.
     */
    public function test_an_unknown_name_is_refused_rather_than_quietly_custodial(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('something-else');

        AppServiceProvider::resolveBackend('something-else');
    }

    public function test_the_refusal_names_the_backends_that_do_exist(): void
    {
        try {
            AppServiceProvider::resolveBackend('native');
            $this->fail('An unknown backend name should not resolve.');
        } catch (InvalidArgumentException $e) {
            foreach (['phyrhose', 'proxy', 'null'] as $known) {
                $this->assertStringContainsString($known, $e->getMessage());
            }
        }
    }
}
