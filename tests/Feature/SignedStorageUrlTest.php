<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedStorageUrlTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/vapor/signed-storage-url';

    /**
     * Configure an S3-compatible disk and make it the app default. Presigning never
     * touches the network, so dummy credentials produce a genuinely signed URL.
     */
    private function attachBucket(string $disk, string $bucket): void
    {
        config([
            "filesystems.disks.{$disk}" => [
                'driver' => 's3',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'region' => 'auto',
                'bucket' => $bucket,
                'endpoint' => 'https://accountid.r2.cloudflarestorage.com',
                'use_path_style_endpoint' => true,
                'throw' => false,
            ],
            'filesystems.default' => $disk,
        ]);
    }

    public function test_it_signs_against_the_default_disk_not_the_hard_coded_s3_disk(): void
    {
        // The regression this whole change exists for: vapor-core signed against
        // `filesystems.disks.s3` + $_ENV['AWS_BUCKET'] regardless of FILESYSTEM_DISK, so on
        // Laravel Cloud — where the attached bucket is the "private" disk — it signed for a
        // bucket the environment does not have. Point the two disks at different buckets
        // and the signature must name the default one.
        config(['filesystems.disks.s3.bucket' => 'the-wrong-vapor-bucket']);
        $this->attachBucket('private', 'the-cloud-bucket');

        $response = $this->actingAs(User::factory()->create())
            ->postJson(self::URL, ['content_type' => 'application/json']);

        $response->assertCreated();
        $this->assertSame('the-cloud-bucket', $response->json('bucket'));
        $this->assertStringContainsString('the-cloud-bucket', $response->json('url'));
        $this->assertStringNotContainsString('the-wrong-vapor-bucket', $response->json('url'));
    }

    public function test_it_returns_a_usable_presigned_put(): void
    {
        $this->attachBucket('private', 'onbd-private');

        $response = $this->actingAs(User::factory()->create())
            ->postJson(self::URL, ['content_type' => 'application/json']);

        $response->assertCreated();
        // The shape Vapor.store() consumes: it PUTs the file to `url` with `headers`, then
        // hands `key` back to us as the file_key the import job reads.
        $response->assertJsonStructure(['uuid', 'bucket', 'key', 'url', 'headers']);
        $this->assertStringStartsWith('tmp/', $response->json('key'));
        $this->assertStringContainsString('X-Amz-Signature', $response->json('url'));
        $this->assertSame('application/json', $response->json('headers.Content-Type'));
    }

    public function test_the_issued_key_passes_the_import_endpoints_validation(): void
    {
        // CodeController validates file_key against /^[a-zA-Z0-9\/_\-\.]+$/ before queueing
        // the import, so a key shape this controller can emit must clear that rule.
        $this->attachBucket('private', 'onbd-private');

        $key = $this->actingAs(User::factory()->create())
            ->postJson(self::URL)->json('key');

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\/_\-\.]+$/', $key);
    }

    public function test_it_sends_no_acl_because_r2_rejects_per_object_acls(): void
    {
        // Cloudflare R2 (which backs Laravel Cloud object storage) manages visibility at
        // the bucket level and fails a PutObject carrying an ACL with NotImplemented.
        // vapor-core always sent one; ours must not, in the headers or the signature.
        $this->attachBucket('private', 'onbd-private');

        $response = $this->actingAs(User::factory()->create())->postJson(self::URL);

        $response->assertCreated();
        $headerNames = array_map('strtolower', array_keys($response->json('headers')));
        $this->assertNotContains('x-amz-acl', $headerNames);

        $url = $response->json('url');
        $this->assertStringNotContainsString('x-amz-acl', strtolower($url));
        // ...and the ACL header must not be among the signed headers either, or S3 would
        // demand it be sent.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringNotContainsString('acl', strtolower($query['X-Amz-SignedHeaders'] ?? ''));
    }

    public function test_it_reports_a_disk_that_cannot_sign_uploads(): void
    {
        // Self-hosted on the local disk, or a cloud disk StorageDisk had to fall back from:
        // there is no signed upload to issue, so say so rather than leaking a driver error.
        config(['filesystems.default' => 'local']);

        $response = $this->actingAs(User::factory()->create())->postJson(self::URL);

        $response->assertStatus(503);
        $this->assertStringContainsString('not available', $response->json('message'));
    }

    public function test_it_rejects_an_unauthenticated_upload_request(): void
    {
        $this->attachBucket('private', 'onbd-private');

        $this->postJson(self::URL)->assertForbidden();
    }
}
