# FrankenPHP serves the app directly, so one container is the whole web tier —
# no separate FPM pool and web server to keep in step.
FROM dunglas/frankenphp:1-php8.4-alpine AS base

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:8000

WORKDIR /app

# sodium encrypts the stored Bring! passwords, pdo_sqlite is the database,
# intl and opcache are what Symfony wants in production. install-php-extensions
# pulls its own build dependencies and removes them again, so nothing beyond
# the extensions themselves stays behind.
RUN install-php-extensions sodium pdo_sqlite intl opcache


FROM base AS build

# Composer and the tools it needs live in this stage only — the runtime image
# never installs a dependency.
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MAX_PARALLEL_HTTP=6

RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies first: this layer only rebuilds when the lock file moves.
#
# Composer falls back to codeload.github.com for some packages, and anonymous
# downloads there are rate limited — a cold build fetching everything at once
# can hit HTTP 429. COMPOSER_MAX_PARALLEL_HTTP keeps it under the limit; pass a
# token to lift it entirely:
#   docker build --secret id=composer_auth,src=auth.json
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=secret,id=composer_auth,target=/tmp/auth.json,required=false \
    if [ -f /tmp/auth.json ]; then cp /tmp/auth.json "$COMPOSER_HOME/auth.json"; fi \
    && composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress \
    && rm -f "$COMPOSER_HOME/auth.json"

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    # importmap:install pulls the JavaScript vendor files from a CDN, so it is
    # the one build step that can fail on a network hiccup rather than on
    # anything being wrong. Two retries turn that into a delay.
    && for attempt in 1 2 3; do \
           composer run-script --no-dev post-install-cmd && break; \
           echo "post-install-cmd failed (attempt $attempt), retrying..."; \
           sleep 5; \
       done \
    && test -d assets/vendor \
    # Downloads the Tailwind binary, then writes the hashed assets that
    # AssetMapper serves in prod — dev resolves them on the fly, prod does not.
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    # The binary is ~100 MB and has done its job; var/ is a volume at runtime
    # anyway, so nothing built into it would survive.
    && rm -rf var/cache var/log var/tailwind


FROM base AS runtime

# Caddy keeps its state here, and a non-root process needs it writable.
ENV XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

COPY --from=build --chown=www-data:www-data /app /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
# Same image, second role: the cron service overrides the entrypoint with this.
COPY docker/maintenance.sh /usr/local/bin/maintenance

RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/maintenance \
    && mkdir -p var data backups config/jwt /data/caddy /config/caddy \
    && chown -R www-data:www-data var data backups config/jwt /data /config

# Only state, and only state: the database and the OAuth keypair. var/ stays
# inside the container on purpose — it holds the compiled cache, and a volume
# would carry the previous image's compiled templates into the new one.
#
# backups/ is not listed: only the cron role writes there, and the compose file
# mounts a volume for it. What the directory above is for is its ownership — a
# fresh named volume inherits it from the image, and without that the mount
# would belong to root and www-data could not write a single copy.
VOLUME ["/app/data", "/app/config/jwt"]

# Not root — and not merely as good practice. docker-compose drops all
# capabilities, which takes CAP_DAC_OVERRIDE with them, so even uid 0 obeys the
# permission bits and could not write to the volumes it does not own.
#
# The frankenphp binary carries cap_net_bind_service, and the kernel refuses to
# exec a file with capabilities that are not in the bounding set — hence the
# cap_add in docker-compose.yml. Stripping it here instead would rewrite the
# 58 MB binary into a new layer.
USER www-data

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
