"""The one call that leaves this container towards Symfony.

Bring! credentials are never in the token — the server exchanges the user's
bearer for them at an endpoint that only exists on the Docker network. What
matters here is that each way this can fail says something the user can act on,
because these messages are what Claude shows them.
"""

from __future__ import annotations

import aiohttp
import pytest
import server
from fastmcp.exceptions import ToolError
from multidict import CIMultiDict
from yarl import URL


class FakeResponse:
    def __init__(self, status: int, payload: dict | None = None) -> None:
        self.status = status
        self._payload = payload or {}

    async def json(self) -> dict:
        return self._payload

    def raise_for_status(self) -> None:
        if self.status >= 400:
            # The real one carries request info, and its __str__ reads it.
            request = aiohttp.RequestInfo(
                URL(server.CREDENTIALS_URL),
                "GET",
                CIMultiDict(),
                URL(server.CREDENTIALS_URL),
            )

            raise aiohttp.ClientResponseError(request, (), status=self.status)

    async def __aenter__(self) -> "FakeResponse":
        return self

    async def __aexit__(self, *_) -> bool:
        return False


class FakeSession:
    """Records the single GET the server makes."""

    def __init__(self, response: FakeResponse | Exception) -> None:
        self.response = response
        self.url: str | None = None
        self.headers: dict[str, str] = {}

    def get(self, url: str, headers: dict[str, str]):
        self.url = url
        self.headers = headers

        if isinstance(self.response, Exception):
            raise self.response

        return self.response

    async def __aenter__(self) -> "FakeSession":
        return self

    async def __aexit__(self, *_) -> bool:
        return False


@pytest.fixture
def respond(monkeypatch):
    def install(response: FakeResponse | Exception) -> FakeSession:
        session = FakeSession(response)
        monkeypatch.setattr(server.aiohttp, "ClientSession", lambda: session)

        return session

    return install


async def test_it_forwards_the_users_own_token(respond):
    session = respond(FakeResponse(200, {"username": "user@example.com", "password": "hunter2"}))

    credentials = await server._fetch_credentials("access-token")

    assert credentials == {"username": "user@example.com", "password": "hunter2"}
    assert session.url == server.CREDENTIALS_URL
    assert session.headers["Authorization"] == "Bearer access-token"


async def test_a_user_without_a_stored_account_is_sent_to_the_account_page(respond):
    """404 is the normal case for somebody who has just added the connector."""
    respond(FakeResponse(404))

    with pytest.raises(ToolError) as error:
        await server._fetch_credentials("access-token")

    assert f"{server.AUTH_SERVER_URL}/account" in str(error.value)


@pytest.mark.parametrize("status", [401, 403])
async def test_a_rejected_token_asks_for_a_reconnect(respond, status):
    respond(FakeResponse(status))

    with pytest.raises(ToolError, match="Reconnect the connector"):
        await server._fetch_credentials("access-token")


async def test_a_broken_authorization_server_is_reported_as_unreachable(respond):
    """A 500 must not read like a credential problem: nothing is wrong with the
    account, and telling the user to check it sends them nowhere."""
    respond(FakeResponse(500))

    with pytest.raises(ToolError, match="Credential service unreachable"):
        await server._fetch_credentials("access-token")


async def test_a_connection_failure_is_reported_as_unreachable(respond):
    respond(aiohttp.ClientConnectionError("no route to host"))

    with pytest.raises(ToolError, match="Credential service unreachable"):
        await server._fetch_credentials("access-token")
