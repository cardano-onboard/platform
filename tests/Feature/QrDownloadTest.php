<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_qr_requires_auth(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $response = $this->get(route('campaigns.download-qr', $campaign));
        $response->assertRedirect('/login');
    }

    public function test_download_qr_requires_campaign_ownership(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create();
        Code::factory()->for($campaign)->create();

        $response = $this->actingAs($user2)->get(route('campaigns.download-qr', $campaign));
        $response->assertForbidden();
    }

    public function test_download_qr_redirects_when_no_codes(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('campaigns.download-qr', $campaign));
        $response->assertRedirect();
    }

    public function test_download_qr_returns_zip(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(3)->create();

        $response = $this->actingAs($user)->get(route('campaigns.download-qr', $campaign));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContains('qrcodes-', $response->headers->get('Content-Disposition'));
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack ?? '', $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
