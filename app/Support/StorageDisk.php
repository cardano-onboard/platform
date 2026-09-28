<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Resolves a filesystem disk name to one that is actually configured.
 *
 * A cloud disk whose credentials were never injected, whether because the host never
 * attached a bucket to the environment or because a self-hosted install never filled in
 * AWS_*, surfaces deep inside Flysystem as a TypeError on a null bucket, which reaches the
 * user as a blank 500 with nothing pointing at the real cause. Resolving names through
 * here instead turns that into a logged, named misconfiguration and keeps the feature
 * working on the local disk.
 */
class StorageDisk
{
    /**
     * The name of a usable disk: the preferred one when it is fully configured,
     * otherwise the fallback. Pass null to start from the app's default disk.
     */
    public static function resolve(?string $preferred = null, string $fallback = 'local'): string
    {
        $name = $preferred ?: config('filesystems.default', $fallback);

        $missing = static::missingConfig($name);

        if ($missing === []) {
            return $name;
        }

        Log::error("Filesystem disk [{$name}] is not usable; falling back to [{$fallback}].", [
            'missing' => $missing,
        ]);

        return $fallback;
    }

    /**
     * The settings a disk needs but is missing, named as the env vars an operator would
     * set. An empty array means the disk is usable.
     */
    public static function missingConfig(string $name): array
    {
        $config = config("filesystems.disks.{$name}");

        if (! is_array($config) || blank($config['driver'] ?? null)) {
            return ["filesystems.disks.{$name}"];
        }

        if ($config['driver'] !== 's3') {
            return [];
        }

        // Both of these blow up before any request is made: the Flysystem adapter
        // type-errors on a null bucket, and the AWS SDK rejects a client with no region.
        // Credentials are deliberately not required — role-based auth supplies them
        // outside the config, and an empty key there is legitimate.
        $required = ['bucket' => 'AWS_BUCKET', 'region' => 'AWS_DEFAULT_REGION'];

        return collect($required)
            ->reject(fn ($env, $key) => filled($config[$key] ?? null))
            ->values()
            ->all();
    }
}
