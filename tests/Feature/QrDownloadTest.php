<?php

namespace Tests\Feature;

use App\Http\Controllers\CodeController;
use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\QrExport;
use App\Models\User;
use App\Services\QrExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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

    /**
     * Ask for a bundle and let the queue produce it.
     *
     * The tests run on the sync connection, so the render happens inside this request and the
     * redirect comes back with the bundle already stored. That is what a developer checkout
     * and an install with no worker both do, and it is why the request is asserted as a
     * redirect rather than a download: this endpoint no longer serves bytes.
     */
    private function requestExport(User $user, Campaign $campaign, array $settings = []): void
    {
        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), $settings)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('campaigns.show', $campaign));
    }

    /** Fetch a bundle that has already been built. */
    private function download(User $user, Campaign $campaign, array $settings = [])
    {
        $url = route('campaigns.download-qr', $campaign);

        return $this->actingAs($user)->get($settings === [] ? $url : $url.'?'.http_build_query($settings));
    }

    /**
     * Point the deployment's claim endpoint at a subdomain.
     *
     * Routes are registered before a test can set config, so the domain route is registered
     * here the way bootstrap/app.php registers it. Without it claimUrl() correctly degrades
     * to the long route and nothing about the payload changes.
     */
    private function useClaimSubdomain(string $domain): void
    {
        config()->set('cardano.claim_domain', $domain);

        Route::middleware('api')->domain($domain)
            ->post('/v1/{campaign}', [CodeController::class, 'claim'])
            ->name('claim.v1.short');
        Route::getRoutes()->refreshNameLookups();
    }

    /** Ask for a bundle, then fetch it: what the operator does, in two requests. */
    private function exportAndDownload(User $user, Campaign $campaign, array $settings = [])
    {
        $this->requestExport($user, $campaign, $settings);

        return $this->download($user, $campaign, $settings);
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

        $response = $this->exportAndDownload($user, $campaign);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContains('qrcodes-', $response->headers->get('Content-Disposition'));
    }

    public function test_a_download_of_a_bundle_nobody_has_built_queues_it_instead_of_rendering_inline(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        // The old contract rendered here, holding the request open for the whole render.
        $response = $this->download($user, $campaign);

        $response->assertRedirect(route('campaigns.show', $campaign));
        $this->assertDatabaseHas('campaign_tasks', [
            'campaign_id' => $campaign->id,
            'type' => GenerateQrExport::TASK_TYPE,
            'status' => CampaignTask::STATUS_COMPLETE,
        ]);
        // On the sync connection the run finished inside that request, so the bundle it
        // queued is already there for the next one.
        $this->download($user, $campaign)->assertOk();
    }

    public function test_download_qr_survives_an_unconfigured_cloud_disk(): void
    {
        // Regression guard for a 500 seen in the wild: the app resolved an s3-driver disk
        // whose AWS_BUCKET was never injected, and Flysystem type-errored on the null
        // bucket before a single byte was written. The export must still be served.
        config(['cardano.qr_storage.disk' => 'private']);
        config(['filesystems.disks.private.bucket' => null]);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $response = $this->exportAndDownload($user, $campaign);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        // Served from the local disk it fell back to, not from the broken cloud disk. The
        // row has to name that disk too, or a download next week looks in the wrong place.
        $this->assertCount(1, Storage::disk('local')->allFiles('qr-exports'));
        $this->assertSame('local', QrExport::where('campaign_id', $campaign->id)->value('disk'));
    }

    public function test_download_qr_is_idempotent_across_requests(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $this->exportAndDownload($user, $campaign)->assertOk();

        $files = Storage::disk('local')->allFiles('qr-exports');
        $this->assertCount(1, $files, 'export bundle should be cached to the disk');

        // Replace the cached bundle with a sentinel; asking for the same export again must
        // leave THIS stored file alone (proving nothing was re-rendered) and serve it.
        Storage::disk('local')->put($files[0], 'SENTINEL');

        $this->requestExport($user, $campaign);
        $this->assertSame('SENTINEL', Storage::disk('local')->get($files[0]), 'a second request re-rendered a bundle that was already stored');

        $again = $this->download($user, $campaign);
        $again->assertOk();
        $this->assertSame('SENTINEL', $again->streamedContent());
    }

    public function test_download_qr_cache_busts_when_codes_change(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $this->exportAndDownload($user, $campaign)->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('qr-exports'));

        // Adding a code changes the codes-version → new cache key → a fresh bundle.
        Code::factory()->for($campaign)->create();
        $this->exportAndDownload($user, $campaign)->assertOk();
        $this->assertCount(2, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_changing_the_claim_url_produces_a_fresh_bundle(): void
    {
        // The claim URL is encoded into every sticker. It was not part of the cache key, so
        // pointing the deployment at a claim subdomain left the key where it was: the
        // operator downloaded the bundle rendered for the old host, and printed it.
        config()->set('cardano.claim_domain', null);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        $code = Code::factory()->for($campaign)->create();

        $before = $this->exportAndDownload($user, $campaign, ['format' => 'svg']);
        $before->assertOk();
        $onOldHost = $this->zipEntries($this->downloadedBytes($before))[$code->code.'.svg'];

        $this->useClaimSubdomain('claim.onbd.test');

        // The stored bundle must not be served: its stickers point at the previous host.
        $this->download($user, $campaign, ['format' => 'svg'])
            ->assertRedirect(route('campaigns.show', $campaign));

        $after = $this->exportAndDownload($user, $campaign, ['format' => 'svg']);
        $after->assertOk();
        $onNewHost = $this->zipEntries($this->downloadedBytes($after))[$code->code.'.svg'];

        $this->assertCount(2, Storage::disk('local')->allFiles('qr-exports'), 'the new claim URL should have produced its own bundle');
        // The sticker is a rendering of the payload, so a sticker that did not change is a
        // payload that did not change.
        $this->assertNotSame($onOldHost, $onNewHost, 'the stickers still encode the old claim URL');
    }

    public function test_a_claim_domain_whose_route_is_missing_does_not_move_the_key(): void
    {
        // Routes cached at build time without the short route, with CLAIM_DOMAIN injected at
        // runtime: claimUrl() degrades to the long route, so every sticker is unchanged and
        // the stored bundle is still the right one. Keying on the configured domain rather
        // than the resolved URL would have thrown that bundle away and re-rendered it.
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->create();

        $exports = new QrExportService;
        $opts = ['format' => 'pdf', 'size' => 1.0, 'dpi' => 203, 'ecc' => 'L', 'header' => false, 'footer' => false];
        $before = $exports->cacheKey($campaign, $opts);

        config()->set('cardano.claim_domain', 'claim.onbd.test');
        $this->assertFalse(Route::has('claim.v1.short'), 'guard precondition: the short route is not registered');

        $this->assertSame($before, $exports->cacheKey($campaign, $opts));
    }

    public function test_a_row_that_advertises_a_bundle_the_disk_no_longer_holds_is_corrected(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $this->requestExport($user, $campaign);

        $export = QrExport::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertSame(QrExport::STATUS_READY, $export->status);

        // A bucket lifecycle rule deletes the object without telling the application.
        Storage::disk('local')->delete($export->path);

        // Held here so the state the operator is shown can be read. Let the run proceed and
        // it rebuilds the same bundle and the row goes back to ready, which is the right
        // ending and not the thing being checked.
        Queue::fake();

        $this->download($user, $campaign)->assertRedirect(route('campaigns.show', $campaign));

        Queue::assertPushed(GenerateQrExport::class);

        $export->refresh();
        $this->assertSame(QrExport::STATUS_EXPIRED, $export->status, 'the row still advertises a download that would 404');
        $this->assertNull($export->path);
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
        $this->exportAndDownload($user, $campaign)->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('qr-exports'));

        // …remains downloadable after the campaign ends (cache hit, no regeneration).
        $this->travelTo('2026-02-15');
        $this->download($user, $campaign)->assertOk();
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

    public function test_prune_command_removes_the_partials_of_renders_that_never_finished(): void
    {
        // An interrupted render leaves a half-finished archive beside the finished ones, and
        // one whose settings stopped matching before it could be resumed is never picked up
        // again. Nothing else deletes those, so a render abandoned every week would be a
        // storage bill that only grows.
        $disk = Storage::disk('local');
        $disk->put('qr-exports/c/abandoned.zip.part', 'half an archive');
        $disk->put('qr-exports/c/in-flight.zip.part', 'half an archive');
        touch($disk->path('qr-exports/c/abandoned.zip.part'), now()->subDays(10)->getTimestamp());

        $this->artisan('qr:prune-exports')->assertSuccessful();

        $this->assertFalse($disk->exists('qr-exports/c/abandoned.zip.part'));
        $this->assertTrue(
            $disk->exists('qr-exports/c/in-flight.zip.part'),
            'a render still working was cleared out from under itself',
        );
    }

    public function test_download_qr_defaults_to_pdf(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count(2)->create();

        $response = $this->exportAndDownload($user, $campaign);
        $response->assertOk();

        $entries = $this->zipEntries($this->downloadedBytes($response));

        // Every archive carries the row-per-code manifest beside the stickers, so the
        // stickers are what is counted here rather than the entries.
        $this->assertArrayHasKey(QrExportService::MANIFEST_CSV, $entries);

        $stickers = $this->stickers($entries);
        $this->assertCount(2, $stickers);
        foreach ($stickers as $name => $content) {
            $this->assertStringEndsWith('.pdf', $name);
            $this->assertStringStartsWith('%PDF', $content);
        }
    }

    public function test_download_qr_svg_embeds_header_and_footer(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['end_date' => '2026-12-31']);
        $code = Code::factory()->for($campaign)->create();

        $response = $this->exportAndDownload($user, $campaign, [
            'format' => 'svg',
            'size' => 2,          // both captions require a large-enough sticker
            'header' => 1,
            'footer' => 1,
        ]);
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

        // Both captions on a 1" sticker: rejected (QR would shrink too far to scan). Asked
        // for both ways round, because a combination one endpoint accepts and the other
        // refuses is a bundle that can be built and never fetched.
        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?size=1&header=1&footer=1')
            ->assertSessionHasErrors('footer');

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), ['size' => 1, 'header' => 1, 'footer' => 1])
            ->assertSessionHasErrors('footer');

        // A single caption at 1" is fine.
        $this->exportAndDownload($user, $campaign, ['size' => 1, 'header' => 1, 'footer' => 0])
            ->assertOk();

        // Both captions are allowed once the sticker is large enough.
        $this->exportAndDownload($user, $campaign, ['size' => 1.5, 'header' => 1, 'footer' => 1])
            ->assertOk();
    }

    public function test_download_qr_png_matches_gd_availability(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        $code = Code::factory()->for($campaign)->create();

        $settings = ['format' => 'png', 'dpi' => 203, 'size' => 1];

        if (\App\Services\QrStickerService::pngSupported()) {
            $response = $this->exportAndDownload($user, $campaign, $settings);
            $response->assertOk();
            $entries = $this->zipEntries($this->downloadedBytes($response));
            $this->assertArrayHasKey($code->code.'.png', $entries);
            // PNG magic number.
            $this->assertStringStartsWith("\x89PNG", $entries[$code->code.'.png']);
        } else {
            // Without GD the option must be refused rather than silently producing junk, and
            // refused at the request too: queueing a render the worker cannot perform would
            // turn a validation error into a failed job.
            $this->actingAs($user)
                ->post(route('campaigns.qr-exports.store', $campaign), $settings)
                ->assertSessionHasErrors('format');

            $this->download($user, $campaign, $settings)->assertSessionHasErrors('format');
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
     * The sticker entries of an archive, without the manifests describing them.
     *
     * @param  array<string, string>  $entries
     * @return array<string, string>
     */
    private function stickers(array $entries): array
    {
        return array_filter(
            $entries,
            static fn (string $name) => $name !== QrExportService::MANIFEST_CSV
                && $name !== QrExportService::HANDOVER_TXT
                && ! str_ends_with($name, '/'.QrExportService::MANIFEST_TXT),
            ARRAY_FILTER_USE_KEY,
        );
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
