<?php

namespace App\Console\Commands;

use CardanoPhp\Bech32\Bech32;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only checks against a deployed instance, run right after a deploy.
 *
 * Everything sent is something an anonymous visitor could send, and every request that
 * reaches an endpoint able to write is one the application refuses before it writes:
 * a claim with no usable code or address, a code-creation call with no token. Nothing is
 * created and nothing is paid, so it is safe to point at a live deployment.
 *
 * Hosts are arguments rather than configuration, so the same command checks any
 * deployment from any checkout without that checkout's own settings being involved.
 */
class SmokeCheck extends Command
{
    protected $signature = 'smoke:check
        {base_url : Base URL of the deployment, e.g. https://app.example.com}
        {--claim-url= : Base URL of the short claim subdomain, when the deployment has one}
        {--campaign= : Id of a live campaign, which turns on the claim refusal checks}
        {--network= : Network of the campaign (mainnet, preprod or preview), so only a matching address is sent}
        {--timeout=15 : Seconds to wait for each request}';

    protected $description = 'Run read-only post-deploy checks against a running deployment';

    private const ORIGIN = 'https://smoke-check.invalid';

    /** @var array<int, array{bool, string, string}> */
    private array $results = [];

    public function handle(): int
    {
        $base = $this->normaliseUrl((string) $this->argument('base_url'));

        if ($base === null) {
            $this->error('base_url must be an absolute http or https URL.');

            return self::FAILURE;
        }

        $claimUrl = null;

        if ($this->option('claim-url')) {
            $claimUrl = $this->normaliseUrl((string) $this->option('claim-url'));

            if ($claimUrl === null) {
                $this->error('--claim-url must be an absolute http or https URL.');

                return self::FAILURE;
            }
        }

        $network = $this->option('network');

        if ($network !== null && ! in_array($network, ['mainnet', 'preprod', 'preview'], true)) {
            $this->error('--network must be mainnet, preprod or preview.');

            return self::FAILURE;
        }

        // An id no campaign has, for the checks that must never touch a real one.
        $absent = (string) Str::ulid();

        $home = $this->checkPage('home page', $base.'/');
        $this->checkPage('login page', $base.'/login');
        $this->checkPage('ADA onboarding page', $base.'/ada/now');
        $this->checkStatus('health endpoint', 'GET', $base.'/up', 200, follow: true);

        $this->checkAssets($base, $home);
        $this->checkSecurityHeaders($base, $home);

        $this->checkPreflight('CORS preflight, claim API', $base.'/api/claim/v1/'.$absent);

        if ($claimUrl !== null) {
            $this->checkPreflight('CORS preflight, claim host v1 path', $claimUrl.'/v1/'.$absent);
        }

        $this->checkStatus('code API refuses no token (create)', 'POST', $base.'/api/v1/campaigns/'.$absent.'/codes', 401);
        $this->checkStatus('code API refuses no token (status)', 'GET', $base.'/api/v1/campaigns/'.$absent.'/codes/smoke/status', 401);
        $this->checkStatus('claim API refuses unknown campaign', 'POST', $base.'/api/claim/v1/'.$absent, 404);

        if ($campaign = $this->option('campaign')) {
            $endpoints = ['claim API' => $base.'/api/claim/v1/'.$campaign];

            if ($claimUrl !== null) {
                $endpoints['short claim route'] = $claimUrl.'/v1/'.$campaign;
            }

            foreach ($endpoints as $label => $url) {
                $this->checkClaimRefusals($label, $url, $network);
            }
        }

        $failed = 0;

        foreach ($this->results as [$passed, $name, $detail]) {
            $failed += $passed ? 0 : 1;
            $this->line(sprintf('%s  %s: %s', $passed ? 'PASS' : 'FAIL', $name, $detail));
        }

        if ($claimUrl !== null && ! $campaign) {
            // A preflight is answered by the CORS middleware before routing, so it passes
            // whether or not the claim route is registered on that host.
            $this->newLine();
            $this->line('NOTE  --claim-url was given without --campaign, so the claim host\'s v1 route was not shown to exist. Its preflight checks CORS configuration only.');
        }

        $this->newLine();
        $this->line(sprintf('%d checks, %d failed.', count($this->results), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A page loads and is the application rather than a host's placeholder: the
     * Inertia root element is only rendered by this application's own layout.
     */
    private function checkPage(string $name, string $url): ?Response
    {
        $response = $this->send('GET', $url, $name, headers: ['Accept' => 'text/html'], follow: true);

        if ($response === null) {
            return null;
        }

        if ($response->status() !== 200) {
            $this->record(false, $name, "{$url} answered {$response->status()}");

            return $response;
        }

        if (! str_contains($response->body(), 'data-page=')) {
            $this->record(false, $name, "{$url} answered 200 without the application's page root");

            return $response;
        }

        $this->record(true, $name, "{$url} 200");

        return $response;
    }

    private function checkStatus(string $name, string $method, string $url, int $expected, bool $follow = false): void
    {
        $response = $this->send($method, $url, $name, follow: $follow);

        if ($response === null) {
            return;
        }

        $this->record(
            $response->status() === $expected,
            $name,
            "{$method} {$url} answered {$response->status()}, expected {$expected}",
        );
    }

    /**
     * Every script and stylesheet the home page references is fetched. A deploy whose
     * build step failed, or whose asset host is misconfigured, still serves the HTML.
     */
    private function checkAssets(string $base, ?Response $home): void
    {
        $name = 'built assets';

        if ($home === null || ! $home->successful()) {
            $this->record(false, $name, 'home page did not load, so no asset list to check');

            return;
        }

        preg_match_all('/(?:src|href)="([^"]+\.(?:js|css))(?:\?[^"]*)?"/i', $home->body(), $matches);
        $assets = array_values(array_unique($matches[1]));

        if ($assets === []) {
            $this->record(false, $name, 'home page references no .js or .css files');

            return;
        }

        $broken = [];

        foreach ($assets as $asset) {
            $url = $this->resolve($base, html_entity_decode($asset));
            $response = $this->send('GET', $url, $name, headers: ['Accept' => '*/*'], record: false, follow: true);

            // A missing asset behind a catch-all route comes back as the application's own
            // HTML page with a 200, which is not a script or a stylesheet.
            if ($response === null
                || $response->status() !== 200
                || $response->body() === ''
                || str_contains(strtolower($response->header('Content-Type')), 'text/html')) {
                $broken[] = $url.' ('.($response?->status() ?? 'no response').')';
            }
        }

        $this->record(
            $broken === [],
            $name,
            $broken === []
                ? count($assets).' loaded'
                : count($broken).' of '.count($assets).' failed: '.implode(', ', $broken),
        );
    }

    private function checkSecurityHeaders(string $base, ?Response $home): void
    {
        $name = 'security headers';

        if ($home === null) {
            $this->record(false, $name, 'home page did not load, so no headers to check');

            return;
        }

        $expected = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => null,
            'Content-Security-Policy' => null,
        ];

        if (str_starts_with($base, 'https://')) {
            $expected['Strict-Transport-Security'] = null;
        }

        $problems = [];

        foreach ($expected as $header => $value) {
            $actual = $home->header($header);

            if ($actual === '') {
                $problems[] = "{$header} missing";
            } elseif ($value !== null && strcasecmp($actual, $value) !== 0) {
                $problems[] = "{$header} is \"{$actual}\", expected \"{$value}\"";
            }
        }

        $this->record(
            $problems === [],
            $name,
            $problems === [] ? implode(', ', array_keys($expected)).' present' : implode('; ', $problems),
        );
    }

    /**
     * A wallet running inside a webview sends an OPTIONS preflight before its claim
     * POST, and never sends the POST unless the preflight carries an allow-origin
     * header. The router's own answer to OPTIONS is a bare 200 without one.
     */
    private function checkPreflight(string $name, string $url): void
    {
        $response = $this->send('OPTIONS', $url, $name, headers: [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        if ($response === null) {
            return;
        }

        $allowOrigin = $response->header('Access-Control-Allow-Origin');
        $allowMethods = array_map('trim', explode(',', strtoupper($response->header('Access-Control-Allow-Methods'))));
        $passed = $response->successful()
            && ($allowOrigin === '*' || $allowOrigin === self::ORIGIN)
            && in_array('POST', $allowMethods, true);

        $this->record(
            $passed,
            $name,
            $allowOrigin === ''
                ? "OPTIONS {$url} answered {$response->status()} with no Access-Control-Allow-Origin"
                : sprintf(
                    'OPTIONS %s answered %d, Access-Control-Allow-Origin: %s, Access-Control-Allow-Methods: %s',
                    $url,
                    $response->status(),
                    $allowOrigin,
                    $response->header('Access-Control-Allow-Methods') ?: '(none)',
                ),
        );
    }

    /**
     * Three claims the endpoint must refuse before it looks at, let alone spends, any
     * code: an unreadable address, an address with no staking credential, and a code
     * that does not exist. The addresses carry all-zero key hashes, which no wallet
     * holds, and the code is random, so none of them can match anything real.
     */
    private function checkClaimRefusals(string $label, string $url, ?string $network): void
    {
        $code = 'smoke-'.Str::lower(Str::random(24));

        $this->expectClaimStatus("{$label} refuses a malformed address", $url, [
            'code' => $code,
            'address' => 'addr1smokecheck',
        ], 'invalidaddress');

        $this->expectClaimStatus("{$label} refuses an enterprise address", $url, [
            'code' => $code,
            'address' => Bech32::encodeCardanoAddress(6, 1, str_repeat('0', 56))['address'],
        ], 'invalidaddress', requireMessage: true);

        // The network check runs before the code lookup. With --network only the matching
        // address is sent. Without it the mainnet form is tried first and the testnet form
        // only if the campaign turns out to be on a test network, which costs one
        // wrong-network line in the deployment's log.
        $zero = str_repeat('0', 56);
        $mainnet = Bech32::encodeCardanoAddress(0, 1, $zero, $zero)['address'];
        $testnet = Bech32::encodeCardanoAddress(0, 0, $zero, $zero)['address'];

        $name = "{$label} answers an unknown code with notfound";
        $first = $network === null || $network === 'mainnet' ? $mainnet : $testnet;
        $response = $this->send('POST', $url, $name, json: ['code' => $code, 'address' => $first]);

        if ($network === null && $response !== null && $response->json('status') === 'invalidnetwork') {
            $response = $this->send('POST', $url, $name, json: ['code' => $code, 'address' => $testnet]);
        }

        if ($response !== null) {
            $this->recordClaimStatus($name, $url, $response, 'notfound', 404);
        }
    }

    private function expectClaimStatus(string $name, string $url, array $payload, string $status, bool $requireMessage = false): void
    {
        $response = $this->send('POST', $url, $name, json: $payload);

        if ($response !== null) {
            $this->recordClaimStatus($name, $url, $response, $status, 400, $requireMessage);
        }
    }

    /**
     * The claim endpoint reports a refusal in the body, as CIP-0099 describes it, with
     * the HTTP status of the response itself left at 200. The body is what a wallet
     * reads, so the body is what is checked.
     */
    private function recordClaimStatus(string $name, string $url, Response $response, string $status, int $code, bool $requireMessage = false): void
    {
        $body = $response->json();
        $passed = is_array($body)
            && ($body['status'] ?? null) === $status
            && (int) ($body['code'] ?? 0) === $code
            && (! $requireMessage || ! empty($body['message']));

        $this->record(
            $passed,
            $name,
            sprintf('POST %s answered %d %s', $url, $response->status(), Str::limit(trim($response->body()), 160)),
        );
    }

    /**
     * API requests ask for JSON and do not follow redirects, so an endpoint that sends an
     * unauthenticated caller to the login page is reported as the redirect it is rather
     * than as the login page's 200.
     */
    private function send(string $method, string $url, string $name, array $headers = [], ?array $json = null, bool $record = true, bool $follow = false): ?Response
    {
        try {
            $request = Http::timeout(max(1, (int) $this->option('timeout')))
                ->withUserAgent('onboard-smoke-check')
                ->withHeaders(array_merge(['Accept' => 'application/json'], $headers));

            if (! $follow) {
                $request = $request->withoutRedirecting();
            }

            return $json === null
                ? $request->send($method, $url)
                : $request->send($method, $url, ['json' => $json]);
        } catch (Throwable $e) {
            if ($record) {
                $this->record(false, $name, "{$method} {$url} failed: ".Str::limit($e->getMessage(), 160));
            }

            return null;
        }
    }

    private function record(bool $passed, string $name, string $detail): void
    {
        $this->results[] = [$passed, $name, $detail];
    }

    private function normaliseUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true) || ! parse_url($url, PHP_URL_HOST)) {
            return null;
        }

        return $url;
    }

    private function resolve(string $base, string $reference): string
    {
        if (preg_match('#^https?://#i', $reference)) {
            return $reference;
        }

        if (str_starts_with($reference, '//')) {
            return parse_url($base, PHP_URL_SCHEME).':'.$reference;
        }

        $origin = parse_url($base, PHP_URL_SCHEME).'://'.parse_url($base, PHP_URL_HOST)
            .(parse_url($base, PHP_URL_PORT) ? ':'.parse_url($base, PHP_URL_PORT) : '');

        return $origin.'/'.ltrim($reference, '/');
    }
}
