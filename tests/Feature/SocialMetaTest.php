<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_social_meta_is_present_on_the_public_page(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // OpenGraph + Twitter Card scaffolding for link previews.
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:description"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
        $this->assertStringContainsString('name="description"', $html);
    }

    public function test_og_image_is_an_absolute_url_not_a_root_relative_path(): void
    {
        // Scrapers need an absolute URL, and on Vapor public/ is served from CloudFront —
        // a root-relative "/favicon.png" 404s there. asset() gives an absolute, CDN-correct URL.
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression(
            '#property="og:image" content="https?://[^"]+/og\.png"#',
            $html,
            'og:image must be an absolute asset() URL, not a root-relative path',
        );
    }
}
