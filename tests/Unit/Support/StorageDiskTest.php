<?php

namespace Tests\Unit\Support;

use App\Support\StorageDisk;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StorageDiskTest extends TestCase
{
    public function test_a_fully_configured_disk_is_used_as_is(): void
    {
        config(['filesystems.disks.private' => [
            'driver' => 's3',
            'bucket' => 'onbd-private',
            'region' => 'auto',
        ]]);

        $this->assertSame([], StorageDisk::missingConfig('private'));
        $this->assertSame('private', StorageDisk::resolve('private'));
    }

    public function test_an_s3_disk_without_a_bucket_falls_back_instead_of_type_erroring(): void
    {
        // The production failure: Laravel Cloud had not injected AWS_BUCKET, so the disk
        // resolved with bucket => null and Flysystem's adapter threw a TypeError, which
        // reached the user as a bare 500 on the QR download.
        config(['filesystems.disks.private' => [
            'driver' => 's3',
            'bucket' => null,
            'region' => 'auto',
        ]]);

        Log::shouldReceive('error')->once()->withArgs(
            fn ($message, $context) => str_contains($message, 'private')
                && $context['missing'] === ['AWS_BUCKET']
        );

        $this->assertSame('local', StorageDisk::resolve('private'));
    }

    public function test_a_missing_region_is_reported_too(): void
    {
        // The AWS SDK refuses to build a client without a region, so it is just as fatal
        // as an absent bucket and has to be caught on the same pass.
        config(['filesystems.disks.private' => [
            'driver' => 's3',
            'bucket' => 'onbd-private',
            'region' => '',
        ]]);

        $this->assertSame(['AWS_DEFAULT_REGION'], StorageDisk::missingConfig('private'));
    }

    public function test_an_undefined_disk_falls_back(): void
    {
        // Cloud injects FILESYSTEM_DISK=private; before this change no disk of that name
        // existed in config, so every Storage call against it died on an unknown driver.
        config(['filesystems.disks.nope' => null]);
        Log::shouldReceive('error')->once();

        $this->assertSame('local', StorageDisk::resolve('nope'));
    }

    public function test_local_disks_need_no_credentials(): void
    {
        $this->assertSame([], StorageDisk::missingConfig('local'));
        $this->assertSame('local', StorageDisk::resolve('local'));
    }

    public function test_a_null_preference_starts_from_the_app_default(): void
    {
        config(['filesystems.default' => 'public']);

        $this->assertSame('public', StorageDisk::resolve(null));
    }

    public function test_s3_credentials_are_not_required(): void
    {
        // Role-based auth supplies them outside the config, so blank key/secret is
        // legitimate and must not trigger a fallback.
        config(['filesystems.disks.private' => [
            'driver' => 's3',
            'bucket' => 'onbd-private',
            'region' => 'auto',
            'key' => null,
            'secret' => null,
        ]]);

        $this->assertSame('private', StorageDisk::resolve('private'));
    }
}
