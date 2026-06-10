#!/usr/bin/env bash
set -e

echo "==> Onboard.Ninja — container startup"

# ---------------------------------------------------------------------------
# 1. Ensure an APP_KEY is present (required for encryption / sessions).
#    In production you should bake a real key into .env or inject it as an
#    environment variable.  This fallback generates one on first boot and
#    writes it into the running container's .env so subsequent processes
#    within the same container lifecycle see it.
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY}" ]; then
    echo "==> No APP_KEY set — generating one (copy it to your .env.docker for persistence)"
    php artisan key:generate --force
else
    echo "==> APP_KEY is set"
fi

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
