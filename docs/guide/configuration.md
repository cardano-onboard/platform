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

## Chain data (Koios)

Reward-token lookups, protocol parameters and address balances all read from **Koios**,
the public Cardano query layer. Defaults work out of the box; override per network if
needed:

- `KOIOS_MAINNET_URL` (default `https://api.koios.rest/api/v1/`)
- `KOIOS_PREPROD_URL` (default `https://preprod.koios.rest/api/v1/`)
- `KOIOS_PREVIEW_URL` (default `https://preview.koios.rest/api/v1/`)
- `KOIOS_API_TOKEN` — optional bearer token for higher rate limits
- `KOIOS_TIMEOUT` (default `10`) — seconds to wait on one request
- `KOIOS_RETRIES` (default `3`) — attempts before a read is treated as failed
- `KOIOS_MAX_BODY_BYTES` (default `5120`) — largest request body Koios accepts; asset lookups are split into requests under it; a value below `256` is ignored

Only a connection that never completed is retried. A status Koios chose to send is an
answer, and asking again for an answer it has already given spends someone else's rate
limit to hear it twice.

A network with no URL configured is refused rather than read against mainnet, so a
misspelled network name produces an error instead of mainnet figures under a testnet name.

## Token names and decimals

The campaign page names each reward token and shows its amount in whole units. It reads
the Cardano token registry first, then a CIP-68 token's reference datum, then CIP-25
metadata from the token's minting transaction. Only registry tokens are stored in the
database; the rest are cached:

- `ASSET_METADATA_CIP68_CACHE_HOURS` (default `24`) — how long CIP-68 metadata is kept
  before it is read again, since the reference datum can change
- `ASSET_METADATA_CIP25_CACHE_DAYS` (default `365`) — how long CIP-25 metadata is kept;
  it changes only if the policy mints the token again
- `ASSET_METADATA_LOOKUPS_PER_MINUTE` (default `120`) — lookup requests one signed-in
  user may make per minute; each covers up to 50 tokens

A token Koios does not know is not asked about again for ten minutes. Only registry
tokens show a logo.

## Protocol parameters

Fees, the minimum-UTxO rate and the size ceilings are read from the chain and cached per
network under the epoch they belong to, so they refresh when the epoch turns over rather
than on a timer. There is nothing to configure here but how often the epoch number itself
is checked:

- `CARDANO_TIP_TTL` (default `60`) — seconds before the current epoch number is looked up
  again

That window is how often the provider is asked what epoch it is, not how long parameters
are trusted: within one epoch every reading is the same reading, and a new epoch is a new
cache entry. Raising it costs only lateness in noticing a boundary, and the shortest epoch
on any of these networks is a day.

No protocol parameter value can be pinned in configuration. A fee or minimum-UTxO figure
set by hand is one this application would keep using after the ledger had moved on. The
failure is quiet: too low and transactions are rejected after a campaign is funded, too
high and every output is overfunded out of the operator's own balance. If a parameter
cannot be read, the operation stops and says so.

## NFT minting (NMKR, optional)

To mint an NFT per claim, configure an NMKR project under `config/cardano.php` → `nmkr`
(`NMKR_PREPROD_API_KEY` / `NMKR_MAINNET_API_KEY` and the API URLs), then set the project
UID and NFT count when creating a code.

## Allowed networks

`ALLOWED_NETWORKS` lists the Cardano networks this deployment will accept for a campaign,
comma separated. The default is all three, so an existing installation is unaffected:

```dotenv
ALLOWED_NETWORKS=preprod,preview,mainnet
```

Narrow it to lock an environment down. An installation set to `preprod,preview` will not
create a mainnet campaign at all, which keeps a demo or pre-release deployment off real
funds.

A network that is not on the list is **absent from the network selector** on the campaign
create and edit forms, rather than shown and then rejected: nothing at a disabled control
could explain why it is unavailable. The controller validates against the same list, so a
request that names an excluded network is refused whichever form produced it.

Existing campaigns are grandfathered. A campaign already on a network you later remove
keeps that one network in its own selector, so the rest of its fields can still be edited
and saved. It cannot be moved to a *different* network that is also off the list. A
campaign's network is editable only until its first claim, whatever the allowlist says.

When mainnet is off the allowlist, set `MAINNET_APP_URL` to the deployment where mainnet
campaigns are actually created:

```dotenv
MAINNET_APP_URL=https://example.com
```

The campaign create form then shows a short hint under the network field pointing there.
Leave it unset and the form says nothing extra — this is what a self-hosted install and
an unrestricted deployment both do by default.

## Deployment notice

An optional banner shown at the top of the login, dashboard and every other
operator-facing page. It says nothing until you configure a message:

```dotenv
APP_NOTICE="This platform is now preprod only. Create mainnet campaigns on the main app."
APP_NOTICE_TYPE=info
APP_NOTICE_LINK_URL=https://example.com
APP_NOTICE_LINK_TEXT="Go to the main app"
```

| Setting               | Default  | Notes                                                  |
|------------------------|----------|---------------------------------------------------------|
| `APP_NOTICE`           | *(none)* | Plain text — rendered as text, not HTML. Unset shows nothing. |
| `APP_NOTICE_TYPE`      | `info`   | `info` or `warning`. Anything else falls back to `info`. |
| `APP_NOTICE_LINK_URL`  | *(none)* | Must be an `http(s)` URL or it is silently dropped and only the message shows. |
| `APP_NOTICE_LINK_TEXT` | *(none)* | Falls back to the URL itself if `APP_NOTICE_LINK_URL` is set and this is not. |

This is one banner meant for the people running the deployment, for example to point
visitors on a retired host at wherever the platform lives now. It never appears on a
page a claim recipient can land on — `/ada/now` and the claim endpoints carry no such
prop, whatever is configured — so an attendee claiming from a link printed months ago is
never told the platform they are on has moved.

## QR export storage

Generated QR sticker bundles are **cached and reused**: downloading the same campaign with the
same settings a second time serves the stored ZIP instead of regenerating it (regeneration is
CPU — and on serverless, billed — time). Choose where bundles live:

```dotenv
QR_STORAGE_DISK=          # blank = default FILESYSTEM_DISK (local); or "s3"
QR_STORAGE_PATH=qr-exports
QR_STORAGE_TTL_DAYS=7     # stale bundles pruned after N days
QR_STORAGE_URL_TTL=15     # signed-URL lifetime (minutes), remote disks only
QR_EXPORT_JOB_TIMEOUT=900 # seconds a worker may spend on one attempt at a bundle
QR_EXPORT_CHUNK_SIZE=250  # stickers rendered between two chances to stop
QR_EXPORT_WORK_BUDGET=    # seconds one attempt renders for; blank derives it from retry_after
QR_EXPORT_RESUME_WINDOW=60 # minutes an export may keep resuming before it is given up on
QR_EXPORT_RETENTION_DAYS=90 # days a spent export's record is kept
```

- **Self-hosted:** leave `QR_STORAGE_DISK` blank to use the local disk — just make sure the
  storage volume has room (bundles are small: ~4 KB/PDF, ~13 KB/PNG, ~45 KB/SVG per sticker).
- **Serverless or object-storage-backed:** set `QR_STORAGE_DISK=s3`. Downloads are then served
  as a short-lived **signed URL**, so the bundle goes straight from the object store to the
  browser instead of back through the application. That keeps a large campaign's ZIP out of the
  app's memory, and on a serverless runtime it also keeps the download clear of the platform's
  response-size limit.

The cache key includes the export settings, a fingerprint of the codes + expiration, **and the
claim URL printed into every sticker**. Adding or removing a code, changing the campaign's end
date, or moving the claim endpoint to a different host produces a fresh bundle.

**Bundles are built in the background.** Asking for an export queues the render and returns
straight away, and the campaign page shows the run while it works. Once the run has finished,
downloading the export serves the stored bundle. **Run a queue worker** to keep renders off the
request. Under `QUEUE_CONNECTION=sync` the render happens inside the request that asked for it, so
a large campaign holds the browser open for the whole render.

**There is no maximum export size.** A render works through the codes `QR_EXPORT_CHUNK_SIZE` at a
time. Once `QR_EXPORT_WORK_BUDGET` seconds have passed it stores the half-finished archive beside
the finished one, records the code it reached, and hands the queue message back. The next attempt
fetches that partial and carries on from the recorded code, so a worker killed by a deployment, a
timeout or a lost reservation costs one batch of stickers. A partial that is not the size or the
sticker count it was stored at is discarded and its batch rendered again, so an interrupted
upload can never become a bundle that looks complete and is short.

Leave `QR_EXPORT_WORK_BUDGET` blank unless you have a reason not to. It is then derived from the
queue connection's `retry_after`, which is the window an attempt has to finish inside before the
queue offers the same message to a second worker. Setting it above `retry_after` puts two workers
on one archive; the overlap lock refuses the second, but the attempts are then spent on lock
contention instead of stickers. Setting it to `0` stores after every batch, which loses the least
work to an interruption and does the most round trips to the disk.

`QR_EXPORT_RESUME_WINDOW` is the ceiling on the export as a whole. One still not finished this
long after it was asked for is given up on and reported as failed on the campaign page. A render
that throws three times is given up on without waiting for that window.

**Expiry / cleanup:** the scheduled `qr:prune-exports` command runs daily, so make sure the
scheduler is active. It deletes anything under the export prefix older than `QR_STORAGE_TTL_DAYS`
on any disk. That includes the half-finished archives an interrupted render leaves behind: one
whose settings stopped matching is never resumed into, and this is what removes it. The sweep
then reconciles the export records against the disk. A record whose bundle has passed its expiry,
or whose file is no longer there, is marked expired, and its path cleared, so the record stops
describing a download that nothing can serve.

That second pass is what makes a **bucket lifecycle rule** on the `qr-exports/` prefix a workable
alternative to the TTL sweep on S3. The rule removes the object without telling the application,
and the next sweep notices and corrects the record. Keep the command scheduled even then: the
rule takes the object, and only the sweep corrects the record.

Records outlive their bundles, because a record holds what an export was and the settings a
regenerate replays. An expired record stays regenerable: asking for the same export again queues
a fresh render and replaces the record in place. `QR_EXPORT_RETENTION_DAYS` is how long an
expired record is kept before the sweep deletes it, which is what keeps the table from growing
for the life of the deployment. A record whose bundle is still downloadable is never deleted,
whatever the retention window says. `--days` and `--retention-days` override the two ages for one
run.

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
  - **Serverless / managed hosting:** add the domain to the platform's environment, issue a
    certificate for it, and point DNS (CNAME) at the platform's routing target. Most managed
    platforms route every domain you add to the same app, so the host-based route resolves
    automatically once the certificate and DNS record are in place.

## Queue workers

Code imports, claim processing and the onboarding analysis run on a queue worker, not in the web
request. A worker reserves a job for a fixed window. Once that window passes, the job goes back
on the queue and a second worker starts it, even if the first worker is still running it. The
window has to be longer than the longest job can run:

```dotenv
QUEUE_RETRY_AFTER=1200    # seconds a job stays reserved
```

One setting covers the `database`, `redis` and `beanstalkd` connections, so a deployment that
changes queue driver keeps the same window. On SQS the window is the queue's visibility timeout,
set on the queue in AWS rather than here, and it needs the same value.

Raise it if you add a job that can run longer than the window. A window shorter than a job's own
timeout means a second worker picks up work the first worker is still doing: duplicated writes,
duplicated outbound calls, and on a metered runtime the bill for both.

Lowering it shortens how long a job sits after the worker running it dies, since the expired
reservation is what puts the job back on the queue. That is worth having only where no job can
still be running when the window passes.

## Bulk code uploads

A codes file goes straight from the browser to object storage and never passes through the
application: the browser asks this app to sign a PUT, sends the bytes to the bucket itself, and
the import then reads the object it left behind. A file of tens of megabytes through a PHP
process is a request that times out.

```dotenv
SIGNED_UPLOAD_EXPIRES_MINUTES=5
```

This is how long, in minutes, the browser has to finish that upload. It has to outlast a large
file on a slow connection and no longer, because until it expires the signed URL is a standing
permission to write to the bucket.

This needs `FILESYSTEM_DISK` to name a disk that can sign an upload, which means an
S3-compatible bucket. On the local disk there is nothing to sign: the upload is refused with an
explanation on the screen, and codes can still be added by hand from the campaign page.

## What a claim costs on chain

```dotenv
NETWORK_FEE_LOVELACE=200000
```

This is the estimated chain cost of putting one claim in front of one recipient. A fact about the
network rather than a price anyone sets, which is why every installation has it and why it is
never revenue: it funds a campaign's bucket, and it accounts for what a campaign spent.

It is recorded on each claim as an estimate. Claims are batched into a single transaction, so the
real fee is per transaction rather than per recipient and is not knowable at the moment a claim is
taken. What each claim recorded is what the campaign's cost summary reports, so raising this
figure changes what later claims record and leaves earlier ones alone.

## Limits & rate limiting

| Setting                   | Env                        | Default        |
|---------------------------|----------------------------|----------------|
| Max upload file size      | `UPLOAD_MAX_FILE_SIZE`     | 10 MB          |
| Max codes per import      | `UPLOAD_MAX_CODES`         | 10000          |
| Claim rate (per IP)       | `CLAIM_RATE_PER_IP`        | 60 / min       |
| Claim rate (per campaign) | `CLAIM_RATE_PER_CAMPAIGN`  | 120 / min      |
| Claim rate (per `codes:claim` account) | `CLAIM_RATE_PER_TOKEN` | 300 / min |
| Proxy monthly quota       | `PROXY_MONTHLY_LIMIT`      | 1000           |
| Code API create rate (per token) | `CODE_API_CREATE_RATE_PER_TOKEN` | 60 / min |
| Code API status rate (per token) | `CODE_API_STATUS_RATE_PER_TOKEN` | 60 / min |
| Code API max tokens per code  | `CODE_API_MAX_TOKENS_PER_CODE` | 20        |
| Code API max token quantity   | `CODE_API_MAX_TOKEN_QUANTITY`  | 1000000   |

## Reward minimums

Every Cardano output has to hold a minimum amount of ADA that rises with the number of
policies, the number of assets and the length of every asset name. A code set below its own
minimum cannot be paid at all, so the code form works the figure out and refuses one that is
under it.

```dotenv
COINS_PER_UTXO_BYTE=4310
MIN_UTXO_ADDRESS_BYTES=57
MIN_UTXO_HEADROOM_LOVELACE=1000000
```

`COINS_PER_UTXO_BYTE` (default `4310`) is a protocol parameter and is read from the chain
through the transaction backend. This setting is the fallback used when that read fails, so
a campaign can still be configured while a query layer is down. Change it only if mainnet's
value changes and a release has not caught up.

`MIN_UTXO_ADDRESS_BYTES` (default `57`) is the address length assumed when sizing an output.
57 bytes is a base address with a staking part, which is what every wallet a claimant is
likely to arrive with produces. A shorter address needs less, so assuming this never
understates the minimum.

`MIN_UTXO_HEADROOM_LOVELACE` (default `1000000`) is how far above the minimum a reward has
to sit before the form stops warning about it. A reward resting exactly on its minimum can
be received and not spent: moving a token costs a fee, and the output may not drop below
its own floor. Somebody whose wallet is minutes old has nothing else to pay that fee with.
Set it to `0` to turn the warning off; the refusal below the chain's own minimum stays
either way.

## Background work

Some work runs on a queue instead of in the request that asked for it. Importing a codes file
is one, and the onboarding analysis is another. Each reports its progress to the campaign page
while it runs, so the operator watches it there instead of reloading the page to find out
whether it finished.

A run that stops reporting for longer than `TASK_STALE_AFTER_SECONDS` is treated as dead, and
the same work can then be started again. The default covers the longest job this application
runs, 15 minutes, plus two minutes of grace. Raise it if you raise a job timeout past that.
Lower it and a long job is declared dead while it is still working.

```dotenv
TASK_STALE_AFTER_SECONDS=1020
```

## Security headers

Response security headers (CSP, COOP, CORP, Permissions-Policy, and optional COEP) are
set by the `SecurityHeaders` middleware and configured in `config/security.php`. Every
value is env-overridable — for example, tighten the `CONTENT_SECURITY_POLICY` for your
deployment, or enable `CROSS_ORIGIN_EMBEDDER_POLICY` once you've verified your CDN assets
send the required CORP headers.

## Admin account

The seeded admin is configured via `config/admin.php` (`ADMIN_EMAIL` / `ADMIN_PASSWORD`)
and created by the seeder on `php artisan migrate --seed`.

## Console commands

| Command | What it does |
|---|---|
| `api:token {user}` | Mints a Sanctum token for the account named by email, scoped to exactly the `--abilities` given. Options: `--abilities`, `--expires` (required), `--name`. See [Code API](./api-reference#code-api-sanctum). |
| `qr:prune-exports` | Deletes cached QR bundles, and the partials of interrupted renders, older than `QR_STORAGE_TTL_DAYS`, expires the records whose bundle has gone, and deletes expired records older than `QR_EXPORT_RETENTION_DAYS`. `--days` and `--retention-days` override the two ages. |
| `onboard:analyze {campaign}` | Classifies a campaign's claimant wallets. Options: `--operator`, `--from`, `--to`, `--scope-only`. See [Measuring onboarding](./onboarding-analysis). |
| `smoke:check {base_url}` | Read-only checks against a running deployment, one line per check, exiting non-zero if any fail. Options: `--claim-url`, `--campaign`, `--network`, `--timeout`. |

`api:token` is how a self-hosted instance issues a code API token: it has no profile
page to mint one from, and this is the same command a SaaS operator can use for the same
purpose. Repeat `--abilities` for more than one, for example `--abilities=codes:create
--abilities=codes:status`. An ability outside that pair is refused, and so is an
`--expires` value that already lies in the past. `--expires` is required: unlike a
profile-page token, which is always stamped for 24 hours, there is no default here that
mints a token which never expires.

`smoke:check` is for the minutes after a deployment. It loads the home, login and
`/ada/now` pages, the `/up` health endpoint, and every script and stylesheet the home
page references. It checks the security headers, sends a CORS preflight to the claim
endpoint, and confirms that the code API refuses a request with no token and that the
claim endpoint refuses a campaign that does not exist. `--claim-url` adds the same
preflight against the `v1` path on `CLAIM_DOMAIN`.

A preflight checks CORS configuration only. The CORS middleware answers a preflight to
any `api/*` or `v1/*` path before routing, so it passes whether or not a claim route is
registered there. `--campaign` is what proves each claim route answers: it takes the ID of
a live campaign and sends three claims the endpoint must refuse, an unreadable address, an
enterprise address and a code that does not exist, to `/api/claim/v1` and, with
`--claim-url`, to the short route as well. Those claims count against that campaign's
per-minute claim limit, three or four per route, so pick a campaign that is not taking
claims at a live event. `--network` (`mainnet`, `preprod` or `preview`) names the
campaign's network. Without it the unknown-code claim tries a mainnet address first and
leaves one wrong-network line in the log of a test-network deployment.

It sends only what an anonymous visitor could send. Nothing it sends can create a
campaign, code or claim, or move funds. For example:

```bash
php artisan smoke:check https://app.example.com --claim-url=https://claim.example.com --campaign=01J... --network=mainnet
```

## Beta banner

`BETA_BANNER` (default `true`) toggles the "beta" notice in the UI. Turn it off for a
formal launch.
