<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Partner;
use App\Support\QrExportFolders;
use App\Support\StorageDisk;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotent storage for generated QR export bundles.
 *
 * A campaign's sticker ZIP is generated once, keyed by a hash of the export settings
 * plus a "codes version" that changes whenever the codes (or the campaign's expiration)
 * change. A repeat download with identical inputs is served from storage — no paid
 * re-generation. Works over any Laravel disk: `local` for self-hosted, `s3` for SaaS.
 */
class QrExportService
{
    /** Every sticker at the root of the archive, the way an export has always looked. */
    public const GROUP_FLAT = 'flat';

    /** One folder per partner, plus a folder for the codes that were given to nobody. */
    public const GROUP_PARTNER = 'partner';

    /** @var list<string> */
    public const GROUPINGS = [self::GROUP_FLAT, self::GROUP_PARTNER];

    /** An export of only the codes that have no partner. */
    public const SCOPE_UNASSIGNED = 'unassigned';

    /** One row per code: which partner, which code, which file, and what the QR carries. */
    public const MANIFEST_CSV = 'manifest.csv';

    /** The count and the code list for one folder, beside the stickers it describes. */
    public const MANIFEST_TXT = 'manifest.txt';

    /** The sheet somebody prints and carries to the table. */
    public const HANDOVER_TXT = 'handover.txt';

    /** Resolved once per instance, so a fallback is decided (and logged) once per request. */
    protected ?string $diskName = null;

    /**
     * Optional so the service stays constructible with `new` — several tests and the cache
     * key path never render anything, and a renderer is only needed by renderInto().
     */
    public function __construct(protected ?QrStickerService $stickers = null) {}

    protected function stickers(): QrStickerService
    {
        return $this->stickers ??= app(QrStickerService::class);
    }

    /**
     * The configured storage disk (falls back to the app default, then to a disk that
     * actually works — see StorageDisk for why an unusable one can't just be used).
     */
    public function diskName(): string
    {
        return $this->diskName ??= StorageDisk::resolve(config('cardano.qr_storage.disk'));
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Deterministic cache key for a campaign + export settings. Same inputs → same key;
     * a change to the codes/expiration flows in via codesVersion() so the cache busts.
     *
     * Every input the archive's contents depend on has to be here, and the claim URL is one
     * of them: it is encoded into every sticker's payload. Before it was included, moving a
     * deployment to a claim subdomain rewrote every payload while leaving the key where it
     * was, so operators were served the previous archive and printed stickers pointing at
     * the old host. The resolved URL is taken rather than the configured domain, because the
     * short route is used only when it is actually registered: a cached route table built
     * without it degrades to the long URL, which changes the payload just as much.
     */
    public function cacheKey(Campaign $campaign, array $opts): string
    {
        return hash('sha256', json_encode([
            'campaign' => $campaign->id,
            'format' => $opts['format'],
            'size' => (string) $opts['size'],
            'dpi' => (int) $opts['dpi'],
            'ecc' => $opts['ecc'],
            'header' => (bool) $opts['header'],
            'footer' => (bool) $opts['footer'],
            'render' => $this->renderVersion(),
            'version' => $this->codesVersion($campaign),
            'claim' => $campaign->claimUrl(),
            // The archive's shape. A grouped export and a flat one of the same codes are two
            // different files, and a key that ignored this would serve whichever was built
            // first to whoever asked second.
            'group' => $this->grouping($opts),
            // Which codes are in it. Without this a partner's stack and the whole campaign
            // share a key, and an operator asking for one vendor's codes is handed the
            // archive of everybody's.
            'scope' => $this->scope($opts),
            // What the folders are called and what the manifests say. A partner renamed the
            // morning of an event changes every folder name and every manifest line while
            // leaving the codes untouched, so codesVersion() does not move and the archive
            // would otherwise still carry yesterday's name.
            'partners' => $this->partnersVersion($campaign),
        ]));
    }

    /**
     * Rendering revision baked into the cache key — bumping QrStickerService::RENDER_VERSION
     * invalidates every previously cached bundle. Overridable so the behaviour is testable.
     */
    protected function renderVersion(): int
    {
        return QrStickerService::RENDER_VERSION;
    }

    /**
     * A fingerprint that changes when the set of codes changes (add/remove/edit) or the
     * campaign's expiration (which prints as the header) changes. QR content depends only
     * on the code, the claim URL, and the expiration — not on rewards — so this suffices.
     */
    public function codesVersion(Campaign $campaign): string
    {
        // select([]) first clears the columns the Code model injects by default — its
        // `$withCount = ['rewards','claims']` adds `codes.*` plus count subqueries. Mixing
        // those non-aggregated columns with count()/max() and no GROUP BY is rejected by
        // MySQL under only_full_group_by (SQLite silently allows it, so tests miss it).
        $agg = $campaign->codes()
            ->select([])
            ->selectRaw('count(*) as c, max(updated_at) as m')
            ->first();

        return implode('|', [
            (int) ($agg->c ?? 0),
            (string) ($agg->m ?? ''),
            (string) ($campaign->updated_at?->getTimestamp() ?? 0),
        ]);
    }

    /**
     * A fingerprint that changes when the campaign's partners change.
     *
     * Renames are the reason this exists. A partner's name is on its folder and in three
     * manifests, and renaming one changes none of the codes, so nothing else in the key
     * moves. Deleted partners are counted too: a code keeps pointing at a soft-deleted
     * partner and keeps being filed under its name, so removing one has to bust the key as
     * surely as adding one does.
     */
    public function partnersVersion(Campaign $campaign): string
    {
        // The names themselves rather than a count and a timestamp, which is what this is
        // for: the names are what the archive carries. A timestamp cannot answer it anyway,
        // because both databases here store updated_at to the second, and a rename followed
        // by an export in that same second would leave the fingerprint exactly where it was
        // and serve the archive with the old name on every folder.
        //
        // Reading them all is affordable in a way that reading every code would not be: a
        // campaign has as many partners as an event has tables.
        $partners = Partner::withTrashed()
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get(['id', 'name', 'deleted_at'])
            ->map(static fn (Partner $partner) => [
                (string) $partner->id,
                (string) $partner->name,
                // A removed partner is still a folder, because its codes still point at it.
                // Removing one changes nothing else here, so it has to change this.
                $partner->deleted_at !== null,
            ])
            ->all();

        return hash('sha256', (string) json_encode($partners));
    }

    /** How this export is laid out, defaulting to the flat archive exports have always been. */
    public function grouping(array $opts): string
    {
        $group = $opts['group'] ?? self::GROUP_FLAT;

        return in_array($group, self::GROUPINGS, true) ? $group : self::GROUP_FLAT;
    }

    /** Which codes this export covers: all of them, one partner's, or the unassigned ones. */
    public function scope(array $opts): ?string
    {
        $scope = $opts['scope'] ?? null;

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    /**
     * The codes an export covers, in the order they are rendered.
     *
     * One place, used by the count the operator is shown, by the render, and by the
     * manifests. Three answers to "which codes" that could disagree would be three numbers
     * on screen that do not add up.
     */
    public function codesFor(Campaign $campaign, array $opts)
    {
        $scope = $this->scope($opts);

        return $campaign->codes()
            ->when($scope === self::SCOPE_UNASSIGNED, static fn ($query) => $query->whereNull('codes.partner_id'))
            ->when(
                $scope !== null && $scope !== self::SCOPE_UNASSIGNED,
                static fn ($query) => $query->where('codes.partner_id', $scope),
            );
    }

    /** How many codes this export covers. */
    public function codeCount(Campaign $campaign, array $opts): int
    {
        return $this->codesFor($campaign, $opts)->count();
    }

    public function path(Campaign $campaign, string $key): string
    {
        $base = trim(config('cardano.qr_storage.path', 'qr-exports'), '/');

        return "{$base}/{$campaign->id}/{$key}.zip";
    }

    public function exists(string $path, ?string $diskName = null): bool
    {
        return Storage::disk($diskName ?? $this->diskName())->exists($path);
    }

    /**
     * Render one batch of codes into a local archive, appending to whatever is already in it.
     *
     * The unit a caller can be interrupted between, and the only way stickers are rendered:
     * there is no render-it-all-in-one-call alternative, because nothing in the application
     * can promise to still be running at the end of a large one.
     *
     * A local temp file rather than the destination disk, because a ZIP is written by
     * seeking around it and a remote disk cannot be seeked. store() streams a finished file
     * onwards, and putPart() streams a half-finished one. sys_get_temp_dir() rather than the
     * application's storage directory: the one place every runtime this ships on guarantees
     * is writable, including a worker whose application directory is mounted read-only.
     *
     * Codes are taken in id order after $after, so a cursor is one integer and resuming is a
     * where clause rather than a count of rows to skip: a skip would move under an insert,
     * and re-reading rows already rendered would put duplicate entries in the archive.
     *
     * CREATE without OVERWRITE, so an archive carried over from an earlier attempt keeps
     * every entry it already holds. The archive is closed before returning, because close()
     * is what actually writes it: a caller that stores the file between batches must be
     * storing a file that is complete as far as it goes.
     *
     * @param  ?int  $after  Render codes with an id above this one. Null starts at the beginning.
     * @param  ?int  $limit  How many codes at most. Null renders the rest of the campaign.
     * @param  ?callable  $onEach  Called once per sticker written, with the code it was for.
     * @return array{cursor: ?int, rendered: int} The id to resume after, and how many were written.
     */
    public function renderInto(
        string $localZipPath,
        Campaign $campaign,
        array $opts,
        ?int $after = null,
        ?int $limit = null,
        ?callable $onEach = null,
    ): array {
        $codes = $this->codesFor($campaign, $opts)
            ->when($after !== null, static fn ($query) => $query->where('codes.id', '>', $after))
            ->orderBy('codes.id')
            ->when($limit !== null, static fn ($query) => $query->limit($limit))
            ->get();

        if ($codes->isEmpty()) {
            // Nothing to add. Opening the archive to write no entries would be the one way
            // to turn a finished render into a failure: ZipArchive refuses to close an
            // archive it was asked to create and given nothing to put in.
            return ['cursor' => $after, 'rendered' => 0];
        }

        $stickers = $this->stickers();

        // Built for every partner on the campaign rather than for the ones in this batch,
        // because a folder name depends on the whole set: two partners whose names slug the
        // same way are told apart by each other. A batch-sized view of that would file the
        // same partner in two different folders on two different attempts.
        $folders = $this->grouping($opts) === self::GROUP_PARTNER
            ? QrExportFolders::forCampaign($campaign)
            : null;

        $format = $opts['format'];
        $extension = $stickers->extension($format);
        $size = (float) $opts['size'];
        $dpi = (int) $opts['dpi'];
        $ecc = $opts['ecc'];
        $wantFooter = (bool) ($opts['footer'] ?? false);
        $headerText = ($opts['header'] ?? false) && $campaign->end_date
            ? 'Expires '.$campaign->end_date
            : null;

        // Read once and passed into the loop: it is the same for every sticker, and
        // resolving it per code would be a route lookup ten thousand times over.
        $claimBaseUrl = $campaign->claimUrl();

        $zip = new \ZipArchive;

        if ($zip->open($localZipPath, \ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Could not open a temporary archive at {$localZipPath}.");
        }

        $cursor = $after;
        $rendered = 0;

        foreach ($codes as $code) {
            $claimUri = $this->claimUri($claimBaseUrl, $code->code);

            $zip->addFromString(
                $this->entryName($code->code, $extension, $folders?->folderFor($code->partner_id)),
                $stickers->render(
                    $claimUri,
                    $format,
                    $size,
                    $dpi,
                    $ecc,
                    $headerText,
                    $wantFooter ? $code->code : null,
                ),
            );

            $cursor = (int) $code->id;
            $rendered++;

            if ($onEach !== null) {
                $onEach($code);
            }
        }

        // close() is where the archive is actually written, so it is the only place a full
        // disk or a vanished temp directory shows up. Reporting the failure here is the
        // difference between a task that says what went wrong and one that stores nothing
        // and calls itself finished. The file is left alone rather than deleted: a caller
        // resuming into it owns it, and everything written before this batch is still good.
        if (! $zip->close()) {
            throw new RuntimeException('The sticker archive could not be written to the temporary directory.');
        }

        return ['cursor' => $cursor, 'rendered' => $rendered];
    }

    /** What a sticker's QR carries: the claim endpoint, and the code, as a wallet deep link. */
    public function claimUri(string $claimBaseUrl, string $code): string
    {
        return 'web+cardano://claim/v1?faucet_url='.urlencode($claimBaseUrl).'&code='.$code;
    }

    /** Where one sticker goes inside the archive. The folder is a slug, or nothing at all. */
    private function entryName(string $code, string $extension, ?string $folder): string
    {
        return ($folder === null ? '' : $folder.'/').$code.'.'.$extension;
    }

    /**
     * Describe a finished archive from the inside, and write that description into it.
     *
     * Every number here is counted off the archive's own entries rather than off the
     * database. That is the whole point of the manifests: they have to describe the file the
     * operator is holding, so that the count in a folder's header, the codes listed under it
     * and the files sitting beside them are three ways of asking one question. Counting the
     * codes the export was meant to contain would produce manifests that are right about an
     * archive nobody has, which is the failure these sheets exist to catch.
     *
     * The database is asked for one thing only: the partner name behind each code, which is
     * text and is never part of a path.
     *
     * @return array{layout: string, extension: string, entries: int}
     */
    public function writeManifests(
        string $localZipPath,
        Campaign $campaign,
        array $opts,
        string $key,
        ?DateTimeInterface $at = null,
    ): array {
        $extension = $this->stickers()->extension($opts['format']);
        $grouped = $this->grouping($opts) === self::GROUP_PARTNER;
        $scope = $this->scope($opts);

        $zip = new \ZipArchive;

        if ($zip->open($localZipPath) !== true) {
            throw new RuntimeException('The finished archive could not be opened to describe it.');
        }

        $byFolder = $this->stickerEntries($zip, $extension);
        $folders = $grouped ? QrExportFolders::forCampaign($campaign) : null;
        $partners = $this->partnerNameByCode($campaign, $opts);
        $ref = $this->exportRef($key);
        // Through Carbon rather than off the argument, which may be any DateTimeInterface,
        // and in UTC because the sheet is read by whoever is holding it rather than in the
        // timezone of the machine that rendered it.
        $exportedAt = Carbon::instance($at ?? now())->utc()->format('Y-m-d H:i').' UTC';
        $claimBaseUrl = $campaign->claimUrl();

        $rows = [];
        $counts = [];

        foreach ($byFolder as $folder => $entries) {
            $counts[$folder] = count($entries);

            foreach ($entries as $code => $entry) {
                $rows[] = [
                    // The name the operator typed, read from the database. It appears here
                    // and in the manifest headers, and in neither place is it part of a path.
                    $this->oneLine((string) ($partners[$code] ?? '')),
                    $code,
                    $entry,
                    $this->claimUri($claimBaseUrl, $code),
                ];
            }
        }

        $zip->addFromString(self::MANIFEST_CSV, $this->manifestCsv($rows));

        if ($grouped) {
            foreach ($byFolder as $folder => $entries) {
                $zip->addFromString($folder.'/'.self::MANIFEST_TXT, $this->manifestText(
                    $campaign,
                    $folders->nameFor($folder),
                    array_keys($entries),
                    $ref,
                    $exportedAt,
                ));
            }

            $zip->addFromString(self::HANDOVER_TXT, $this->handoverText(
                $campaign,
                $counts,
                $folders,
                $ref,
                $exportedAt,
            ));
        }

        if (! $zip->close()) {
            throw new RuntimeException('The archive could not be written with its manifests.');
        }

        $summary = [
            'layout' => $grouped ? self::GROUP_PARTNER : self::GROUP_FLAT,
            'extension' => strtolower($opts['format']),
            'entries' => array_sum($counts),
        ];

        if ($grouped) {
            $summary['folders'] = array_values(array_map(
                static fn ($folder) => [
                    'folder' => $folder,
                    'partner' => $folders->nameFor($folder),
                    'codes' => $counts[$folder],
                ],
                array_keys($counts),
            ));
        }

        if ($scope !== null) {
            $summary['scope'] = $scope;
        }

        return $summary;
    }

    /** The short reference the manifests carry, so an operator can name the archive they hold. */
    public function exportRef(string $key): string
    {
        return substr($key, 0, 12);
    }

    /**
     * Every sticker in the archive, by folder and then by code.
     *
     * Entries that are not stickers are skipped rather than counted: a manifest written by
     * an earlier call is not a code, and counting one would add a phantom to the number the
     * folder is checked against.
     *
     * @return array<string, array<string, string>> folder => code => entry name
     */
    private function stickerEntries(\ZipArchive $zip, string $extension): array
    {
        $byFolder = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) ($zip->statIndex($i)['name'] ?? '');

            if (! str_ends_with($name, '.'.$extension)) {
                continue;
            }

            $folder = str_contains($name, '/') ? Str::beforeLast($name, '/') : '';
            $code = Str::beforeLast(Str::afterLast($name, '/'), '.');

            $byFolder[$folder][$code] = $name;
        }

        foreach (array_keys($byFolder) as $folder) {
            ksort($byFolder[$folder]);
        }

        uksort($byFolder, static function (string $a, string $b) {
            // The codes nobody was given read last, on every sheet. They are the leftovers of
            // the campaign rather than one of its partners, and a printed handover that opens
            // with them buries the names somebody is looking for.
            $rank = static fn (string $folder) => $folder === QrExportFolders::UNASSIGNED ? 1 : 0;

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return $byFolder;
    }

    /**
     * The partner behind each code, as text.
     *
     * Through the query builder rather than the model, so the two counts the Code model
     * attaches to every query are not run once per code to read one name each. The join is
     * plain rather than a relation for the same reason, and it reaches soft-deleted partners
     * on purpose: a code handed out under a name keeps that name after the partner is gone.
     *
     * @return array<string, ?string> code => partner name
     */
    private function partnerNameByCode(Campaign $campaign, array $opts): array
    {
        $scope = $this->scope($opts);

        return DB::table('codes')
            ->leftJoin('partners', 'partners.id', '=', 'codes.partner_id')
            ->where('codes.campaign_id', $campaign->id)
            ->when(
                $scope === self::SCOPE_UNASSIGNED,
                static fn (QueryBuilder $query) => $query->whereNull('codes.partner_id'),
            )
            ->when(
                $scope !== null && $scope !== self::SCOPE_UNASSIGNED,
                static fn (QueryBuilder $query) => $query->where('codes.partner_id', $scope),
            )
            ->select('codes.code as code', 'partners.name as partner')
            ->pluck('partner', 'code')
            ->all();
    }

    /**
     * One row per code: the partner, the code, the file it is in, and what its QR carries.
     *
     * Written with fputcsv rather than by joining commas, because a partner name is free
     * text: one with a comma in it would otherwise shift every column after it, and one with
     * a quote in it would break the row for anything reading the file.
     *
     * @param  list<list<string>>  $rows
     */
    private function manifestCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['partner', 'code', 'filename', 'claim_url']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * One folder's own sheet: what it is, how many codes are in it, and which ones.
     *
     * The count and the list are written from the same array, so a folder whose header says
     * a hundred lists a hundred. Checking those two against each other, and against the
     * files in the folder, is how somebody at a table proves a stack is complete.
     *
     * @param  list<string>  $codes
     */
    private function manifestText(
        Campaign $campaign,
        string $partner,
        array $codes,
        string $ref,
        string $exportedAt,
    ): string {
        $lines = [
            'Onboard.Ninja QR export',
            '',
            'Campaign:    '.$this->oneLine($campaign->name),
            'Partner:     '.$this->oneLine($partner),
            'Codes:       '.count($codes),
            'Exported:    '.$exportedAt,
            'Export ref:  '.$ref,
            '',
        ];

        return implode("\n", array_merge($lines, $codes))."\n";
    }

    /**
     * The sheet at the root: who gets what, and how many there are altogether.
     *
     * The total is added up from the same per-folder counts printed beside each name, so the
     * sheet can never say one thing while the folders it lists say another. A folder that
     * never reached the archive at all is a different failure, and it is caught before this
     * is written, by the run refusing to store an archive shorter than what it rendered.
     *
     * @param  array<string, int>  $counts
     */
    private function handoverText(
        Campaign $campaign,
        array $counts,
        QrExportFolders $folders,
        string $ref,
        string $exportedAt,
    ): string {
        $lines = [
            'Onboard.Ninja QR export',
            '',
            'Campaign:    '.$this->oneLine($campaign->name),
            'Exported:    '.$exportedAt,
            'Export ref:  '.$ref,
            '',
            sprintf('%-40s %-28s %7s', 'Partner', 'Folder', 'Codes'),
        ];

        foreach ($counts as $folder => $count) {
            $lines[] = sprintf(
                '%-40s %-28s %7d',
                $this->oneLine($folders->nameFor($folder), 40),
                $folder,
                $count,
            );
        }

        $lines[] = '';
        $lines[] = sprintf('%-40s %-28s %7d', 'Total', '', array_sum($counts));

        return implode("\n", $lines)."\n";
    }

    /**
     * A name, on one line and no longer than a column.
     *
     * A partner name is free text an operator typed, and these sheets are read line by line,
     * by a person and sometimes by a script. A name carrying a newline would otherwise write
     * its own lines into a manifest, and a second "Codes:" line is exactly the number these
     * sheets exist to be trusted about.
     */
    private function oneLine(string $value, int $limit = 80): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $value)), $limit, '');
    }

    /**
     * How many codes are read from the database at a time.
     *
     * Overridable so a test can render across a chunk boundary without needing a campaign
     * big enough to reach the real one.
     */
    protected function renderChunkSize(): int
    {
        return (int) config('cardano.qr_storage.chunk_size', 250);
    }

    /** A private file name in the one directory every runtime here guarantees is writable. */
    public function tempZipPath(): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/qr-export-'.Str::uuid()->toString().'.zip';
    }

    /**
     * Where a half-finished archive waits between attempts.
     *
     * Beside the finished archive and keyed the same way, so the partial for one set of
     * settings can never be picked up by a render of another. On the export disk rather than
     * the worker's temp directory because the attempt that resumes is usually not on the
     * machine that stopped: on a serverless runtime it certainly is not, and there a
     * partial in local temp is a partial nobody will ever see again.
     *
     * The suffix keeps it out of the way of anything looking for finished archives, which
     * all end in .zip.
     */
    public function partPath(Campaign $campaign, string $key): string
    {
        return $this->path($campaign, $key).'.part';
    }

    /** Put the work done so far somewhere the next attempt can reach it. */
    public function putPart(string $partPath, string $localZipPath): void
    {
        $this->store($partPath, $localZipPath);
    }

    /**
     * Fetch a previous attempt's partial archive into a local file to carry on writing to.
     *
     * False where there is nothing to fetch, which is an ordinary answer: the part may have
     * been pruned, or written to a disk this worker resolved differently, and starting over
     * is the correct response to either.
     */
    public function fetchPart(string $partPath, string $localZipPath): bool
    {
        if (! $this->disk()->exists($partPath)) {
            return false;
        }

        $source = $this->disk()->readStream($partPath);

        if (! is_resource($source)) {
            return false;
        }

        $target = fopen($localZipPath, 'w');

        if (! is_resource($target)) {
            fclose($source);

            return false;
        }

        $copied = stream_copy_to_stream($source, $target);

        fclose($source);
        fclose($target);

        return $copied !== false;
    }

    /** Throw away a partial archive. Safe to call when there is none. */
    public function deletePart(string $partPath): void
    {
        if ($this->disk()->exists($partPath)) {
            $this->disk()->delete($partPath);
        }
    }

    /**
     * How many entries a local archive holds, or null when it is not a readable archive.
     *
     * The question a resuming attempt has to answer before it appends anything: bytes that
     * arrived from a disk after an interrupted upload can be the right length and still not
     * open, and can open and hold fewer entries than the attempt that wrote them recorded.
     * Either way the file is not what the checkpoint says it is, and appending to it would
     * produce an archive short of stickers that nothing afterwards could tell from a good one.
     */
    public function entriesIn(string $localZipPath): ?int
    {
        if (! is_file($localZipPath)) {
            return null;
        }

        $zip = new \ZipArchive;

        if ($zip->open($localZipPath) !== true) {
            return null;
        }

        $entries = $zip->numFiles;
        $zip->close();

        return $entries;
    }

    /** Stream a local file into the configured disk (resource keeps memory flat for big ZIPs). */
    public function store(string $path, string $localZipPath): void
    {
        $stream = fopen($localZipPath, 'r');
        $this->disk()->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /**
     * Serve a stored export: redirect to a short-lived signed URL on disks that support it
     * (e.g. S3, which keeps the bytes out of the app's own HTTP response and clear of any
     * host response-size cap), otherwise stream the file directly (local disk / self-hosted).
     */
    public function respond(string $path, string $downloadName, ?string $diskName = null): Response
    {
        // A stored export records the disk its bytes actually went to, which is not always
        // the one configured now: an unusable cloud disk falls back at write time, and the
        // configuration can change between a render and a download a week later. Serving
        // from the recorded disk is the difference between a download and a 404.
        $diskName ??= $this->diskName();
        $disk = Storage::disk($diskName);

        // Remote disks (S3, etc.): hand back a short-lived signed URL and redirect, so the
        // bytes are served straight from the store and never through this response, which
        // a serverless host will cap at a few MB. Local disks stream the file directly.
        //
        // Asked of the disk rather than read off a configured driver name. A disk registered
        // at runtime has no driver in the configuration, and reading the absence as "not
        // local" sent the download to a signed URL that was never generated. What actually
        // decides this is whether the bytes are on this machine's filesystem, which the disk
        // itself knows. The second half covers a remote disk that cannot sign at all, such
        // as FTP: streaming it through the response is slower than a redirect and is not an
        // error, where asking it for a URL it has no way to produce is.
        if (! $disk instanceof LocalFilesystemAdapter
            && $disk instanceof FilesystemAdapter
            && $disk->providesTemporaryUrls()) {
            $url = $disk->temporaryUrl(
                $path,
                now()->addMinutes((int) config('cardano.qr_storage.url_ttl_minutes', 15)),
                [
                    'ResponseContentType' => 'application/zip',
                    'ResponseContentDisposition' => 'attachment; filename="'.$downloadName.'"',
                ],
            );

            return redirect()->away($url);
        }

        return $disk->download($path, $downloadName, ['Content-Type' => 'application/zip']);
    }
}
