"""Per-subject Bring sessions.

A busy user's connector makes a few hundred calls a day, and logging in to
Bring! on each of them would be both slow and rude. The cache is what avoids
that — and the thing a cache has to get right is when to stop trusting itself.
"""

from __future__ import annotations

import pytest
import server
from bring_api import BringAuthException
from fastmcp.exceptions import ToolError


async def test_an_unauthenticated_call_is_refused(monkeypatch, authenticated):
    """Belt and braces: FastMCP rejects these before a tool runs, but the
    lookup below would otherwise dereference None."""
    monkeypatch.setattr(server, "get_access_token", lambda: None)

    with pytest.raises(ToolError, match="authenticated request"):
        await server._session()


async def test_a_token_without_a_subject_is_refused(authenticated, bring):
    authenticated("")

    with pytest.raises(ToolError, match="no subject"):
        await server._session()


async def test_the_first_call_logs_in_and_keeps_the_session(authenticated, bring, cache):
    entry = await server._session()

    assert bring.logins == 1
    assert cache.entries["user@example.com"] is entry


async def test_a_second_call_reuses_the_login(authenticated, bring):
    first = await server._session()
    second = await server._session()

    assert first is second
    assert bring.logins == 1


async def test_two_users_do_not_share_a_session(authenticated, bring, cache):
    await server._session()
    authenticated("someone-else@example.com")
    await server._session()

    assert set(cache.entries) == {"user@example.com", "someone-else@example.com"}
    assert bring.logins == 2


async def test_an_expired_entry_is_rebuilt_and_the_old_one_closed(
    monkeypatch, authenticated, bring, cache
):
    """The point of the TTL: a password changed in the web UI has to take
    effect without restarting the container."""
    first = await server._session()
    monkeypatch.setattr(server, "CACHE_TTL", 0)

    second = await server._session()

    assert second is not first
    assert first.session.closed
    assert bring.logins == 2
    assert len(cache.entries) == 1


async def test_the_connectors_default_list_travels_with_the_session(
    monkeypatch, authenticated, bring
):
    async def credentials(bearer: str) -> dict:
        return {"username": "user@example.com", "password": "hunter2", "list_name": "Weekend"}

    monkeypatch.setattr(server, "_fetch_credentials", credentials)

    assert (await server._session()).list_name == "Weekend"


async def test_a_failed_bring_login_closes_the_session_and_names_the_account_page(
    authenticated, bring, cache, sessions
):
    """Without the close this would leak an aiohttp session per failed call,
    and a wrong stored password fails on every single one."""
    bring.login_error = BringAuthException("invalid credentials")

    with pytest.raises(ToolError) as error:
        await server._session()

    assert f"{server.AUTH_SERVER_URL}/account" in str(error.value)
    assert sessions[-1].closed
    assert cache.entries == {}
