<?php

namespace Tests\Feature;

use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\QrExport;
use App\Models\User;
use App\Models\Wallet;
use App\Services\QrExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What asking for an export tells the dialog, and how a finished one is fetched.
 *
 * The dialog has to branch on what actually happened, not read a sentence. The branch that
 * matters is an archive that is already built: it has to be offered straight away, because
 * showing a progress panel for work nobody is doing, and taking it away a moment later,
 * reads as a render that skipped most of the job.
 */
class QrExportReadyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_an_archive_already_on_the_disk_is_reported_ready_and_not_queued(): void
    {
        Bus::fake();
        [$user, $campaign] = $this->campaignWithCodes(2);

        $this->storeArchiveFor($campaign);

        $result = $this->request($user, $campaign);

        $this->assertSame('ready', $result['status']);
        $this->assertArrayHasKey('download_url', $result);
        $this->assertArrayNotHasKey('task_id', $result, 'a ready archive has no run to watch');

        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('campaign_tasks', 0);
    }

    public function test_the_download_a_ready_result_names_actually_serves_the_archive(): void
    {
        // A status of ready that hands back a URL nothing answers at is worse than saying
        // nothing: the dialog offers a button whose failure the operator discovers by
        // pressing it.
        Bus::fake();
        [$user, $campaign] = $this->campaignWithCodes(2);

        $this->storeArchiveFor($campaign, 'the finished archive');

        $result = $this->request($user, $campaign);

        $response = $this->actingAs($user)->get($result['download_url']);
        $response->assertOk();

        $this->assertSame('the finished archive', $response->streamedContent());
    }

    public function test_an_archive_that_has_to_be_rendered_is_reported_queued_with_its_run(): void
    {
        Bus::fake();
        [$user, $campaign] = $this->campaignWithCodes(2);

        $result = $this->request($user, $campaign);

        $this->assertSame('queued', $result['status']);

        $task = CampaignTask::where('campaign_id', $campaign->id)->sole();
        $this->assertSame($task->id, $result['task_id'], 'the dialog was not told which run to watch');
        $this->assertSame(GenerateQrExport::TASK_TYPE, $task->type);
    }

    public function test_a_request_for_a_render_somebody_else_started_names_that_run(): void
    {
        // Two people on one campaign, or one person who pressed twice. The second is not
        // told "nothing happened"; they are pointed at the run that is already going, which
        // is the one their export is coming from.
        Bus::fake();
        [$user, $campaign] = $this->campaignWithCodes(2);

        $first = $this->request($user, $campaign);
        $second = $this->request($user, $campaign);

        $this->assertSame('queued', $second['status']);
        $this->assertSame($first['task_id'], $second['task_id']);
        $this->assertDatabaseCount('campaign_tasks', 1);
    }

    public function test_a_refusal_is_reported_as_a_refusal(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $result = $this->request($user, $campaign);

        $this->assertSame('refused', $result['status']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_an_ended_campaign_is_a_refusal_rather_than_a_run_that_never_arrives(): void
    {
        Bus::fake();
        [$user, $campaign] = $this->campaignWithCodes(2);
        $campaign->forceFill(['end_date' => now()->subDay()->toDateString()])->save();

        $result = $this->request($user, $campaign);

        $this->assertSame('refused', $result['status']);
        Bus::assertNothingDispatched();
    }

    public function test_a_finished_export_is_offered_on_the_campaign_page(): void
    {
        // The way back to an export whose dialog was closed. Nothing polls for this: the
        // finished run names this prop, and the shared poller reloads exactly it.
        [$user, $campaign] = $this->campaignWithCodes(2);
        Wallet::factory()->for($campaign)->create();

        $export = QrExport::factory()->for($campaign)->create(['codes_total' => 2]);

        $props = $this->pageProps($user, $campaign);

        $this->assertCount(1, $props['qr_exports']);
        $this->assertSame($export->id, $props['qr_exports'][0]['id']);
        $this->assertSame(2, $props['qr_exports'][0]['codes_total']);
        $this->assertStringContainsString($export->id, $props['qr_exports'][0]['download_url']);

        $this->assertSame(['qr_exports'], GenerateQrExport::reloads(), 'a finished render must name the panel it fills');
    }

    public function test_an_export_that_is_gone_is_not_offered_on_the_campaign_page(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(2);
        Wallet::factory()->for($campaign)->create();

        QrExport::factory()->for($campaign)->expired()->create();
        QrExport::factory()->for($campaign)->create(['expires_at' => now()->subMinute()]);

        $this->assertSame([], $this->pageProps($user, $campaign)['qr_exports']);
    }

    public function test_another_tenant_cannot_download_an_export(): void
    {
        [$owner, $campaign] = $this->campaignWithCodes(2);
        $export = $this->storedExportFor($campaign);

        $this->actingAs(User::factory()->create())
            ->get(route('campaigns.qr-exports.download', [$campaign->id, $export->id]))
            ->assertForbidden();
    }

    public function test_an_export_belonging_to_another_campaign_is_not_served(): void
    {
        // Route binding hands over any export whose id is guessed, and both campaigns here
        // belong to the same account, so the policy alone would let this through.
        $user = User::factory()->create();
        $mine = Campaign::factory()->for($user)->create();
        $other = Campaign::factory()->for($user)->create();

        $export = $this->storedExportFor($other);

        $this->actingAs($user)
            ->get(route('campaigns.qr-exports.download', [$mine->id, $export->id]))
            ->assertNotFound();
    }

    public function test_an_export_whose_bytes_are_gone_says_so_and_stops_advertising_itself(): void
    {
        [$user, $campaign] = $this->campaignWithCodes(2);
        Wallet::factory()->for($campaign)->create();

        $export = $this->storedExportFor($campaign);
        Storage::disk('local')->delete($export->path);

        $this->actingAs($user)
            ->get(route('campaigns.qr-exports.download', [$campaign->id, $export->id]))
            ->assertRedirect();

        $export->refresh();
        $this->assertSame(QrExport::STATUS_EXPIRED, $export->status);
        $this->assertNull($export->path);
        $this->assertSame([], $this->pageProps($user, $campaign)['qr_exports']);
    }

    public function test_an_export_is_served_from_the_disk_it_recorded(): void
    {
        // The configured disk can change between a render and a download a week later, and
        // an unusable cloud disk falls back at write time, so the row records where the
        // bytes went. Serving from the configured disk would 404 on an archive that is
        // sitting right there.
        Storage::fake('archived');
        [$user, $campaign] = $this->campaignWithCodes(2);

        $export = QrExport::factory()->for($campaign)->create([
            'disk' => 'archived',
            'path' => 'qr-exports/'.$campaign->id.'/elsewhere.zip',
        ]);
        Storage::disk('archived')->put($export->path, 'bytes on the other disk');

        config(['cardano.qr_storage.disk' => 'local']);

        $response = $this->actingAs($user)
            ->get(route('campaigns.qr-exports.download', [$campaign->id, $export->id]));

        $response->assertOk();
        $this->assertSame('bytes on the other disk', $response->streamedContent());
    }

    /** The structured result the dialog branches on. */
    private function request(User $user, Campaign $campaign, array $opts = []): array
    {
        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), $opts + ['format' => 'svg'])
            ->assertRedirect();

        $result = session('qr_export');

        $this->assertIsArray($result, 'the request told the dialog nothing it could branch on');

        return $result;
    }

    private function pageProps(User $user, Campaign $campaign): array
    {
        return $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->original->getData()['page']['props'];
    }

    /** Put an archive on the disk under the key the campaign's settings resolve to. */
    private function storeArchiveFor(Campaign $campaign, string $body = 'archive'): string
    {
        $exports = app(QrExportService::class);
        $opts = ['format' => 'svg', 'size' => 1.0, 'dpi' => 203, 'ecc' => 'L', 'header' => false, 'footer' => false];
        $path = $exports->path($campaign, $exports->cacheKey($campaign, $opts));

        Storage::disk('local')->put($path, $body);

        return $path;
    }

    private function storedExportFor(Campaign $campaign): QrExport
    {
        $export = QrExport::factory()->for($campaign)->create();
        Storage::disk('local')->put($export->path, 'archive');

        return $export;
    }

    /** @return array{0: User, 1: Campaign} */
    private function campaignWithCodes(int $codes): array
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        if ($codes > 0) {
            Code::factory()->for($campaign)->count($codes)->create();
        }

        return [$user, $campaign->refresh()];
    }
}
