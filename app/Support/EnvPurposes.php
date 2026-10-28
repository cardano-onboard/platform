<?php

namespace App\Support;

/**
 * One line on what a variable controls. This is the only source of a purpose: the comments in
 * the configuration files are never read out, so nothing typed into one reaches a listing. A
 * variable not listed here has no purpose line.
 *
 * These are statements about what a setting does and what the extreme values do, not values:
 * nothing here depends on how any one installation is configured.
 */
final class EnvPurposes
{
    public const BY_NAME = [
        // application
        'ADMIN_EMAIL' => 'Email of the account the administrator seeder marks as the administrator.',
        'ADMIN_PASSWORD' => 'Password the administrator seeder gives that account. Change it from the example value.',
        'APP_DEBUG' => 'Shows stack traces and configuration to visitors when true. Keep it false.',
        'APP_ENV' => 'Name of this environment. Production-only protections apply when it is production.',
        'APP_KEY' => 'Encrypts sessions and stored secrets. The application will not serve without it.',
        'APP_NAME' => 'Application name, and the base of the cache, Redis and session cookie prefixes.',
        'APP_NOTICE' => 'Banner text shown to every visitor. Leave unset for no banner.',
        'APP_NOTICE_TYPE' => 'Banner style, info or warning. Anything else is shown as info.',
        'APP_URL' => 'Public address of this installation. Links, signed URLs and cookies are built from it.',
        'ASSET_URL' => 'Where built assets are served from when that is not APP_URL.',
        'BETA_BANNER' => 'Shows the beta notice bar on public pages.',
        'BCRYPT_ROUNDS' => 'Cost of password hashing. Higher is slower to compute and slower to guess.',

        // storage
        'AWS_ACCESS_KEY_ID' => 'Access key id for the s3 disk and the other drivers that use it.',
        'AWS_SECRET_ACCESS_KEY' => 'Secret key for the s3 disk and the other drivers that use it.',
        'AWS_BUCKET' => 'Bucket for the s3 disk. An s3 disk with no bucket falls back to local storage.',
        'AWS_DEFAULT_REGION' => 'Region for the s3 disk and the other drivers that use it. An s3 disk with no region falls back to local storage.',
        'FILESYSTEM_DISK' => 'Default storage disk. A local disk is not shared between instances.',
        'QR_STORAGE_DISK' => 'Disk QR exports are stored on. Naming a disk the host did not configure breaks exports.',

        // database, cache, sessions, queue
        'DB_CONNECTION' => 'Which database connection the application uses: mysql, pgsql, sqlite or sqlsrv.',
        'DB_DATABASE' => 'Database name, or the file path for SQLite.',
        'DB_HOST' => 'Database server address.',
        'DB_PORT' => 'Database server port.',
        'DB_USERNAME' => 'Database user.',
        'DB_PASSWORD' => 'Database password.',
        'CACHE_DRIVER' => 'Where cached values and locks live. Instances that do not share the store do not share locks.',
        'SESSION_DRIVER' => 'Where sessions are stored. Instances that do not share the store lose each other\'s sessions.',
        'SESSION_SECURE_COOKIE' => 'Marks the session cookie secure so a browser sends it over https only.',
        'QUEUE_CONNECTION' => 'Queue driver. sync runs jobs inside the request and ignores delays, so claims are neither batched nor confirmed later.',
        'QUEUE_RETRY_AFTER' => 'Seconds before a running job is handed to a second worker. Keep it longer than the longest job.',
        'HOUSEKEEPING_ON_TRAFFIC' => 'Runs overdue housekeeping from incoming requests, for installs with no scheduler.',
        'SANCTUM_STATEFUL_DOMAINS' => 'Hosts whose browser sessions count as stateful for token authentication. Include the application host.',
        'CONTENT_SECURITY_POLICY' => 'Replaces the built-in content security policy verbatim when set.',

        // mail
        'MAIL_MAILER' => 'Mail transport. log and array never deliver.',
        'MAIL_HOST' => 'SMTP server address, used when MAIL_MAILER is smtp.',
        'MAIL_PORT' => 'SMTP server port.',
        'MAIL_FROM_ADDRESS' => 'Sender address on outgoing mail. Providers refuse the example address.',
        'POSTMARK_TOKEN' => 'Postmark server token. Every send fails without it when MAIL_MAILER is postmark.',
        'MAILGUN_DOMAIN' => 'Mailgun sending domain, used when MAIL_MAILER is mailgun.',
        'MAILGUN_SECRET' => 'Mailgun API key, used when MAIL_MAILER is mailgun.',
        'LOG_LEVEL' => 'Lowest severity written to the log. debug logs request detail on every request.',

        // transactions
        'TRANSACTION_BACKEND' => 'Where transactions are built and sent: phyrhose, proxy or null. null records claims and sends nothing.',
        'ALLOWED_NETWORKS' => 'Networks a campaign can be created on, comma separated. Empty or misspelled means none.',
        'MAINNET_APP_URL' => 'Where mainnet campaigns are created when this installation does not accept them. Only a hint on the create form.',
        'PHYRHOSE_MAINNET_ID' => 'Network id for the transaction backend on mainnet. No mainnet campaign wallet or claim can be sent without it.',
        'PHYRHOSE_MAINNET_JWT' => 'Token for the transaction backend on mainnet. Every mainnet request is refused without it.',
        'PHYRHOSE_MAINNET_URL' => 'Address of the transaction backend for mainnet.',
        'PHYRHOSE_PREPROD_ID' => 'Network id for the transaction backend on preprod. No preprod campaign wallet or claim can be sent without it.',
        'PHYRHOSE_PREPROD_JWT' => 'Token for the transaction backend on preprod. Every preprod request is refused without it.',
        'PHYRHOSE_PREPROD_URL' => 'Address of the transaction backend for preprod.',
        'PHYRHOSE_SUBMIT_TIMEOUT_SECONDS' => 'How long sending a batch of claims may take. Too short cuts off sends the backend accepted, and they are paid twice.',
        'PROXY_API_URL' => 'Address of the relay used when TRANSACTION_BACKEND is proxy.',
        'PROXY_API_TOKEN' => 'Token for the relay used when TRANSACTION_BACKEND is proxy. The relay refuses every request without it.',
        'KOIOS_API_TOKEN' => 'Optional Koios token. Without one the public rate limits apply and lookups can be refused with 429.',
        'KOIOS_MAINNET_URL' => 'Address of the Koios service for mainnet chain reads.',
        'KOIOS_PREPROD_URL' => 'Address of the Koios service for preprod chain reads.',
        'KOIOS_PREVIEW_URL' => 'Address of the Koios service for preview chain reads.',
        'NETWORK_FEE_LOVELACE' => 'Estimated network fee per claim, used for funding and cost accounting.',
        'COINS_PER_UTXO_BYTE' => 'Ledger cost per byte of an output, used when the chain read fails. A wrong value misprices codes.',
        'MIN_UTXO_ADDRESS_BYTES' => 'Address length assumed when a code is priced before its recipient is known.',

        // claims, codes and uploads
        'CLAIM_DOMAIN' => 'Separate host for claim links. Unset, and claim links use the longer path on the main host.',
        'ADA_DOMAIN' => 'Extra host that serves the /ada/now page as well as the main host.',
        'ADA_STAKE_POOL' => 'Pool ticker or hex id shown on the /ada/now page.',
        'CLAIM_RATE_PER_IP' => 'Claim requests allowed per IP address each minute. Zero blocks every claim.',
        'CLAIM_RATE_PER_CAMPAIGN' => 'Claim requests allowed per campaign each minute. Zero blocks every claim.',
        'CLAIM_RATE_PER_TOKEN' => 'Claim requests allowed per account each minute for callers using a codes:claim token. Zero blocks them all.',
        'CODE_API_CREATE_RATE_PER_TOKEN' => 'Code creation requests allowed per API token each minute. Zero blocks the code API.',
        'CODE_API_STATUS_RATE_PER_TOKEN' => 'Code status requests allowed per API token each minute. Zero blocks the status calls.',
        'CODE_API_MAX_TOKENS_PER_CODE' => 'Most reward tokens one code creation request may carry. Zero rejects every request.',
        'CODE_API_MAX_TOKEN_QUANTITY' => 'Largest quantity of a single reward token in a request. Zero rejects every request.',
        'POO_PUSH_DELAY_SECONDS' => 'Seconds a claim waits before it is sent, so claims taken meanwhile go out together. 30 when neither this nor POO_PUSH_DELAY is set.',
        'POO_PUSH_DELAY' => 'Earlier name for the batching window, in minutes. Read only while POO_PUSH_DELAY_SECONDS is blank.',
        'UPLOAD_MAX_CODES' => 'Most codes one upload may carry. Zero refuses every upload.',
        'UPLOAD_MAX_FILE_SIZE' => 'Largest upload in bytes. Zero refuses every upload.',
        'QR_EXPORT_JOB_TIMEOUT' => 'Longest one QR export attempt may run. Keep it under QUEUE_RETRY_AFTER and at or below TASK_STALE_AFTER_SECONDS.',
        'TASK_STALE_AFTER_SECONDS' => 'Silence after which a background task is presumed dead and can be started again. Keep it at or above QR_EXPORT_JOB_TIMEOUT.',

        // timing and limits
        'CLAIM_CHECK_AFTER_SUBMIT_SECONDS' => 'How long after claims are sent the first check for their confirmation runs.',
        'CLAIM_CHECK_DEBOUNCE_SECONDS' => 'However many status requests arrive, at most one claim check per campaign is queued in this many seconds.',
        'CLAIM_CHECK_MAX_PER_PASS' => 'The most claims one background check asks about, oldest first.',
        'CLAIM_RETRY_BACKOFF_BASE_SECONDS' => 'How long a claim whose transaction timed out waits before it is sent again. Each later timeout doubles it.',
        'HOUSEKEEPING_CHECK_EVERY_SECONDS' => 'The least time between two overdue-task checks queued by incoming requests.',
        'HOUSEKEEPING_LOCK_MINUTES' => 'How long a housekeeping task\'s run is presumed alive.',
        'HOUSEKEEPING_RETRY_AFTER_MINUTES' => 'How long traffic waits before retrying a failed housekeeping task. Each further failure in a row doubles it, up to the task\'s interval.',
        'HOUSEKEEPING_ASSET_SYNC_EVERY_MINUTES' => 'How often housekeeping refreshes the known-asset registry.',
        'HOUSEKEEPING_QR_PRUNE_EVERY_MINUTES' => 'How often housekeeping prunes old QR exports.',
        'QR_EXPORT_CHUNK_SIZE' => 'How many stickers a QR export renders between two chances to stop.',
        'QR_EXPORT_RESUME_WINDOW' => 'How long, in minutes, a QR export may keep resuming before it is given up on.',
        'QR_EXPORT_RETENTION_DAYS' => 'How long a QR export\'s record is kept after its archive has gone.',
        'QR_EXPORT_WORK_BUDGET' => 'How long one QR export attempt renders before it stores what it has and resumes.',
        'QR_STORAGE_TTL_DAYS' => 'Days a QR export bundle is kept before it is pruned.',
        'QR_STORAGE_URL_TTL' => 'Minutes a signed download link for a QR export stays valid.',
        'QR_STORAGE_PATH' => 'Folder on the QR storage disk that holds the exports.',
        'KOIOS_TIMEOUT' => 'How long one Koios request may take, in seconds.',
        'KOIOS_RETRIES' => 'How many times a failed Koios request is tried again.',
        'KOIOS_MAX_BODY_BYTES' => 'The largest request body Koios accepts, in bytes.',
        'PHYRHOSE_TIMEOUT_SECONDS' => 'How long one request to the transaction backend may take in all.',
        'PHYRHOSE_CONNECT_TIMEOUT_SECONDS' => 'How long connecting to the transaction backend may take.',
        'ASSET_METADATA_CIP25_CACHE_DAYS' => 'Days looked-up CIP-25 asset metadata is kept.',
        'ASSET_METADATA_CIP68_CACHE_HOURS' => 'Hours looked-up CIP-68 asset metadata is kept.',
        'ASSET_METADATA_LOOKUPS_PER_MINUTE' => 'Metadata lookups one signed-in user may make each minute.',
        'CARDANO_TIP_TTL' => 'Seconds before the current epoch is looked up again.',
        'PROXY_MONTHLY_LIMIT' => 'Requests a month the proxy relay endpoint accepts.',
        'SIGNED_UPLOAD_EXPIRES_MINUTES' => 'Minutes a signed upload link stays valid.',
        'SESSION_LIFETIME' => 'Minutes a session lasts.',
        'SESSION_DOMAIN' => 'Domain the session cookie is sent to.',
        'SESSION_COOKIE' => 'Name of the session cookie.',

        // banner, mail and security headers
        'APP_NOTICE_LINK_URL' => 'Address of the link beside the banner. Shown only when it is a well-formed http or https address.',
        'APP_NOTICE_LINK_TEXT' => 'Text of the link beside the banner. The address itself is used when this is blank.',
        'MAIL_FROM_NAME' => 'Sender name on outgoing mail.',
        'MAIL_ENCRYPTION' => 'Encryption for the SMTP connection: tls or ssl.',
        'MAIL_USERNAME' => 'SMTP user name.',
        'MAIL_PASSWORD' => 'SMTP password.',
        'PERMISSIONS_POLICY' => 'Value of the Permissions-Policy header sent with every page.',
        'CROSS_ORIGIN_OPENER_POLICY' => 'Value of the Cross-Origin-Opener-Policy header.',
        'CROSS_ORIGIN_RESOURCE_POLICY' => 'Value of the Cross-Origin-Resource-Policy header.',
        'CROSS_ORIGIN_EMBEDDER_POLICY' => 'Value of the Cross-Origin-Embedder-Policy header. Blank sends none.',
    ];
}
