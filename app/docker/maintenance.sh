#!/bin/sh
#
# Periodic maintenance, as its own container against the same volumes.
#
# Not crond: busybox's needs to run as root to drop privileges, and this
# container deliberately does not. A loop that sleeps to the next slot is the
# whole of what a daily schedule needs, and its next run is visible in the log.
#
# Deliberately does *not* migrate. The app container does that on start, and
# two processes migrating one SQLite file at the same moment is a race with a
# corrupted schema at the end of it.
set -eu

cd /app

# Time of day to run, in whole hours UTC. 04:00 is quiet and well clear of the
# app container's own restart window.
RUN_AT_HOUR="${MAINTENANCE_HOUR:-4}"
DAY=86400

if [ -z "${BRING_CREDENTIALS_KEY:-}" ] || [ -z "${APP_SECRET:-}" ]; then
    echo "BRING_CREDENTIALS_KEY and APP_SECRET must be set, same as for the app container." >&2
    exit 1
fi

run() {
    # None of these may take the loop down with it. A locked database or an
    # unreachable mail server is a reason to try again tomorrow, not to stop
    # maintaining the installation until somebody notices.

    # First, so today's copy still holds the accounts the prune is about to
    # delete — a wrong deadline is recoverable for as long as the backups go
    # back further than the mistake.
    php bin/console app:database:backup --no-interaction \
        || echo "Database backup failed; will retry at the next run." >&2

    php bin/console app:accounts:prune-inactive --no-interaction \
        || echo "Pruning inactive accounts failed; will retry at the next run." >&2

    php bin/console league:oauth2-server:clear-expired-tokens --no-interaction \
        || echo "Clearing expired tokens failed; will retry at the next run." >&2
}

while true; do
    now="$(date -u +%s)"

    # Midnight UTC of the current day, plus the hour — and if that moment has
    # already passed today, the same one tomorrow.
    next=$(( now - now % DAY + RUN_AT_HOUR * 3600 ))
    [ "$next" -le "$now" ] && next=$(( next + DAY ))

    echo "Next maintenance run at $(date -u -d "@$next" 2>/dev/null || date -u -r "$next")."
    sleep $(( next - now ))

    echo "Running maintenance."
    run
done
