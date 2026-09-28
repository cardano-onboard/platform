<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Partner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which of the people a campaign handed codes to actually produced claims.
 *
 * An organiser gives a hundred cards to each of five partners. Four of them come back
 * with claims and one does not, and the one that does not is the finding: it is what
 * changes who gets cards next time. Everything here is built so that finding cannot be
 * lost.
 *
 * A partner with no claims is a row reading zero, never a missing row. The difference is
 * the whole point of the report. A row that is absent reads as data that was not
 * gathered, and an organiser looking at four rows has no way to tell a partner who
 * produced nothing from a partner whose numbers failed to load, so they discount both.
 * A row reading zero says the cards were handed out and nothing came back, which is an
 * answer.
 *
 * The same reasoning makes a removed partner stay in the report. Partners are soft
 * deleted, and the codes they were given keep pointing at the row, so dropping them here
 * would take their codes out of the report without putting them anywhere else and the
 * columns would quietly stop adding up.
 *
 * Codes nobody was assigned are their own row rather than excluded or folded into a
 * partner. Excluding them makes the totals disagree with the codes table on the same
 * page, and folding them into a partner credits somebody with cards they were never
 * given.
 *
 * Shaped after App\Services\OnboardingAnalysisService: per-campaign rows, a summarize()
 * over them for the headline figures, and percentages rounded to a single decimal. It
 * differs in one place deliberately. That service reports zero for a percentage with no
 * denominator, which suits it because its denominator is a wallet population that is
 * either there or is not. Here an empty denominator is null, because a partner with no
 * codes and a partner whose hundred cards produced nothing are the two findings this
 * report exists to tell apart, and reporting both as zero per cent merges them.
 */
class PartnerConversionService
{
    /** What the row for codes that were never assigned to anybody is called. */
    public const UNASSIGNED_LABEL = 'Unassigned';

    /**
     * Array key standing in for "no partner". Not a ULID, so it cannot collide with a
     * real partner id, and not null, because PHP casts a null array key to the empty
     * string and the two would stop being distinguishable.
     */
    private const UNASSIGNED_KEY = '__unassigned__';

    /**
     * The whole report: one row per partner plus the unassigned row, and the headline
     * figures over them.
     *
     * The page and the CSV export both read this, so the sheet an organiser downloads
     * cannot disagree with the panel they downloaded it from.
     */
    public function for(Campaign $campaign): array
    {
        $rows = $this->rows($campaign);

        return [
            'rows' => $rows->all(),
            'summary' => $this->summarize($rows),
        ];
    }

    /**
     * One row per partner, ranked by claim rate, with the unassigned row last.
     *
     * Unassigned sorts outside the ranking rather than into it. It is a bucket rather
     * than somebody who was handed cards, and placing it among the partners invites a
     * reader to compare it with them as though it had competed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Campaign $campaign): Collection
    {
        $tally = $this->tally($campaign);

        // withTrashed, not Campaign::partners(). That relation is the picker's list and
        // excludes removed partners on purpose, which is right for a form offering a
        // choice and wrong for a report of what already happened.
        $partners = Partner::withTrashed()
            ->where('campaign_id', $campaign->id)
            ->get(['id', 'name', 'kind', 'deleted_at']);

        $rows = $partners
            // A removed partner that never had a code is genuinely nothing: no cards were
            // handed out under that name, so there is no result to report and no total it
            // belongs in. A removed partner that does have codes stays, marked as removed.
            ->filter(fn (Partner $partner) => $partner->deleted_at === null
                || ($tally[$partner->id]['codes'] ?? 0) > 0)
            ->map(fn (Partner $partner) => $this->row(
                $tally[$partner->id] ?? [],
                $partner->name,
                $partner->id,
                $partner->kind,
                $partner->deleted_at !== null,
            ))
            ->sort($this->ranking(...))
            ->values();

        return $rows->push($this->row(
            $tally[self::UNASSIGNED_KEY] ?? [],
            self::UNASSIGNED_LABEL,
            null,
            null,
            false,
        ))->values();
    }

    /**
     * Headline figures over the rows.
     *
     * Partner totals exclude the unassigned row wherever the figure is about partners,
     * and include it wherever the figure is about the campaign's codes, because an
     * organiser asking how many of their codes were claimed means all of them.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function summarize(Collection $rows): array
    {
        $partners = $rows->where('is_unassigned', false);
        $unassigned = $rows->firstWhere('is_unassigned', true) ?? [];

        $codes = (int) $rows->sum('codes');
        $codesClaimed = (int) $rows->sum('codes_claimed');

        return [
            'partners' => $partners->count(),
            'partners_with_codes' => $partners->where('codes', '>', 0)->count(),
            // The finding. Named on its own rather than left to be counted off the table,
            // because it is what the report is for.
            'partners_producing_nothing' => $partners->where('produced_nothing', true)->count(),
            'codes' => $codes,
            'codes_claimed' => $codesClaimed,
            'claims' => (int) $rows->sum('claims'),
            'claim_rate' => self::rate($codesClaimed, $codes),
            'unassigned_codes' => (int) ($unassigned['codes'] ?? 0),
            'unassigned_claims' => (int) ($unassigned['claims'] ?? 0),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Codes, claims and claimed codes for every partner on the campaign at once.
     *
     * One grouped query over the join rather than a count per partner, so a campaign with
     * forty partners costs the same as one with two.
     *
     * COUNT(DISTINCT codes.id) rather than COUNT(*), because the left join repeats a code
     * once per claim against it and a multi-use code would otherwise be counted as
     * several codes issued. COUNT(claims.id) skips the nulls the join produces for a code
     * nobody claimed, so a partner whose codes were never touched counts zero claims
     * rather than one per code.
     *
     * @return array<string, array{codes: int, claims: int, codes_claimed: int}>
     */
    private function tally(Campaign $campaign): array
    {
        $rows = DB::table('codes')
            ->leftJoin('claims', 'claims.code_id', '=', 'codes.id')
            ->where('codes.campaign_id', $campaign->id)
            ->groupBy('codes.partner_id')
            ->selectRaw('codes.partner_id')
            ->selectRaw('COUNT(DISTINCT codes.id) as codes')
            ->selectRaw('COUNT(claims.id) as claims')
            // How many codes were claimed at least once, which is not the number of
            // claims once a code allows more than one use.
            ->selectRaw('COUNT(DISTINCT CASE WHEN claims.id IS NULL THEN NULL ELSE codes.id END) as codes_claimed')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->partner_id ?? self::UNASSIGNED_KEY] = [
                'codes' => (int) $row->codes,
                'claims' => (int) $row->claims,
                'codes_claimed' => (int) $row->codes_claimed,
            ];
        }

        return $out;
    }

    /**
     * @param  array{codes?: int, claims?: int, codes_claimed?: int}  $tally
     * @return array<string, mixed>
     */
    private function row(array $tally, string $name, ?string $partnerId, ?string $kind, bool $removed): array
    {
        $codes = (int) ($tally['codes'] ?? 0);
        $claims = (int) ($tally['claims'] ?? 0);
        $codesClaimed = (int) ($tally['codes_claimed'] ?? 0);

        return [
            'partner_id' => $partnerId,
            'name' => $name,
            'kind' => $kind,
            'is_unassigned' => $partnerId === null,
            'removed' => $removed,
            'codes' => $codes,
            'codes_claimed' => $codesClaimed,
            'claims' => $claims,
            'claim_rate' => self::rate($codesClaimed, $codes),
            // The flag an organiser acts on: cards were handed out under this name and
            // not one of them came back. A partner with no codes is not this, and saying
            // it was would blame somebody for cards they were never given.
            'produced_nothing' => $codes > 0 && $claims === 0,
        ];
    }

    /**
     * Best claim rate first, then by name so the order never depends on which rows the
     * database happened to return first.
     *
     * A partner with no codes has no rate to be ranked on and sorts below every partner
     * that has one, rather than among the partners who scored zero. It did not score
     * zero; it was not measured.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function ranking(array $a, array $b): int
    {
        $byRate = ($b['claim_rate'] ?? -1.0) <=> ($a['claim_rate'] ?? -1.0);

        return $byRate !== 0 ? $byRate : strcasecmp($a['name'], $b['name']);
    }

    /**
     * A percentage to one decimal place, or null where there is no denominator.
     *
     * Null rather than zero: no codes issued is not a nought per cent claim rate, and the
     * distance between those two readings is the report.
     */
    private static function rate(int $of, int $total): ?float
    {
        return $total > 0 ? round(100 * $of / $total, 1) : null;
    }
}
