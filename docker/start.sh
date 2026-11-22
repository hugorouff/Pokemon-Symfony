#!/bin/sh
set -eu

if [ "${1:-}" = 'apache2-foreground' ] && [ -n "${DATABASE_URL:-}" ]; then
    php bin/console app:neon:setup --schema-only --no-interaction
fi

exec docker-php-entrypoint "$@"
