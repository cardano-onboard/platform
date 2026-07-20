<?php

return [
    // Push delay in minutes — capped at 15 minutes due to SQS DelaySeconds limit (900s).
    // If set higher, jobs would be rejected by SQS with InvalidParameterValueException.
    'push_delay' => min(15, max(0, (int) env('POO_PUSH_DELAY', 5))),

    // Upload limits
    'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10 * 1024 * 1024), // bytes
    'max_codes' => (int) env('UPLOAD_MAX_CODES', 10000),

    // Claim API rate limits
    'claim_rate_per_ip' => (int) env('CLAIM_RATE_PER_IP', 60),
    'claim_rate_per_campaign' => (int) env('CLAIM_RATE_PER_CAMPAIGN', 120),

    'nmkr' => [
        'preprod_url' => env('NMKR_PREPROD_API_URL', 'https://studio-api.preprod.nmkr.io/v2/'),
        'mainnet_url' => env('NMKR_MAINNET_API_URL', 'https://studio-api.nmkr.io/v2/'),
        'preprod_api_key' => env('NMKR_PREPROD_API_KEY', ''),
        'mainnet_api_key' => env('NMKR_MAINNET_API_KEY', ''),
    ],
    'beta_banner' => (bool) env('BETA_BANNER', true),

    'proxy' => [
        'monthly_limit' => (int) env('PROXY_MONTHLY_LIMIT', 1000),
    ],

    'transaction_backend' => env('TRANSACTION_BACKEND'),

    // Generated QR export bundles are cached idempotently so a repeat download of the
    // same campaign + settings is served from storage instead of re-generating (paid
    // Lambda compute + storage). Use 'local' for self-hosted (just add disk) or 's3'
    // for SaaS; any Laravel filesystem disk works. ttl_days prunes stale exports (S3
    // users may prefer a native bucket lifecycle rule); url_ttl_minutes bounds signed URLs.
    'qr_storage' => [
        'disk' => env('QR_STORAGE_DISK'),          // null => default filesystem disk
        'path' => env('QR_STORAGE_PATH', 'qr-exports'),
        'ttl_days' => (int) env('QR_STORAGE_TTL_DAYS', 7),
        'url_ttl_minutes' => (int) env('QR_STORAGE_URL_TTL', 15),
    ],

    // Optional dedicated subdomain for the claim endpoint (e.g. "claim.onbd.io").
    // When set, claim URLs / QR deep-links use https://<domain>/v1/{campaign} instead
    // of the longer /api/claim/v1/{campaign} path — shorter payload = less dense QR.
    // The original api.php route stays registered so previously-printed QRs keep working.
    'claim_domain' => env('CLAIM_DOMAIN'),
    'proxy_api_url' => env('PROXY_API_URL', 'https://beta.onbd.io/api/v1/proxy'),
    'proxy_api_token' => env('PROXY_API_TOKEN', ''),

    // Koios — public Cardano query layer used for native-asset metadata lookups.
    // Optional bearer token raises rate limits but is not required.
    'koios' => [
        'mainnet_url' => env('KOIOS_MAINNET_URL', 'https://api.koios.rest/api/v1/'),
        'preprod_url' => env('KOIOS_PREPROD_URL', 'https://preprod.koios.rest/api/v1/'),
        'preview_url' => env('KOIOS_PREVIEW_URL', 'https://preview.koios.rest/api/v1/'),
        'token' => env('KOIOS_API_TOKEN', ''),
    ],

    'phyrhose' => [
        'preprod_url' => env('PHYRHOSE_PREPROD_URL', 'https://testnet.phyrhose.io/'),
        'mainnet_url' => env('PHYRHOSE_MAINNET_URL', 'https://api.phyrhose.io/'),
        'preprod_jwt' => env('PHYRHOSE_PREPROD_JWT', ''),
        'preprod_id' => env('PHYRHOSE_PREPROD_ID', ''),
        'mainnet_jwt' => env('PHYRHOSE_MAINNET_JWT', ''),
        'mainnet_id' => env('PHYRHOSE_MAINNET_ID', ''),
    ],
];
