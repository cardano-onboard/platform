#!/usr/bin/env bash
set -e

echo "==> Onboard.Ninja — container startup"

# ---------------------------------------------------------------------------
# 1. Require an APP_KEY (used for encryption, sessions and signed URLs).
#
#    This does NOT fall back to `key:generate`. When APP_KEY is exported into
#    the container as an empty variable — which is what `APP_KEY=` in .env
#    does via env_file — Laravel's dotenv loader will not overwrite it, so a
#    key written to the .env file is ignored and `config:cache` below would
#    bake the empty value in. The app then 500s on every request with
#    "No application encryption key has been specified."
#
#    Generating a throwaway key per boot would be worse: it changes on every
#    restart, silently invalidating sessions and making anything previously
#    encrypted undecryptable. So we fail fast with instructions instead.
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    echo ""
    echo "ERROR: APP_KEY is not set."
    echo ""
    echo "  Onboard.Ninja cannot start without an application encryption key."
    echo "  The quickstart script generates one for you, along with database"
    echo "  passwords and an admin login:"
    echo ""
    echo "      ./quickstart.sh"
    echo ""
    echo "  Or set it by hand in your .env:"
    echo ""
    echo "      APP_KEY=base64:\$(openssl rand -base64 32)"
    echo ""
    echo "  Keep this value stable. Changing it invalidates existing sessions"
    echo "  and makes previously encrypted data unreadable."
    echo ""
    exit 1
fi
echo "==> APP_KEY is set"

# ---------------------------------------------------------------------------
# 2. Wait for MySQL to be ready before running migrations.
# ---------------------------------------------------------------------------
echo "==> Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306} ..."
for i in $(seq 1 30); do
    if php -r "
        \$c = @new PDO(
            'mysql:host=${DB_HOST};port=${DB_PORT:-3306};dbname=${DB_DATABASE}',
            '${DB_USERNAME}', '${DB_PASSWORD}'
        );
        echo 'ok';
    " 2>/dev/null | grep -q ok; then
        echo "==> MySQL is ready"
        break
    fi
    echo "    ... attempt ${i}/30 — sleeping 3s"
    sleep 3
done

# ---------------------------------------------------------------------------
# 3. Run migrations (idempotent — safe to run on every startup).
# ---------------------------------------------------------------------------
echo "==> Running migrations ..."
php artisan migrate --force

# ---------------------------------------------------------------------------
# 4. Run the AdminSeeder to ensure the admin account exists.
#    DatabaseSeeder is kept empty in the repo; only AdminSeeder is called
#    here so we don't create dummy data on every boot.
# ---------------------------------------------------------------------------
echo "==> Seeding admin account ..."
php artisan db:seed --class=AdminSeeder --force

# ---------------------------------------------------------------------------
# 5. Cache configuration and routes for production performance.
# ---------------------------------------------------------------------------
echo "==> Caching config / routes / views ..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ---------------------------------------------------------------------------
# 6. Ensure storage symlink exists.
# ---------------------------------------------------------------------------
php artisan storage:link --force 2>/dev/null || true

# ---------------------------------------------------------------------------
# 7. Hand off to supervisord (manages php-fpm, nginx, queue workers,
#    and the scheduler loop).
# ---------------------------------------------------------------------------
echo "==> Starting supervisord ..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
