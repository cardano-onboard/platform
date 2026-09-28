<?php

namespace Tests\Feature;

use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\User;
use App\Services\QrExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Asking for a sticker archive.
 *
 * What this endpoint is for is spending a render exactly once. Two operators on one campaign,
 * an impatient double click, and a request for something that was built last week all have to
 * come out the same way: one run, or none.
 */
class QrExportRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_it_requires_a_signed_in_account(): void
    {
        $campaign = Campaign::factory()->create();

        $this->post(route('campaigns.qr-exports.store', $campaign))->assertRedirect('/login');
    }

    public function test_another_tenant_cannot_spend_a_render_on_a_campaign_that_is_not_theirs(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $campaign = Campaign::factory()->for($owner)->create();
        Code::factory()->for($campaign)->create();

        $this->actingAs($stranger)
            ->post(route('campaigns.qr-exports.store', $campaign))
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('campaign_tasks', 0);
    }

    public function test_it_claims_a_run_and_queues_it(): void
    {
        Queue::fake();

        [$user, $campaign] = $this->campaignWithCodes();

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), ['format' => 'svg', 'size' => 2, 'footer' => 1])
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'Preparing your QR export'));

        $task = CampaignTask::where('campaign_id', $campaign->id)->sole();

        $this->assertSame(GenerateQrExport::TASK_TYPE, $task->type);
        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertSame($user->id, $task->requested_by);
        // The settings the operator chose, on the row the run reads them back from.
        $this->assertSame('svg', $task->payload['options']['format']);
        // A JSON column has no float 2.0, so this is what the row can hold and what the run
        // casts back before it renders.
        $this->assertEquals(2, $task->payload['options']['size']);
        $this->assertTrue($task->payload['options']['footer']);
        $this->assertFalse($task->payload['options']['header']);

        Queue::assertPushed(GenerateQrExport::class, fn (GenerateQrExport $job) => $job->campaign_id === $campaign->id
            && $job->task_id === $task->id);
    }

    public function test_a_second_request_while_the_first_is_running_queues_nothing(): void
    {
        Queue::fake();

        [$user, $campaign] = $this->campaignWithCodes();

        $this->actingAs($user)->post(route('campaigns.qr-exports.store', $campaign))->assertRedirect();

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign))
            ->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'already being prepared'));

        Queue::assertPushed(GenerateQrExport::class, 1);
        $this->assertDatabaseCount('campaign_tasks', 1);
    }

    public function test_two_people_asking_for_different_settings_get_a_run_each(): void
    {
        // The dedupe key is the cache key, so these are different archives and neither should
        // be waiting on the other.
        Queue::fake();

        [$user, $campaign] = $this->campaignWithCodes();

        $this->actingAs($user)->post(route('campaigns.qr-exports.store', $campaign), ['format' => 'pdf'])->assertRedirect();
        $this->actingAs($user)->post(route('campaigns.qr-exports.store', $campaign), ['format' => 'svg'])->assertRedirect();

        Queue::assertPushed(GenerateQrExport::class, 2);
        $this->assertDatabaseCount('campaign_tasks', 2);
    }

    public function test_an_archive_that_already_exists_is_not_rendered_again(): void
    {
        [$user, $campaign] = $this->campaignWithCodes();

        // Build it the way the operator would.
        $this->actingAs($user)->post(route('campaigns.qr-exports.store', $campaign))->assertRedirect();
        $this->assertCount(1, Storage::disk('local')->allFiles('qr-exports'));

        Queue::fake();

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign))
            ->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'ready to download'));

        Queue::assertNothingPushed();
    }

    public function test_a_failed_run_can_be_asked_for_again(): void
    {
        // Regenerating is the whole point of a "try again" button, and it is the same request
        // rather than a second endpoint that deletes a row first.
        Queue::fake();

        [$user, $campaign] = $this->campaignWithCodes();

        $this->actingAs($user)->post(route('campaigns.qr-exports.store', $campaign))->assertRedirect();

        $task = CampaignTask::where('campaign_id', $campaign->id)->sole();
        $task->update(['status' => CampaignTask::STATUS_FAILED, 'error' => 'It did not work.', 'completed_at' => now()]);

        $this->actingAs($user)->post(route('campaigns.qr-exports.store', $campaign))->assertRedirect();

        Queue::assertPushed(GenerateQrExport::class, 2);
        $this->assertDatabaseCount('campaign_tasks', 1);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_QUEUED, $task->status);
        $this->assertNull($task->error, 'the previous failure is still on screen next to a run that is going again');
    }

    public function test_an_ended_campaign_cannot_spend_a_render(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
        Code::factory()->for($campaign)->count(2)->create();

        $this->travelTo('2026-02-15');
        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign))
            ->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'has ended'));
        $this->travelBack();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('campaign_tasks', 0);
    }

    public function test_a_campaign_with_no_codes_is_refused_before_anything_is_queued(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign))
            ->assertSessionHas('message', fn ($m) => str_contains((string) $m, 'no codes'));

        Queue::assertNothingPushed();
    }

    public function test_settings_that_cannot_be_rendered_are_refused(): void
    {
        Queue::fake();

        [$user, $campaign] = $this->campaignWithCodes();

        $cases = [
            'format' => ['format' => 'gif'],
            'size' => ['size' => 10],
            'ecc' => ['ecc' => 'Z'],
            'dpi' => ['dpi' => 6],
        ];

        foreach ($cases as $field => $settings) {
            $this->actingAs($user)
                ->post(route('campaigns.qr-exports.store', $campaign), $settings)
                ->assertSessionHasErrors($field);
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('campaign_tasks', 0);
    }

    public function test_the_endpoint_is_throttled(): void
    {
        // Every distinct combination is a different archive and a full render, so a dialog
        // being cycled through its options has to run out of requests before it runs out of
        // the operator's money.
        Queue::fake();

        [$user, $campaign] = $this->campaignWithCodes();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->post(route('campaigns.qr-exports.store', $campaign), ['size' => 1 + ($i / 10)])
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), ['size' => 2])
            ->assertStatus(429);
    }

    public function test_the_request_and_the_download_agree_on_where_the_archive_goes(): void
    {
        // The two endpoints compute the key separately. If they ever disagreed, every export
        // would be built at one address and fetched from another, and nothing would ever be
        // ready.
        [$user, $campaign] = $this->campaignWithCodes();

        $this->actingAs($user)->post(route('campaigns.qr-exports.store', $campaign), ['format' => 'svg', 'size' => 1.5])->assertRedirect();

        $exports = new QrExportService;
        $key = $exports->cacheKey($campaign->refresh(), [
            'format' => 'svg', 'size' => 1.5, 'dpi' => 203, 'ecc' => 'L', 'header' => false, 'footer' => false,
        ]);

        $this->assertTrue($exports->exists($exports->path($campaign, $key)));

        $this->actingAs($user)
            ->get(route('campaigns.download-qr', $campaign).'?'.http_build_query(['format' => 'svg', 'size' => 1.5]))
            ->assertOk();
    }

    /** @return array{0: User, 1: Campaign} */
    private function campaignWithCodes(int $codes = 2): array
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Code::factory()->for($campaign)->count($codes)->create();

        return [$user, $campaign->refresh()];
    }
}
