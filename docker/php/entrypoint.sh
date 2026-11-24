#!/bin/sh
# Container entrypoint for the PHP image.
#
# It runs before the main command (php-fpm, queue worker, ...) and prepares
# whatever must exist at runtime. Keep it idempotent: it runs on every start.
set -e

cd /var/www/backend

# CONTAINER_MODE is set by the Dockerfile stage (dev or prod), so the image
# itself knows how it was built. Laravel's own APP_ENV lives in backend/.env.
if [ "${CONTAINER_MODE:-prod}" = "dev" ]; then
    # Development: install dependencies on first start, so a fresh clone works
    # with a single `docker compose up`.
    if [ ! -f vendor/autoload.php ]; then
        echo "[entrypoint] vendor/ missing, running composer install..."
        composer install --no-interaction --prefer-dist
    fi
else
    # Production: configuration comes from environment variables, which are only
    # known at runtime, so the config/route caches are built here, not in the image.
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

# Database migrations are run by exactly one service (see RUN_MIGRATIONS in
# compose.yaml). Running them from every replica at once would race.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
