<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Partner;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * How the conversion report reaches the campaign page, and what it costs to get there.
 *
 * The campaign page is expensive and polls itself while a campaign runs, so the prop is a
 * closure: Inertia resolves a closure prop only for a request that asks for it, and a
 * reload of one panel must not pay for a grouped query over every code on the campaign.
 * Each test that asserts the work did not happen has a sibling asserting it does happen on
 * a full page load, because proving an absence proves nothing if the prop was never wired
 * up at all.
 *
 * The export is covered here too, against the same fixture, because a sheet that disagrees
 * with the panel it was downloaded from is worse than no sheet.
 */
class PartnerConversionPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'start_date' => now()->subWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        // A wallet on the null backend, so the page renders without the bucket balance
        // becoming an HTTP call these tests would have to fake.
        Wallet::factory()->for($this->campaign)->create(['backend' => 'null']);
    }

    /**
     * Two partners who both produced claims, one who produced none, and codes assigned to
     * nobody. Small, and enough for every row kind to have to appear.
     */
    private function fixture(): void
    {
        $winner = Partner::factory()->for($this->campaign)->create(['name' => 'Booth East', 'kind' => 'staff']);
        foreach (Code::factory()->count(2)->for($this->campaign)->create(['partner_id' => $winner->id]) as $code) {
            Claim::factory()->for($code)->create();
        }

        $half = Partner::factory()->for($this->campaign)->create(['name' => 'Booth West', 'kind' => 'staff']);
        $halfCodes = Code::factory()->count(4)->for($this->campaign)->create(['partner_id' => $half->id]);
        Claim::factory()->for($halfCodes[0])->create();

        Partner::factory()->for($this->campaign)->create(['name' => 'Silent Print Run', 'kind' => 'print']);
        Code::factory()->count(5)->for($this->campaign)->create([
            'partner_id' => Partner::where('name', 'Silent Print Run')->value('id'),
        ]);

        Code::factory()->count(3)->for($this->campaign)->create(['partner_id' => null]);
    }

    /** @param  array<int, string>  $only */
    private function partialHeaders(array $only): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'Campaign/Show',
            'X-Inertia-Partial-Data' => implode(',', $only),
        ];
    }

    /**
     * Whether a request ran the grouped tally. codes_claimed is only ever selected by the
     * conversion report, so its presence in the query log is the report having run.
     */
    private function tallyRanDuring(callable $request): bool
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $request();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        foreach ($queries as $query) {
            if (str_contains($query, 'codes_claimed')) {
                return true;
            }
        }

        return false;
    }

    public function test_the_campaign_page_carries_the_report(): void
    {
        $this->fixture();

        $response = $this->actingAs($this->user)->get(route('campaigns.show', $this->campaign));

        $response->assertOk();

        $report = $response->original->getData()['page']['props']['partner_conversion'];

        $this->assertSame(
            ['Booth East', 'Booth West', 'Silent Print Run', 'Unassigned'],
            array_column($report['rows'], 'name')
        );
        $this->assertSame(1, $report['summary']['partners_producing_nothing']);
    }

    /**
     * The partner who produced nothing survives the trip to the browser. A row filtered
     * out here would be as invisible to an organiser as one the query never returned.
     */
    public function test_the_partner_who_produced_nothing_reaches_the_page(): void
    {
        $this->fixture();

        $response = $this->actingAs($this->user)->get(route('campaigns.show', $this->campaign));

        $rows = $response->original->getData()['page']['props']['partner_conversion']['rows'];
        $silent = array_values(array_filter($rows, fn ($row) => $row['name'] === 'Silent Print Run'))[0];

        $this->assertSame(5, $silent['codes']);
        $this->assertSame(0, $silent['claims']);
        $this->assertTrue($silent['produced_nothing']);
    }

    public function test_a_full_page_load_runs_the_report(): void
    {
        $this->fixture();

        $this->assertTrue($this->tallyRanDuring(
            fn () => $this->actingAs($this->user)
                ->get(route('campaigns.show', $this->campaign))
                ->assertOk()
        ));
    }

    public function test_a_partial_reload_for_another_panel_does_not_run_the_report(): void
    {
        $this->fixture();

        $response = null;

        $ran = $this->tallyRanDuring(function () use (&$response) {
            $response = $this->actingAs($this->user)->get(
                route('campaigns.show', $this->campaign),
                $this->partialHeaders(['balance'])
            );
        });

        $response->assertOk();
        $this->assertFalse($ran);
        $this->assertArrayNotHasKey('partner_conversion', $response->json('props'));
    }

    public function test_a_partial_reload_for_the_report_still_runs_it(): void
    {
        $this->fixture();

        $response = null;

        $ran = $this->tallyRanDuring(function () use (&$response) {
            $response = $this->actingAs($this->user)->get(
                route('campaigns.show', $this->campaign),
                $this->partialHeaders(['partner_conversion'])
            );
        });

        $response->assertOk();
        $this->assertTrue($ran);
        $this->assertSame(1, $response->json('props.partner_conversion.summary.partners_producing_nothing'));
    }

    /** @return array<int, array<int, string>> */
    private function exportRows(string $body): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function export(): array
    {
        $response = $this->actingAs($this->user)->get(route('campaigns.export-partners', $this->campaign));
        $response->assertOk();

        return $this->exportRows($response->streamedContent());
    }

    public function test_the_export_writes_every_row_the_panel_shows(): void
    {
        $this->fixture();

        $rows = $this->export();

        $this->assertSame([
            'partner', 'kind', 'partner_status', 'codes_issued', 'codes_claimed',
            'claims', 'claim_rate_pct', 'produced_nothing',
        ], $rows[0]);

        $this->assertSame([
            ['Booth East', 'staff', 'live', '2', '2', '2', '100', 'no'],
            ['Booth West', 'staff', 'live', '4', '1', '1', '25', 'no'],
            ['Silent Print Run', 'print', 'live', '5', '0', '0', '0', 'yes'],
            // Three codes went out under nobody's name and none came back. The column
            // is about the codes on the row, so it says so here too; the summary's
            // count of partners who produced nothing is what excludes this row.
            ['Unassigned', '', 'unassigned', '3', '0', '0', '0', 'yes'],
        ], array_slice($rows, 1));
    }

    /**
     * The one column a reader sorts on to find what to change next time. Dropping the
     * zero rows from the export would undo on the way to the spreadsheet the thing the
     * report is for.
     *
     * It marks every block of codes that produced nothing, which includes the codes
     * assigned to nobody. Those are a real finding too: cards went out unattributed and
     * none came back. Only the summary's count is about partners, because only a partner
     * is somebody an organiser decides whether to hand cards to again.
     */
    public function test_the_export_marks_every_block_of_codes_that_produced_nothing(): void
    {
        $this->fixture();

        $flagged = array_column(array_filter(
            array_slice($this->export(), 1),
            fn ($row) => $row[7] === 'yes'
        ), 0);

        $this->assertSame(['Silent Print Run', 'Unassigned'], array_values($flagged));

        $response = $this->actingAs($this->user)->get(route('campaigns.show', $this->campaign));
        $summary = $response->original->getData()['page']['props']['partner_conversion']['summary'];

        $this->assertSame(1, $summary['partners_producing_nothing']);
    }

    /**
     * No codes issued is an empty cell, not a nought. A spreadsheet averaging the rate
     * column has to skip that partner rather than average in a zero they never earned.
     */
    public function test_the_export_leaves_the_rate_blank_where_no_codes_were_issued(): void
    {
        Partner::factory()->for($this->campaign)->create(['name' => 'Never Used', 'kind' => 'mailing']);

        $rows = array_slice($this->export(), 1);
        $row = array_values(array_filter($rows, fn ($r) => $r[0] === 'Never Used'))[0];

        $this->assertSame('', $row[6]);
        $this->assertSame('no', $row[7]);
    }

    /**
     * A partner name is operator-entered free text. Commas, quotes and newlines in it have
     * to survive the round trip as one field, or every column after it shifts and the
     * sheet reports one partner's codes against another's name.
     */
    public function test_a_partner_name_full_of_csv_punctuation_stays_one_field(): void
    {
        $awkward = "Smith, \"Bob\" & Co\nBooth 3";

        $partner = Partner::factory()->for($this->campaign)->create(['name' => $awkward, 'kind' => 'partner']);
        Code::factory()->count(2)->for($this->campaign)->create(['partner_id' => $partner->id]);

        $rows = array_slice($this->export(), 1);
        $row = array_values(array_filter($rows, fn ($r) => $r[0] === $awkward))[0];

        $this->assertCount(8, $row);
        $this->assertSame('2', $row[3]);
        $this->assertSame('yes', $row[7]);
    }

    /** A removed partner is marked as such rather than passed off as one still on the picker. */
    public function test_the_export_says_which_partners_were_removed(): void
    {
        $gone = Partner::factory()->for($this->campaign)->create(['name' => 'Last Year', 'kind' => 'print']);
        $codes = Code::factory()->count(2)->for($this->campaign)->create(['partner_id' => $gone->id]);
        Claim::factory()->for($codes[0])->create();
        $gone->delete();

        $rows = array_slice($this->export(), 1);
        $row = array_values(array_filter($rows, fn ($r) => $r[0] === 'Last Year'))[0];

        $this->assertSame('removed', $row[2]);
        $this->assertSame('2', $row[3]);
        $this->assertSame('1', $row[5]);
    }

    public function test_the_export_is_refused_to_somebody_elses_campaign(): void
    {
        $this->fixture();

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('campaigns.export-partners', $this->campaign))
            ->assertForbidden();
    }

    public function test_the_export_is_refused_to_a_guest(): void
    {
        $this->get(route('campaigns.export-partners', $this->campaign))
            ->assertRedirect(route('login'));
    }
}
