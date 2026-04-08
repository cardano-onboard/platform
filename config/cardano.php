<?php

return [
    'push_delay' => env("POO_PUSH_DELAY"),

    // Upload limits
    'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10 * 1024 * 1024), // bytes
    'max_codes'     => (int) env('UPLOAD_MAX_CODES', 10000),

    // Claim API rate limits
    'claim_rate_per_ip'       => (int) env('CLAIM_RATE_PER_IP', 60),
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
    'proxy_api_url'       => env('PROXY_API_URL', 'https://beta.onbd.io/api/v1/proxy'),
    'proxy_api_token'     => env('PROXY_API_TOKEN', ''),

    'phyrhose' => [
        'preprod_url' => env('PHYRHOSE_PREPROD_URL', 'https://testnet.phyrhose.io/'),
        'mainnet_url' => env('PHYRHOSE_MAINNET_URL', 'https://api.phyrhose.io/'),
        'preprod_jwt' => env('PHYRHOSE_PREPROD_JWT', ''),
        'preprod_id' => env('PHYRHOSE_PREPROD_ID', ''),
        'mainnet_jwt' => env('PHYRHOSE_MAINNET_JWT', ''),
        'mainnet_id' => env('PHYRHOSE_MAINNET_ID', ''),
    ]
];
