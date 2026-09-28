<?php

namespace App\Http\Controllers;

use App\Support\StorageDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues the pre-signed PUT that the browser uploads a bulk codes file to.
 *
 * Replaced a third-party package's controller, which assumes one specific storage layout
 * and breaks on any other:
 *
 *  - It reads AWS_BUCKET and the credentials straight out of $_ENV and takes the region
 *    from `filesystems.disks.s3`, so it always signs against the "s3" disk no matter what
 *    FILESYSTEM_DISK says. A host that injects its own bucket exposes it under the disk
 *    name the bucket was created with, which is not "s3".
 *  - It always sends an ACL header on the PutObject. An S3-compatible store that manages
 *    visibility per bucket rejects per-object ACLs with a NotImplemented error, so every
 *    upload would fail even once the bucket resolved.
 *
 * Signing through the resolved disk fixes both: the disk already carries the bucket,
 * region, endpoint, path-style flag and credentials for whichever backend is attached,
 * and Laravel's temporaryUploadUrl() sets no ACL. Presigning is an offline computation,
 * so this makes no network call.
 */
class SignedStorageUrlController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'content_type' => ['nullable', 'string', 'max:255'],
        ]);

        $diskName = StorageDisk::resolve();
        $bucket = config("filesystems.disks.{$diskName}.bucket");

        Gate::authorize('uploadFiles', [$request->user(), $bucket]);

        $uuid = (string) Str::uuid();
        $key = 'tmp/'.$uuid;
        // The uploader sends the key but leaves it empty for a file with no type, and
        // validate() omits it entirely when absent — both mean "unknown".
        $contentType = $validated['content_type'] ?? null ?: 'application/octet-stream';

        try {
            // No ACL in the options on purpose — see the class docblock. The bucket's own
            // visibility governs the object, on both a bucket-level-visibility store and a
            // modern S3 bucket (which rejects ACL headers outright under
            // bucket-owner-enforced ownership).
            $signed = Storage::disk($diskName)->temporaryUploadUrl(
                $key,
                now()->addMinutes((int) config('filesystems.signed_upload_expires_minutes', 5)),
                ['ContentType' => $contentType],
            );
        } catch (RuntimeException $e) {
            // The resolved disk has no concept of a signed upload — a self-hosted install
            // on the local disk, or a cloud disk that StorageDisk had to fall back from.
            // Say so plainly instead of surfacing a driver-level error to the uploader.
            Log::error('Cannot issue a signed upload URL.', [
                'disk' => $diskName,
                'missing' => StorageDisk::missingConfig(config('filesystems.default')),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'File uploads are not available on this server: the configured '
                    ."storage disk [{$diskName}] does not support direct uploads. Attach an "
                    .'object storage bucket, or add codes without a file.',
            ], 503);
        }

        return response()->json([
            'uuid' => $uuid,
            'bucket' => $bucket,
            'key' => $key,
            'url' => $signed['url'],
            'headers' => array_merge($signed['headers'], ['Content-Type' => $contentType]),
        ], 201);
    }
}
