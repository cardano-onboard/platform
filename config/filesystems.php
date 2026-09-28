<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        /*
         * S3-compatible object storage for a managed host.
         *
         * Hosts that let you attach a bucket inject FILESYSTEM_DISK plus the AWS_*
         * credentials, and set FILESYSTEM_DISK to the "disk name" chosen when the bucket
         * was created. A disk of that name has to exist here or nothing can resolve it,
         * so "private" is defined for hosts that use that name.
         *
         * No 'visibility' key on purpose: some S3-compatible stores manage visibility at
         * the bucket level and reject per-object ACL headers with a NotImplemented error.
         * A bucket created as private stays private; reach for temporaryUrl() to hand out
         * access.
         */
        'private' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Signed Upload Lifetime
    |--------------------------------------------------------------------------
    |
    | How long, in minutes, a browser has to finish a direct upload against the
    | pre-signed PUT that SignedStorageUrlController issues. It has to outlast a
    | large codes file on a slow connection and no longer, since the URL is a
    | standing permission to write to the bucket until it expires.
    |
    | This value used to come from a third-party package that provided the signed
    | upload route before this application had its own. The five minutes is the
    | same default that package used.
    |
    */

    'signed_upload_expires_minutes' => (int) env('SIGNED_UPLOAD_EXPIRES_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
