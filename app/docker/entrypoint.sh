#!/bin/sh
#
# Brings a fresh container to a usable state before handing over to the server.
#
# Everything here is idempotent: the volumes survive container replacement, so
# each step has to cope with finding its work already done.
set -eu

cd /app

if [ -z "${BRING_CREDENTIALS_KEY:-}" ]; then
    echo "BRING_CREDENTIALS_KEY is not set. Generate one with:" >&2
    echo "  docker compose run --rm --entrypoint php app bin/console app:credentials:generate-key" >&2
    # --entrypoint php, or this very check refuses the command that fixes it.
    exit 1
fi

# Signs the login links and keys the login rate limiter. Empty is accepted all
# the way to the first request that needs it, which then fails with "A
# non-empty secret is required" and no hint as to why — so it is checked here.
if [ -z "${APP_SECRET:-}" ]; then
    echo "APP_SECRET is not set. Any random string will do:" >&2
    echo "  openssl rand -hex 16" >&2
    exit 1
fi

# The keypair signs access tokens. Generating it here means a first start needs
# no manual step, and the volume keeps it stable afterwards — a new keypair
# would invalidate every token Claude still holds.
if [ ! -f config/jwt/private.pem ]; then
    echo "Generating the OAuth keypair..."
    php bin/console league:oauth2-server:generate-keypair --quiet
    chmod 600 config/jwt/private.pem
    chmod 644 config/jwt/public.pem
fi

# Small single-instance service: running migrations on start is simpler than a
# separate deploy step, and doctrine skips the ones already applied.
echo "Applying migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

php bin/console cache:warmup

exec "$@"
