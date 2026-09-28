<?php

// A setting read from its current env name first, falling back to an earlier name for the
// same setting, and finally to $default. An explicitly blank value ('') is treated the same
// as an unset one: a deployment's env file can carry a blank line for a setting nobody has
// filled in yet, and (int) '' is 0, which would otherwise silently zero out a rate limit
// instead of falling through to whichever name is actually set. Deliberately not `?:`, which
// would also discard a genuine '0'.
//
// Guarded so a second require of this file, which this application's own tests do to read a
// setting under a changed environment, does not fail trying to redeclare it.
if (! function_exists('cardano_env_with_earlier_name')) {
    function cardano_env_with_earlier_name(string $current, string $earlier, int $default): int
    {
        foreach ([$current, $earlier] as $name) {
            $value = env($name);

            if ($value !== null && $value !== '') {
                return (int) $value;
            }
        }

        return $default;
    }
}

return [
    // Push delay in minutes — capped at 15 minutes due to SQS DelaySeconds limit (900s).
    // If set higher, jobs would be rejected by SQS with InvalidParameterValueException.
    'push_delay' => min(15, max(0, (int) env('POO_PUSH_DELAY', 5))),

    // Networks this deployment will accept for a campaign. Defaults to all three so
    // self-hosted installs and production keep every option; a staging environment
    // sets ALLOWED_NETWORKS=preprod,preview so real mainnet funds never transit an
    // environment that takes every merge before it is validated.
    'allowed_networks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_NETWORKS', 'preprod,preview,mainnet'))
    ))),

    // Where mainnet campaigns are created when this deployment does not accept them
    // (mainnet missing from allowed_networks above). Shown as a short hint on the
    // campaign create form's network field when both that is true and this is set;
    // nothing is shown when it is unset, so a self-hosted install stays silent.
    'mainnet_app_url' => env('MAINNET_APP_URL'),

    // What one claim costs to put on chain, per recipient, as an estimate.
    //
    // A fact about the chain rather than a price we set, which is why it lives here and
    // not in the pricing configuration. A self-hosted install pays it exactly as a hosted
    // one does, and needs it to fund a bucket and to account for what a campaign spent.
    //
    // An estimate, and stamped on each claim as one. Claims are batched into a single
    // transaction, so the real fee is per transaction rather than per recipient and is
    // not knowable at the moment a claim is taken.
    'network_fee_lovelace' => (int) env('NETWORK_FEE_LOVELACE', 200_000),

    // The minimum ADA every Cardano output has to carry, and how much above it a reward
    // should be set. See App\Support\MinUtxo for the arithmetic.
    //
    // A fact about the chain rather than a price, like the network fee above, so a
    // self-hosted install reads the same numbers a hosted one does.
    'min_utxo' => [
        // Multiplied by the size of the output in bytes. Governance can change it, so it
        // is read from the chain through the transaction backend; this is the fallback
        // used when that read fails, and is the mainnet value at the time of writing.
        'coins_per_utxo_byte' => (int) env('COINS_PER_UTXO_BYTE', 4310),

        // The length assumed for the recipient's address when sizing the output. 57 bytes
        // is a base address: a header, a payment part and a staking part. Every wallet a
        // claimant is likely to arrive with gives one of these, and an enterprise address
        // is shorter, so assuming it never understates the minimum.
        'address_bytes' => (int) env('MIN_UTXO_ADDRESS_BYTES', 57),

        // How far above the minimum a per-code reward should sit before it stops being
        // warned about. An output resting exactly on its floor cannot be spent without
        // ADA from somewhere else, and for a claimant whose wallet is minutes old there
        // is no somewhere else. One ADA covers a fee with room to spare.
        'headroom_lovelace' => (int) env('MIN_UTXO_HEADROOM_LOVELACE', 1_000_000),
    ],

    // Upload limits
    'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10 * 1024 * 1024), // bytes
    'max_codes' => (int) env('UPLOAD_MAX_CODES', 10000),

    // Claim API rate limits
    'claim_rate_per_ip' => (int) env('CLAIM_RATE_PER_IP', 60),
    'claim_rate_per_campaign' => (int) env('CLAIM_RATE_PER_CAMPAIGN', 120),

    // A claim carrying a Sanctum token with the codes:claim ability is rate limited by
    // the token's own account instead of by IP and campaign — every claim the same
    // external caller makes on an attendee's behalf arrives from the same server address,
    // so the IP limit above would ration every attendee behind it together. Keyed on the
    // account rather than the token's own id so minting a second token never doubles the
    // budget. Higher than the IP and campaign defaults because one account's traffic
    // stands in for many attendees' worth of claims that would otherwise have spread
    // across their own IPs.
    'claim_rate_per_token' => (int) env('CLAIM_RATE_PER_TOKEN', 300),

    // Code API (POST .../codes, GET .../codes/{code}/status) — a caller with no
    // dashboard and no IP an operator would recognise, so the limit is keyed on the
    // token itself rather than the address it happens to arrive from. Create and status
    // are rationed separately, so a caller polling status cannot starve its own creates.
    'code_api' => [
        // CODE_API_* is the current name. PASSPORT_API_* is an earlier name for the same
        // setting, still read as a fallback so an existing deployment's env file keeps
        // working until it is updated.
        'create_rate_per_token' => cardano_env_with_earlier_name('CODE_API_CREATE_RATE_PER_TOKEN', 'PASSPORT_API_CREATE_RATE_PER_TOKEN', 60),
        'status_rate_per_token' => cardano_env_with_earlier_name('CODE_API_STATUS_RATE_PER_TOKEN', 'PASSPORT_API_STATUS_RATE_PER_TOKEN', 60),

        // How many reward tokens one code-creation request may carry, and how large a
        // single token's quantity may be. Bounds on what the campaign page's own form
        // never had to bound, because a person typing into it does not type a billion.
        'max_tokens_per_code' => cardano_env_with_earlier_name('CODE_API_MAX_TOKENS_PER_CODE', 'PASSPORT_API_MAX_TOKENS_PER_CODE', 20),
        'max_token_quantity' => cardano_env_with_earlier_name('CODE_API_MAX_TOKEN_QUANTITY', 'PASSPORT_API_MAX_TOKEN_QUANTITY', 1_000_000),
    ],

    // Background work an operator waits on. A run reports a heartbeat while it works, and
    // silence for longer than this means the worker is gone and the run can be claimed
    // again; without that, a worker killed mid-job would wedge its campaign for that kind
    // of work forever.
    //
    // The default is the longest job timeout in this application (900 seconds, the
    // onboarding analysis) plus two minutes of grace. The worker kills a job at its
    // timeout, so silence for longer than the timeout plus the grace means nothing is
    // running. Raise it alongside any job timeout that goes past it.
    'tasks' => [
        'stale_after_seconds' => (int) env('TASK_STALE_AFTER_SECONDS', 1020),
    ],

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
    // compute + storage). Use 'local' for self-hosted (just add disk) or 's3' against an
    // object store; any Laravel filesystem disk works. ttl_days prunes stale exports (S3
    // users may prefer a native bucket lifecycle rule); url_ttl_minutes bounds signed URLs.
    'qr_storage' => [
        'disk' => env('QR_STORAGE_DISK'),          // null => default filesystem disk
        'path' => env('QR_STORAGE_PATH', 'qr-exports'),
        'ttl_days' => (int) env('QR_STORAGE_TTL_DAYS', 7),
        'url_ttl_minutes' => (int) env('QR_STORAGE_URL_TTL', 15),
        // How long a single attempt may spend rendering before the worker kills it. An
        // attempt, not the export: a render stores what it has finished and resumes, so this
        // bounds one sitting rather than the whole job. Kept at or below the
        // tasks.stale_after_seconds grace above, which is what decides when a silent run may
        // be started again.
        'job_timeout_seconds' => (int) env('QR_EXPORT_JOB_TIMEOUT', 900),
        // How many stickers are rendered between two chances to stop. Smaller means less
        // work repeated when an attempt is cut off, and more round trips to the disk holding
        // the half-finished archive.
        'chunk_size' => (int) env('QR_EXPORT_CHUNK_SIZE', 250),
        // How long one attempt renders for before storing what it has and handing the
        // message back. Left unset it is derived from the queue connection's own retry_after,
        // which is the number it has to stay under, so a deployment that lengthens its
        // reservation window gets longer attempts without setting anything here. Set to 0 to
        // store after every batch, which is the most durable and the most round trips.
        'work_budget_seconds' => env('QR_EXPORT_WORK_BUDGET'),
        // How long an export may keep resuming before it is given up on. There is no ceiling
        // on how many codes an export may hold; this is the bound instead, and it is a
        // clock rather than a count because attempts carry the render forward.
        'resume_window_minutes' => (int) env('QR_EXPORT_RESUME_WINDOW', 60),
        // How long an export's record is kept after its archive has gone, so the table does
        // not grow for the life of the deployment. Longer than the TTL above by a wide
        // margin on purpose: the archive is what expires, while the row is the history of
        // what was printed and the settings a regenerate replays, and an operator asking
        // what a sticker in someone's hand was made from is asking months later.
        'retention_days' => (int) env('QR_EXPORT_RETENTION_DAYS', 90),
    ],

    // Optional dedicated subdomain for the claim endpoint (e.g. "claim.onbd.io").
    // When set, claim URLs / QR deep-links use https://<domain>/v1/{campaign} instead
    // of the longer /api/claim/v1/{campaign} path — shorter payload = less dense QR.
    // The original api.php route stays registered so previously-printed QRs keep working.
    'claim_domain' => env('CLAIM_DOMAIN'),

    // Chain-namespaced public pages (kept generic for a multi-chain future).
    // The Cardano ("ADA") onboarding page lives at /ada/now. When 'domain' is
    // set (e.g. "ada.onbd.io") the same pages are ALSO served at that subdomain's
    // root (so /now resolves there) — leave it null until DNS and the host's domain
    // config point at it.
    // 'stake_pool' is an optional pool ticker or hex id: when set, the staking
    // step offers a one-tap web+cardano://stake delegation deep-link; when null
    // it shows the in-wallet steps only (no dead deep-link to a missing pool).
    'ada' => [
        'domain' => env('ADA_DOMAIN'),
        'stake_pool' => env('ADA_STAKE_POOL'),
    ],
    'proxy_api_url' => env('PROXY_API_URL', 'https://beta.onbd.io/api/v1/proxy'),
    'proxy_api_token' => env('PROXY_API_TOKEN', ''),

    // Koios — public Cardano query layer used for native-asset metadata lookups.
    // Optional bearer token raises rate limits but is not required.
    'koios' => [
        'mainnet_url' => env('KOIOS_MAINNET_URL', 'https://api.koios.rest/api/v1/'),
        'preprod_url' => env('KOIOS_PREPROD_URL', 'https://preprod.koios.rest/api/v1/'),
        'preview_url' => env('KOIOS_PREVIEW_URL', 'https://preview.koios.rest/api/v1/'),
        'token' => env('KOIOS_API_TOKEN', ''),

        // How long the chain-data provider waits on one request, in seconds, and how many
        // attempts it makes before giving up. These bound a queued job that is reading
        // chain state: a provider that stops answering should fail the job, not hold a
        // worker open on it.
        'timeout' => (int) env('KOIOS_TIMEOUT', 10),
        'retries' => (int) env('KOIOS_RETRIES', 3),

        // The largest request body Koios accepts, in bytes. The public tier refuses
        // anything over 5120 with a 413, and a list of assets is split to stay under it.
        'max_body_bytes' => (int) env('KOIOS_MAX_BODY_BYTES', 5120),
    ],

    // How long a native asset's display metadata is cached, by where it was read from.
    // CIP-0025 minting metadata changes only if the policy mints the asset again, so it
    // is kept for a long time but still expires. A CIP-0068 reference datum can be moved
    // by its script at any time, so it is re-read at least daily. Neither is written to
    // the known_assets table, which holds off-chain registry tokens only.
    'asset_metadata' => [
        'cip25_cache_days' => (int) env('ASSET_METADATA_CIP25_CACHE_DAYS', 365),
        'cip68_cache_hours' => (int) env('ASSET_METADATA_CIP68_CACHE_HOURS', 24),
        // Metadata lookup requests one signed-in user may make per minute.
        'lookups_per_minute' => (int) env('ASSET_METADATA_LOOKUPS_PER_MINUTE', 120),
    ],

    // Protocol parameters are read from the chain and cached per network under the epoch
    // they belong to, so they refresh when the epoch turns over rather than on a timer.
    // There are no parameter values here to configure: a pinned fee or minimum-UTxO
    // figure is one the arithmetic would keep using after the ledger had moved on.
    'protocol_params' => [
        // Seconds before the epoch number is looked up again. This is how often the
        // provider is asked what epoch it is, not how long parameters are trusted, so a
        // longer window costs only lateness in noticing a boundary, and the shortest
        // epoch on any supported network is a day.
        'tip_ttl' => (int) env('CARDANO_TIP_TTL', 60),
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
