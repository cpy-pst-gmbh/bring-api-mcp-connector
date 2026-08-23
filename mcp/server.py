"""MCP server that writes entries to a Bring! shopping list.

Multi-user: every request carries an access token issued by the Symfony
authorization server. The token's `sub` identifies the user, and the Bring!
credentials for that user are fetched from Symfony over the internal network —
they are never part of the token, so they never pass through Anthropic.

Logged-in Bring sessions are cached per subject for CREDENTIALS_CACHE_TTL
seconds, so a credential change in the web UI takes effect without a restart.
"""

from __future__ import annotations

import asyncio
import logging
import os
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass, field

import aiohttp
from bring_api import Bring, BringAuthException, BringRequestException
from fastmcp import FastMCP
from fastmcp.exceptions import ToolError
from fastmcp.server.auth import RemoteAuthProvider
from fastmcp.server.auth.providers.jwt import JWTVerifier
from fastmcp.server.dependencies import get_access_token, get_context
from pydantic import AnyHttpUrl

logging.basicConfig(
    level=os.getenv("LOG_LEVEL", "INFO"),
    format="[%(asctime)s] [%(name)s] [%(levelname)s] %(message)s",
)
logger = logging.getLogger("bring-mcp")


def _env(name: str, default: str | None = None, required: bool = False) -> str:
    value = os.getenv(name, default or "")
    if required and not value:
        raise RuntimeError(f"Environment variable {name} is not set")
    return value


# Public HTTPS address of this MCP server, without a trailing slash.
BASE_URL = _env("BASE_URL", required=True).rstrip("/")

# Public HTTPS address of the Symfony authorization server. This is what the
# protected-resource metadata advertises, so it has to be the address Claude
# can reach.
AUTH_SERVER_URL = _env("AUTH_SERVER_URL", required=True).rstrip("/")

# Where to fetch the JWKS. Defaults to the public address, but on a Docker
# network the container should talk to Symfony directly rather than leaving
# through the reverse proxy and coming back.
AUTH_SERVER_INTERNAL_URL = (
    _env("AUTH_SERVER_INTERNAL_URL") or AUTH_SERVER_URL
).rstrip("/")

# Where to read the user's Bring! credentials. Defaults to the public host,
# but should point at the container on the Docker network so the endpoint
# never needs a route through the reverse proxy.
CREDENTIALS_URL = _env(
    "CREDENTIALS_URL", f"{AUTH_SERVER_INTERNAL_URL}/internal/bring-credentials"
)

# league/oauth2-server puts the *client* identifier in `aud`, not the resource
# URL. Since every user mints their own connector client, there is no single
# value to pin, so audience checking is off by default. Nothing is lost: the
# token is still signed by the authorization server and must carry the `bring`
# scope, and Symfony rejects a client that does not belong to the token's
# subject. Set this only for a deployment with one shared client.
OAUTH_AUDIENCE = _env("OAUTH_AUDIENCE") or None

REQUIRED_SCOPE = "bring"
CACHE_TTL = float(_env("CREDENTIALS_CACHE_TTL", "900"))


@dataclass
class BringSession:
    """A logged-in Bring instance plus the session it borrows."""

    session: aiohttp.ClientSession
    bring: Bring
    # Default list for the connector this token belongs to; None = first list.
    list_name: str | None
    created_at: float

    async def close(self) -> None:
        await self.session.close()

    @property
    def expired(self) -> bool:
        return time.monotonic() - self.created_at >= CACHE_TTL


@dataclass
class SessionCache:
    """Per-subject Bring sessions, guarded so a burst of tool calls logs in once."""

    entries: dict[str, BringSession] = field(default_factory=dict)
    lock: asyncio.Lock = field(default_factory=asyncio.Lock)

    async def close_all(self) -> None:
        for entry in self.entries.values():
            await entry.close()
        self.entries.clear()


@asynccontextmanager
async def lifespan(_: FastMCP):
    cache = SessionCache()
    try:
        yield cache
    finally:
        await cache.close_all()


verifier = JWTVerifier(
    jwks_uri=f"{AUTH_SERVER_INTERNAL_URL}/.well-known/jwks.json",
    # The tokens carry no `iss` claim: league/oauth2-server builds them without
    # one and lcobucci/jwt refuses registered claims as extra claims, so there
    # is nothing to check here.
    issuer=None,
    audience=OAUTH_AUDIENCE,
    algorithm="RS256",
    required_scopes=[REQUIRED_SCOPE],
)

auth = RemoteAuthProvider(
    token_verifier=verifier,
    authorization_servers=[AnyHttpUrl(AUTH_SERVER_URL)],
    base_url=BASE_URL,
    scopes_supported=[REQUIRED_SCOPE],
    resource_name="Bring Shopping List",
)

mcp = FastMCP(
    "Bring Shopping List",
    auth=auth,
    lifespan=lifespan,
    instructions=(
        "Writes entries to the user's Bring! shopping list. "
        "Use add_items to add several ingredients in one go."
    ),
)


async def _fetch_credentials(bearer: str) -> dict:
    """Asks Symfony for the Bring! credentials belonging to this token."""
    headers = {"Authorization": f"Bearer {bearer}", "Accept": "application/json"}

    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(CREDENTIALS_URL, headers=headers) as response:
                if response.status == 404:
                    raise ToolError(
                        "No Bring! account is stored for you yet. Add one at "
                        f"{AUTH_SERVER_URL}/account, then try again."
                    )
                if response.status in (401, 403):
                    raise ToolError(
                        "The authorization server rejected this token. "
                        "Reconnect the connector."
                    )

                response.raise_for_status()
                return await response.json()
    except aiohttp.ClientError as exc:
        raise ToolError(f"Credential service unreachable: {exc}") from exc


async def _session() -> BringSession:
    """Returns a logged-in Bring session for the caller, building it if needed."""
    token = get_access_token()

    if token is None:
        raise ToolError("This tool requires an authenticated request.")

    subject = str(token.claims.get("sub", ""))

    if not subject:
        raise ToolError("The access token carries no subject.")

    cache: SessionCache = get_context().request_context.lifespan_context

    async with cache.lock:
        entry = cache.entries.get(subject)

        if entry is not None and not entry.expired:
            return entry

        if entry is not None:
            # Credentials may have changed in the web UI since this was built.
            await entry.close()
            del cache.entries[subject]

        credentials = await _fetch_credentials(token.token)
        session = aiohttp.ClientSession()
        bring = Bring(session, credentials["username"], credentials["password"])

        try:
            await bring.login()
        except (BringAuthException, BringRequestException) as exc:
            await session.close()
            raise ToolError(
                f"Bring! login failed for the stored account: {exc}. "
                f"Check the credentials at {AUTH_SERVER_URL}/account."
            ) from exc

        logger.info("Logged in to Bring for subject %s", subject)

        entry = BringSession(
            session=session,
            bring=bring,
            list_name=credentials.get("list_name"),
            created_at=time.monotonic(),
        )
        cache.entries[subject] = entry

        return entry


async def _call(request):
    """Awaits a Bring! call and turns its failures into something readable.

    bring-api raises the same two exceptions from every call, and every one of
    them can hit a service that is simply not there right now. Left uncaught
    they reach the user as a traceback, which says nothing about what to do —
    so all of them take this route.

    A login failure is not one of these: that has its own message, because the
    stored credentials are something the user can go and fix.
    """
    try:
        return await request
    except (BringAuthException, BringRequestException) as exc:
        raise ToolError(f"Bring is unreachable: {exc}") from exc


async def _resolve_list(name: str | None) -> tuple[Bring, str, str]:
    """Returns (bring, uuid, name) of the target list for the caller.

    An explicit name wins; otherwise the default the user picked for this
    connector applies, and failing that the account's first list.
    """
    entry = await _session()
    wanted = (name or entry.list_name or "").strip()

    lists = (await _call(entry.bring.load_lists())).lists

    if not lists:
        raise ToolError("This Bring! account has no lists.")

    if not wanted:
        return entry.bring, lists[0].listUuid, lists[0].name

    for candidate in lists:
        if candidate.name.strip().lower() == wanted.lower():
            return entry.bring, candidate.listUuid, candidate.name

    available = ", ".join(candidate.name for candidate in lists)
    raise ToolError(f"List {wanted!r} not found. Available: {available}")


@mcp.tool
async def list_shopping_lists() -> list[str]:
    """Names all available Bring shopping lists."""
    entry = await _session()

    lists = (await _call(entry.bring.load_lists())).lists

    return [candidate.name for candidate in lists]


@mcp.tool
async def add_items(
    items: list[str],
    list_name: str | None = None,
    specifications: list[str] | None = None,
) -> str:
    """Adds items to the shopping list.

    Args:
        items: Item names, e.g. ["Milk", "Carrots"].
        list_name: Exact name of the target list. Empty uses the list this
            connector defaults to. Call list_shopping_lists when unsure.
        specifications: Optional details per item, in the same order as
            items, e.g. ["1 liter", "500 g"]. Shorter lists are padded
            with empty details.
    """
    if not items:
        raise ToolError("No items given.")

    specs = list(specifications or [])
    specs += [""] * (len(items) - len(specs))

    bring, uuid, name = await _resolve_list(list_name)

    for item, spec in zip(items, specs):
        await _call(bring.save_item(uuid, item, spec))
        logger.info("Added: %s (%s) -> %s", item, spec or "-", name)

    return f"Added {len(items)} items to {name!r}."


def _describe(purchase) -> str:
    """`itemId` is the display name; `specification` carries the quantity."""
    if purchase.specification:
        return f"{purchase.itemId} ({purchase.specification})"

    return purchase.itemId


@mcp.tool
async def get_shopping_list(list_name: str | None = None) -> dict:
    """Shows open and already completed items of a list.

    Args:
        list_name: Exact list name. Empty uses the connector's default.
    """
    bring, uuid, name = await _resolve_list(list_name)
    items = (await _call(bring.get_list(uuid))).items

    return {
        "list": name,
        "open": [_describe(i) for i in items.purchase],
        "completed": [_describe(i) for i in items.recently],
    }


@mcp.tool
async def complete_item(item: str, list_name: str | None = None) -> str:
    """Ticks an item off the list.

    Args:
        item: Name of the item as it appears on the list.
        list_name: Exact list name. Empty uses the connector's default.
    """
    bring, uuid, name = await _resolve_list(list_name)
    await _call(bring.complete_item(uuid, item))

    return f"Ticked {item!r} off {name!r}."


if __name__ == "__main__":
    mcp.run(
        transport="http",
        host=_env("HTTP_HOST", "0.0.0.0"),
        port=int(_env("HTTP_PORT", "8080")),
    )
