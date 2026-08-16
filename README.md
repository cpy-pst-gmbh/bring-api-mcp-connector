# bring-mcp

MCP-Server, der Artikel auf eine Bring!-Einkaufsliste schreibt. Gedacht als
Custom Connector für Claude: Rezeptzutaten landen direkt auf der Liste,
statt sie zu kopieren.

Basiert auf [`bring-api`](https://github.com/miaucl/bring-api) (inoffiziell,
nicht von Bring! Labs AG unterstützt) und [FastMCP](https://gofastmcp.com).

## Tools

| Tool | Zweck |
| --- | --- |
| `add_items` | Artikel hinzufügen, optional mit Mengenangabe |
| `get_shopping_list` | Offene und erledigte Artikel anzeigen |
| `list_shopping_lists` | Verfügbare Listen nennen |
| `complete_item` | Artikel abhaken |

## Warum GitHub OAuth

Die Custom-Connector-UI von Claude bietet nur OAuth-Felder — ein statischer
Bearer-Token lässt sich dort nicht hinterlegen. Statt einen eigenen
Authorization Server zu betreiben, nutzt dieser Server GitHubs OAuth als
Identitätsanbieter. `ALLOWED_GITHUB_USERS` schränkt danach auf deine
eigenen Accounts ein — ohne diese Variable käme **jeder** GitHub-Nutzer rein.

## Setup

### 1. GitHub OAuth App anlegen

Unter <https://github.com/settings/developers> → *New OAuth App*:

- **Homepage URL**: deine `BASE_URL`
- **Authorization callback URL**: `<BASE_URL>/auth/callback`

Client ID und Secret notieren.

### 2. Konfigurieren

```bash
cp .env.example .env
$EDITOR .env
```

Werte in Anführungszeichen lassen — Passwörter mit `&`, `<` oder `;` werden
sonst falsch eingelesen.

### 3. Starten

```bash
docker compose up -d --build
```

Der Server lauscht auf Port 8080. Davor gehört ein Reverse Proxy mit
gültigem Zertifikat, der `BASE_URL` auf diesen Port mappt. Der MCP-Endpunkt
liegt unter `<BASE_URL>/mcp`.

### 4. In Claude eintragen

Einstellungen → Connectors → *Add Custom Connector* → `<BASE_URL>/mcp`.
Danach auf *Connect* klicken und den GitHub-Login bestätigen.

## Sicherheitshinweise

- Der Server muss aus dem Internet erreichbar sein — Anthropic ruft ihn aus
  der eigenen Infrastruktur auf, nicht von deinem Gerät. mTLS am Proxy
  funktioniert deshalb nicht.
- `ALLOWED_GITHUB_USERS` immer setzen.
- Bring-Zugangsdaten liegen als Environment-Variablen im Container. Wenn
  dir das zu offen ist: Docker Secrets nutzen und die Variablen per
  `_FILE`-Konvention einlesen.
- Es gibt keine offizielle Bring-API. Ändert Bring seine Endpunkte, bricht
  der Server. Kein Setup für kritische Abhängigkeiten.

## Lokale Entwicklung

```bash
python -m venv .venv && .venv/bin/pip install -r requirements.txt
set -a && source .env && set +a
.venv/bin/python -m src.server
```

Zum Testen ohne Claude eignet sich der MCP Inspector.
