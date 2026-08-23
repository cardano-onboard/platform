<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessUploadedCodes;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessUploadedCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_codes_from_valid_json(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create();

        $codesData = [
            'CODE001' => [
                'lovelaces' => 2000000,
                'abc123def456abc123def456abc123def456abc123def456abc123def456.746f6b656e' => 5,
            ],
            'CODE002' => [
                'lovelaces' => 3000000,
            ],
        ];

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode($codesData));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 2);
        $this->assertDatabaseCount('rewards', 1);
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        // Create a file larger than the configured max
        $largeContent = str_repeat('x', config('cardano.max_file_size', 10 * 1024 * 1024) + 1);
        Storage::disk($this->uploadDisk())->put('test/large.json', $largeContent);

        (new ProcessUploadedCodes($campaign->id, 'test/large.json'))->handle();

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_rejects_invalid_json_structure(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        Storage::disk($this->uploadDisk())->put('test/invalid.json', '"just a string"');

        (new ProcessUploadedCodes($campaign->id, 'test/invalid.json'))->handle();

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_skips_codes_with_invalid_token_format(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create();

        $codesData = [
            'CODE001' => [
                'lovelaces' => 2000000,
                'invalid_token_no_dot' => 5,
            ],
        ];

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode($codesData));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 1);
        $this->assertDatabaseCount('rewards', 0);
    }

    public function test_skips_entries_missing_lovelaces(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $codesData = [
            'CODE001' => [
                'some_field' => 'no lovelaces key',
            ],
        ];

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode($codesData));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_reads_the_upload_from_the_configured_default_disk(): void
    {
        // The job used to hard-code the "s3" disk, which meant it read from a bucket that
        // doesn't exist on Laravel Cloud (where the injected disk is "private") or on a
        // self-hosted install (no AWS_* at all). It must follow the app's default disk.
        config(['filesystems.default' => 'uploads']);
        config(['filesystems.disks.uploads' => ['driver' => 'local', 'root' => storage_path('app/uploads')]]);
        Storage::fake('uploads');

        $campaign = Campaign::factory()->for(User::factory())->create();
        Storage::disk('uploads')->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 1);
    }

    /** The disk the signed upload wrote to — the app default, never a hard-coded name. */
    private function uploadDisk(): string
    {
        return config('filesystems.default');
    }
}
