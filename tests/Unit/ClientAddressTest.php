<?php

namespace Tests\Unit;

use App\Support\ClientAddress;
use PHPUnit\Framework\TestCase;

class ClientAddressTest extends TestCase
{
    public function test_an_ipv4_address_is_its_own_key(): void
    {
        $this->assertSame('203.0.113.7', ClientAddress::key('203.0.113.7'));
        $this->assertNotSame(ClientAddress::key('203.0.113.7'), ClientAddress::key('203.0.113.8'));
    }

    public function test_ipv6_addresses_in_one_64_share_a_key_and_another_64_does_not(): void
    {
        $key = ClientAddress::key('2001:db8:1:2::1');

        $this->assertSame($key, ClientAddress::key('2001:0db8:0001:0002:ffff:ffff:ffff:ffff'));
        $this->assertSame($key, ClientAddress::key('2001:db8:1:2:abcd::9'));
        $this->assertNotSame($key, ClientAddress::key('2001:db8:1:3::1'));
    }

    public function test_an_ipv4_address_carried_in_ipv6_counts_as_that_ipv4_address(): void
    {
        $this->assertSame('203.0.113.7', ClientAddress::key('::ffff:203.0.113.7'));
    }

    public function test_an_ipv4_compatible_address_counts_as_that_ipv4_address(): void
    {
        $this->assertSame('203.0.113.7', ClientAddress::key('::203.0.113.7'));
    }

    public function test_a_nat64_address_counts_as_its_embedded_ipv4_address(): void
    {
        $this->assertSame('198.51.100.1', ClientAddress::key('64:ff9b::198.51.100.1'));
        $this->assertNotSame(ClientAddress::key('64:ff9b::198.51.100.1'), ClientAddress::key('64:ff9b::198.51.100.2'));
    }

    public function test_the_ipv6_loopback_stays_apart_from_embedded_ipv4_addresses(): void
    {
        $this->assertSame('0000000000000000::/64', ClientAddress::key('::1'));
        $this->assertNotSame(ClientAddress::key('::1'), ClientAddress::key('::203.0.113.7'));
    }

    public function test_a_missing_address_gets_one_shared_key(): void
    {
        $this->assertSame('unknown', ClientAddress::key(null));
    }
}
