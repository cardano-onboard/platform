<?php

namespace Tests\Feature;

use App\Jobs\GenerateQrExport;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\Partner;
use App\Models\QrExport;
use App\Models\User;
use App\Services\QrExportService;
use App\Support\QrExportFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Exporting one partner's codes on their own.
 *
 * What this is for is the moment somebody is handed their stack. Exporting the campaign and
 * then picking the right folder out of the archive works, and is not what an operator with a
 * vendor standing in front of them wants to do.
 *
 * The thing that has to hold is that a scoped archive and a full one are two different
 * files. They are built from the same campaign, the same settings and the same codes table,
 * and the only difference between them is the scope, so a cache that does not carry it
 * hands an operator asking for one vendor's stack the whole campaign's stickers, under a
 * name that says otherwise.
 */
class QrExportScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_an_operator_can_export_one_partners_codes(): void
    {
        [$user, $campaign] = $this->campaign();

        $vendor = $this->partnerWithCodes($campaign, 'Vendor A', 2);
        $this->partnerWithCodes($campaign, 'Booth Staff', 3);
        Code::factory()->for($campaign)->create();

        $entries = $this->exportArchive($user, $campaign, ['scope' => $vendor->id]);

        $this->assertSame($this->codesOf($vendor), $this->codesIn($entries));
        $this->assertSame(
            2,
            (int) QrExport::where('campaign_id', $campaign->id)->firstOrFail()->codes_total,
            'the export recorded the campaign rather than the stack that was asked for',
        );
    }

    public function test_a_scoped_export_is_never_served_the_full_campaigns_archive(): void
    {
        [$user, $campaign] = $this->campaign();

        $vendor = $this->partnerWithCodes($campaign, 'Vendor A', 2);
        $this->partnerWithCodes($campaign, 'Booth Staff', 3);

        $whole = $this->exportArchive($user, $campaign);
        $this->assertCount(5, $this->codesIn($whole));

        $scoped = $this->exportArchive($user, $campaign, ['scope' => $vendor->id]);

        $this->assertSame($this->codesOf($vendor), $this->codesIn($scoped));
        $this->assertCount(
            2,
            Storage::disk('local')->allFiles('qr-exports'),
            'the scoped export was answered with the archive built for the whole campaign',
        );
    }

    public function test_two_partners_do_not_share_one_archive(): void
    {
        [$user, $campaign] = $this->campaign();

        $vendor = $this->partnerWithCodes($campaign, 'Vendor A', 2);
        $booth = $this->partnerWithCodes($campaign, 'Booth Staff', 3);

        $first = $this->exportArchive($user, $campaign, ['scope' => $vendor->id]);
        $second = $this->exportArchive($user, $campaign, ['scope' => $booth->id]);

        $this->assertSame($this->codesOf($vendor), $this->codesIn($first));
        $this->assertSame($this->codesOf($booth), $this->codesIn($second));
        $this->assertCount(2, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_the_codes_nobody_was_given_can_be_exported_on_their_own(): void
    {
        [$user, $campaign] = $this->campaign();

        $this->partnerWithCodes($campaign, 'Vendor A', 2);
        $loose = Code::factory()->for($campaign)->count(3)->create()->pluck('code')->sort()->values()->all();

        $entries = $this->exportArchive($user, $campaign, ['scope' => QrExportService::SCOPE_UNASSIGNED]);

        $this->assertSame($loose, $this->codesIn($entries));
    }

    public function test_a_scoped_grouped_export_holds_one_folder_and_its_sheet(): void
    {
        [$user, $campaign] = $this->campaign();

        $vendor = $this->partnerWithCodes($campaign, 'Vendor A', 2);
        $this->partnerWithCodes($campaign, 'Booth Staff', 3);

        $entries = $this->exportArchive($user, $campaign, [
            'scope' => $vendor->id,
            'group' => QrExportService::GROUP_PARTNER,
        ]);

        $this->assertArrayHasKey('vendor-a/'.QrExportService::MANIFEST_TXT, $entries);
        $this->assertStringContainsString('Codes:       2', $entries['vendor-a/'.QrExportService::MANIFEST_TXT]);
        $this->assertStringContainsString('Total', $entries[QrExportService::HANDOVER_TXT]);
        $this->assertSame($this->codesOf($vendor), $this->codesIn($entries));

        // One partner was asked for, so one folder is what is there. A handover sheet
        // listing the other vendors would be a sheet for an archive this is not.
        $this->assertStringNotContainsString('booth-staff', $entries[QrExportService::HANDOVER_TXT]);
    }

    public function test_another_campaigns_partner_is_refused(): void
    {
        [$user, $campaign] = $this->campaign();
        Code::factory()->for($campaign)->create();

        $elsewhere = Partner::factory()->for(Campaign::factory()->create())->create();

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), ['scope' => $elsewhere->id])
            ->assertSessionHasErrors('scope');

        // Nothing was queued and nothing was rendered: an id that is not this campaign's is
        // a refusal rather than an export of no codes.
        $this->assertSame(0, $campaign->tasks()->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_a_partner_with_no_codes_is_refused_before_anything_is_queued(): void
    {
        [$user, $campaign] = $this->campaign();

        Code::factory()->for($campaign)->count(2)->create();
        $empty = Partner::factory()->for($campaign)->create(['name' => 'Vendor A']);

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), ['scope' => $empty->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('refused', session('qr_export')['status']);
        $this->assertStringContainsString('partner', strtolower(session('qr_export')['message']));
        $this->assertSame(0, $campaign->tasks()->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_the_run_refuses_a_scope_that_is_not_on_its_campaign(): void
    {
        // The run reads its settings from a JSON column written by an earlier request on
        // another machine. A partner id in there that belongs to somebody else's campaign
        // has to be refused where the render happens, not only where the form was posted.
        [$user, $campaign] = $this->campaign();
        Code::factory()->for($campaign)->count(2)->create();

        $elsewhere = Partner::factory()->for(Campaign::factory()->create())->create();

        $task = CampaignTask::claim(
            $campaign,
            GenerateQrExport::TASK_TYPE,
            GenerateQrExport::dedupeKey(str_repeat('a', 64)),
            ['options' => [
                'format' => 'svg',
                'size' => 1.0,
                'dpi' => 203,
                'ecc' => 'L',
                'header' => false,
                'footer' => false,
                'group' => QrExportService::GROUP_PARTNER,
                'scope' => $elsewhere->id,
            ]],
            $user->id,
        );

        (new GenerateQrExport($campaign->id, $task->id))->handle(app(QrExportService::class));

        $task->refresh();

        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('not on this campaign', (string) $task->error);
        $this->assertCount(0, Storage::disk('local')->allFiles('qr-exports'));
    }

    public function test_the_campaign_page_offers_every_stack_with_its_count(): void
    {
        [$user, $campaign] = $this->campaign();

        $vendor = $this->partnerWithCodes($campaign, 'Vendor A', 2);
        Code::factory()->for($campaign)->count(3)->create();

        // Removed partners are off the picker: a stack cannot be handed to somebody who is
        // no longer on the campaign, and their codes are still in the campaign's own export.
        $gone = $this->partnerWithCodes($campaign, 'Old Vendor', 1);
        $gone->delete();

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Campaign/Show')
                ->where('export_scopes', [
                    ['value' => 'all', 'label' => 'All codes', 'codes' => 6],
                    ['value' => $vendor->id, 'label' => 'Vendor A', 'codes' => 2],
                    [
                        'value' => QrExportService::SCOPE_UNASSIGNED,
                        'label' => QrExportFolders::UNASSIGNED_LABEL,
                        'codes' => 3,
                    ],
                ])
            );
    }

    public function test_the_page_says_which_stack_each_stored_archive_holds(): void
    {
        // Several archives of one campaign sit in this list at once now. Rows that all read
        // "40 stickers, PDF, 1 inch" and download different files are a list nobody can use.
        [$user, $campaign] = $this->campaign();

        $vendor = $this->partnerWithCodes($campaign, 'Vendor A', 2);

        $this->request($user, $campaign, [
            'scope' => $vendor->id,
            'group' => QrExportService::GROUP_PARTNER,
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Campaign/Show')
                ->where('qr_exports.0.scope_label', 'Vendor A')
                ->where('qr_exports.0.layout', QrExportService::GROUP_PARTNER)
                ->where('qr_exports.0.codes_total', 2)
            );
    }

    public function test_the_download_is_named_for_the_stack_it_holds(): void
    {
        [$user, $campaign] = $this->campaign(['name' => 'Summit Booth']);

        $vendor = $this->partnerWithCodes($campaign, 'Vendor A', 2);

        $this->request($user, $campaign, ['scope' => $vendor->id]);

        $export = QrExport::where('campaign_id', $campaign->id)->firstOrFail();

        $this->actingAs($user)
            ->get(route('campaigns.qr-exports.download', [$campaign->id, $export->id]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=qrcodes-summit-booth-vendor-a.zip');
    }

    /** @return array{0: User, 1: Campaign} */
    private function campaign(array $attributes = []): array
    {
        $user = User::factory()->create();

        return [$user, Campaign::factory()->for($user)->create($attributes)];
    }

    private function partnerWithCodes(Campaign $campaign, string $name, int $codes): Partner
    {
        $partner = Partner::factory()->for($campaign)->create(['name' => $name]);

        Code::factory()->for($campaign)->count($codes)->create(['partner_id' => $partner->id]);

        return $partner;
    }

    /** @return list<string> */
    private function codesOf(Partner $partner): array
    {
        return $partner->codes()->orderBy('code')->pluck('code')->all();
    }

    /**
     * The codes an archive holds, wherever in it they are.
     *
     * @param  array<string, string>  $entries
     * @return list<string>
     */
    private function codesIn(array $entries): array
    {
        $codes = [];

        foreach (array_keys($entries) as $name) {
            if (str_ends_with($name, '.svg')) {
                $codes[] = basename($name, '.svg');
            }
        }

        sort($codes);

        return $codes;
    }

    private function request(User $user, Campaign $campaign, array $settings = []): array
    {
        $settings += ['format' => 'svg'];

        $this->actingAs($user)
            ->post(route('campaigns.qr-exports.store', $campaign), $settings)
            ->assertSessionHasNoErrors();

        $answer = session('qr_export');

        $this->assertIsArray($answer);
        $this->assertArrayHasKey('cache_key', $answer, 'the request did not say which export it was: '.json_encode($answer));

        return $answer;
    }

    /** @return array<string, string> entry name => contents */
    private function exportArchive(User $user, Campaign $campaign, array $settings = []): array
    {
        $answer = $this->request($user, $campaign, $settings);

        $export = QrExport::where('campaign_id', $campaign->id)
            ->where('cache_key', $answer['cache_key'])
            ->firstOrFail();

        $local = tempnam(sys_get_temp_dir(), 'qr-scoped');
        file_put_contents($local, Storage::disk($export->disk)->get($export->path));

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($local) === true, 'the stored archive could not be opened');

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->statIndex($i)['name']] = $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($local);

        return $entries;
    }
}
