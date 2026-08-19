"""Shared fixtures and doubles for the MCP server tests.

The module under test reads its configuration at import time and refuses to
load without BASE_URL and AUTH_SERVER_URL, so those are set here — conftest is
imported before any test module, and with it before `server`.

Nothing in this suite talks to Bring!, to Symfony or to a network at all. The
doubles below stand in for the three seams the server has: the credential
endpoint, the Bring client, and the request context FastMCP provides.
"""

from __future__ import annotations

import os
import sys
from dataclasses import dataclass, field
from pathlib import Path
from types import SimpleNamespace

import pytest

os.environ.setdefault("BASE_URL", "https://mcp.example.com")
os.environ.setdefault("AUTH_SERVER_URL", "https://bring.example.com")

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import server  # noqa: E402


@dataclass
class FakeList:
    name: str
    listUuid: str  # noqa: N815 — the attribute bring-api exposes


@dataclass
class FakeItem:
    itemId: str  # noqa: N815 — the display name, as bring-api spells it
    specification: str = ""


@dataclass
class FakeBring:
    """Stands in for bring_api.Bring, recording what the tools asked of it."""

    lists: list[FakeList] = field(default_factory=list)
    purchase: list[FakeItem] = field(default_factory=list)
    recently: list[FakeItem] = field(default_factory=list)
    saved: list[tuple[str, str, str]] = field(default_factory=list)
    completed: list[tuple[str, str]] = field(default_factory=list)
    logins: int = 0
    login_error: Exception | None = None

    async def login(self) -> None:
        self.logins += 1

        if self.login_error is not None:
            raise self.login_error

    async def load_lists(self):
        return SimpleNamespace(lists=self.lists)

    async def get_list(self, uuid: str):
        return SimpleNamespace(
            items=SimpleNamespace(purchase=self.purchase, recently=self.recently),
        )

    async def save_item(self, uuid: str, item: str, specification: str) -> None:
        self.saved.append((uuid, item, specification))

    async def complete_item(self, uuid: str, item: str) -> None:
        self.completed.append((uuid, item))


@dataclass
class FakeClientSession:
    """The aiohttp session the server borrows for a Bring instance."""

    closed: bool = False

    async def close(self) -> None:
        self.closed = True


@pytest.fixture
def cache() -> server.SessionCache:
    return server.SessionCache()


@pytest.fixture
def authenticated(monkeypatch, cache):
    """Puts a token and a lifespan context in place, the way FastMCP would.

    Returns a callable that switches the subject, so one test can act as two
    different users without rebuilding the world.
    """
    token = SimpleNamespace(token="access-token", claims={"sub": "user@example.com"})

    monkeypatch.setattr(server, "get_access_token", lambda: token)
    monkeypatch.setattr(
        server,
        "get_context",
        lambda: SimpleNamespace(
            request_context=SimpleNamespace(lifespan_context=cache),
        ),
    )

    def act_as(subject: str) -> None:
        token.claims["sub"] = subject

    return act_as


@pytest.fixture
def sessions() -> list[FakeClientSession]:
    """Every aiohttp session the server opened, in order."""
    return []


@pytest.fixture
def bring(monkeypatch, sessions) -> FakeBring:
    """Replaces the Bring client and the session it is handed."""
    instance = FakeBring(lists=[FakeList("Groceries", "uuid-groceries")])

    def open_session() -> FakeClientSession:
        session = FakeClientSession()
        sessions.append(session)

        return session

    monkeypatch.setattr(server, "Bring", lambda session, username, password: instance)
    monkeypatch.setattr(server.aiohttp, "ClientSession", open_session)
    monkeypatch.setattr(
        server,
        "_fetch_credentials",
        _credentials(username="user@example.com", password="hunter2"),
    )

    return instance


def _credentials(**payload):
    async def fetch(bearer: str) -> dict:
        return dict(payload)

    return fetch
