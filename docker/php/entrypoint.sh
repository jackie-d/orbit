#!/bin/sh
# Orbit container entrypoint.
#
# The same image runs every role; only the command differs:
#   php-fpm                         web (default)
#   php artisan queue:work ...      queue worker
#   php artisan schedule:work       scheduler
#   php artisan migrate --force     migrations (Helm hook Job)
set -eu

# `docker run orbit-app -F` => php-fpm -F
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

cd /var/www/html

# storage/ and bootstrap/cache may be emptyDir / volume mounts: recreate the layout.
mkdir -p \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

# Environment only exists at runtime, so config/route/event caches are built here.
# (No view cache: Orbit is API-only and ships no Blade views.)
if [ "${LARAVEL_OPTIMIZE:-true}" = "true" ]; then
  for cache in config:cache route:cache event:cache; do
    php artisan "$cache" --no-ansi -q || {
      echo "orbit-entrypoint: 'artisan $cache' failed" >&2
      exit 1
    }
  done
fi

exec "$@"
