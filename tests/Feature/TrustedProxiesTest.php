<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which connecting addresses may tell the app who the client is.
 *
 * Every request goes through the app's own global middleware, with the connecting address
 * (REMOTE_ADDR) and the forwarded headers set the way a real connection would present them.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_CLIENT = '203.0.113.50';

    private const FORGED = '198.18.0.77';

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.trusted_proxies' => '']);

        Route::get('/trusted-proxies-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'url' => url('/'),
        ]);
    }

    /**
     * What the app makes of a request that arrives from $remote carrying forged headers.
     *
     * @return array{ip: string, secure: bool, host: string, port: int, url: string}
     */
    private function probe(string $remote, string $forwardedFor = self::FORGED, ?string $proto = null, array $extra = []): array
    {
        $server = ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $forwardedFor] + $extra;

        if ($proto !== null) {
            $server['HTTP_X_FORWARDED_PROTO'] = $proto;
        }

        // An absolute URI, because the test client builds a relative one from the previous
        // request, and a trusted https request would make the next one read as secure.
        return $this->call('GET', 'http://localhost/trusted-proxies-probe', [], [], [], $server)->assertOk()->json();
    }

    // --- unset ---

    public function test_unset_ignores_a_forged_header_from_a_public_address(): void
    {
        $this->assertSame(self::PUBLIC_CLIENT, $this->probe(self::PUBLIC_CLIENT)['ip']);
    }

    #[DataProvider('privateProxies')]
    public function test_unset_believes_the_header_from_loopback_and_private_addresses(string $proxy): void
    {
        $this->assertSame(self::FORGED, $this->probe($proxy)['ip']);
    }

    public static function privateProxies(): array
    {
        return [
            'ipv4 loopback' => ['127.0.0.1'],
            'ipv6 loopback' => ['::1'],
            '10/8' => ['10.4.5.6'],
            '172.16/12' => ['172.20.0.2'],
            '192.168/16' => ['192.168.1.9'],
            'fc00::/7' => ['fd12:3456::1'],
        ];
    }

    #[DataProvider('justOutsidePrivateRanges')]
    public function test_unset_does_not_trust_addresses_just_outside_the_private_ranges(string $remote): void
    {
        $this->assertSame($remote, $this->probe($remote)['ip']);
    }

    public static function justOutsidePrivateRanges(): array
    {
        return [
            'before 172.16/12' => ['172.15.255.1'],
            'after 172.16/12' => ['172.32.0.1'],
            'public 192.169' => ['192.169.0.1'],
            'public ipv6' => ['2001:db8::1'],
        ];
    }

    #[DataProvider('blankSettings')]
    public function test_a_blank_or_separator_only_value_is_the_same_as_unset(string $setting): void
    {
        config(['security.trusted_proxies' => $setting]);

        $this->assertSame(self::PUBLIC_CLIENT, $this->probe(self::PUBLIC_CLIENT)['ip']);
        $this->assertSame(self::FORGED, $this->probe('10.0.0.5')['ip']);
    }

    public static function blankSettings(): array
    {
        return [['   '], [','], [' , ,']];
    }

    public function test_unset_takes_the_scheme_from_a_private_proxy_and_not_from_a_public_client(): void
    {
        $this->assertFalse($this->probe(self::PUBLIC_CLIENT, proto: 'https')['secure']);
        $this->assertTrue($this->probe('10.0.0.5', proto: 'https')['secure']);
    }

    // --- * ---

    public function test_a_star_believes_the_header_from_any_address(): void
    {
        config(['security.trusted_proxies' => '*']);

        $this->assertSame(self::FORGED, $this->probe(self::PUBLIC_CLIENT)['ip']);
        $this->assertSame(self::FORGED, $this->probe('10.0.0.5')['ip']);
        $this->assertTrue($this->probe(self::PUBLIC_CLIENT, proto: 'https')['secure']);
    }

    // --- a list ---

    public function test_a_list_trusts_exactly_the_addresses_and_ranges_it_names(): void
    {
        config(['security.trusted_proxies' => ' 203.0.113.9 ,198.51.100.0/24 , 2001:db8::/32 ']);

        // A single address, a CIDR range and an IPv6 range, with whitespace around each.
        $this->assertSame(self::FORGED, $this->probe('203.0.113.9')['ip']);
        $this->assertSame(self::FORGED, $this->probe('198.51.100.200')['ip']);
        $this->assertSame(self::FORGED, $this->probe('2001:db8:1::5')['ip']);

        // Next door to each is not trusted.
        $this->assertSame('203.0.113.10', $this->probe('203.0.113.10')['ip']);
        $this->assertSame('198.51.101.1', $this->probe('198.51.101.1')['ip']);
        $this->assertSame('2001:db9::1', $this->probe('2001:db9::1')['ip']);

        // The private ranges are not trusted unless listed.
        $this->assertSame('10.0.0.5', $this->probe('10.0.0.5')['ip']);
        $this->assertSame('127.0.0.1', $this->probe('127.0.0.1')['ip']);
    }

    // --- the setting is read from config ---

    public function test_the_value_is_read_from_config_on_each_request(): void
    {
        $this->assertSame(self::PUBLIC_CLIENT, $this->probe(self::PUBLIC_CLIENT)['ip']);

        config(['security.trusted_proxies' => '*']);
        $this->assertSame(self::FORGED, $this->probe(self::PUBLIC_CLIENT)['ip']);

        config(['security.trusted_proxies' => self::PUBLIC_CLIENT]);
        $this->assertSame(self::FORGED, $this->probe(self::PUBLIC_CLIENT)['ip']);
        $this->assertSame('203.0.113.51', $this->probe('203.0.113.51')['ip']);

        config(['security.trusted_proxies' => '']);
        $this->assertSame(self::PUBLIC_CLIENT, $this->probe(self::PUBLIC_CLIENT)['ip']);
    }

    // --- the per-IP limits ---

    public function test_rotating_the_header_from_a_public_address_does_not_get_past_the_per_ip_limit(): void
    {
        config(['cardano.claim_rate_per_ip' => 3, 'cardano.claim_rate_per_campaign' => 1000]);
        $campaign = Campaign::factory()->create();

        $statuses = [];

        foreach (range(1, 8) as $i) {
            $statuses[] = $this->call('POST', '/api/claim/v1/'.$campaign->id, [], [], [], [
                'REMOTE_ADDR' => self::PUBLIC_CLIENT,
                'HTTP_X_FORWARDED_FOR' => "198.18.0.$i",
                'HTTP_ACCEPT' => 'application/json',
            ])->getStatusCode();
        }

        $this->assertSame([200, 200, 200, 429, 429, 429, 429, 429], $statuses);
    }

    public function test_the_same_rotation_does_get_past_the_limit_when_every_address_is_trusted(): void
    {
        config(['security.trusted_proxies' => '*', 'cardano.claim_rate_per_ip' => 3, 'cardano.claim_rate_per_campaign' => 1000]);
        $campaign = Campaign::factory()->create();

        foreach (range(1, 8) as $i) {
            $this->call('POST', '/api/claim/v1/'.$campaign->id, [], [], [], [
                'REMOTE_ADDR' => self::PUBLIC_CLIENT,
                'HTTP_X_FORWARDED_FOR' => "198.18.0.$i",
                'HTTP_ACCEPT' => 'application/json',
            ])->assertOk();
        }
    }

    // --- the shipped default ---

    public function test_the_shipped_default_is_blank_and_behaves_as_the_private_ranges(): void
    {
        // The default as written in the config file. Reading the file rather than config()
        // means an environment that sets TRUSTED_PROXIES cannot hide a changed default.
        $this->assertSame(1, preg_match("/'trusted_proxies' => env\('TRUSTED_PROXIES', '([^']*)'\)/", file_get_contents(config_path('security.php')), $match));
        $shipped = $match[1];
        $this->assertSame('', $shipped);

        config(['security.trusted_proxies' => $shipped]);
        $this->assertSame(self::PUBLIC_CLIENT, $this->probe(self::PUBLIC_CLIENT)['ip']);
        $this->assertSame(self::FORGED, $this->probe('10.0.0.5')['ip']);
    }

    // --- IPv4-mapped peers ---

    #[DataProvider('mappedPeers')]
    public function test_unset_trusts_the_ipv4_mapped_form_of_the_private_ranges(string $remote, bool $trusted): void
    {
        $this->assertSame($trusted ? self::FORGED : $remote, $this->probe($remote)['ip']);
    }

    public static function mappedPeers(): array
    {
        return [
            'mapped loopback' => ['::ffff:127.0.0.1', true],
            'mapped loopback neighbour' => ['::ffff:127.0.0.2', false],
            'below mapped 10/8' => ['::ffff:9.255.255.255', false],
            'mapped 10/8 low' => ['::ffff:10.0.0.0', true],
            'mapped 10/8 high' => ['::ffff:10.255.255.255', true],
            'above mapped 10/8' => ['::ffff:11.0.0.1', false],
            'below mapped 172.16/12' => ['::ffff:172.15.255.255', false],
            'mapped 172.16/12 low' => ['::ffff:172.16.0.0', true],
            'mapped 172.16/12 high' => ['::ffff:172.31.255.255', true],
            'above mapped 172.16/12' => ['::ffff:172.32.0.0', false],
            'below mapped 192.168/16' => ['::ffff:192.167.255.255', false],
            'mapped 192.168/16 low' => ['::ffff:192.168.0.0', true],
            'mapped 192.168/16 high' => ['::ffff:192.168.255.255', true],
            'above mapped 192.168/16' => ['::ffff:192.169.0.1', false],
            'mapped public' => ['::ffff:203.0.113.5', false],
        ];
    }

    // --- entries that are not addresses ---

    #[DataProvider('invalidEntries')]
    public function test_an_entry_that_is_not_acceptable_is_dropped_and_nothing_else_is_trusted_in_its_place(string $setting): void
    {
        config(['security.trusted_proxies' => $setting]);

        $this->assertSame(self::PUBLIC_CLIENT, $this->probe(self::PUBLIC_CLIENT)['ip']);
        $this->assertSame('10.0.0.5', $this->probe('10.0.0.5')['ip']);
        $this->assertSame('127.0.0.1', $this->probe('127.0.0.1')['ip']);
        $this->assertSame('::ffff:10.0.0.5', $this->probe('::ffff:10.0.0.5')['ip']);
    }

    public static function invalidEntries(): array
    {
        // A value with nothing acceptable in it trusts nothing. The last three keep one
        // valid entry, and trust is exactly that entry.
        return [
            'host name' => ['nginx'],
            'star inside a list' => ['*,'],
            'REMOTE_ADDR' => ['REMOTE_ADDR'],
            'PRIVATE_SUBNETS' => ['PRIVATE_SUBNETS'],
            'double star' => ['**'],
            'ipv4 prefix too long' => ['10.0.0.0/33'],
            'ipv6 prefix too long' => ['::1/129'],
            'negative prefix' => ['10.0.0.0/-1'],
            'two slashes' => ['10.0.0.0/8/8'],
            'ipv4 zero prefix' => ['0.0.0.0/0'],
            'ipv6 zero prefix' => ['::/0'],
            'zero prefix on a host' => ['10.0.0.0/0'],
            'doubled zero prefix' => ['10.0.0.0/00'],
            'leading zero prefix' => ['10.0.0.0/032'],
            'leading zero prefix, ipv6' => ['2001:db8::/064'],
            'mapped address with an ipv4 prefix' => ['::ffff:10.0.0.5/32'],
            'mapped range with an ipv4 prefix' => ['::ffff:10.0.0.0/8'],
            'star beside a valid entry' => ['*, 192.0.2.1'],
            'host name beside a valid entry' => ['nginx,192.0.2.1'],
            'zero prefix beside a valid entry' => ['0.0.0.0/0, 192.0.2.1'],
        ];
    }

    public function test_a_value_with_nothing_valid_trusts_nothing_even_where_the_platform_would_trust_everything(): void
    {
        $_SERVER['LARAVEL_CLOUD'] = '1';

        try {
            config(['security.trusted_proxies' => 'nginx']);

            $this->assertSame(self::PUBLIC_CLIENT, $this->probe(self::PUBLIC_CLIENT)['ip']);
            $this->assertSame('10.0.0.5', $this->probe('10.0.0.5')['ip']);
        } finally {
            unset($_SERVER['LARAVEL_CLOUD']);
        }
    }

    public function test_a_list_entry_that_is_valid_still_works_beside_dropped_ones(): void
    {
        config(['security.trusted_proxies' => 'nginx, *, REMOTE_ADDR, 203.0.113.0/24']);

        $this->assertSame(self::FORGED, $this->probe(self::PUBLIC_CLIENT)['ip']);
        $this->assertSame('198.51.100.9', $this->probe('198.51.100.9')['ip']);
    }

    public function test_a_lone_host_entry_at_the_maximum_prefix_is_accepted(): void
    {
        config(['security.trusted_proxies' => '203.0.113.7/32, 2001:db8::5/128']);

        $this->assertSame(self::FORGED, $this->probe('203.0.113.7')['ip']);
        $this->assertSame('203.0.113.8', $this->probe('203.0.113.8')['ip']);
        $this->assertSame(self::FORGED, $this->probe('2001:db8::5')['ip']);
        $this->assertSame('2001:db8::6', $this->probe('2001:db8::6')['ip']);
    }

    public function test_a_star_with_spaces_around_it_is_a_star(): void
    {
        config(['security.trusted_proxies' => '  *  ']);

        $this->assertSame(self::FORGED, $this->probe(self::PUBLIC_CLIENT)['ip']);
    }

    // --- an IPv4 entry covers its mapped form ---

    public function test_an_ipv4_entry_also_trusts_the_mapped_form_of_that_address_and_only_that(): void
    {
        config(['security.trusted_proxies' => '10.0.0.5']);

        $this->assertSame(self::FORGED, $this->probe('10.0.0.5')['ip']);
        $this->assertSame(self::FORGED, $this->probe('::ffff:10.0.0.5')['ip']);
        $this->assertSame('::ffff:10.0.0.6', $this->probe('::ffff:10.0.0.6')['ip']);
        $this->assertSame('::1', $this->probe('::1')['ip']);
        $this->assertSame('::ffff:8.8.8.8', $this->probe('::ffff:8.8.8.8')['ip']);
    }

    public function test_an_ipv4_range_also_trusts_the_mapped_form_of_that_range_and_only_that(): void
    {
        config(['security.trusted_proxies' => '10.0.0.0/24']);

        $this->assertSame(self::FORGED, $this->probe('::ffff:10.0.0.200')['ip']);
        $this->assertSame('::ffff:10.0.1.1', $this->probe('::ffff:10.0.1.1')['ip']);
        $this->assertSame('::ffff:8.8.8.8', $this->probe('::ffff:8.8.8.8')['ip']);
        $this->assertSame('::1', $this->probe('::1')['ip']);
    }

    // --- the Forwarded header ---

    public function test_the_forwarded_header_is_never_trusted(): void
    {
        foreach (['', '*'] as $setting) {
            config(['security.trusted_proxies' => $setting]);

            $seen = $this->probe('10.0.0.5', extra: ['HTTP_FORWARDED' => 'for=198.18.0.99;proto=https;host=evil.example']);

            $this->assertSame(self::FORGED, $seen['ip'], "with TRUSTED_PROXIES='{$setting}'");
            $this->assertSame('localhost', $seen['host']);
            $this->assertFalse($seen['secure']);
        }
    }

    // --- X-Forwarded-Host, -Port and -Prefix ---

    public function test_the_forwarded_host_and_port_are_ignored_from_an_untrusted_address(): void
    {
        $seen = $this->probe(self::PUBLIC_CLIENT, extra: ['HTTP_X_FORWARDED_HOST' => 'evil.example', 'HTTP_X_FORWARDED_PORT' => '4444']);

        $this->assertSame('localhost', $seen['host']);
        $this->assertSame(80, $seen['port']);
        $this->assertSame('http://localhost', $seen['url']);
    }

    public function test_the_forwarded_host_and_port_are_used_from_a_trusted_address(): void
    {
        $seen = $this->probe('10.0.0.5', extra: ['HTTP_X_FORWARDED_HOST' => 'app.example', 'HTTP_X_FORWARDED_PORT' => '8443']);

        $this->assertSame('app.example', $seen['host']);
        $this->assertSame(8443, $seen['port']);
    }

    public function test_the_forwarded_prefix_is_ignored_even_from_a_trusted_address(): void
    {
        foreach (['', '*'] as $setting) {
            config(['security.trusted_proxies' => $setting]);

            $seen = $this->probe('10.0.0.5', extra: ['HTTP_X_FORWARDED_PREFIX' => '/evilprefix']);

            $this->assertSame('http://localhost', $seen['url'], "with TRUSTED_PROXIES='{$setting}'");
        }
    }

    // --- an appended chain from a same-host proxy ---

    public function test_a_public_client_behind_an_appending_same_host_proxy_cannot_forge_its_address(): void
    {
        // The proxy appends the address it saw to whatever the client sent.
        $this->assertSame(self::PUBLIC_CLIENT, $this->probe('127.0.0.1', self::FORGED.', '.self::PUBLIC_CLIENT)['ip']);

        // The right-most untrusted entry wins, and trusted hops after it are skipped.
        $this->assertSame(self::PUBLIC_CLIENT, $this->probe('127.0.0.1', '198.18.0.1, '.self::PUBLIC_CLIENT.', 10.0.0.9')['ip']);
    }

    // --- a list set in code ---

    public function test_a_list_set_with_at_is_honoured_over_the_config_value(): void
    {
        try {
            \Illuminate\Http\Middleware\TrustProxies::at([self::PUBLIC_CLIENT]);

            $this->assertSame(self::FORGED, $this->probe(self::PUBLIC_CLIENT)['ip']);
            $this->assertSame('10.0.0.5', $this->probe('10.0.0.5')['ip']);
        } finally {
            \Illuminate\Http\Middleware\TrustProxies::flushState();
        }
    }
}
