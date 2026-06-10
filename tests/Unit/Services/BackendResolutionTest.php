<?php

namespace Tests\Unit\Services;

use App\Providers\AppServiceProvider;
use App\Services\NullBackend;
use App\Services\PhyrhoseBackend;
use App\Services\ProxyBackend;
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

    public function test_unknown_value_resolves_to_phyrhose_backend(): void
    {
        $this->assertInstanceOf(PhyrhoseBackend::class, AppServiceProvider::resolveBackend('something-else'));
    }
}
