<?php

namespace App\Services;

use App\Models\Campaign;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
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
    /** The configured storage disk (falls back to the app default). */
    public function diskName(): string
    {
        return config('cardano.qr_storage.disk') ?: config('filesystems.default');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Deterministic cache key for a campaign + export settings. Same inputs → same key;
     * a change to the codes/expiration flows in via codesVersion() so the cache busts.
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

    public function path(Campaign $campaign, string $key): string
    {
        $base = trim(config('cardano.qr_storage.path', 'qr-exports'), '/');

        return "{$base}/{$campaign->id}/{$key}.zip";
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
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
     * (e.g. S3 — avoids streaming the bytes back through Lambda and its 6 MB response cap),
     * otherwise stream the file directly (local disk / self-hosted).
     */
    public function respond(string $path, string $downloadName): Response
    {
        $disk = $this->disk();
        $driver = config("filesystems.disks.{$this->diskName()}.driver");

        // Remote disks (S3, etc.): hand back a short-lived signed URL and redirect, so the
        // bytes are served straight from the store — never streamed back through Lambda
        // (which caps responses at ~6 MB). Local disks stream the file directly.
        if ($driver !== 'local') {
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
