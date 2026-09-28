<?php

namespace App\Services;

use App\Cardano\AssetMetadataReader;
use App\Models\KnownAsset;

/**
 * The name, ticker, decimals and logo a page shows for a native asset, keyed by subject
 * (policy id + asset name hex).
 *
 * Read from the known_assets table first, which holds off-chain registry tokens only, then
 * from the cached Koios answers (see KoiosService::ttlFor). Only a registry token is ever
 * written to known_assets: CIP-0068 and CIP-0025 metadata live in the cache, where they
 * expire, and nothing a user types reaches either.
 */
class AssetDisplay
{
    /** Set in a known_assets row's metadata once its logo has been read from the registry. */
    public const LOGO_CHECKED = 'logo_checked';

    public function __construct(private readonly KoiosService $koios) {}

    /**
     * What is known without calling Koios. An asset with nothing cached is absent from the
     * result, so the page knows to ask for it; one known to be missing maps to null.
     *
     * @param  list<array{0: string, 1: string}>  $assets  [policy id, asset name hex] pairs
     * @return array<string, array|null>
     */
    public function cached(array $assets, string $network): array
    {
        $wanted = self::keyed($assets);
        if ($wanted === []) {
            return [];
        }

        [$results, $unchecked] = $this->fromRegistryTable($wanted, $network);

        foreach ($this->koios->cachedAssetInfo(array_values(array_diff_key($wanted, $results)), $network) as $subject => $info) {
            // A registry row Koios is remembered not to know still has its own decimals.
            $results[$subject] = $info === null ? ($unchecked[$subject] ?? null) : self::display($info);
        }

        // A registry row with nothing cached is left for the page to ask about, so its
        // logo is fetched; the lookup falls back to the row if Koios does not answer.
        return $results;
    }

    /**
     * Every asset, calling Koios once for whatever is not already known.
     *
     * @param  list<array{0: string, 1: string}>  $assets
     * @return array<string, array|null>
     */
    public function resolve(array $assets, string $network): array
    {
        $wanted = self::keyed($assets);
        [$results, $unchecked] = $this->fromRegistryTable($wanted, $network);
        $missing = array_diff_key($wanted, $results);

        if ($missing === []) {
            return $results;
        }

        $answers = $this->koios->assetInfoMany(array_values($missing), $network);

        foreach (array_keys($missing) as $subject) {
            $info = $answers[$subject] ?? null;

            if ($info !== null) {
                $results[$subject] = self::display($info);
                self::remember($info, $network);
            } elseif (isset($unchecked[$subject])) {
                // Koios failed or does not know it, but the registry sync does: its name
                // and decimals still stand, without a logo.
                $results[$subject] = $unchecked[$subject];
            } elseif (array_key_exists($subject, $answers)) {
                $results[$subject] = null;
            }
        }

        return $results;
    }

    /** Writes a registry token to known_assets; anything else is left to the cache. */
    public static function remember(array $info, string $network): ?KnownAsset
    {
        if (($info['source'] ?? null) !== AssetMetadataReader::SOURCE_REGISTRY) {
            return null;
        }

        return KnownAsset::updateOrCreate(
            [
                'policy_id' => strtolower($info['policy_id']),
                'asset_name' => strtolower($info['asset_name'] ?? ''),
                'network' => $network,
            ],
            [
                'ticker' => $info['ticker'] ?? null,
                'name' => $info['name'] ?? null,
                'fingerprint' => $info['fingerprint'] ?? null,
                'decimals' => $info['decimals'] ?? 0,
                'logo' => $info['logo'] ?? null,
                'description' => $info['description'] ?? null,
                'metadata' => [
                    'ascii' => $info['asset_name_ascii'] ?? null,
                    self::LOGO_CHECKED => true,
                ],
            ]
        );
    }

    /** The fields a page needs to show an asset, and nothing it does not. */
    public static function display(array $asset): array
    {
        return [
            'ticker' => $asset['ticker'] ?? null,
            'name' => $asset['name'] ?? null,
            'decimals' => (int) ($asset['decimals'] ?? 0),
            'logo' => $asset['logo'] ?? null,
        ];
    }

    /**
     * Registry tokens from known_assets whose logo has been fetched from the registry. A
     * row the registry sync wrote carries no logo, because the sync leaves logos out to
     * keep the table small, so it is fetched once through Koios and remembered with the
     * LOGO_CHECKED marker before this table answers for it.
     */
    /**
     * @return array{0: array<string, array>, 1: array<string, array>} rows whose logo has
     *                                                                 been fetched, and rows only the sync has written (display fields, no logo)
     */
    private function fromRegistryTable(array $wanted, string $network): array
    {
        if ($wanted === []) {
            return [[], []];
        }

        $results = [];
        $unchecked = [];

        KnownAsset::query()
            ->where('network', $network)
            ->whereIn('policy_id', array_values(array_unique(array_column($wanted, 0))))
            ->whereIn('asset_name', array_values(array_unique(array_column($wanted, 1))))
            ->get(['policy_id', 'asset_name', 'ticker', 'name', 'decimals', 'logo', 'metadata'])
            ->each(function (KnownAsset $known) use (&$results, &$unchecked, $wanted) {
                $subject = $known->policy_id.$known->asset_name;
                if (! isset($wanted[$subject])) {
                    return;
                }
                if (self::logoChecked($known)) {
                    $results[$subject] = self::display($known->toArray());
                } else {
                    $unchecked[$subject] = array_merge(self::display($known->toArray()), ['logo' => null]);
                }
            });

        return [$results, $unchecked];
    }

    /** @return array<string, array{0: string, 1: string}> */
    private static function keyed(array $assets): array
    {
        $wanted = [];
        foreach ($assets as [$policy, $name]) {
            $policy = strtolower(trim((string) $policy));
            $name = strtolower(trim((string) $name));
            if (self::wellFormed($policy, $name)) {
                $wanted[$policy.$name] = [$policy, $name];
            }
        }

        return $wanted;
    }

    /**
     * A 28-byte policy id and an asset name of whole bytes, at most 32 of them. Koios
     * refuses a whole asset_info batch over one odd-length name, so a malformed pair is
     * left out rather than allowed to fail the assets beside it.
     */
    public static function wellFormed(string $policy, string $name): bool
    {
        return preg_match('/^[0-9a-f]{56}$/', $policy) === 1
            && preg_match('/^(?:[0-9a-f]{2}){0,32}$/', $name) === 1;
    }

    public static function logoChecked(KnownAsset $known): bool
    {
        return ($known->metadata[self::LOGO_CHECKED] ?? false) === true;
    }
}
