<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\Partner;
use Illuminate\Support\Str;

/**
 * What each partner's folder inside a grouped sticker archive is called.
 *
 * A partner name is eighty characters of free text an operator typed at a booth, and the
 * archive is unzipped on somebody's laptop. So the name never reaches a path: a slug does,
 * and the slug is the only string that is ever joined to one. Nothing is concatenated onto
 * it afterwards, which is what closes the Zip Slip shape at the point it would open.
 *
 * Two folders may never be the same folder. The count in a folder's manifest, the codes
 * listed under it and the files beside them are the three numbers the whole feature rests
 * on, and two partners sharing a folder makes all three of them wrong at once. So the map
 * is built for every partner on the campaign together, rather than a name at a time, and
 * uniqueness is a property of the finished map rather than a hope about slugs.
 *
 * Deterministic: the same partners produce the same folders on every regeneration, so a
 * reprint lands in the folder the printer already has. Partners are walked in id order for
 * that reason, not the order a query happened to return them in.
 */
final class QrExportFolders
{
    /** Where codes with no partner go. Reserved: a partner slugging to it is moved aside. */
    public const UNASSIGNED = 'unassigned';

    /** What a code with no partner is called on a manifest. */
    public const UNASSIGNED_LABEL = 'Unassigned';

    /** Long enough to recognise a name in, short enough for a path on any operating system. */
    private const MAX_LENGTH = 40;

    /**
     * @param  array<string, string>  $folders  partner id => folder
     * @param  array<string, string>  $names  folder => the partner name it stands for
     */
    private function __construct(
        private readonly array $folders,
        private readonly array $names,
    ) {}

    /**
     * Every partner the campaign has ever had, including removed ones.
     *
     * withTrashed, because removing a partner is not the same as unassigning its codes: a
     * soft delete leaves codes pointing at the row, and those codes were handed out under
     * that name. Dropping them into `unassigned/` would take the attribution off a stack
     * somebody is holding. It also keeps the folder map stable when a partner is removed
     * mid-campaign, which a printer with half the run already done cares about.
     */
    public static function forCampaign(Campaign $campaign): self
    {
        return self::forPartners(
            Partner::withTrashed()
                ->where('campaign_id', $campaign->id)
                ->orderBy('id')
                ->get(['id', 'name'])
                ->all(),
        );
    }

    /**
     * @param  iterable<object|array{id: string, name: string}>  $partners
     */
    public static function forPartners(iterable $partners): self
    {
        $rows = [];

        foreach ($partners as $partner) {
            $id = (string) (is_array($partner) ? ($partner['id'] ?? '') : $partner->id);

            if ($id === '') {
                continue;
            }

            $rows[$id] = (string) (is_array($partner) ? ($partner['name'] ?? '') : $partner->name);
        }

        // Walked in id order so the map does not depend on how a query sorted its rows, and
        // so the last-resort numbering below lands on the same partner every time.
        ksort($rows);

        $shared = [];

        foreach ($rows as $name) {
            $slug = self::slug($name);

            if ($slug !== '') {
                $shared[$slug] = ($shared[$slug] ?? 0) + 1;
            }
        }

        $folders = [];
        $names = [self::UNASSIGNED => self::UNASSIGNED_LABEL];
        $taken = [self::UNASSIGNED => true];

        foreach ($rows as $id => $name) {
            $slug = self::slug($name);

            $candidate = match (true) {
                // Punctuation, or a name written entirely in characters that do not survive
                // transliteration. It is still somebody's partner and still needs a folder.
                $slug === '' => 'partner-'.self::tail($id, 8),
                // The reserved folder, and two partners whose names slug the same way. Both
                // are the same problem, so both get the same answer.
                $slug === self::UNASSIGNED, ($shared[$slug] ?? 0) > 1 => $slug.'-'.self::tail($id, 6),
                default => $slug,
            };

            // Belt and braces over the slug: whatever produced this string, only a lowercase
            // slug is ever joined to a path. A name that got this far with a separator, a
            // dot segment or an absolute path in it is replaced rather than repaired.
            if (! preg_match('/^[a-z0-9][a-z0-9-]{0,'.(self::MAX_LENGTH + 10).'}$/', $candidate)) {
                $candidate = 'partner-'.self::tail($id, 8);
            }

            $folders[$id] = $folder = self::free($candidate, $taken);
            $taken[$folder] = true;
            $names[$folder] = $name;
        }

        return new self($folders, $names);
    }

    /** The folder a code belongs in. Null, or a partner this campaign does not have, is unassigned. */
    public function folderFor(?string $partnerId): string
    {
        if ($partnerId === null || $partnerId === '') {
            return self::UNASSIGNED;
        }

        return $this->folders[$partnerId] ?? self::UNASSIGNED;
    }

    /** The name to print for a folder, which is the only place the operator's own text appears. */
    public function nameFor(string $folder): string
    {
        return $this->names[$folder] ?? self::UNASSIGNED_LABEL;
    }

    /** @return array<string, string> partner id => folder */
    public function all(): array
    {
        return $this->folders;
    }

    /**
     * The slug part of a folder name, or an empty string when the name gives nothing.
     *
     * Truncated before anything is appended, so a long name that needs disambiguating still
     * ends up inside a sane path length rather than at forty characters plus a suffix.
     */
    private static function slug(string $name): string
    {
        return trim(Str::limit(Str::slug($name), self::MAX_LENGTH, ''), '-');
    }

    /**
     * The end of an id, as a disambiguator.
     *
     * The end rather than the beginning, which is what a ULID makes it: the first ten
     * characters are the millisecond it was created in, so two partners added in the same
     * few minutes share their leading characters entirely and a prefix would disambiguate
     * nothing. The trailing characters are the random half.
     */
    private static function tail(string $id, int $length): string
    {
        $tail = preg_replace('/[^a-z0-9]/', '', Str::lower(substr($id, -$length)));

        return $tail === '' ? substr(hash('sha256', $id), 0, $length) : $tail;
    }

    /**
     * A folder nobody else has, given the ones already handed out.
     *
     * The suffix above is thirty bits of a partner's id, so reaching this is somewhere
     * between unlikely and never. It is here because "unlikely" is not the guarantee the
     * counts need: two partners in one folder is an archive whose every manifest is wrong,
     * and a number on the end costs nothing to keep that impossible.
     *
     * @param  array<string, bool>  $taken
     */
    private static function free(string $candidate, array $taken): string
    {
        if (! isset($taken[$candidate])) {
            return $candidate;
        }

        $suffix = 2;

        while (isset($taken[$candidate.'-'.$suffix])) {
            $suffix++;
        }

        return $candidate.'-'.$suffix;
    }
}
