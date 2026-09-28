<?php

namespace Tests\Feature\Console;

use CardanoPhp\Bech32\Bech32;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * smoke:check against a faked deployment. Each test starts from a deployment on which
 * every check passes and breaks exactly one thing, so a check that stopped looking at
 * what it names would leave its test green only if it also stopped failing here.
 */
class SmokeCheckTest extends TestCase
{
    private const BASE = 'https://app.example.test';

    private const CLAIM = 'https://claim.example.test';

    private const CAMPAIGN = '01jabcdefghjkmnpqrstvwxyz0';

    /** What the faked deployment does. Tests change one entry and run the command. */
    private array $deployment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->deployment = [
            'page_body' => '<html><head><script type="module" src="/build/assets/app-1.js"></script>'
                .'<link rel="stylesheet" href="https://assets.example.test/build/assets/app-1.css"></head>'
                .'<body><div id="app" data-page="{}"></div></body></html>',
            'page_status' => 200,
            'headers' => [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Content-Security-Policy' => "default-src 'self'",
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            ],
            'broken_asset' => null,
            'preflight_origin' => ['api' => '*', 'short' => '*'],
            'preflight_status' => 204,
            'preflight_methods' => 'GET, POST',
            'asset_content_type' => 'text/javascript',
            'code_api_status' => 401,
            'unknown_campaign_status' => 404,
            'enterprise_refused' => true,
            'enterprise_message' => true,
            'malformed_code' => 400,
            'campaign_network' => 'mainnet',
            'offline' => false,
        ];

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->respond($request));
    }

    public function test_a_healthy_deployment_passes_every_check(): void
    {
        [$exit, $output] = $this->smoke();

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertStringContainsString('17 checks, 0 failed.', $output);
    }

    public function test_without_a_campaign_or_claim_url_only_the_general_checks_run(): void
    {
        [$exit, $output] = $this->smoke(claimUrl: false, campaign: false);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('10 checks, 0 failed.', $output);
        $this->assertStringNotContainsString('short claim route', $output);
        $this->assertStringNotContainsString('refuses a malformed address', $output);
    }

    public function test_a_page_that_is_not_the_application_fails(): void
    {
        $this->deployment['page_body'] = '<html><body>Site not found</body></html>';

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'home page');
        $this->assertFailed($output, 'built assets');
    }

    public function test_a_page_answering_an_error_fails(): void
    {
        $this->deployment['page_status'] = 500;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'login page');
    }

    public function test_a_missing_asset_fails(): void
    {
        $this->deployment['broken_asset'] = 'https://assets.example.test/build/assets/app-1.css';

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'built assets');
        $this->assertStringContainsString('1 of 2 failed', $output);
    }

    public function test_a_missing_security_header_fails(): void
    {
        unset($this->deployment['headers']['Strict-Transport-Security']);

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'security headers');
        $this->assertStringContainsString('Strict-Transport-Security missing', $output);
    }

    public function test_a_weakened_frame_policy_fails(): void
    {
        $this->deployment['headers']['X-Frame-Options'] = 'SAMEORIGIN';

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'security headers');
    }

    public function test_a_short_claim_route_without_cors_fails(): void
    {
        // What production answers before the fix: the router's bare 200 to OPTIONS.
        $this->deployment['preflight_origin']['short'] = null;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'CORS preflight, claim host v1 path');
        $this->assertPassed($output, 'CORS preflight, claim API');
    }

    public function test_a_code_api_that_redirects_to_login_fails(): void
    {
        $this->deployment['code_api_status'] = 302;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'code API refuses no token (create)');
        $this->assertFailed($output, 'code API refuses no token (status)');
        // Reported as the redirect, not as the login page it leads to.
        $this->assertStringContainsString('answered 302, expected 401', $output);
    }

    public function test_an_unknown_campaign_that_is_not_refused_fails(): void
    {
        $this->deployment['unknown_campaign_status'] = 200;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'claim API refuses unknown campaign');
    }

    public function test_an_accepted_enterprise_address_fails(): void
    {
        // A deployment without the base-address rule looks the code up instead.
        $this->deployment['enterprise_refused'] = false;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'claim API refuses an enterprise address');
        $this->assertFailed($output, 'short claim route refuses an enterprise address');
        $this->assertPassed($output, 'claim API refuses a malformed address');
    }

    public function test_an_enterprise_refusal_that_does_not_say_why_fails(): void
    {
        // A wallet shows the message; without it the claimant sees only that the address
        // was refused, with nothing to tell them which address would be accepted.
        $this->deployment['enterprise_message'] = false;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'claim API refuses an enterprise address');
    }

    public function test_a_testnet_campaign_is_checked_with_a_testnet_address(): void
    {
        $this->deployment['campaign_network'] = 'preprod';

        [$exit, $output] = $this->smoke();

        $this->assertSame(0, $exit, $output);
        $this->assertPassed($output, 'claim API answers an unknown code with notfound');
    }

    public function test_an_unreachable_deployment_fails_without_throwing(): void
    {
        $this->deployment['offline'] = true;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'home page');
        $this->assertStringNotContainsString('PASS', $output);
    }

    /** @return array<string, array{int}> */
    public static function codeApiStatuses(): array
    {
        return ['not found' => [404], 'server error' => [500], 'forbidden' => [403]];
    }

    /**
     * @dataProvider codeApiStatuses
     */
    public function test_a_code_api_answering_anything_but_401_fails(int $status): void
    {
        $this->deployment['code_api_status'] = $status;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'code API refuses no token (create)');
        $this->assertFailed($output, 'code API refuses no token (status)');
    }

    public function test_an_unknown_campaign_answering_a_server_error_fails(): void
    {
        $this->deployment['unknown_campaign_status'] = 500;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'claim API refuses unknown campaign');
    }

    public function test_a_preflight_allowing_a_different_origin_fails(): void
    {
        $this->deployment['preflight_origin']['api'] = 'https://evil.example';
        $this->deployment['preflight_status'] = 200;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'CORS preflight, claim API');
        $this->assertPassed($output, 'CORS preflight, claim host v1 path');
    }

    public function test_a_preflight_echoing_the_requesting_origin_passes(): void
    {
        $this->deployment['preflight_origin']['api'] = 'https://smoke-check.invalid';

        [$exit, $output] = $this->smoke();

        $this->assertSame(0, $exit, $output);
    }

    public function test_a_preflight_refused_with_the_header_set_fails(): void
    {
        $this->deployment['preflight_status'] = 403;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'CORS preflight, claim API');
        $this->assertFailed($output, 'CORS preflight, claim host v1 path');
    }

    public function test_a_preflight_that_does_not_allow_post_fails(): void
    {
        $this->deployment['preflight_methods'] = 'GET';

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'CORS preflight, claim API');
    }

    public function test_a_preflight_without_allowed_methods_fails(): void
    {
        $this->deployment['preflight_methods'] = null;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'CORS preflight, claim API');
    }

    public function test_a_refusal_with_the_right_status_and_the_wrong_code_fails(): void
    {
        $this->deployment['malformed_code'] = 404;

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'claim API refuses a malformed address');
        $this->assertPassed($output, 'claim API refuses an enterprise address');
    }

    public function test_an_asset_served_as_html_fails(): void
    {
        // A missing file behind a catch-all route: the application's page, with a 200.
        $this->deployment['asset_content_type'] = 'text/html; charset=UTF-8';

        [$exit, $output] = $this->smoke();

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'built assets');
    }

    public function test_claim_url_without_a_campaign_says_the_route_was_not_verified(): void
    {
        [$exit, $output] = $this->smoke(campaign: false);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('NOTE  --claim-url was given without --campaign', $output);
    }

    public function test_with_a_campaign_there_is_no_unverified_route_note(): void
    {
        [, $output] = $this->smoke();

        $this->assertStringNotContainsString('NOTE', $output);
    }

    public function test_a_network_sends_only_the_matching_address(): void
    {
        $this->deployment['campaign_network'] = 'preprod';

        [$exit, $output] = $this->smoke(network: 'preprod');

        $this->assertSame(0, $exit, $output);
        $this->assertPassed($output, 'claim API answers an unknown code with notfound');

        $addresses = Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), self::CAMPAIGN))
            ->map(fn (Request $r) => $r['address'])
            ->filter(fn (string $a) => strlen($a) > 70);

        $this->assertCount(2, $addresses);

        foreach ($addresses as $address) {
            $this->assertStringStartsWith('addr_test1', $address);
        }
    }

    public function test_a_wrong_network_is_not_retried(): void
    {
        // Told mainnet, the command sends only the mainnet address, so a preprod campaign
        // answers invalidnetwork and the check fails rather than quietly retrying.
        $this->deployment['campaign_network'] = 'preprod';

        [$exit, $output] = $this->smoke(network: 'mainnet');

        $this->assertSame(1, $exit);
        $this->assertFailed($output, 'claim API answers an unknown code with notfound');
    }

    public function test_an_unknown_network_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('smoke:check', ['base_url' => self::BASE, '--network' => 'testnet']));
        Http::assertNothingSent();
    }

    public function test_a_base_url_that_is_not_absolute_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('smoke:check', ['base_url' => 'app.example.test']));
        Http::assertNothingSent();
    }

    public function test_it_sends_nothing_that_could_create_or_spend(): void
    {
        $this->smoke();

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);

        $this->assertNotEmpty($sent);

        foreach ($sent as $request) {
            $this->assertFalse($request->hasHeader('Authorization'), $request->url());
            $this->assertContains($request->method(), ['GET', 'OPTIONS', 'POST'], $request->url());

            if ($request->method() !== 'POST') {
                continue;
            }

            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/codes')) {
                // The code API is only ever asked without a token, which it refuses.
                continue;
            }

            // Every claim either names a campaign that does not exist or carries a code
            // made up for the run and an address no wallet holds.
            $this->assertMatchesRegularExpression('#^/(api/claim/)?v1/[0-9A-Za-z]{26}$#', $path);

            if (! str_ends_with($path, self::CAMPAIGN)) {
                continue;
            }

            $this->assertStringStartsWith('smoke-', $request['code']);

            if ($request['address'] === 'addr1smokecheck') {
                continue;
            }

            $decoded = Bech32::decodeCardanoAddress($request['address']);
            $this->assertSame(str_repeat('0', 56), $decoded['paymentHash']);
            $this->assertContains($decoded['stakingHash'], ['', str_repeat('0', 56)]);
        }
    }

    /** @return array{int, string} */
    private function smoke(bool $claimUrl = true, bool $campaign = true, ?string $network = null): array
    {
        $arguments = ['base_url' => self::BASE.'/'];

        if ($network !== null) {
            $arguments['--network'] = $network;
        }

        if ($claimUrl) {
            $arguments['--claim-url'] = self::CLAIM;
        }

        if ($campaign) {
            $arguments['--campaign'] = self::CAMPAIGN;
        }

        $exit = Artisan::call('smoke:check', $arguments);

        return [$exit, Artisan::output()];
    }

    private function assertFailed(string $output, string $check): void
    {
        $this->assertMatchesRegularExpression('/^FAIL  '.preg_quote($check, '/').':/m', $output, $output);
    }

    private function assertPassed(string $output, string $check): void
    {
        $this->assertMatchesRegularExpression('/^PASS  '.preg_quote($check, '/').':/m', $output, $output);
    }

    private function respond(Request $request)
    {
        $d = $this->deployment;

        if ($d['offline']) {
            throw new ConnectionException('Could not resolve host');
        }

        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $isClaimHost = str_starts_with($url, self::CLAIM);

        if ($request->method() === 'OPTIONS') {
            $origin = $d['preflight_origin'][$isClaimHost ? 'short' : 'api'];

            return $origin === null
                ? Http::response('', 200)
                : Http::response('', $d['preflight_status'], array_filter([
                    'Access-Control-Allow-Origin' => $origin,
                    'Access-Control-Allow-Methods' => $d['preflight_methods'],
                ]));
        }

        if (str_contains($path, '/build/assets/')) {
            return $url === $d['broken_asset']
                ? Http::response('Not Found', 404)
                : Http::response('/* asset */', 200, ['Content-Type' => $d['asset_content_type']]);
        }

        if (in_array($path, ['/', '/login', '/ada/now'], true)) {
            $status = $path === '/login' ? $d['page_status'] : 200;

            return Http::response($d['page_body'], $status, $d['headers']);
        }

        if ($path === '/up') {
            return Http::response('ok', 200);
        }

        if (str_starts_with($path, '/api/v1/campaigns/')) {
            return $d['code_api_status'] === 302
                ? Http::response('', 302, ['Location' => self::BASE.'/login'])
                : Http::response(['message' => 'Unauthenticated.'], $d['code_api_status']);
        }

        if ($request->method() === 'POST' && preg_match('#^/(api/claim/)?v1/([0-9A-Za-z]{26})$#', $path, $m)) {
            if ($m[2] !== self::CAMPAIGN) {
                return Http::response(['message' => 'Not Found'], $d['unknown_campaign_status']);
            }

            return Http::response($this->claim($request['address'] ?? ''), 200);
        }

        return Http::response('unexpected request '.$request->method().' '.$url, 599);
    }

    /** The claim endpoint's order of refusals, as CodeController::claim applies them. */
    private function claim(string $address): array
    {
        if (! preg_match('/^addr(_test)?1[023456789acdefghjklmnpqrstuvwxyz]{53,103}$/', $address)) {
            return ['code' => $this->deployment['malformed_code'], 'status' => 'invalidaddress'];
        }

        $enterprise = strlen($address) < 70;

        if ($enterprise && $this->deployment['enterprise_refused']) {
            return $this->deployment['enterprise_message']
                ? ['code' => 400, 'status' => 'invalidaddress', 'message' => 'Claims need a base address with a staking key.']
                : ['code' => 400, 'status' => 'invalidaddress'];
        }

        $testnet = str_starts_with($address, 'addr_test1');

        if ($testnet !== ($this->deployment['campaign_network'] !== 'mainnet')) {
            return ['code' => 400, 'status' => 'invalidnetwork'];
        }

        return ['code' => 404, 'status' => 'notfound'];
    }
}
