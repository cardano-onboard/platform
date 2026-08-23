<?php

namespace Tests\Feature;

use App\Http\Controllers\CodeController;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClaimSubdomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_url_uses_default_api_route_when_no_domain_configured(): void
    {
        config()->set('cardano.claim_domain', null);
        $campaign = Campaign::factory()->create();

        $this->assertStringContainsString('/api/claim/v1/'.$campaign->id, $campaign->claimUrl());
    }

    public function test_claim_url_uses_short_subdomain_route_when_configured(): void
    {
        config()->set('cardano.claim_domain', 'claim.onbd.test');

        // Mirror the bootstrap/app.php registration (routes are booted before the
        // test sets config, so register the domain route here to exercise the branch).
        Route::middleware('api')->domain('claim.onbd.test')
            ->post('/v1/{campaign}', [CodeController::class, 'claim'])
            ->name('claim.v1.short');
        Route::getRoutes()->refreshNameLookups();

        $campaign = Campaign::factory()->create();

        // Short host + path, no "/api/claim" — the whole point of the subdomain.
        $this->assertSame('http://claim.onbd.test/v1/'.$campaign->id, $campaign->claimUrl());
        $this->assertStringNotContainsString('/api/', $campaign->claimUrl());
    }

    public function test_claim_url_falls_back_when_domain_configured_but_route_missing(): void
    {
        // Reproduces the staging RouteNotFoundException: on Vapor, routes are cached at
        // BUILD time but CLAIM_DOMAIN is injected at RUNTIME, so config resolves the
        // domain while claim.v1.short is absent from the (build-time) route table. Here
        // we set the config but deliberately do NOT register the route to simulate that
        // skew — claimUrl() must degrade to the long route, never throw.
        config()->set('cardano.claim_domain', 'claim.onbd.test');
        $this->assertFalse(Route::has('claim.v1.short'), 'guard precondition: route not registered');

        $campaign = Campaign::factory()->create();

        $url = $campaign->claimUrl();
        $this->assertStringContainsString('/api/claim/v1/'.$campaign->id, $url);
    }
}
