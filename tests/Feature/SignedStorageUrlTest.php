<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SignedStorageUrlTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/uploads/signed-url';

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
                'endpoint' => 'https://object-store.example.test',
                'use_path_style_endpoint' => true,
                'throw' => false,
            ],
            'filesystems.default' => $disk,
        ]);
    }

    /**
     * The route is the application's own now. A third-party package registered it while it
     * was installed, so with the package gone an unregistered route would answer 404 and the
     * bulk upload would have nowhere to ask for a signature.
     */
    public function test_the_upload_signing_route_is_registered_by_this_application(): void
    {
        $route = Route::getRoutes()->getByName('uploads.signed-url');

        $this->assertNotNull($route, 'the frontend asks Ziggy for uploads.signed-url');
        $this->assertSame(ltrim(self::URL, '/'), $route->uri());
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));

        // Web middleware and no more, which is what the replaced route carried. Authorisation
        // is the uploadFiles gate: 'auth' here would answer a signed-out upload with a
        // redirect the import dialog cannot read instead of the 403 it can.
        $this->assertSame(['web'], $route->gatherMiddleware());
    }

    /**
     * The lifetime comes from a config file this repository owns. It used to come from the
     * one the third-party package published, which is gone, and a lifetime that silently
     * fell back to a framework default would be a signed URL nobody chose the expiry of.
     */
    public function test_the_signature_expires_after_the_configured_lifetime(): void
    {
        $this->attachBucket('private', 'onbd-private');
        config(['filesystems.signed_upload_expires_minutes' => 9]);

        $url = $this->actingAs(User::factory()->create())->postJson(self::URL)->json('url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('540', $query['X-Amz-Expires'] ?? null);
    }

    public function test_it_signs_against_the_default_disk_not_the_hard_coded_s3_disk(): void
    {
        // The regression this whole change exists for: the third-party package signed against
        // `filesystems.disks.s3` + $_ENV['AWS_BUCKET'] regardless of FILESYSTEM_DISK, so a
        // host that exposes its attached bucket under another disk name got a signature for
        // a bucket the environment does not have. Point the two disks at different buckets
        // and the signature must name the default one.
        config(['filesystems.disks.s3.bucket' => 'the-hard-coded-bucket']);
        $this->attachBucket('private', 'the-attached-bucket');

        $response = $this->actingAs(User::factory()->create())
            ->postJson(self::URL, ['content_type' => 'application/json']);

        $response->assertCreated();
        $this->assertSame('the-attached-bucket', $response->json('bucket'));
        $this->assertStringContainsString('the-attached-bucket', $response->json('url'));
        $this->assertStringNotContainsString('the-hard-coded-bucket', $response->json('url'));
    }

    public function test_it_returns_a_usable_presigned_put(): void
    {
        $this->attachBucket('private', 'onbd-private');

        $response = $this->actingAs(User::factory()->create())
            ->postJson(self::URL, ['content_type' => 'application/json']);

        $response->assertCreated();
        // The shape the uploader consumes: it PUTs the file to `url` with `headers`, then
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

    public function test_it_sends_no_acl_because_bucket_level_stores_reject_them(): void
    {
        // An S3-compatible store that manages visibility per bucket rather than per object
        // fails a PutObject carrying an ACL with NotImplemented, and so does a modern S3
        // bucket under bucket-owner-enforced ownership. The third-party package always sent
        // one; ours must not, in the headers or the signature.
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
