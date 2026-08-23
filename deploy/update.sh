#!/bin/bash
#
# Unattended update for the production stack.
#
#   ./update.sh              pull, and deploy only if something actually moved
#   ./update.sh --force      deploy even when the images are unchanged
#   ./update.sh --on|--off   raise or lower the maintenance page by hand
#
# The order is the whole point of the script:
#
#   maintenance page up -> back up the database -> replace the containers ->
#   wait for /health -> maintenance page down
#
# The app container migrates on start, so there is a window in which the schema
# and the running code disagree. Serving a 503 through it is better than
# serving half a request, and a failed deploy leaves the page up rather than
# taking it down over a stack that never came back.
set -euo pipefail

# Where docker-compose.yml and .env live. Defaults to this script's directory,
# which is where the deployment instructions put all three.
STACK_DIR="${STACK_DIR:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)}"

# Apache serves the page from here and watches for the flag beside it. Both
# paths have to match the vhost; see apache-vhosts.conf.example.
MAINTENANCE_DIR="${MAINTENANCE_DIR:-/var/www/maintenance}"
FLAG="$MAINTENANCE_DIR/maintenance.flag"

# Checked directly rather than through the proxy, which is answering 503 to
# everything at that moment. Covers the MCP container too: the app's health
# page calls it.
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8000/health.json}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"

LOCK="${LOCK:-/var/lock/bring-update.lock}"

log() { printf '%s  %s\n' "$(date -u '+%Y-%m-%d %H:%M:%SZ')" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

maintenance_on() {
    mkdir -p "$MAINTENANCE_DIR"
    touch "$FLAG"
    log "Maintenance page is up."
}

maintenance_off() {
    rm -f "$FLAG"
    log "Maintenance page is down."
}

case "${1:-}" in
    --on)  maintenance_on;  exit 0 ;;
    --off) maintenance_off; exit 0 ;;
    --force) FORCE=1 ;;
    '') FORCE=0 ;;
    *) die "Unknown argument: $1" ;;
esac

# Two timer runs must not overlap. A pull that takes longer than the interval
# would otherwise meet its own successor halfway through a container swap.
exec 9>"$LOCK"
flock -n 9 || { log "Another run holds the lock; nothing to do."; exit 0; }

cd "$STACK_DIR"

compose() { docker compose "$@"; }

# The digest of every image the stack refers to, or "missing" for one that has
# never been pulled. Compared against itself after the pull to decide whether
# there is anything to deploy at all.
digests() {
    local image
    while read -r image; do
        [ -n "$image" ] || continue
        printf '%s %s\n' "$image" \
            "$(docker image inspect --format '{{.Id}}' "$image" 2>/dev/null || echo missing)"
    done < <(compose config --images | sort -u)
}

before="$(digests)"

log "Pulling images..."
compose pull --quiet

after="$(digests)"

if [ "$before" = "$after" ] && [ "$FORCE" -eq 0 ]; then
    log "Images are unchanged. Nothing to deploy."
    exit 0
fi

# Both halves have to come from the same commit. With IMAGE_TAG=latest the two
# matrix jobs push independently, so a run that lands between them would take
# the new app together with the old MCP server. The build stamps the commit
# into every image; disagreement means the other job is still going, and the
# next timer tick will find them matched.
# `|| revisions=0` because of pipefail: an image built before the label existed
# leaves grep with nothing to match, and a failing pipeline in an assignment
# would take the script down over a check that is meant to be advisory.
revisions="$(
    compose config --images | sort -u | while read -r image; do
        docker image inspect \
            --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' \
            "$image" 2>/dev/null || true
    done | grep -v '^$' | sort -u | wc -l
)" || revisions=0

if [ "$revisions" -gt 1 ]; then
    log "Images come from different commits; the build is probably still running. Retrying next run."
    exit 0
fi

if [ "$before" = "$after" ]; then
    log "Images are unchanged; deploying anyway because --force was given."
else
    log "New images found."
fi

log "Starting the maintenance window."
maintenance_on

# In-flight requests get a moment to finish before their backend disappears.
sleep 3

# Before the migrations, not after: a migration is the one step in an update
# that can lose data, and the copy is worthless once it has run. The cron
# container owns the backup volume, which is why the command runs in there.
log "Backing up the database..."
if ! compose exec -T cron php bin/console app:database:backup --no-interaction; then
    # Nothing has been replaced yet, so this is a clean abort: put the site
    # back and let somebody look at it.
    maintenance_off
    die "Backup failed. Nothing was deployed."
fi

log "Replacing the containers..."
compose up -d --remove-orphans

log "Waiting for the stack to come up..."
deadline=$(( $(date +%s) + HEALTH_TIMEOUT ))
until curl -fsS -o /dev/null --max-time 10 "$HEALTH_URL"; do
    if [ "$(date +%s)" -ge "$deadline" ]; then
        # The page stays up on purpose. Rolling back is not an option once the
        # migrations have run — they only go forwards — so the honest state is
        # "in maintenance until a human has looked", not "open and broken".
        log "Stack did not come up within ${HEALTH_TIMEOUT}s. Leaving the maintenance page in place." >&2
        log "  docker compose logs --tail=100" >&2
        log "  ./update.sh --off   once it is healthy again" >&2
        exit 1
    fi
    sleep 3
done

maintenance_off
log "Deployed."

# The images just replaced lost their tag to the pull and would otherwise sit
# there for good. Rolling back does not depend on them: every build is also
# pushed under a sha-<commit> tag, so an older version is an IMAGE_TAG in .env
# and another pull away.
docker image prune -f >/dev/null
