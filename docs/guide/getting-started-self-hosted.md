# Getting Started (Self-hosted)

The open-source edition runs anywhere Docker does. This guide gets you from a clone to a
running instance.

## Prerequisites

- **Docker** and the Docker Compose plugin
- A Cardano transaction backend (see [Configuration](./configuration)) — or start with
  the `null` backend for a dry run

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

## 2. Bring up the stack

```bash
docker compose up -d
```

This starts the app (nginx + PHP-FPM + queue worker + scheduler), MySQL, and Redis.
Wait for the healthchecks to report healthy.

## 3. Migrate and seed

```bash
docker compose exec app php artisan migrate --seed
```

This creates the schema, seeds your admin user (from `.env`), and loads the
**known-asset registry** (common mainnet tokens like HOSKY and USDM) so you can add
reward tokens by ticker.

## 4. Sign in

Open `http://localhost:8080` and log in with the admin credentials from your `.env`.
You're live — continue to [Campaigns & funding](./campaigns).

## Production notes

- Terminate TLS at a reverse proxy in front of the container.
- The container already runs the **queue worker** and **scheduler** under supervisord —
  these process claim delivery and the periodic claim-status checks. Keep the container
  running; don't disable them.
- Review [Configuration](./configuration) for backend selection, rate limits, and the
  security headers in `config/security.php`.
