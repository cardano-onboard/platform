# Getting Started (Self-hosted)

The open-source edition runs anywhere Docker does. This guide gets you from a clone to a
running instance.

## Prerequisites

- **Docker** and the Docker Compose plugin
- A wrapping key, two recovery key hashes from wallets you hold, and a stake address for the
  network you will use (step 2 below). The instance submits its own transactions by default.
  Phyrhose and the proxy are alternatives (see [Configuration](./configuration)), and the `null`
  backend is a dry run (it sends nothing and reports success, so never leave it set for a real campaign)

## 1. Clone and configure

```bash
git clone https://github.com/cardano-onboard/platform.git onboard-ninja
cd onboard-ninja
./quickstart.sh
```

`quickstart.sh` writes your `.env` and generates the values that must be unique to your
install — the Laravel `APP_KEY`, the MySQL passwords, and the admin login. It prompts for
the admin email and password; press enter at the password prompt to have one generated.
Use `./quickstart.sh --yes` for an unattended install.

::: warning APP_KEY is mandatory
The container will refuse to start without `APP_KEY`, and it must stay stable across
restarts — it encrypts sessions and stored data, so changing it later logs everyone out
and makes previously encrypted values unreadable.
:::

To configure by hand instead, `cp .env.docker .env` and fill in the values marked
`[REQUIRED]`. Each key is documented inline in `.env.docker`. See
[Configuration](./configuration) for the full reference.

If port 8080 is already in use, pass `--port` to the quickstart script — it sets
`APP_PORT` and keeps `APP_URL` in sync:

```bash
./quickstart.sh --port 8082
```

For the database and Redis host ports, set `DB_HOST_PORT` / `REDIS_HOST_PORT` in
`.env` rather than editing `docker-compose.yml`.

## 2. Set up campaign wallets

`.env.docker` ships with `TRANSACTION_BACKEND=native`: this instance builds, signs and submits its
own transactions, and each campaign holds its own key. Three things are supplied in `.env` once,
before the first campaign is created. Without them campaign creation is refused, and the message
names each variable that is missing.

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

Restart the stack after editing `.env`. To use Phyrhose or the proxy instead, set
`TRANSACTION_BACKEND` to `phyrhose` or `proxy` and fill in the credentials described in
[Configuration](./configuration).

## 3. Bring up the stack

```bash
docker compose up -d
```

This starts the app (nginx + PHP-FPM + queue worker + scheduler), MySQL, and Redis.
Wait for the healthchecks to report healthy.

## 4. Migrate and seed

```bash
docker compose exec app php artisan migrate --seed
```

The container creates your admin user from `.env` each time it starts. This command
creates the schema if it is not there yet and loads the
**known-asset registry** (common mainnet tokens like HOSKY and USDM) so you can add
reward tokens by ticker.

## 5. Sign in

Open `http://localhost:8080` and log in with the admin credentials from your `.env`.
You're live — continue to [Campaigns & funding](./campaigns).

## Production notes

- Terminate TLS at a reverse proxy in front of the container.
- The container already runs the **queue worker** and **scheduler** under supervisord —
  these process claim delivery and the periodic claim-status checks. Keep the container
  running; don't disable them.
- The scheduler also runs the daily QR export cleanup and the six-hourly token registry
  refresh. A deployment without a scheduler gets these from traffic instead; see
  [Scheduled maintenance](./configuration#scheduled-maintenance).
- Review [Configuration](./configuration) for backend selection, rate limits, and the
  security headers in `config/security.php`.
