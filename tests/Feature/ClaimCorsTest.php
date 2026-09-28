<?php

namespace Tests\Feature;

use App\Http\Controllers\CodeController;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The claim endpoint is reachable at two addresses: the long /api/claim/v1/{campaign}
 * route and the short /v1/{campaign} route on the claim subdomain. A wallet that runs
 * the claim inside a webview sends an OPTIONS preflight first and drops the POST unless
 * that preflight is answered, so both addresses have to carry the same CORS headers.
 */
class ClaimCorsTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM_DOMAIN = 'claim.onbd.test';

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cardano.claim_domain', self::CLAIM_DOMAIN);

        // Mirror the bootstrap/app.php registration: routes boot before a test can set
        // the claim domain, so the subdomain route is registered here.
        Route::middleware('api')->domain(self::CLAIM_DOMAIN)
            ->post('/v1/{campaign}', [CodeController::class, 'claim'])
            ->name('claim.v1.short');
        Route::getRoutes()->refreshNameLookups();

        $this->campaign = Campaign::factory()->create();
    }

    public function test_preflight_to_short_claim_route_returns_cors_headers(): void
    {
        $response = $this->preflight('http://'.self::CLAIM_DOMAIN.'/v1/'.$this->campaign->id);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsStringIgnoringCase(
            'POST',
            $response->headers->get('Access-Control-Allow-Methods'),
        );
        $this->assertStringContainsStringIgnoringCase(
            'content-type',
            $response->headers->get('Access-Control-Allow-Headers'),
        );
    }

    public function test_preflight_to_long_claim_route_returns_cors_headers(): void
    {
        $response = $this->preflight(route('claim.v1', $this->campaign));

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsStringIgnoringCase(
            'POST',
            $response->headers->get('Access-Control-Allow-Methods'),
        );
        $this->assertStringContainsStringIgnoringCase(
            'content-type',
            $response->headers->get('Access-Control-Allow-Headers'),
        );
    }

    public function test_short_claim_route_answers_a_cross_origin_post(): void
    {
        $response = $this->postJson(
            'http://'.self::CLAIM_DOMAIN.'/v1/'.$this->campaign->id,
            ['address' => 'addr_test1qz'.str_repeat('a', 50)],
            ['Origin' => 'http://localhost'],
        );

        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertJson(['status' => 'missingcode']);
    }

    public function test_long_claim_route_answers_a_cross_origin_post(): void
    {
        $response = $this->postJson(
            route('claim.v1', $this->campaign),
            ['address' => 'addr_test1qz'.str_repeat('a', 50)],
            ['Origin' => 'http://localhost'],
        );

        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertJson(['status' => 'missingcode']);
    }

    /**
     * A route outside the configured CORS paths stays outside them: adding the claim
     * path must not turn the whole application into an open cross-origin surface.
     */
    public function test_unrelated_route_is_not_cors_enabled(): void
    {
        $response = $this->preflight(url('/login'));

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * The browser preflight a webview wallet sends before the claim POST.
     */
    private function preflight(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->call('OPTIONS', $url, server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
    }
}
