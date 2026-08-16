"""MCP-Server, der Einträge auf eine Bring!-Einkaufsliste schreibt.

Auth: GitHub OAuth (FastMCP GitHubProvider). Optional auf einzelne
GitHub-Logins eingeschränkt, damit nicht jeder GitHub-Nutzer der Welt
in die Einkaufsliste schreiben kann.
"""

from __future__ import annotations

import logging
import os
from contextlib import asynccontextmanager
from dataclasses import dataclass

import aiohttp
from bring_api import Bring, BringAuthException, BringRequestException
from fastmcp import FastMCP
from fastmcp.server.auth.providers.github import GitHubProvider
from fastmcp.server.dependencies import get_access_token
from fastmcp.exceptions import ToolError

logging.basicConfig(
    level=os.getenv("LOG_LEVEL", "INFO"),
    format="[%(asctime)s] [%(name)s] [%(levelname)s] %(message)s",
)
logger = logging.getLogger("bring-mcp")


def _env(name: str, default: str | None = None, required: bool = False) -> str:
    value = os.getenv(name, default or "")
    if required and not value:
        raise RuntimeError(f"Umgebungsvariable {name} ist nicht gesetzt")
    return value


BRING_USERNAME = _env("BRING_USERNAME", required=True)
BRING_PASSWORD = _env("BRING_PASSWORD", required=True)
DEFAULT_LIST = _env("BRING_LIST_NAME")

# Kommaseparierte GitHub-Logins, die den Server benutzen dürfen.
# Leer = jeder authentifizierte GitHub-Account. Setz das!
ALLOWED_USERS = {
    u.strip().lower() for u in _env("ALLOWED_GITHUB_USERS").split(",") if u.strip()
}


@dataclass
class BringSession:
    """Hält aiohttp-Session und eingeloggte Bring-Instanz."""

    session: aiohttp.ClientSession
    bring: Bring


@asynccontextmanager
async def lifespan(_: FastMCP):
    session = aiohttp.ClientSession()
    bring = Bring(session, BRING_USERNAME, BRING_PASSWORD)
    try:
        await bring.login()
        logger.info("Login bei Bring erfolgreich")
        yield BringSession(session=session, bring=bring)
    finally:
        await session.close()


auth = GitHubProvider(
    client_id=_env("GITHUB_CLIENT_ID", required=True),
    client_secret=_env("GITHUB_CLIENT_SECRET", required=True),
    base_url=_env("BASE_URL", required=True),
)

mcp = FastMCP(
    "Bring Einkaufsliste",
    auth=auth,
    lifespan=lifespan,
    instructions=(
        "Schreibt Einträge auf die Bring!-Einkaufsliste des Nutzers. "
        "Nutze add_items, um mehrere Zutaten in einem Rutsch hinzuzufügen."
    ),
)


def _check_user() -> None:
    """Wirft, wenn der eingeloggte GitHub-Account nicht freigegeben ist."""
    if not ALLOWED_USERS:
        return
    token = get_access_token()
    login = str((token.claims if token else {}).get("login", "")).lower()
    if login not in ALLOWED_USERS:
        logger.warning("Zugriff abgelehnt für GitHub-Login %r", login)
        raise ToolError("Dieser Account ist für diesen Server nicht freigegeben.")


async def _bring() -> Bring:
    from fastmcp.server.dependencies import get_context

    state: BringSession = get_context().request_context.lifespan_context
    return state.bring


async def _resolve_list(name: str | None) -> tuple[str, str]:
    """Liefert (uuid, name) der Zielliste."""
    bring = await _bring()
    wanted = (name or DEFAULT_LIST).strip()
    try:
        lists = (await bring.load_lists())["lists"]
    except (BringAuthException, BringRequestException) as exc:
        raise ToolError(f"Bring nicht erreichbar: {exc}") from exc

    if not wanted:
        return lists[0]["listUuid"], lists[0]["name"]

    for entry in lists:
        if entry["name"].strip().lower() == wanted.lower():
            return entry["listUuid"], entry["name"]

    verfuegbar = ", ".join(e["name"] for e in lists)
    raise ToolError(f"Liste {wanted!r} nicht gefunden. Vorhanden: {verfuegbar}")


@mcp.tool
async def list_shopping_lists() -> list[str]:
    """Nennt alle verfügbaren Bring-Einkaufslisten."""
    _check_user()
    bring = await _bring()
    lists = (await bring.load_lists())["lists"]
    return [entry["name"] for entry in lists]


@mcp.tool
async def add_items(
    items: list[str],
    list_name: str | None = None,
    specifications: list[str] | None = None,
) -> str:
    """Fügt Artikel zur Einkaufsliste hinzu.

    Args:
        items: Artikelnamen, z.B. ["Milch", "Karotten"].
        list_name: Zielliste. Leer = BRING_LIST_NAME bzw. erste Liste.
        specifications: Optionale Zusätze pro Artikel, gleiche Reihenfolge
            wie items, z.B. ["1 Liter", "500 g"]. Kürzere Listen werden
            mit leeren Zusätzen aufgefüllt.
    """
    _check_user()
    if not items:
        raise ToolError("Keine Artikel übergeben.")

    specs = list(specifications or [])
    specs += [""] * (len(items) - len(specs))

    uuid, name = await _resolve_list(list_name)
    bring = await _bring()

    for item, spec in zip(items, specs):
        await bring.save_item(uuid, item, spec)
        logger.info("Hinzugefügt: %s (%s) -> %s", item, spec or "-", name)

    return f"{len(items)} Artikel zu {name!r} hinzugefügt."


@mcp.tool
async def get_shopping_list(list_name: str | None = None) -> dict:
    """Zeigt offene und bereits erledigte Artikel einer Liste."""
    _check_user()
    uuid, name = await _resolve_list(list_name)
    bring = await _bring()
    data = await bring.get_list(uuid)
    items = data["items"]
    return {
        "liste": name,
        "offen": [i["itemId"] for i in items["purchase"]],
        "erledigt": [i["itemId"] for i in items["recently"]],
    }


@mcp.tool
async def complete_item(item: str, list_name: str | None = None) -> str:
    """Hakt einen Artikel auf der Liste ab."""
    _check_user()
    uuid, name = await _resolve_list(list_name)
    bring = await _bring()
    await bring.complete_item(uuid, item)
    return f"{item!r} auf {name!r} abgehakt."


if __name__ == "__main__":
    mcp.run(
        transport="http",
        host=_env("HTTP_HOST", "0.0.0.0"),
        port=int(_env("HTTP_PORT", "8080")),
    )
