# Onboard.Ninja — Self-Hosted Cardano Airdrop Platform

[![CI](https://github.com/cardano-onboard/platform/actions/workflows/ci.yaml/badge.svg)](https://github.com/cardano-onboard/platform/actions/workflows/ci.yaml)

Onboard.Ninja is an open-source, self-hosted tool for running Cardano token airdrops and distributions. Create campaigns, generate claim codes, and distribute ADA or native assets to wallets without relying on a third-party service.

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

# 5. Run database migrations and seed default data
php artisan migrate --seed

# 6. Start the development server
php artisan serve
```

Visit `http://localhost:8000` to access the application.

For production deployments, serve the application with a proper web server (nginx or Apache) pointing to the `public/` directory, and run the queue worker and scheduler:

```bash
php artisan queue:work
php artisan schedule:work
```

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

The default backend is `null` (test mode). See the [Transaction Backend](#transaction-backend) section below for how to switch to a live backend.

```env
# Start in test mode (fake wallets, no real transactions)
TRANSACTION_BACKEND=null
```

### NMKR (optional)

NMKR Studio is an alternative backend option. API keys are optional if you are using a different backend implementation.

```env
NMKR_PREPROD_API_URL=https://studio-api.preprod.nmkr.io/v2/
NMKR_PREPROD_API_KEY=your_preprod_key

NMKR_MAINNET_API_URL=https://studio-api.nmkr.io/v2/
NMKR_MAINNET_API_KEY=your_mainnet_key
```

### Network Selection

Network is selected per campaign (mainnet, preprod, or preview). No global network setting is required — campaigns are created on a specific network, and the appropriate backend credentials are used automatically.

### Admin / First User

There is no separate admin role. Register an account through the web UI at `/register`. The first registered user owns their own campaigns. In a private deployment you can disable public registration by removing or restricting the `/register` route in `routes/auth.php`.

---

## Using the Platform

### 1. Register and log in

Navigate to your deployment URL and register an account.

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

Share the campaign claim URL (shown on the campaign page) with recipients. They enter their claim code and a Cardano wallet address. The platform validates the code, checks wallet eligibility if one-per-wallet is enabled, and queues the transaction.

### 5. Monitor claims

The campaign detail page shows all claims with their current status:

- **Pending** — queued, transaction not yet submitted
- **Processing** — transaction submitted, awaiting confirmation
- **Completed** — confirmed on-chain with a transaction hash
- **Failed** — exceeded retry limit; manual intervention may be required

Use the **Check Claims** button to manually trigger a status check for pending transactions. The scheduler also checks automatically every five minutes.

### 6. Refund remaining balance

After a campaign ends, use the **Refund** button to return any unclaimed tokens from the campaign bucket wallet to a specified Cardano address.

---

## Transaction Backend

The platform uses a pluggable transaction backend controlled by the `TRANSACTION_BACKEND` environment variable. No code changes are needed to switch between backends.

### `null` — Test Mode (default)

```env
TRANSACTION_BACKEND=null
```

- Generates fake wallet addresses and instantly "completes" all transactions
- A persistent red **TEST MODE** banner is shown across the entire application warning users not to send real tokens
- Perfect for verifying your deployment, testing the UI, and running demos
- **Do NOT send real ADA or tokens** to any wallet addresses shown in test mode

> **Tip:** Start with `null` to confirm everything is working, then switch to a live backend when ready.

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
- **Recommended for production** if you have your own Phyrhose account

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

1. Set `TRANSACTION_BACKEND` to `phyrhose` or `proxy` in your `.env`
2. Fill in the corresponding credentials
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
