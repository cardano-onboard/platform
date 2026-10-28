# Configuration

Self-hosted configuration is driven by environment variables (see `.env.docker` for an
annotated template) and surfaced through Laravel config files. This page covers the
settings most operators need. *(SaaS users can skip this page — we manage configuration
for you.)*

## Transaction backends

Set `TRANSACTION_BACKEND` to choose how rewards are sent on-chain:

| Value      | Behavior                                                                 |
|------------|--------------------------------------------------------------------------|
| `native`   | The self-hosted default. This installation builds, signs and submits its own transactions; each campaign holds its own key. See below. |
| `phyrhose` | Sends via the Phyrhose transaction backend (preprod/mainnet).            |
| `proxy`    | Relays through the hosted SaaS proxy using your API token (no node).     |
| `null`     | Dry run — records claims but sends nothing. Great for testing the flow.  |

Phyrhose credentials live under `config/cardano.php` → `phyrhose` (`PHYRHOSE_PREPROD_URL`,
`PHYRHOSE_PREPROD_JWT`, `PHYRHOSE_PREPROD_ID`, and the mainnet equivalents). For `proxy`,
set `PROXY_API_URL` and `PROXY_API_TOKEN` (generate the token on the SaaS Profile page).

A request to Phyrhose gives up after `PHYRHOSE_TIMEOUT_SECONDS` (10 by default), or after
`PHYRHOSE_CONNECT_TIMEOUT_SECONDS` (5 by default) if it cannot connect. Sending a batch of
claims has its own, longer limit, `PHYRHOSE_SUBMIT_TIMEOUT_SECONDS` (60 by default). A
claim status check that cannot reach the backend stops there and leaves the remaining
claims for the next check.

A campaign keeps the backend it was created under for its whole life: the backend is recorded
on the campaign's wallet when the wallet is made. Changing `TRANSACTION_BACKEND` affects only
campaigns created afterwards.

## Native campaign wallets

With `TRANSACTION_BACKEND=native`, each new campaign gets its own signing key and a native
script address, and the application builds, signs and submits its payments itself. Chain data
and submission go through Koios (below). It is the default in `.env.docker`, and Phyrhose and the
proxy stay available as alternatives by changing `TRANSACTION_BACKEND`.

Three things are supplied once, before the first campaign is created. Without them campaign
creation is refused, and the message names each variable that is missing.

1. **A wrapping key.** It encrypts the signing key of every campaign. Make one and put it in
   `CAMPAIGN_WRAPPING_KEY_1`:

   ```bash
   openssl rand -base64 32
   # or, without openssl:
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   ```

   Keep a copy somewhere other than the server. If it is lost, the campaign keys encrypted under it
   cannot be read. `quickstart.sh` generates one for you.
2. **Two recovery key hashes**, in `CAMPAIGN_OWNER_KEY_HASH_1` and `CAMPAIGN_OWNER_KEY_HASH_2`, from
   two wallets you hold. Either wallet can always spend a campaign's funds, whatever happens to
   this server, and a hash cannot be added to a campaign's address afterward. A hash is 56
   lowercase hex characters: the payment key hash of an address in the wallet, for example from
   `cardano-cli address key-hash --payment-verification-key-file payment.vkey`.
3. **A stake address** for each network you use, in `CAMPAIGN_STAKE_ADDRESS_PREPROD` or
   `CAMPAIGN_STAKE_ADDRESS_MAINNET`. Campaign funds are staked with it, so they keep earning while
   they wait. It begins `stake_test1` on preprod and `stake1` on mainnet.

The settings in full:

- `CAMPAIGN_OWNER_KEY_HASH_1` and `CAMPAIGN_OWNER_KEY_HASH_2`: the two recovery key hashes,
  each 56 lowercase hex characters (blake2b-224 of an Ed25519 verification key). Both go into
  every campaign's script, and a campaign cannot be given a wallet without both.
- `CAMPAIGN_WRAPPING_KEY_1`: the secret campaign keys are encrypted under, base64 of 32 random
  bytes. It is not `APP_KEY`, and it must be kept for as long as any campaign encrypted under it
  exists. `CAMPAIGN_WRAPPING_KEY_2` holds the next one during a rotation, and
  `CAMPAIGN_WRAPPING_KEY_VERSION` (default `1`) says which one new keys are written under.
- `CAMPAIGN_STAKE_ADDRESS_MAINNET`, `CAMPAIGN_STAKE_ADDRESS_PREPROD` and
  `CAMPAIGN_STAKE_ADDRESS_PREVIEW`: the stake address campaign funds are staked with on each
  network, as a bech32 stake address (`stake1...` on mainnet, `stake_test1...` on the test
  networks). No default.

A campaign's address is a base address: its script controls the funds, and a stake credential
beside it controls only the staking rights. A campaign run on the deployment's own funds carries
the stake address configured for its network, so those funds keep earning rewards and keep the
delegation the deployment chose. A campaign on a network with no stake address configured is
refused at creation, and the error names the variable to set. There is no fallback to an address
without a stake part.

The stake address, the recovery key hashes and the expiry below are all inside a campaign's
address. Changing any of them changes the address of campaigns created afterwards and never the
address of one that already exists.

How long a campaign's key may spend:

A campaign's key stops being able to sign 90 days after the campaign's end date. Payments
confirm, unchecked claims are checked and what is left is returned inside this window. The 90
days are fixed in the application and no setting changes them, because they are inside every
campaign's address. A campaign's end date can later be moved, but never past its expiry less 90
days.

- `CAMPAIGN_AUTHORISATION_DAYS` (default `180`): the longest a campaign's key may spend, counted
  from the day it is created and including the 90 days of settlement. With the defaults, a campaign created
  today can end at most 90 days from now; a later end date is refused at creation with the latest
  one that fits.

How payments are sent and settled:

- `CAMPAIGN_VALIDITY_SECONDS` (default `7200`): how long a built payment stays valid. A payment
  that has not reached the chain by then never will, and only then is it rebuilt.
- `CAMPAIGN_CONFIRMATION_DEPTH` (default `3`): blocks on top of a payment before it counts as
  confirmed.

Payments go out one after another: while one is waiting for the chain, the next is built on the
change it returns to the campaign, so a campaign does not wait for a confirmation between batches.
A reward that includes tokens is paid as one output holding the tokens and exactly the ledger's
minimum lovelace for that output, or the reward's stored lovelace if that is higher. What a
campaign's inputs hold beyond what is sent goes back to the campaign, each asset class in an
output of its own. A reward whose token the campaign does not hold is left unpaid and tried
again on a later run, and the rest of the batch is paid. A batch the campaign's balance does not
cover in full pays the claims it does cover, and the rest are paid once it is topped up. What is left
in a campaign is returned only once its payments have confirmed and no claim is waiting to be paid,
and a refund asked for sooner is refused with that reason. A campaign holding more outputs than one transaction can spend is returned in as many transactions as it takes.

Confirmations are read from the chain whenever claims are checked, and a payment the chain has not
seen is offered to it again until its validity runs out. Nothing needs a scheduler. `php artisan
poo:env-check` verifies each native setting above.

Switching a deployment to `native` changes only the campaigns created afterwards. Each campaign's
wallet records the backend it was created on, and its payments, status checks and refunds keep
using that backend.

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

To mint an NFT per claim, enter the NMKR API key on the campaign, then set the project UID
and NFT count when creating a code. The campaign's key is the one that mints. The
`NMKR_MAINNET_API_URL` and `NMKR_PREPROD_API_URL` settings choose the NMKR endpoint (mainnet
campaigns use the first, preprod and preview campaigns the second). `NMKR_MAINNET_API_KEY` and
`NMKR_PREPROD_API_KEY` are replaced by the campaign's key at claim time and are not used as a
fallback.

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

**Expiry / cleanup:** the `qr:prune-exports` command runs daily, from the scheduler or from
traffic (see [Scheduled maintenance](#scheduled-maintenance)). It deletes anything under the export prefix older than `QR_STORAGE_TTL_DAYS`
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

## Scheduled maintenance

Two tasks keep a deployment tidy:

- `assets:sync-registry` refreshes the token registry that token names and decimals are read
  from. It runs every six hours.
- `qr:prune-exports` deletes expired QR sticker bundles and reconciles the export records, as
  described under [QR export storage](#qr-export-storage). It runs daily.

They run in either of two ways, and a deployment may use both.

**From the scheduler.** The Docker image runs `php artisan schedule:run` every minute, which runs
each task at the times `routes/console.php` sets. Any other deployment can do the same with
`php artisan schedule:work`, or a cron entry that runs `schedule:run` every minute.

**From traffic.** A deployment that runs no scheduler, for example one that scales to zero
between visits, gets the same tasks from its requests. Once a response has been sent, the
application reads the time the next task falls due from the cache. Before that time it does
nothing more, so requests queue no jobs while no task is due. The time is worked out again from
the `housekeeping_runs` table when a run finishes, and at least once an hour. Once a task is due,
the request queues a check, unless one was queued within the last
`HOUSEKEEPING_CHECK_EVERY_SECONDS`. The check runs on a queue worker, reads when each task
last succeeded, and queues a run of each task that is overdue. Nothing runs inside the request.
Web pages, the API and the claim subdomain all count as traffic. This path needs a queue worker:
on the `sync` queue connection it is skipped, because the tasks would then run in the web
process.

Every successful run records when it started in the `housekeeping_runs` table, whichever way it
was started. A task the scheduler has just run is therefore not run again by traffic. The
record is kept in the database rather than the cache because a cache can come back empty after
a restart. A running task also holds a lock in that table, so a check does not start a second
run beside it. A lock left by a run that died is given up after `HOUSEKEEPING_LOCK_MINUTES`.
Traffic treats a task as overdue only once its interval has passed by five minutes, so a
scheduled run starting on the minute its interval comes round is not joined by a second one.

A failed run records no success, and traffic waits before trying the task again:
`HOUSEKEEPING_RETRY_AFTER_MINUTES` (default `15`) after the first failure, twice that after the
second failure in a row, and so on, up to the task's own interval. The wait runs from when the
run failed, and the same five minutes are added to it for the same reason: once the wait has
grown to the interval, a failed scheduled run's wait ends on the minute the next scheduled run
starts. A run that dies without finishing, for example when its worker is stopped for running
too long or out of memory, counts as a failure from when it started, and is not tried again
before its lock is given up. A success resets the count.

```dotenv
HOUSEKEEPING_ON_TRAFFIC=true
HOUSEKEEPING_CHECK_EVERY_SECONDS=60
HOUSEKEEPING_LOCK_MINUTES=60
HOUSEKEEPING_RETRY_AFTER_MINUTES=15
HOUSEKEEPING_ASSET_SYNC_EVERY_MINUTES=360
HOUSEKEEPING_QR_PRUNE_EVERY_MINUTES=1440
```

Set `HOUSEKEEPING_ON_TRAFFIC=false` to rely on the scheduler alone. The two interval settings
and the retry wait apply to the traffic path; the scheduler keeps the times in
`routes/console.php`.

`assets:sync-registry` exits with a failure when Koios stops answering part-way through the
registry. The tokens fetched before that point are kept, and the run is not recorded as done, so
traffic tries it again after the retry wait.

## Claim delivery timing

A claim is not sent the moment it is taken. Claims wait a short batching window and then go
to the transaction backend together. The backend does not report back when a transaction
lands, so the platform asks: a little after the claims were sent, and again at growing
intervals while any of them is unconfirmed; whenever a status request or a repeated claim
asks about a claim still waiting; and on the scheduler, every five minutes, where one runs.

The checks after sending need a queue that can wait. On the `sync`, `deferred` and `background`
queue connections a job runs straight away and ignores its delay, so no checks are queued after
sending. Claims are then confirmed and retried only by status requests and the scheduler, so a
deployment on one of these connections needs `php artisan schedule:run` run every minute from
cron. Without it, a claim whose recipient closes the page stays pending. Set `QUEUE_CONNECTION`
to `database` or `redis` and run a worker instead where possible.

```dotenv
POO_PUSH_DELAY_SECONDS=30            # batching window before claims are sent
CLAIM_CHECK_AFTER_SUBMIT_SECONDS=40  # first check after sending; later ones double, up to 300
CLAIM_CHECK_DEBOUNCE_SECONDS=15      # at most one requested check per campaign per window
CLAIM_CHECK_MAX_PER_PASS=100         # claims one background check asks about, oldest first
CLAIM_RETRY_BACKOFF_BASE_SECONDS=60  # wait before resending after a first timeout
```

`POO_PUSH_DELAY_SECONDS` cannot exceed 900. `POO_PUSH_DELAY`, in whole minutes, is the
earlier name for the batching window. When `POO_PUSH_DELAY_SECONDS` is blank or absent and
`POO_PUSH_DELAY` is present, `POO_PUSH_DELAY` decides the window as it always has, and a
blank or 0 value means claims are sent without waiting. The 30-second default applies only
when neither is present. The example environment file ships both blank, so a fresh copy
sends claims without waiting. The **Check claims** button and the onboarding analysis ask
about every waiting claim whatever `CLAIM_CHECK_MAX_PER_PASS` says.

The checks after sending wait 40, 80 and 160 seconds and then five minutes at a time, and
stop once nothing is waiting for confirmation or half an hour after the last send. A
campaign has one such run of checks at a time. Sending more claims while it runs adds a
check 40 seconds after that send and starts the waits again from there.

When the backend reports that a claim's transaction timed out, the claim is sent again after
`CLAIM_RETRY_BACKOFF_BASE_SECONDS`, and each further timeout doubles the wait: 1, 2, 4 and 8
minutes at the default. The fifth timeout marks the claim failed. A claim waiting out its
backoff is not sent early by a send run that other claims bring round.

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
minimum cannot be paid at all, so every code is stored at the higher of the amount entered
and its exact minimum.

```dotenv
COINS_PER_UTXO_BYTE=4310
MIN_UTXO_ADDRESS_BYTES=57
```

`COINS_PER_UTXO_BYTE` (default `4310`) is a protocol parameter and is read from the chain
through the transaction backend. This setting is the fallback used when that read fails, so
a campaign can still be configured while a query layer is down. Change it only if mainnet's
value changes and a release has not caught up.

`MIN_UTXO_ADDRESS_BYTES` (default `57`) is the address length assumed when sizing an output.
57 bytes is a base address with a staking part, which is what every wallet a claimant is
likely to arrive with produces. A shorter address needs less, so assuming this never
understates the minimum.

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
and created by `php artisan db:seed --class=AdminSeeder`, which the Docker entrypoint runs on every start.

## Console commands

| Command | What it does |
|---|---|
| `api:token {user}` | Mints a Sanctum token for the account named by email, scoped to exactly the `--abilities` given. Options: `--abilities`, `--expires` (required), `--name`. See [Code API](./api-reference#code-api-sanctum). |
| `qr:prune-exports` | Deletes cached QR bundles, and the partials of interrupted renders, older than `QR_STORAGE_TTL_DAYS`, expires the records whose bundle has gone, and deletes expired records older than `QR_EXPORT_RETENTION_DAYS`. `--days` and `--retention-days` override the two ages. |
| `claims:retry-failed {campaign}` | Asks the transaction backend about each failed claim that has a purchase ID before touching it. A claim the backend reports paid is completed, and only one it reports timed out is reset to pending with a clean retry count and queued to be sent again. Held claims and claims with a transaction hash are left alone. It stops at the first answer that cannot be reached or read, and the claims after it stay failed. `--dry-run` reports how many would be reset without changing anything. |
| `claims:complete-confirmed` | Marks completed the claims that carry a real transaction hash but were left pending or failed. Safe to run at any time and as often as you like. `--dry-run` prints the count and writes nothing. |
| `onboard:analyze {campaign}` | Classifies a campaign's claimant wallets. Options: `--operator`, `--from`, `--to`, `--scope-only`. See [Measuring onboarding](./onboarding-analysis). |
| `smoke:check {base_url}` | Read-only checks against a running deployment, one line per check, exiting non-zero if any fail. Options: `--claim-url`, `--campaign`, `--network`, `--timeout`. |
| `poo:env-check` | Checks this installation's environment and prints one row per setting: ok, warning, missing or wrong, with the variable to change. Never prints a secret. Options: `--profile` (`production` by default, so pass `self-hosted` here), `--live`, `--timeout`, `--strict`. With `--all` it lists every variable instead, with its value and default. |

`api:token` is how a self-hosted instance issues a code API token: it has no profile
page to mint one from, and this is the same command a SaaS operator can use for the same
purpose. Repeat `--abilities` for more than one, for example `--abilities=codes:create
--abilities=codes:status`. An ability outside that pair is refused, and so is an
`--expires` value that already lies in the past. `--expires` is required: unlike a
profile-page token, which is always stamped for 24 hours, there is no default here that
mints a token which never expires.

`poo:env-check` is for the minutes before you start an installation or after you change
its `.env`. Run `php artisan poo:env-check --profile=self-hosted`. It exits non-zero when a
setting is missing or wrong, for example an `APP_KEY` that is blank, an `ADMIN_PASSWORD`
left at the example value, or a `TRANSACTION_BACKEND` whose credentials are not set. Each
message names the variable and what to put there. Warnings, such as `APP_DEBUG=true` or the
`sync` queue, do not fail the command unless you add `--strict`. `--live` also makes
read-only requests to your database, queue, transaction backend, Koios and mail server,
each limited to `--timeout` seconds (5 by default). A `DB_CONNECTION`, `QUEUE_CONNECTION`,
`CACHE_DRIVER`, `SESSION_DRIVER`, `MAIL_MAILER` or `FILESYSTEM_DISK` that names nothing the
installation has is reported as wrong, and the check does not repeat the value. Settings
are read from the running configuration, so run it where the application runs.

To find the variable behind a behaviour, run `php artisan poo:env-check --all`. It lists
every environment variable the application reads, grouped by config file, with the value
the running configuration has, the default written in that file, and a state: `set` when
the value differs from the default, `default` when it equals it (it may still be set to
that value in `.env`), `empty`, or `unknown` when it cannot be compared with the
default. A `*` marks a variable the check has a rule for. `--grep=redis` keeps the
variables whose name, config key, group or one-line description contain every word
given, `--changed` keeps only those that differ from their default, `--group=mail`
limits the list to one config file (several, separated by commas), and `--json` prints
the same rows as JSON. The list is read from the config files, so it covers every
variable the version you are running reads. A value is printed only when it is the kind
of value its variable holds: a switch, a number, a port, one of the known words, a host
name, a short name or an address. Everything else shows as `set (hidden)`, in text and
in JSON: passwords, keys and tokens, free text such as names and messages, paths, and
any value that does not have the shape expected. An empty password or key shows as
`unset`. An address is shown without its user name and password, with every query value
and any query key that is not a short lower case word replaced by `***`, and hidden
whole when its host is not a plain host name. Rows for a database, queue, cache or mail
connection other than the one selected say `(unused connection)` and are left out of
`--changed`. Error output is different: when the application cannot start, for example
on a malformed `.env`, the framework's own error message can print the value it choked
on, so do not paste that output anywhere.

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

`BETA_BANNER` (default `false`) toggles the "beta" notice in the UI. Set it to `true`
to show the notice.
