<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Partner;
use App\Models\User;
use App\Services\PartnerConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which partners produced claims, and the one thing this report must never do.
 *
 * A partner who produced nothing has to be a row reading zero. An absent row reads as
 * data that failed to load, so an organiser discounts it; a zero row is an answer they
 * act on. Most of what follows exists to hold that difference in place, against a removed
 * partner, a partner with no codes at all, codes assigned to nobody, and a partner an
 * operator has confusingly named "Unassigned".
 *
 * The arithmetic is checked against a fixture whose answer is written out by hand below,
 * so the expected numbers do not come from the same code that produces them.
 */
class PartnerConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Campaign $campaign;

    /** @var array<string, Partner> */
    private array $partners = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'start_date' => now()->subWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);
    }

    /**
     * A campaign whose answer is known before the code is run.
     *
     *   Alpha      4 codes, every one claimed once      4 codes, 4 claimed,  4 claims, 100.0%
     *   Bravo      5 codes, one of them multi-use       5 codes, 2 claimed,  4 claims,  40.0%
     *   Charlie    3 codes, nothing came back           3 codes, 0 claimed,  0 claims,   0.0%  <- the finding
     *   Delta      set up, never handed a code          0 codes, 0 claimed,  0 claims,   no rate
     *   Echo       2 codes, then removed from the picker 2 codes, 1 claimed, 1 claim,   50.0%
     *   Foxtrot    removed, never handed a code         absent entirely
     *   Unassigned 2 codes nobody was assigned          2 codes, 1 claimed,  1 claim,   50.0%
     *
     * Totals: 16 codes, 8 of them claimed, 10 claims, 50.0% overall.
     */
    private function knownCampaign(): void
    {
        $alpha = $this->partner('Alpha', 'staff');
        foreach ($this->codesFor($alpha, 4) as $code) {
            Claim::factory()->for($code)->create();
        }

        $bravo = $this->partner('Bravo', 'partner');
        // One code allowing five uses, claimed three times. Three claims against one card
        // is three claims and one card, and a rate that read 60% here would be counting
        // the same card three times.
        $multi = Code::factory()->for($this->campaign)->create(['partner_id' => $bravo->id, 'uses' => 5]);
        Claim::factory()->count(3)->for($multi)->create();
        $single = Code::factory()->for($this->campaign)->create(['partner_id' => $bravo->id]);
        Claim::factory()->for($single)->create();
        $this->codesFor($bravo, 3);

        $charlie = $this->partner('Charlie', 'print');
        $this->codesFor($charlie, 3);

        $this->partner('Delta', 'mailing');

        $echo = $this->partner('Echo', 'staff');
        $echoCodes = $this->codesFor($echo, 2);
        Claim::factory()->for($echoCodes[0])->create();
        $echo->delete();

        $this->partner('Foxtrot', 'other')->delete();

        $unassigned = Code::factory()->count(2)->for($this->campaign)->create(['partner_id' => null]);
        Claim::factory()->for($unassigned[0])->create();
    }

    private function partner(string $name, ?string $kind = null): Partner
    {
        return $this->partners[$name] = Partner::factory()
            ->for($this->campaign)
            ->create(['name' => $name, 'kind' => $kind]);
    }

    /** @return array<int, Code> */
    private function codesFor(Partner $partner, int $count): array
    {
        return Code::factory()
            ->count($count)
            ->for($this->campaign)
            ->create(['partner_id' => $partner->id])
            ->all();
    }

    private function report(): array
    {
        return app(PartnerConversionService::class)->for($this->campaign);
    }

    /** @return array<string, mixed> */
    private function rowNamed(array $report, string $name): array
    {
        foreach ($report['rows'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("No row named {$name}. Rows present: ".implode(', ', array_column($report['rows'], 'name')));
    }

    /**
     * The claim the whole report rests on. Charlie was handed three codes and nothing came
     * back, and that has to arrive as a row of zeros carrying a flag, not as a gap in the
     * table where an organiser cannot tell a bad partner from a failed query.
     */
    public function test_a_partner_who_produced_nothing_is_a_row_of_zeros_rather_than_an_absent_row(): void
    {
        $this->knownCampaign();

        $charlie = $this->rowNamed($this->report(), 'Charlie');

        $this->assertSame(3, $charlie['codes']);
        $this->assertSame(0, $charlie['codes_claimed']);
        $this->assertSame(0, $charlie['claims']);
        $this->assertSame(0.0, $charlie['claim_rate']);
        $this->assertTrue($charlie['produced_nothing']);
    }

    /**
     * Every figure on every row, against the table written out above. Asserted in one
     * place so a change that shifts one number cannot be waved through as a change to a
     * different test's expectation.
     */
    public function test_the_arithmetic_matches_a_fixture_with_a_known_answer(): void
    {
        $this->knownCampaign();

        $report = $this->report();

        $expected = [
            //          codes, claimed, claims, rate,  produced nothing
            'Alpha' => [4, 4, 4, 100.0, false],
            'Bravo' => [5, 2, 4, 40.0, false],
            'Charlie' => [3, 0, 0, 0.0, true],
            'Delta' => [0, 0, 0, null, false],
            'Echo' => [2, 1, 1, 50.0, false],
            'Unassigned' => [2, 1, 1, 50.0, false],
        ];

        foreach ($expected as $name => [$codes, $claimed, $claims, $rate, $nothing]) {
            $row = $this->rowNamed($report, $name);

            $this->assertSame($codes, $row['codes'], "{$name} codes issued");
            $this->assertSame($claimed, $row['codes_claimed'], "{$name} codes claimed");
            $this->assertSame($claims, $row['claims'], "{$name} claims");
            $this->assertSame($rate, $row['claim_rate'], "{$name} claim rate");
            $this->assertSame($nothing, $row['produced_nothing'], "{$name} produced nothing");
        }

        $this->assertCount(count($expected), $report['rows']);
    }

    public function test_a_partner_whose_codes_were_all_claimed_reports_a_full_rate(): void
    {
        $this->knownCampaign();

        $alpha = $this->rowNamed($this->report(), 'Alpha');

        $this->assertSame(4, $alpha['codes']);
        $this->assertSame(4, $alpha['codes_claimed']);
        $this->assertSame(100.0, $alpha['claim_rate']);
        $this->assertFalse($alpha['produced_nothing']);
    }

    /**
     * Three claims against one five-use code is one card that came back, not three. A rate
     * built on claims rather than on codes would read 80% for Bravo and could pass 100%
     * on a campaign of multi-use codes, which is not a rate.
     */
    public function test_a_multi_use_code_counts_once_towards_the_rate_and_its_claims_are_still_shown(): void
    {
        $this->knownCampaign();

        $bravo = $this->rowNamed($this->report(), 'Bravo');

        $this->assertSame(5, $bravo['codes']);
        $this->assertSame(2, $bravo['codes_claimed']);
        $this->assertSame(4, $bravo['claims']);
        $this->assertSame(40.0, $bravo['claim_rate']);
    }

    /**
     * A partner who was never handed a code did not score nought per cent; they were not
     * measured. Reporting zero there would put them next to Charlie, whose three codes
     * genuinely produced nothing, and the two call for opposite responses.
     */
    public function test_a_partner_with_no_codes_has_no_rate_rather_than_a_rate_of_zero(): void
    {
        $this->knownCampaign();

        $delta = $this->rowNamed($this->report(), 'Delta');

        $this->assertSame(0, $delta['codes']);
        $this->assertNull($delta['claim_rate']);
        $this->assertFalse($delta['produced_nothing']);
    }

    /**
     * Unassigned codes are a row. Dropping them would make the report's totals disagree
     * with the codes table on the same page, and folding them into a partner would credit
     * somebody with cards they were never handed.
     */
    public function test_unassigned_codes_are_their_own_row_and_are_not_folded_into_a_partner(): void
    {
        $this->knownCampaign();

        $report = $this->report();
        $unassigned = $this->rowNamed($report, 'Unassigned');

        $this->assertTrue($unassigned['is_unassigned']);
        $this->assertNull($unassigned['partner_id']);
        $this->assertSame(2, $unassigned['codes']);
        $this->assertSame(1, $unassigned['claims']);

        // No partner absorbed them: the partner rows add up to the codes that carry a
        // partner id, and the unassigned row holds the rest.
        $partnerCodes = array_sum(array_column(
            array_filter($report['rows'], fn ($row) => ! $row['is_unassigned']),
            'codes'
        ));

        $this->assertSame(
            Code::where('campaign_id', $this->campaign->id)->whereNotNull('partner_id')->count(),
            $partnerCodes
        );
    }

    /** The bucket is always there, so its absence never has to be interpreted. */
    public function test_the_unassigned_row_is_present_even_when_every_code_has_a_partner(): void
    {
        $partner = $this->partner('Only');
        $this->codesFor($partner, 3);

        $unassigned = $this->rowNamed($this->report(), 'Unassigned');

        $this->assertSame(0, $unassigned['codes']);
        $this->assertSame(0, $unassigned['claims']);
        $this->assertNull($unassigned['claim_rate']);
        // Nothing was handed out under it, so it is not a partner who produced nothing.
        $this->assertFalse($unassigned['produced_nothing']);
    }

    /**
     * An operator is free to name a partner "Unassigned". That partner's codes are still
     * theirs and must not merge with the codes nobody was assigned, or the one row would
     * report two different things.
     */
    public function test_a_partner_named_unassigned_stays_separate_from_the_unassigned_codes(): void
    {
        $impostor = $this->partner('Unassigned');
        $this->codesFor($impostor, 4);
        Code::factory()->count(2)->for($this->campaign)->create(['partner_id' => null]);

        $rows = $this->report()['rows'];
        $named = array_values(array_filter($rows, fn ($row) => $row['name'] === 'Unassigned'));

        $this->assertCount(2, $named);

        $partnerRow = array_values(array_filter($named, fn ($row) => ! $row['is_unassigned']))[0];
        $bucketRow = array_values(array_filter($named, fn ($row) => $row['is_unassigned']))[0];

        $this->assertSame($impostor->id, $partnerRow['partner_id']);
        $this->assertSame(4, $partnerRow['codes']);
        $this->assertNull($bucketRow['partner_id']);
        $this->assertSame(2, $bucketRow['codes']);
    }

    /**
     * Removing a partner takes them off the picker. It must not take their codes out of
     * the report, or the codes they were handed disappear from every total without
     * appearing anywhere else.
     */
    public function test_a_removed_partner_keeps_its_codes_in_the_report(): void
    {
        $this->knownCampaign();

        $echo = $this->rowNamed($this->report(), 'Echo');

        $this->assertTrue($echo['removed']);
        $this->assertSame(2, $echo['codes']);
        $this->assertSame(1, $echo['claims']);
        $this->assertSame(50.0, $echo['claim_rate']);
    }

    /** A removed partner who was never handed a code has no result to report. */
    public function test_a_removed_partner_that_never_had_codes_is_left_out(): void
    {
        $this->knownCampaign();

        $this->assertNotContains('Foxtrot', array_column($this->report()['rows'], 'name'));
    }

    /**
     * Best rate first, the unmeasured below every partner that has a rate, and the
     * unassigned bucket outside the ranking entirely. It did not compete for anything.
     */
    public function test_rows_are_ranked_by_claim_rate_with_the_unassigned_bucket_last(): void
    {
        $this->knownCampaign();

        $this->assertSame(
            ['Alpha', 'Echo', 'Bravo', 'Charlie', 'Delta', 'Unassigned'],
            array_column($this->report()['rows'], 'name')
        );
    }

    /** Equal rates fall back to the name, so two runs cannot disagree about the order. */
    public function test_partners_on_the_same_rate_are_ordered_by_name(): void
    {
        foreach (['Zulu', 'Mike', 'Alpha'] as $name) {
            $partner = $this->partner($name);
            foreach ($this->codesFor($partner, 2) as $code) {
                Claim::factory()->for($code)->create();
            }
        }

        $this->assertSame(
            ['Alpha', 'Mike', 'Zulu', 'Unassigned'],
            array_column($this->report()['rows'], 'name')
        );
    }

    public function test_the_summary_totals_agree_with_the_rows(): void
    {
        $this->knownCampaign();

        $report = $this->report();
        $summary = $report['summary'];

        $this->assertSame(5, $summary['partners']);
        $this->assertSame(4, $summary['partners_with_codes']);
        $this->assertSame(1, $summary['partners_producing_nothing']);
        $this->assertSame(16, $summary['codes']);
        $this->assertSame(8, $summary['codes_claimed']);
        $this->assertSame(10, $summary['claims']);
        $this->assertSame(50.0, $summary['claim_rate']);
        $this->assertSame(2, $summary['unassigned_codes']);
        $this->assertSame(1, $summary['unassigned_claims']);

        // The totals are the rows added up, not a second query that could drift from them.
        $this->assertSame($summary['codes'], array_sum(array_column($report['rows'], 'codes')));
        $this->assertSame($summary['claims'], array_sum(array_column($report['rows'], 'claims')));
    }

    /** No codes at all: every figure is zero and no rate is claimed for any of them. */
    public function test_a_campaign_with_no_codes_reports_no_rate_anywhere(): void
    {
        $this->partner('Nobody');

        $report = $this->report();

        $this->assertNull($report['summary']['claim_rate']);
        $this->assertSame(0, $report['summary']['codes']);
        $this->assertSame(0, $report['summary']['partners_producing_nothing']);

        foreach ($report['rows'] as $row) {
            $this->assertNull($row['claim_rate'], $row['name'].' claimed a rate with no codes');
        }
    }

    /**
     * A code with no use limit is still one code. It is the campaign shape that breaks a
     * denominator built on uses rather than on codes: summing uses gives zero here, and a
     * rate over zero is either a division by zero or a silent 100%.
     */
    public function test_an_unlimited_code_counts_as_one_code(): void
    {
        $partner = $this->partner('Unlimited');
        $code = Code::factory()->for($this->campaign)->create(['partner_id' => $partner->id, 'uses' => 0]);
        Claim::factory()->count(7)->for($code)->create();

        $row = $this->rowNamed($this->report(), 'Unlimited');

        $this->assertSame(1, $row['codes']);
        $this->assertSame(1, $row['codes_claimed']);
        $this->assertSame(7, $row['claims']);
        $this->assertSame(100.0, $row['claim_rate']);
    }

    /**
     * A claim that failed on chain is still somebody who turned up with the card, so the
     * card did its job and the partner who handed it out gets the credit. Counting only
     * completed claims would also put this panel at odds with the claims column in the
     * codes table on the same page, which counts every claim row.
     */
    public function test_a_failed_claim_still_counts_as_the_card_having_been_used(): void
    {
        $partner = $this->partner('Flaky');
        $code = Code::factory()->for($this->campaign)->create(['partner_id' => $partner->id]);
        Claim::factory()->failed()->for($code)->create();

        $row = $this->rowNamed($this->report(), 'Flaky');

        $this->assertSame(1, $row['claims']);
        $this->assertFalse($row['produced_nothing']);
    }

    /**
     * The tenant boundary. Another campaign's partners must not appear here, and its
     * codes and claims must not reach these totals even where the partner names match.
     */
    public function test_another_campaigns_partners_codes_and_claims_are_not_counted(): void
    {
        $this->knownCampaign();

        $other = Campaign::factory()->for(User::factory())->create();
        $otherPartner = Partner::factory()->for($other)->create(['name' => 'Alpha']);
        $otherCodes = Code::factory()->count(9)->for($other)->create(['partner_id' => $otherPartner->id]);
        Claim::factory()->for($otherCodes[0])->create();
        Code::factory()->count(5)->for($other)->create(['partner_id' => null]);

        $report = $this->report();

        $this->assertSame(4, $this->rowNamed($report, 'Alpha')['codes']);
        $this->assertSame(16, $report['summary']['codes']);
        $this->assertSame(2, $report['summary']['unassigned_codes']);
        $this->assertSame(5, $report['summary']['partners']);
    }

    /**
     * Forty partners cost the same as two. The figures come from one grouped query over
     * the codes plus one read of the partners, so a busy campaign does not turn the
     * campaign page into a query per partner.
     */
    public function test_the_report_costs_the_same_however_many_partners_there_are(): void
    {
        $this->knownCampaign();

        $few = $this->queriesDuring(fn () => $this->report());

        for ($i = 0; $i < 40; $i++) {
            $partner = $this->partner('Bulk '.$i);
            $this->codesFor($partner, 2);
        }

        $many = $this->queriesDuring(fn () => $this->report());

        $this->assertSame(2, $few);
        $this->assertSame($few, $many);
    }

    private function queriesDuring(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
