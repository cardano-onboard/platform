# Configuration

Self-hosted configuration is driven by environment variables (see `.env.docker` for an
annotated template) and surfaced through Laravel config files. This page covers the
settings most operators need. *(SaaS users can skip this page — we manage configuration
for you.)*

## Transaction backends

Set `TRANSACTION_BACKEND` to choose how rewards are sent on-chain:

| Value      | Behavior                                                                 |
|------------|--------------------------------------------------------------------------|
| `null`     | Dry run — records claims but sends nothing. Great for testing the flow.  |
| `phyrhose` | Sends directly via the Phyrhose transaction backend (preprod/mainnet).   |
| `proxy`    | Relays through the hosted SaaS proxy using your API token (no node).     |

Phyrhose credentials live under `config/cardano.php` → `phyrhose` (`PHYRHOSE_PREPROD_URL`,
`PHYRHOSE_PREPROD_JWT`, `PHYRHOSE_PREPROD_ID`, and the mainnet equivalents). For `proxy`,
set `PROXY_API_URL` and `PROXY_API_TOKEN` (generate the token on the SaaS Profile page).

## Native-asset metadata (Koios)

Reward-token lookups use **Koios**, the public Cardano query layer. Defaults work
out of the box; override per network if needed:

- `KOIOS_MAINNET_URL` (default `https://api.koios.rest/api/v1/`)
- `KOIOS_PREPROD_URL` (default `https://preprod.koios.rest/api/v1/`)
- `KOIOS_PREVIEW_URL` (default `https://preview.koios.rest/api/v1/`)
- `KOIOS_API_TOKEN` — optional bearer token for higher rate limits

## NFT minting (NMKR, optional)

To mint an NFT per claim, configure an NMKR project under `config/cardano.php` → `nmkr`
(`NMKR_PREPROD_API_KEY` / `NMKR_MAINNET_API_KEY` and the API URLs), then set the project
UID and NFT count when creating a code.

## QR export storage

Generated QR sticker bundles are **cached and reused**: downloading the same campaign with the
same settings a second time serves the stored ZIP instead of regenerating it (regeneration is
CPU — and on serverless, billed — time). Choose where bundles live:

```dotenv
QR_STORAGE_DISK=          # blank = default FILESYSTEM_DISK (local); or "s3"
QR_STORAGE_PATH=qr-exports
QR_STORAGE_TTL_DAYS=7     # stale bundles pruned after N days
QR_STORAGE_URL_TTL=15     # signed-URL lifetime (minutes), remote disks only
```

- **Self-hosted:** leave `QR_STORAGE_DISK` blank to use the local disk — just make sure the
  storage volume has room (bundles are small: ~4 KB/PDF, ~13 KB/PNG, ~45 KB/SVG per sticker).
- **SaaS / serverless (Vapor):** set `QR_STORAGE_DISK=s3`. Downloads are then served via a
  short-lived **signed URL** (streamed straight from S3, avoiding the Lambda ~6 MB response
  limit), and large campaigns don't stream megabytes back through the function.

The cache key includes the export settings **and** a fingerprint of the codes + expiration, so
adding/removing a code or changing the campaign's end date automatically produces a fresh bundle.

**Expiry / cleanup:** the scheduled `qr:prune-exports` command deletes bundles older than
`QR_STORAGE_TTL_DAYS` on any disk (runs daily — ensure the scheduler is active). On S3 you can
instead configure a native **bucket lifecycle rule** on the `qr-exports/` prefix and rely on that.

## Claim subdomain (optional)

By default the claim endpoint (and the QR deep-links that target it) lives at
`https://<your-app>/api/claim/v1/{campaign}`. You can serve it from a dedicated, shorter
host instead:

```dotenv
CLAIM_DOMAIN=claim.example.com
```

When set, claim URLs and QR codes use `https://claim.example.com/v1/{campaign}`. The shorter
payload produces a **less dense, more scannable QR** — helpful for small printed stickers.
The displayed claim URL and the QR deep-links follow this automatically; nothing else to change.

Notes:

- **Backwards compatible.** The original `/api/claim/v1/{campaign}` route stays registered, so
  any QR codes you've already printed keep working.
- **It's a modest gain.** A subdomain shortens the payload by ~12 characters (one QR version).
  It does not, on its own, make a 1" sticker large enough to carry *both* a header and footer —
  see [QR export options](./claiming#export-options).
- **Requires DNS + TLS.** Point the subdomain at this application and issue a certificate for
  it:
  - **Self-hosted:** add a DNS record for the subdomain to your server and include it in your
    web server / TLS config (e.g. a SAN on your certificate).
  - **Laravel Vapor:** add the domain to the environment, attach an ACM certificate, and point
    DNS (CNAME) at the Vapor distribution. Vapor routes all its domains to the same app, so the
    host-based route resolves automatically.

## Limits & rate limiting

| Setting                   | Env                        | Default        |
|---------------------------|----------------------------|----------------|
| Max upload file size      | `UPLOAD_MAX_FILE_SIZE`     | 10 MB          |
| Max codes per import      | `UPLOAD_MAX_CODES`         | 10000          |
| Claim rate (per IP)       | `CLAIM_RATE_PER_IP`        | 60 / min       |
| Claim rate (per campaign) | `CLAIM_RATE_PER_CAMPAIGN`  | 120 / min      |
| Proxy monthly quota       | `PROXY_MONTHLY_LIMIT`      | 1000           |

## Security headers

Response security headers (CSP, COOP, CORP, Permissions-Policy, and optional COEP) are
set by the `SecurityHeaders` middleware and configured in `config/security.php`. Every
value is env-overridable — for example, tighten the `CONTENT_SECURITY_POLICY` for your
deployment, or enable `CROSS_ORIGIN_EMBEDDER_POLICY` once you've verified your CDN assets
send the required CORP headers.

## Admin account

The seeded admin is configured via `config/admin.php` (`ADMIN_EMAIL` / `ADMIN_PASSWORD`)
and created by the seeder on `php artisan migrate --seed`.

## Beta banner

`BETA_BANNER` (default `true`) toggles the "beta" notice in the UI. Turn it off for a
formal launch.
