# Onboard.Ninja — Self-Hosted Cardano Airdrop Platform

[![CI](https://github.com/cardano-onboard/platform/actions/workflows/ci.yaml/badge.svg)](https://github.com/cardano-onboard/platform/actions/workflows/ci.yaml)

Onboard.Ninja is an open-source, self-hosted platform for running Cardano token airdrops at live events, and for finding out afterwards whether they onboarded anyone. Create campaigns, generate claim codes, print them as QR stickers, and distribute ADA or native assets to people claiming from a wallet they may be installing at that moment.

It implements [CIP-99](https://github.com/cardano-foundation/CIPs/tree/master/CIP-0099), the Cardano standard for onboarding claims, and ships with an on-chain onboarding analysis that reports which claimant wallets were new, which went on to transact on their own, and which delegated to a stake pool.

**What self-hosted means here.** Your instance keeps its records, including claimant addresses, stake keys and the onboarding analysis, in your own database. Claimant data leaves it in three ways. Payments carry claimant addresses: on the native backend your instance submits each payment transaction to Koios, and with the `phyrhose` or `proxy` backend it sends each claim's address and reward to Phyrhose or the hosted proxy to be paid. The onboarding analysis asks Koios about each claimant's wallet history by stake key, whichever backend you use. A code with an NMKR NFT reward sends the claimant's address to NMKR to mint and send the NFT, whichever backend you use. Koios requests go to its public endpoints unless you point the `KOIOS_*_URL` settings at your own. On the native backend, the default, your instance also builds and signs the transactions: each campaign holds its own key and pays from its own address, with no transaction service holding your keys or funds. Phyrhose and the hosted proxy remain available as alternatives. See [Transaction Backend](#transaction-backend) before you deploy: three things must be supplied before the first campaign.

---

## Table of Contents

- [What It Does](#what-it-does)
- [Prerequisites](#prerequisites)
- [Quick Start with Docker](#quick-start-with-docker)
- [Manual Setup](#manual-setup)
- [Configuration](#configuration)
- [Using the Platform](#using-the-platform)
- [Transaction Backend](#transaction-backend)
- [Tech Stack](#tech-stack)
- [License](#license)

---

## What It Does

- Create airdrop campaigns with start and end dates, targeting mainnet, preprod, or preview networks
- Generate single-use or multi-use claim codes to distribute to recipients
- Recipients claim tokens by submitting a Cardano wallet address against a code
- The platform batches and dispatches transactions via a pluggable transaction backend
- Track claim status, view live wallet balances, and refund unclaimed tokens back to any address
- Optional one-per-wallet enforcement to prevent duplicate claims
- Analyse the campaign against the chain afterwards and report, per claimant wallet:
  - **New** — the wallet's first ever transaction was this claim, so the campaign put it on chain
  - **Transacted for itself** — the wallet later sent a transaction of its own. Receiving a second
    payout does not count, so the number cannot be inflated by sending more tokens, and a
    transaction that carried nothing but the wallet's own stake certificate does not count either,
    because a wallet pays the deposit and the fee for its own delegation
  - **Delegated** — the wallet is registered and delegated to a stake pool, reported separately from
    the figure above and with how long after the claim it happened
  - **When** — how long after each claim those happened, reported at 30, 60 and 90 days

  A wallet the chain query could not be read for is reported as unknown rather than as a wallet
  that did nothing, in the panel and in the CSV export alike.

---

## Prerequisites

### Option A: Docker (recommended)

- Docker 24+ with Compose V2 (`docker compose`)

### Option B: Manual

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`
- Composer 2
- MySQL 8 (or MariaDB 10.6+)
- Node.js 18+ with npm

---

## Quick Start with Docker

```bash
git clone https://github.com/cardano-onboard/platform.git
cd platform
./quickstart.sh
docker compose up -d --build
```

`quickstart.sh` creates your `.env` and fills in the values that must be unique
to your install:

- **`APP_KEY`** — Laravel's encryption key. The app will not start without it.
- **`DB_PASSWORD` / `DB_ROOT_PASSWORD`** — random MySQL passwords, replacing the
  placeholder values in the template.
- **`ADMIN_EMAIL` / `ADMIN_PASSWORD`** — the account you log in with. It prompts
  for these; press enter at the password prompt to have one generated.

It writes `.env` with `600` permissions and never overwrites an existing one
unless you pass `--force`. For unattended installs, `./quickstart.sh --yes`
skips the prompts and generates an admin password, printing it at the end.

If port 8080 is already taken, pass a different one — this sets `APP_PORT` and
keeps `APP_URL` in sync:

```bash
./quickstart.sh --port 8082
```

On first run the container runs migrations and seeds the admin account
automatically. Visit `http://localhost:8080` and log in with the admin
credentials from the setup summary.

> **Keep `APP_KEY` stable.** It encrypts sessions and stored data. Changing it
> later logs everyone out and makes previously encrypted values unreadable.

### Manual setup (without the script)

If you'd rather configure by hand:

```bash
cp .env.docker .env
```

Then edit `.env` and set, at minimum:

```env
APP_KEY=base64:...      # generate with: openssl rand -base64 32  (keep the "base64:" prefix)
DB_PASSWORD=...         # any strong password
DB_ROOT_PASSWORD=...    # a different strong password
ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=...      # at least 12 characters
```

Leaving `APP_KEY` blank does not work — the container will stop on startup with
an error telling you to set it. This is deliberate: the alternative is a key
that regenerates on every restart and quietly destroys encrypted data.

### Changing the admin password later

`ADMIN_PASSWORD` is only applied when the admin account is seeded. To change it
afterwards, update `.env` and re-run the seeder:

```bash
docker compose exec app php artisan db:seed --class=AdminSeeder --force
```

### Changing ports

If a port is already taken on your machine (a local MySQL on 3306 is the common
one), set the host port in `.env` — no need to edit `docker-compose.yml`:

```env
APP_PORT=8080          # web UI; keep APP_URL in sync
DB_HOST_PORT=3307      # MySQL, as seen from your host
REDIS_HOST_PORT=6380   # Redis, as seen from your host
```

These change only how services are published to your host. Containers always
reach each other on the internal network using standard ports, so `DB_PORT` and
`REDIS_PORT` should be left alone.

By default MySQL and Redis are published on `127.0.0.1` only, so they are not
reachable from outside the host. If you deliberately need remote access, set
`DB_BIND=0.0.0.0` / `REDIS_BIND=0.0.0.0` — but use strong passwords and a
firewall first, since this exposes them on every interface.

To run more than one stack on the same machine, give each a distinct
`COMPOSE_PROJECT_NAME` along with its own ports.

---

## Manual Setup

```bash
# 1. Clone the repository
git clone https://github.com/cardano-onboard/platform.git
cd platform

# 2. Install PHP dependencies
composer install

# 3. Install and build frontend assets
npm install
npm run build

# 4. Configure environment
cp .env.example .env
php artisan key:generate
# Edit .env — see Configuration section below

# 5. Run database migrations, load the known-asset registry and create the admin account
php artisan migrate --seed
php artisan db:seed --class=AdminSeeder

# 6. Start the development server
php artisan serve
```

Visit `http://localhost:8000` to access the application.

For production deployments, serve the application with a proper web server (nginx or Apache) pointing to the `public/` directory, and run the queue worker and scheduler:

```bash
php artisan queue:work
php artisan schedule:work
```

Without the scheduler, the periodic maintenance tasks (the token registry refresh and the QR export cleanup) still run: once a task is due, requests queue a check at most once a minute, and the queue worker runs whichever task is overdue. A task that fails is tried again after a wait that doubles with each failure in a row. Set `HOUSEKEEPING_ON_TRAFFIC=false` to rely on the scheduler alone. See [Scheduled maintenance](docs/guide/configuration.md#scheduled-maintenance).

---

## Configuration

All configuration is done via the `.env` file. Copy `.env.example` to `.env` and fill in the values described below.

### Application

```env
APP_NAME="Onboard.Ninja"
APP_ENV=production
APP_KEY=                        # generated by php artisan key:generate
APP_DEBUG=false
APP_URL=https://your-domain.com
```

### Database

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=onboard
DB_USERNAME=onboard
DB_PASSWORD=your_password
```

### Queue

For production, use a real queue driver so transaction checks run asynchronously:

```env
QUEUE_CONNECTION=database       # or redis
```

### Transaction Backend

The default backend is `native`: the instance submits its own transactions. See the [Transaction Backend](#transaction-backend) section below for what it needs and for the alternatives.

```env
# The default: this instance submits its own transactions
TRANSACTION_BACKEND=native
```

### NMKR (optional)

NMKR Studio mints and sends NFT rewards, on any transaction backend. Enter the NMKR API key on the campaign; that key is the one that mints, and it is needed only if a code carries an NMKR NFT reward. The two API URLs below choose the endpoint: mainnet campaigns use the mainnet URL, and preprod and preview campaigns use the preprod one. The two API key settings are replaced by the campaign's key at claim time and are not used as a fallback.

```env
NMKR_PREPROD_API_URL=https://studio-api.preprod.nmkr.io/v2/
NMKR_PREPROD_API_KEY=your_preprod_key

NMKR_MAINNET_API_URL=https://studio-api.nmkr.io/v2/
NMKR_MAINNET_API_KEY=your_mainnet_key
```

### Network Selection

Network is selected per campaign (mainnet, preprod, or preview). No global network setting is required — campaigns are created on a specific network, and the appropriate backend credentials are used automatically.

### Admin / First User

A self-hosted install has no sign-up, email verification, password reset or profile page, and no separate admin role. It has one account, created by the admin seeder from `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env`. `quickstart.sh` sets both. The Docker entrypoint runs the seeder on every start, and on a manual install you run it yourself with `php artisan db:seed --class=AdminSeeder`. Sign in at `/login` with those credentials. The seeder marks the account's email as verified, and if the account already exists it sets its password back to `ADMIN_PASSWORD`; see "Changing the admin password later" above. That account owns every campaign.

---

## Using the Platform

### 1. Log in

Navigate to your deployment URL and log in with the admin credentials from your `.env`.

### 2. Create a campaign

From the dashboard, click **New Campaign**. Provide:

- **Name** — unique within your account
- **Description** — shown to recipients
- **Start / end dates** — the window during which codes can be claimed
- **Network** — `mainnet`, `preprod`, or `preview`
- **One per wallet** — optionally restrict each wallet address to a single successful claim

On creation the platform provisions a dedicated bucket wallet via the transaction backend. Fund this wallet with the ADA and tokens you intend to distribute before the campaign start date.

### 3. Generate claim codes

Open your campaign and create codes. Each code specifies:

- **Number of uses** — how many times the code can be claimed
- **Lovelace amount** — ADA to send per claim (1 ADA = 1,000,000 lovelace)
- **Token rewards** — one or more native assets identified by policy ID and asset hex, with quantity per claim

Distribute codes to recipients via email, event check-in, QR code, or any other channel.

### 4. Recipients claim tokens

Share the campaign claim URL (shown on the campaign page) with recipients. Wallets scan the QR code and submit their own address. Only with **Allow claims from a desktop browser** switched on for the campaign does the claim URL also work in a desktop browser, where the recipient enters the claim code and connects a CIP-30 wallet extension. The platform validates the code, checks wallet eligibility if one-per-wallet is enabled, and queues the transaction.

### 5. Monitor claims

The campaign detail page shows all claims with their current status:

- **Pending** — queued, transaction not yet submitted
- **Processing** — transaction submitted, awaiting confirmation
- **Completed** — confirmed on-chain with a transaction hash
- **Failed** — exceeded retry limit; manual intervention may be required

Use the **Check Claims** button to manually trigger a status check for pending transactions. The platform also checks automatically shortly after sending a batch, whenever the code status endpoint or a repeated claim asks about a waiting claim, and every five minutes on the scheduler.

### 6. Refund remaining balance

After a campaign ends, use the **Refund** button to return any unclaimed tokens from the campaign bucket wallet to a specified Cardano address.

---

## Transaction Backend

The platform uses a pluggable transaction backend controlled by the `TRANSACTION_BACKEND` environment variable. No code changes are needed to switch between backends.

### `native` — Your Own Transactions (default)

```env
TRANSACTION_BACKEND=native
CAMPAIGN_WRAPPING_KEY_1=
CAMPAIGN_OWNER_KEY_HASH_1=
CAMPAIGN_OWNER_KEY_HASH_2=
CAMPAIGN_STAKE_ADDRESS_PREPROD=
```

- Each campaign gets its own signing key and address; the instance builds, signs and submits every payment itself
- No transaction service holds your keys or funds; chain reads and submission go to Koios, its public endpoints or your own through the `KOIOS_*_URL` settings

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

### `null` — Test Mode

```env
TRANSACTION_BACKEND=null
```

- Generates fake wallet addresses and instantly "completes" all transactions
- A persistent red **TEST MODE** banner is shown across the entire application warning users not to send real tokens
- Perfect for verifying your deployment, testing the UI, and running demos
- **Do NOT send real ADA or tokens** to any wallet addresses shown in test mode

> **Tip:** Set `null` to look around the interface without real transactions, then switch to a live backend when ready.

### `phyrhose` — Direct Phyrhose Connection

```env
TRANSACTION_BACKEND=phyrhose

# Preprod (testnet)
PHYRHOSE_PREPROD_URL=https://testnet.phyrhose.io/
PHYRHOSE_PREPROD_JWT=your-preprod-jwt
PHYRHOSE_PREPROD_ID=your-preprod-project-id

# Mainnet
PHYRHOSE_MAINNET_URL=https://api.phyrhose.io/
PHYRHOSE_MAINNET_JWT=your-mainnet-jwt
PHYRHOSE_MAINNET_ID=your-mainnet-project-id
```

- Connects directly to the [Phyrhose](https://phyrhose.io) transaction service
- Obtain credentials by signing up at phyrhose.io
- Each campaign selects its network (preprod or mainnet) — the correct credentials are used automatically
- An alternative to `native` if you would rather not hold campaign keys on your own server

### `proxy` — SaaS Proxy Relay

```env
TRANSACTION_BACKEND=proxy
PROXY_API_URL=https://beta.onbd.io/api/v1/proxy
PROXY_API_TOKEN=your-api-token
```

- Routes all transactions through the hosted Onboard.Ninja SaaS instance
- No Phyrhose account needed — the SaaS instance handles the Phyrhose connection
- To get an API token: register at [beta.onbd.io](https://beta.onbd.io), go to Profile, and create an API token
- Setup operations (bucket creation, refunds) count toward a monthly quota; claim operations (payments, balance checks) are unmetered

### Switching to Live Mode

1. Set `TRANSACTION_BACKEND` to `native`, `phyrhose` or `proxy` in your `.env`
2. Fill in the corresponding settings or credentials
3. Restart the application (or run `php artisan config:clear`)
4. The TEST MODE banner disappears automatically

### Custom Backends

All backends implement the `App\Contracts\TransactionBackend` interface:

```php
interface TransactionBackend
{
    public function createBucket(Campaign $campaign, string $network): array;
    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array;
    public function checkStatus(string $purchaseId, string $network): array;
    public function refund(string $campaignId, string $address, string $network): bool;
    public function getBalance(string $address, string $network): array;
}
```

To use a custom implementation, add a new case to the match expression in `app/Providers/AppServiceProvider.php` and set `TRANSACTION_BACKEND` to your custom value.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend framework | Laravel 12 (PHP 8.2+) |
| Frontend framework | Vue 3 (Composition API, `<script setup>`) |
| UI components | Vuetify 3 |
| Server-client glue | Inertia.js |
| Build tool | Vite |
| Routing (frontend) | Ziggy |
| Database | MySQL 8 / MariaDB 10.6+ |
| Authentication | Laravel Breeze (session-based) |

---

## License

Apache License 2.0. See `LICENSE` for full terms.

---

Onboard.Ninja is built and maintained by the Onboard.Ninja team. Contributions are welcome.
