<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Services\QrExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class QrDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Idempotent QR bundles are written to the default disk; fake it so tests don't
        // touch real storage and can inspect the cached artifacts.
        Storage::fake('local');
    }

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

    public function test_download_qr_is_idempotent_across_requests(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $this->actingAs($user)->get(route('campaigns.download-qr', $campaign))->assertOk();

        $files = Storage::disk('local')->allFiles('qr-exports');
        $this->assertCount(1, $files, 'export bundle should be cached to the disk');

        // Replace the cached bundle with a sentinel; an identical second request must serve
        // THIS stored file (proving it did not regenerate), not rebuild the ZIP.
        Storage::disk('local')->put($files[0], 'SENTINEL');

        $again = $this->actingAs($user)->get(route('campaigns.download-qr', $campaign));
        $again->assertOk();
        $this->assertSame('SENTINEL', $again->streamedContent());
    }

    public function test_download_qr_cache_busts_when_codes_change(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $this->actingAs($user)->get(route('campaigns.download-qr', $campaign))->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('qr-exports'));

        // Adding a code changes the codes-version → new cache key → a fresh bundle.
        Code::factory()->for($campaign)->create();
        $this->actingAs($user)->get(route('campaigns.download-qr', $campaign))->assertOk();
        $this->assertCount(2, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_codes_version_query_is_a_pure_aggregate(): void
    {
        // Regression guard for the staging 500: the Code model's `$withCount` injects
        // codes.* + rewards_count/claims_count subqueries, and combining those with
        // count()/max() and no GROUP BY is rejected by MySQL under only_full_group_by.
        // SQLite (the test DB) allows it, so assert the query SHAPE — that the withCount
        // columns are cleared — rather than relying on the DB to reject the statement.
        $campaign = Campaign::factory()->create();
        Code::factory()->for($campaign)->count(2)->create();

        DB::enableQueryLog();
        $version = (new QrExportService)->codesVersion($campaign);
        $agg = collect(DB::getQueryLog())->firstWhere(
            fn ($q) => str_contains($q['query'], 'count(*) as c')
        );
        DB::disableQueryLog();

        $this->assertNotNull($agg, 'codesVersion did not run its aggregate query');
        // The withCount aliases must not appear — their presence means codes.* is being
        // selected alongside the aggregates, which is the only_full_group_by violation.
        $this->assertStringNotContainsString('rewards_count', $agg['query']);
        $this->assertStringNotContainsString('claims_count', $agg['query']);
        // And the fingerprint still reflects the real code count (functionally correct).
        $this->assertStringStartsWith('2|', $version);
    }

    public function test_ended_campaign_blocks_new_generation(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
        ]);
        Code::factory()->for($campaign)->count(2)->create();

        $this->travelTo('2026-02-15');  // after the campaign ended
        $response = $this->actingAs($user)->get(route('campaigns.download-qr', $campaign));
        $this->travelBack();

        $response->assertRedirect();  // bounced with a flash message, not a download
        // Flash under the 'message' key that HandleInertiaRequests actually reads — the
        // controller and the shared prop must agree or the notice never reaches the UI.
        $response->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'has ended'));
        $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'), 'nothing should be generated');
    }

    public function test_flash_message_is_shared_to_the_inertia_page(): void
    {
        // Guards the flash wiring end to end: a redirect()->with('message', ...) must
        // surface as the `flash.message` Inertia prop the frontend renders. Regression
        // guard for the mismatch where the middleware read the 'flash' session key while
        // controllers flashed 'message', so every campaign notice was silently dropped.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)
            ->withSession(['message' => 'Heads up: something happened.'])
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Campaign/Show')
                ->where('flash.message', 'Heads up: something happened.')
            );
    }

    public function test_ended_campaign_still_serves_a_cached_export(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
        ]);
        Code::factory()->for($campaign)->count(2)->create();

        // Generated while active…
        $this->travelTo('2026-01-15');
        $this->actingAs($user)->get(route('campaigns.download-qr', $campaign))->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('qr-exports'));

        // …remains downloadable after the campaign ends (cache hit, no regeneration).
        $this->travelTo('2026-02-15');
        $this->actingAs($user)->get(route('campaigns.download-qr', $campaign))->assertOk();
        $this->travelBack();
    }

    public function test_render_version_change_busts_the_cache_key(): void
    {
        $campaign = Campaign::factory()->create();
        Code::factory()->for($campaign)->create();
        $opts = ['format' => 'pdf', 'size' => 1.0, 'dpi' => 203, 'ecc' => 'L', 'header' => false, 'footer' => false];

        $current = new \App\Services\QrExportService;
        // A service pinned to a different render revision must produce a different key,
        // so cached bundles are invalidated when the rendering output changes.
        $bumped = new class extends \App\Services\QrExportService
        {
            protected function renderVersion(): int
            {
                return 999;
            }
        };

        $this->assertNotSame($current->cacheKey($campaign, $opts), $bumped->cacheKey($campaign, $opts));
    }

    public function test_prune_command_removes_expired_bundles(): void
    {
        $disk = Storage::disk('local');
        $disk->put('qr-exports/c/old.zip', 'old');
        $disk->put('qr-exports/c/fresh.zip', 'fresh');
        // Age the old bundle beyond the default 7-day TTL.
        touch($disk->path('qr-exports/c/old.zip'), now()->subDays(10)->getTimestamp());

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertFalse($disk->exists('qr-exports/c/old.zip'));
        $this->assertTrue($disk->exists('qr-exports/c/fresh.zip'));
    }

    public function test_download_qr_defaults_to_pdf(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $response = $this->actingAs($user)->get(route('campaigns.download-qr', $campaign));
        $response->assertOk();

        $entries = $this->zipEntries($this->downloadedBytes($response));
        $this->assertCount(2, $entries);
        foreach ($entries as $name => $content) {
            $this->assertStringEndsWith('.pdf', $name);
            $this->assertStringStartsWith('%PDF', $content);
        }
    }

    public function test_download_qr_svg_embeds_header_and_footer(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['end_date' => '2026-12-31']);
        $code = Code::factory()->for($campaign)->create();

        $response = $this->actingAs($user)->get(route('campaigns.download-qr', $campaign).'?'.http_build_query([
            'format' => 'svg',
            'size' => 2,          // both captions require a large-enough sticker
            'header' => 1,
            'footer' => 1,
        ]));
        $response->assertOk();

        $entries = $this->zipEntries($this->downloadedBytes($response));
        $this->assertArrayHasKey($code->code.'.svg', $entries);
        $svg = $entries[$code->code.'.svg'];
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('Expires', $svg);           // header
        $this->assertStringContainsString($code->code, $svg);          // footer = the code
    }

    public function test_download_qr_rejects_invalid_params(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->create();

        // Unsupported format
        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?format=gif')
            ->assertSessionHasErrors('format');

        // Size out of the 0.5"–4" range
        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?size=10')
            ->assertSessionHasErrors('size');

        // Bogus ECC level
        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?ecc=Z')
            ->assertSessionHasErrors('ecc');
    }

    public function test_download_qr_forbids_both_captions_on_small_sticker(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['end_date' => '2026-12-31']);
        Code::factory()->for($campaign)->create();

        // Both captions on a 1" sticker: rejected (QR would shrink too far to scan).
        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?size=1&header=1&footer=1')
            ->assertSessionHasErrors('footer');

        // A single caption at 1" is fine.
        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?size=1&header=1&footer=0')
            ->assertOk();

        // Both captions are allowed once the sticker is large enough.
        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?size=1.5&header=1&footer=1')
            ->assertOk();
    }

    public function test_download_qr_png_matches_gd_availability(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        $code = Code::factory()->for($campaign)->create();

        $response = $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?format=png&dpi=203&size=1');

        if (\App\Services\QrStickerService::pngSupported()) {
            $response->assertOk();
            $entries = $this->zipEntries($this->downloadedBytes($response));
            $this->assertArrayHasKey($code->code.'.png', $entries);
            // PNG magic number.
            $this->assertStringStartsWith("\x89PNG", $entries[$code->code.'.png']);
        } else {
            // Without GD the option must be refused rather than silently producing junk.
            $response->assertSessionHasErrors('format');
        }
    }

    /**
     * Read the raw bytes of the (streamed) download response served from the disk.
     */
    private function downloadedBytes($response): string
    {
        return $response->streamedContent();
    }

    /**
     * Unzip a downloaded archive into an [entry name => contents] map.
     */
    private function zipEntries(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qrzip');
        file_put_contents($tmp, $binary);

        $zip = new \ZipArchive;
        $zip->open($tmp);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->statIndex($i)['name'];
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tmp);

        return $entries;
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack ?? '', $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
