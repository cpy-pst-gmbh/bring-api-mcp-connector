# bring-mcp

MCP server that writes items to a Bring! shopping list. Meant as a custom
connector for Claude: recipe ingredients land on the list instead of being
copied by hand.

Built on [`bring-api`](https://github.com/miaucl/bring-api) (unofficial, not
supported by Bring! Labs AG) and [FastMCP](https://gofastmcp.com).

## SaaS

We have a production system that makes it easy to use the connector: https://bring.app.cpy-pst.de

However, the password for Bring must be saved. If you don't want to trust us, you should install it yourself.

## Layout

| Path | Contents |
| --- | --- |
| `mcp/` | Python MCP server (resource server) |
| `app/` | Symfony app — OAuth2 authorization server and web UI |

Each half has its own `Dockerfile`. GitHub Actions builds both on every push to
`main` and pushes them to `ghcr.io/<owner>/<repo>/app` and `.../mcp`.

## How the two halves fit together

Users sign in to the Symfony app with their Bring! credentials — there is no
separate account and no sign-up. Symfony checks the password with Bring! itself,
creates the account on first sign-in, and keeps an encrypted copy of the
password so the connector can act on the user's behalf.

When Claude connects, it runs an authorization code flow with PKCE against
Symfony and receives an access token. The MCP server verifies that token against
Symfony's JWKS and then asks Symfony — over the internal network, with the
user's own token — for that user's Bring! credentials. The credentials are never
part of the token, so they never travel through Anthropic.

If Bring! rejects the password or cannot be reached, the login page offers a
one-time sign-in link by email, which leads to the account page where the stored
password can be corrected.

```
Claude ──► https://bring.example.de/authorize   (sign in, consent)
       ──► https://bring.example.de/token       (code + PKCE → access token)
       ──► https://bring-mcp.example.de/mcp     (Bearer token)
                     │
                     └──► http://symfony/internal/bring-credentials
```

## Tools

| Tool | Purpose |
| --- | --- |
| `add_items` | Add items, optionally with a quantity and a target list |
| `get_shopping_list` | Show open and completed items |
| `list_shopping_lists` | Name the available lists |
| `complete_item` | Tick an item off |

Without an explicit list, tools write to the list chosen for that connector,
falling back to the account's first one.

## Deploying

The server needs two files and nothing else — no checkout, no build step. Both
images are public, so no registry login either.

### 1. Put the stack somewhere

Copy `deploy/docker-compose.yml` and `deploy/.env.example` from this repository
into a directory on the server, for example `/opt/bring-connector`, then:

```bash
cp .env.example .env
```

### 2. Fill in the secrets

```bash
docker run --rm --entrypoint php ghcr.io/cpy-pst-gmbh/bring-api-mcp-connector/app:latest bin/console app:credentials:generate-key
```

`--entrypoint php` skips the startup checks — one of them refuses to run
without the very key this command produces.

`APP_SECRET`, `OAUTH_PASSPHRASE` and `OAUTH_ENCRYPTION_KEY` are any random
strings, `openssl rand -hex 16` each. `BASE_URL` and `MCP_BASE_URL` are the two
public HTTPS addresses.

Set `MAILER_DSN` to a real transport. The sign-in link is the only way back in
when Bring! is unreachable or someone changed their password there, and
`null://null` discards it silently.

Pin `IMAGE_TAG` to a released tag rather than `latest`, so both halves always
move together and a restart cannot pick up a different version.

### 3. Start it

```bash
docker compose pull && docker compose up -d
```

The first start generates the OAuth keypair into a volume and applies the
migrations. Both containers publish to `127.0.0.1` only, so the reverse proxy
is the sole way in:

```bash
curl -s localhost:8000/health.json
```

### 4. Point the proxy at them

`deploy/apache-vhosts.conf.example` holds both vhosts. Two things they have to
get right:

- **`ProxyPass /internal !` on the app vhost.** That endpoint hands decrypted
  Bring! passwords to whoever holds a valid token. The MCP container reaches it
  over the compose network and needs no public route.
- **`flushpackets=on` for `/mcp`**, otherwise the proxy buffers the open
  Streamable HTTP connection and Claude waits for a response that never
  arrives in one piece. `mod_deflate` buffers for the same reason, so `/mcp`
  is excluded from compression as well.
- **`RequestHeader set X-Forwarded-Proto "https"`.** `mod_proxy_http` forwards
  `X-Forwarded-For` and `-Host` but not `-Proto`, and without it Symfony writes
  `http://` into the discovery documents and the sign-in links.

The MCP server takes no notice of forwarded headers at all — its metadata comes
straight from `BASE_URL` and `AUTH_SERVER_URL`, so those have to carry the
public addresses.

Both hostnames need real certificates — Anthropic calls the MCP endpoint from
their own infrastructure and will not accept plain HTTP.

### 5. Check it end to end

Open `BASE_URL`, sign in with a Bring! account, create a connector, and put the
endpoint and client ID into Claude as described below. `/health` should stay
green throughout.

### Updating

```bash
docker compose pull && docker compose up -d
```

Migrations run on start. The database and the keypair live in the `app-data`
and `app-jwt` volumes and survive the replacement — a new keypair would
invalidate every token Claude still holds.

The database file is `bring.db`. An installation that predates that name keeps
its `app.db` sitting in the volume, unused: the app creates an empty `bring.db`
beside it and starts from there. Accounts are recreated on the next sign-in,
because signing in is what creates them — but existing connectors are not, and
have to be added again in Claude. Delete the old file once you are satisfied
nothing is missing.

### Backing up

```bash
docker compose exec app php -r "(new PDO('sqlite:/app/data/bring.db'))->exec(\"VACUUM INTO '/app/data/backup.db'\");"
```

`VACUUM INTO` takes a consistent copy while the app keeps running; copying the
file out from under WAL does not. Save `app-jwt` and `BRING_CREDENTIALS_KEY`
alongside it — without the key the stored passwords are unreadable.

### Adding it to Claude

Sign in at `<BASE_URL>`, create a connector, then in Claude: Settings →
Connectors → *Add Custom Connector* → `<MCP_BASE_URL>/mcp`, and enter the
client ID under Advanced Settings. Claude discovers the authorization server
from the MCP server's metadata, sends the user to Symfony to sign in, and lands
on the consent screen.

Clients created from the console have no owner and stay invisible in the web
UI — useful for an MCP Inspector client:

```bash
docker compose exec app php bin/console league:oauth2-server:create-client --public --redirect-uri="https://claude.ai/api/mcp/auth_callback" --grant-type=authorization_code --grant-type=refresh_token --scope=bring "Claude" claude-connector
```

## Legal texts and the mail signature

`PRIVACY_POLICY_URL` and `IMPRINT_URL` each take one of two things:

| Value | What happens |
| --- | --- |
| `https://example.com/privacy` | the footer links straight there |
| `legal/privacy.md` | the Markdown is rendered and served from `/privacy` |
| empty | no link at all |

Anything starting with `http://` or `https://` is treated as an address
elsewhere; everything else is a path, read relative to the application
directory. The two routes exist either way and answer 404 while the matching
variable names something else, so a typo shows up as a missing page rather than
a missing feature.

The `legal/` directory next to `docker-compose.yml` is mounted read-only into
the container, which is where `legal/privacy.md` resolves. Documents are
GitHub-flavoured Markdown — tables and autolinks included — and are re-read when
the file changes, with no restart and no cache to clear.

`MAIL_SIGNATURE` takes a path in the same way and appends the rendered Markdown
to the end of every outgoing email, separated by a rule. It is attached in the
mailer rather than in a shared email template, so a message added later cannot
go out unsigned by forgetting to extend something.

A file any of the three name but cannot be read fails the `documents` check on
`/health`, naming the variable. Nothing else would say so: the footer link is
simply left out, and the email simply goes unsigned.

`docs/privacy-policy.skeleton.md` inventories what this application actually
stores and who else receives it, as a starting point. It is not legal advice
and not a finished document.

Running from a checkout rather than the container, paths are relative to
`app/`, so the same directory is `../legal/privacy.md`.

## Monitoring

`/health` reports the database (including write access), the MCP server, the
OAuth keypair and the credential encryption key. It comes in two shapes:

| URL | For |
| --- | --- |
| `/health` | a readable page |
| `/health.json` | a monitor |

Both answer **200** when everything passes and **503** when any check fails, so
an uptime check can watch the status code and ignore the body.

Reasons are deliberately terse — the full message goes to the log, because this
endpoint needs no authentication. Set `MCP_HEALTH_URL` to reach the MCP
container directly; otherwise it is derived from `MCP_ENDPOINT`.

## Dormant accounts

An account holds a Bring! password. An abandoned one is a stored secret nobody
is watching, so it does not stay forever:

| Silence | What happens |
| --- | --- |
| 11 months | an email says the account will be deleted in a month |
| 12 months | the account, the stored password and every connector are deleted |

Silence means no sign-in **and** no connector activity — the MCP server fetches
credentials on every call, and each one counts as use. Signing in once resets
the clock and withdraws an outstanding notice.

Both halves are one command, on purpose: nothing is deleted that was not warned
a month earlier, and running the warning without the deletion — or the other way
round — would break that promise.

The `cron` service in the compose file runs it. It is the same image as the
app with the entrypoint replaced by a loop that wakes once a day, prunes
accounts and clears expired OAuth tokens. Nothing to add to the host crontab,
and no `docker` permissions to hand to a cron user. `MAINTENANCE_HOUR` moves
the slot, whole hours UTC, default 4.

```bash
docker compose logs cron
```

The log names the time of the next run. To see what a run would do without
doing it:

```bash
docker compose exec cron php bin/console app:accounts:prune-inactive --dry-run
```

The cron container deliberately does not migrate — the app container does that
on start, and two processes migrating one SQLite file at once is a race.

A notice is only recorded as sent once the mail transport accepted it. With a
broken `MAILER_DSN` the account comes up again on the next run rather than
being deleted over a warning nobody received, so no account is ever removed
without notice — including on an instance where this command had never run.

## Security notes

- The MCP server must be reachable from the internet — Anthropic calls it from
  their own infrastructure, not from your device. mTLS at the proxy therefore
  does not work.
- `/internal/bring-credentials` belongs on the Docker network only.
- Bring! passwords are stored encrypted with libsodium `secretbox`. They have
  to be recoverable in plaintext because Bring! has no token login, so the key
  is as sensitive as the database. There is no local password to leak: the only
  credential this app knows is the Bring! one. Keep it in the secrets vault, not next to
  the SQLite file, and back both up.
- There is no official Bring! API. If Bring changes its endpoints, the server
  breaks. Not a setup for critical dependencies.

## Local development

### MCP server

```bash
python -m venv .venv && .venv/bin/pip install -r mcp/requirements.txt
```

```bash
set -a && source .env && set +a && .venv/bin/python mcp/server.py
```

The MCP Inspector is a good way to test without Claude.

### Symfony app

The committed `app/.env` holds no secrets — every slot for one is empty, and
the environment fills them in. For a checkout that means writing them into
`app/.env.local`, which is ignored by git and left out of the image:

```bash
printf 'APP_SECRET=%s\nOAUTH_PASSPHRASE=%s\nOAUTH_ENCRYPTION_KEY=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" >> app/.env.local
```

Install dependencies and create the schema:

```bash
composer -d app install
```

```bash
php app/bin/console doctrine:migrations:migrate
```

Set `MAILER_DSN` to a real transport, otherwise the sign-in link cannot be
delivered. Then generate the key that encrypts stored Bring! passwords and put
it in `app/.env.local` as `BRING_CREDENTIALS_KEY`:

```bash
php app/bin/console app:credentials:generate-key
```

Generate the OAuth signing keypair:

```bash
php app/bin/console league:oauth2-server:generate-keypair
```

Then run the app and the Tailwind watcher side by side:

```bash
symfony server:start -d --dir=app
```

```bash
php app/bin/console tailwind:build --watch
```

The root is the login — there is no landing page. Sign in with your Bring!
credentials and the first sign-in creates the account. Login and setup are one
flow: the same page walks through signing in, adding a connector and connecting
Claude, with settings below it. Someone who is already set up lands on the last
step.

### Keeping the Bring! constants in sync

Symfony talks to Bring! directly — it has to, since signing in happens before
any MCP token exists — so the base URL and request headers live both in
`bring_api.const` and in `app/.env`. Bumping `bring-api` can move them apart:

```bash
.venv/bin/python tools/check-bring-constants.py
```

```bash
.venv/bin/python tools/check-bring-constants.py --fix
```

A GitHub Actions workflow runs the check whenever `mcp/requirements.txt`
changes. It only guards the constants — a changed endpoint path or response
shape still surfaces as a failing login.

### End-to-end tests

Playwright drives the running containers — the same images that get deployed,
not a dev server:

```bash
docker compose up -d --build
```

```bash
cd tests && npm ci && npx playwright install chromium && npx playwright test
```

Everything past the login form needs a real Bring! account, because an account
here only exists once Bring! has confirmed a password. Those tests skip
themselves unless you provide one:

```bash
BRING_TEST_EMAIL=you@example.com BRING_TEST_PASSWORD=… npx playwright test
```

One test deliberately exhausts the login rate limit. A fresh container has room
for it; after repeated local runs the per-IP budget can run dry and block valid
sign-ins for fifteen minutes:

```bash
docker compose exec app php bin/console cache:pool:clear cache.rate_limiter
```

A GitHub Actions workflow runs the suite on every pull request. Set the same
two values as repository secrets to have the authenticated half run there too.

### Exercising the OAuth and MCP chain

`tools/dev-token.sh` walks the whole authorization code + PKCE flow against a
running Symfony app and prints the access token, so the MCP server can be
tested without Claude:

```bash
tools/dev-token.sh http://127.0.0.1:8000 you@bring-account.example your-bring-password
```

Signing in goes through Bring!, so these have to be real credentials. Feed the
token to the MCP server as a bearer token, for example:

```bash
curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/internal/bring-credentials
```
