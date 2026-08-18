# bring-mcp

MCP server that writes items to a Bring! shopping list. Meant as a custom
connector for Claude: recipe ingredients land on the list instead of being
copied by hand.

Built on [`bring-api`](https://github.com/miaucl/bring-api) (unofficial, not
supported by Bring! Labs AG) and [FastMCP](https://gofastmcp.com).

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

## Setup

### 1. Configure

```bash
cp .env.example .env
```

Generate the secrets it asks for:

```bash
docker compose run --rm app php bin/console app:credentials:generate-key
```

`APP_SECRET`, `OAUTH_PASSPHRASE` and `OAUTH_ENCRYPTION_KEY` are any random
strings — `openssl rand -hex 16` does. The OAuth signing keypair is generated on
first start into a volume; nothing to do by hand.

### 2. Run both containers

```bash
docker compose up -d --build
```

The Symfony app listens on 8000, the MCP server on 8080. Both need a reverse
proxy with a valid certificate in front of them, mapping `BASE_URL` and
`MCP_BASE_URL` to those ports.

Two things the proxy has to get right:

- **Block `/internal` on the app's vhost.** It hands out decrypted Bring!
  passwords to whoever holds a valid token. The MCP container reaches it over
  the compose network and does not need it published.
- **`flushpackets=on` for `/mcp`**, otherwise the proxy buffers the open
  Streamable HTTP connection.

```apache
# On the app vhost
ProxyPass /internal !
ProxyPass / http://127.0.0.1:8000/
ProxyPassReverse / http://127.0.0.1:8000/
```

State lives in two named volumes: `app-data` (the SQLite database) and
`app-jwt` (the OAuth keypair). Both must survive container replacement — a new
keypair invalidates every token Claude still holds.

### 3. Add it to Claude

Sign in at `<BASE_URL>`, create a connector on `/account`, then in Claude:
Settings → Connectors → *Add Custom Connector* → `<MCP_BASE_URL>/mcp`, and
enter the client ID under Advanced Settings. Claude discovers the authorization
server from the MCP server's metadata, sends the user to Symfony to sign in,
and lands on the consent screen.

Clients created from the console have no owner and stay invisible in the web
UI — useful for an MCP Inspector client:

```bash
docker compose exec app php bin/console league:oauth2-server:create-client --public --redirect-uri="https://claude.ai/api/mcp/auth_callback" --grant-type=authorization_code --grant-type=refresh_token --scope=bring "Claude" claude-connector
```

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
