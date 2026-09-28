<?php

namespace App\Cardano;

/**
 * Reads what a page needs to show a native asset (name, ticker, decimals, logo) out of a
 * Koios asset_info row, and says where it came from.
 *
 * Three sources, in order of trust:
 *
 * - registry: the Cardano Foundation off-chain token registry, curated and signed.
 * - cip68: the datum on the asset's (100) reference NFT (CIP-0068). Its first constructor
 *   field holds the metadata, either directly or, from version 4, nested under
 *   "721" => policy id => asset name without its label (CIP-0068 §222, §333, §444).
 *   Koios hands the datum back keyed by the user token's label.
 * - cip25: label 721 in the asset's latest minting transaction (CIP-0025). Version 1 keys
 *   the asset by its UTF-8 name as text, version 2 by its raw bytes.
 *
 * Only the registry supplies a logo this application can render inline (base64 PNG). The
 * CIP-25 image and the CIP-68 logo are URIs, usually ipfs://, and fetching them would mean
 * hotlinking from third-party storage, so neither is read.
 */
class AssetMetadataReader
{
    public const SOURCE_REGISTRY = 'registry';

    public const SOURCE_CIP68 = 'cip68';

    public const SOURCE_CIP25 = 'cip25';

    /** Nothing but the name bytes Koios read as ASCII. */
    public const SOURCE_CHAIN = 'chain';

    /** CIP-0068 user-token labels whose (100) reference datum carries metadata. */
    private const CIP68_USER_LABELS = [222, 333, 444];

    public static function read(array $asset): array
    {
        $registry = is_array($asset['token_registry_metadata'] ?? null) ? $asset['token_registry_metadata'] : [];

        $base = [
            'policy_id' => $asset['policy_id'] ?? null,
            'asset_name' => $asset['asset_name'] ?? null,
            'asset_name_ascii' => $asset['asset_name_ascii'] ?? null,
            'fingerprint' => $asset['fingerprint'] ?? null,
            'description' => null,
        ];

        if ($registry !== []) {
            return array_merge($base, [
                'name' => self::text($registry['name'] ?? null) ?? ($asset['asset_name_ascii'] ?? null),
                'ticker' => self::text($registry['ticker'] ?? null),
                'decimals' => self::decimals($registry['decimals'] ?? null),
                'logo' => self::text($registry['logo'] ?? null),
                'description' => self::text($registry['description'] ?? null),
                'source' => self::SOURCE_REGISTRY,
            ]);
        }

        if (($cip68 = self::cip68($asset)) !== null) {
            return array_merge($base, [
                'name' => self::text($cip68['name'] ?? null) ?? ($asset['asset_name_ascii'] ?? null),
                'ticker' => self::text($cip68['ticker'] ?? null),
                'decimals' => is_int($cip68['decimals'] ?? null) ? self::decimals($cip68['decimals']) : 0,
                'logo' => null,
                'description' => self::text($cip68['description'] ?? null),
                'source' => self::SOURCE_CIP68,
            ]);
        }

        if (($cip25 = self::cip25($asset)) !== null) {
            return array_merge($base, [
                'name' => self::text($cip25['name'] ?? null) ?? ($asset['asset_name_ascii'] ?? null),
                'ticker' => null,
                'decimals' => 0,
                'logo' => null,
                'description' => self::joined($cip25['description'] ?? null),
                'source' => self::SOURCE_CIP25,
            ]);
        }

        return array_merge($base, [
            'name' => $asset['asset_name_ascii'] ?? null,
            'ticker' => null,
            'decimals' => 0,
            'logo' => null,
            'source' => self::SOURCE_CHAIN,
        ]);
    }

    /** The metadata map from the reference datum, decoded to strings and ints. */
    private static function cip68(array $asset): ?array
    {
        $name = AssetName::fromHex($asset['asset_name'] ?? null);
        $byLabel = $asset['cip68_metadata'] ?? null;

        if (! in_array($name->label, self::CIP68_USER_LABELS, true) || ! is_array($byLabel)) {
            return null;
        }

        $datum = $byLabel[(string) $name->label] ?? null;
        $fields = is_array($datum) ? ($datum['fields'] ?? null) : null;

        if (! is_array($fields) || ($datum['constructor'] ?? 0) !== 0 || ! isset($fields[0]['map'])) {
            return null;
        }

        $metadata = self::plutusMap($fields[0]['map']);

        // Version 4 nests the metadata under "721" => policy => name without its label.
        if (isset($metadata['721'])) {
            $nested = $fields[0]['map'];
            $metadata = null;
            foreach ($nested as $entry) {
                if (strtolower($entry['k']['bytes'] ?? '') !== bin2hex('721')) {
                    continue;
                }
                foreach ($entry['v']['map'] ?? [] as $policy) {
                    if (strtolower($policy['k']['bytes'] ?? '') !== strtolower($asset['policy_id'] ?? '')) {
                        continue;
                    }
                    foreach ($policy['v']['map'] ?? [] as $named) {
                        if (strtolower($named['k']['bytes'] ?? '') === strtolower($name->nameHex)) {
                            $metadata = self::plutusMap($named['v']['map'] ?? []);
                        }
                    }
                }
            }
        }

        return $metadata === null || $metadata === [] ? null : $metadata;
    }

    /**
     * A Plutus data map with UTF-8 byte keys, read into key => string|int. Values that are
     * neither bytes nor an int (lists, nested maps) are kept only as a marker that the key
     * exists; nothing here reads them.
     */
    private static function plutusMap(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $key = self::utf8FromHex($entry['k']['bytes'] ?? null);
            if ($key === null) {
                continue;
            }
            $value = $entry['v'] ?? [];
            $out[$key] = match (true) {
                isset($value['bytes']) => self::utf8FromHex($value['bytes']),
                isset($value['int']) && is_int($value['int']) => $value['int'],
                default => true,
            };
        }

        return $out;
    }

    /** The asset's entry under label 721 in its minting transaction's metadata. */
    private static function cip25(array $asset): ?array
    {
        $metadata = $asset['minting_tx_metadata'] ?? null;
        $label = is_array($metadata) ? ($metadata['721'] ?? null) : null;

        if (! is_array($label)) {
            return null;
        }

        $policy = strtolower($asset['policy_id'] ?? '');
        $byPolicy = null;
        foreach ($label as $key => $value) {
            // Koios reads metadata from db-sync, which writes a version 2 bytes key as "0x"
            // followed by its hex.
            if ($key !== 'version' && in_array(strtolower((string) $key), [$policy, '0x'.$policy], true) && is_array($value)) {
                $byPolicy = $value;
            }
        }

        if ($byPolicy === null) {
            return null;
        }

        // Version 1 keys by the name as text, version 2 by its bytes, which db-sync writes
        // as "0x" followed by the hex.
        $hex = strtolower($asset['asset_name'] ?? '');
        $candidates = array_filter([
            $asset['asset_name_ascii'] ?? null,
            self::utf8FromHex($asset['asset_name'] ?? null),
            $hex === '' ? null : '0x'.$hex,
            $hex,
        ], fn ($key) => $key !== null && $key !== '');

        foreach ($candidates as $key) {
            if (isset($byPolicy[$key]) && is_array($byPolicy[$key])) {
                return $byPolicy[$key];
            }
        }

        return null;
    }

    private static function utf8FromHex(?string $hex): ?string
    {
        if ($hex === null || $hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return null;
        }
        $bytes = hex2bin($hex);

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** CIP-0025 lets a long string be split into an array of chunks. */
    private static function joined(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode('', array_filter($value, 'is_string'));
        }

        return self::text($value);
    }

    private static function decimals(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(32, (int) $value)) : 0;
    }
}
