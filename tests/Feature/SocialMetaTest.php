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

    public function test_the_share_card_names_the_brand_not_the_deployment_app_name(): void
    {
        // APP_NAME is a deployment setting and has drifted to "Onboard Ninja" in production,
        // which contradicts the naming rule in brand/README.md. og:site_name must not follow it.
        config(['app.name' => 'Some Deployment Name']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('property="og:site_name" content="Onboard.Ninja"', $html);
        $this->assertStringNotContainsString('property="og:site_name" content="Some Deployment Name"', $html);
    }

    /**
     * The tag names a file, and nothing in the markup says whether that file is there. A
     * card whose image 404s renders as a bare link on every platform that scrapes it, and
     * the page itself looks correct while it happens.
     *
     * The dimensions are the difference between a large card and a thumbnail: the scrapers
     * that fall back to a small card below 1200x630 do it silently too.
     */
    public function test_the_ada_now_share_image_ships_with_the_application(): void
    {
        $image = public_path('og-ada-now.png');

        $this->assertFileExists($image);

        [$width, $height, $type] = getimagesize($image);

        $this->assertSame(IMAGETYPE_PNG, $type, 'og-ada-now.png must actually be a PNG');
        $this->assertSame(1200, $width);
        $this->assertSame(630, $height);
    }

    public function test_og_image_is_an_absolute_url_not_a_root_relative_path(): void
    {
        // Scrapers need an absolute URL, and where public/ is served from a separate asset
        // host a root-relative "/favicon.png" 404s. asset() follows ASSET_URL, so it gives an
        // absolute URL that resolves on either layout.
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression(
            '#property="og:image" content="https?://[^"]+/og\.png"#',
            $html,
            'og:image must be an absolute asset() URL, not a root-relative path',
        );
    }
}
