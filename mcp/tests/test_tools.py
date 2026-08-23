"""The four tools, and the list resolution underneath all of them.

Which list an item lands on is the decision a user cannot undo from Claude, so
it is the part worth pinning down: an explicit name wins, otherwise the default
this connector was created with, and only then the first list on the account.
"""

from __future__ import annotations

import pytest
import server
from fastmcp.exceptions import ToolError

from conftest import FakeItem, FakeList


@pytest.fixture
def two_lists(bring):
    bring.lists = [FakeList("Groceries", "uuid-groceries"), FakeList("Weekend", "uuid-weekend")]

    return bring


async def test_an_explicit_name_wins_over_the_connector_default(
    monkeypatch, authenticated, two_lists
):
    monkeypatch.setattr(server, "_fetch_credentials", _credentials(list_name="Groceries"))

    _, uuid, name = await server._resolve_list("Weekend")

    assert (uuid, name) == ("uuid-weekend", "Weekend")


async def test_the_connector_default_applies_when_no_name_is_given(
    monkeypatch, authenticated, two_lists
):
    monkeypatch.setattr(server, "_fetch_credentials", _credentials(list_name="Weekend"))

    _, uuid, name = await server._resolve_list(None)

    assert (uuid, name) == ("uuid-weekend", "Weekend")


async def test_without_a_default_the_first_list_is_used(authenticated, two_lists):
    _, uuid, name = await server._resolve_list(None)

    assert (uuid, name) == ("uuid-groceries", "Groceries")


async def test_the_name_is_matched_regardless_of_case_and_padding(authenticated, two_lists):
    """Claude passes on what the user typed, and people do not type list names
    the way Bring! stores them."""
    _, uuid, _ = await server._resolve_list("  weekend ")

    assert uuid == "uuid-weekend"


async def test_an_unknown_list_names_the_ones_that_exist(authenticated, two_lists):
    with pytest.raises(ToolError) as error:
        await server._resolve_list("Pantry")

    assert "Groceries, Weekend" in str(error.value)


async def test_an_account_without_lists_is_reported(authenticated, bring):
    bring.lists = []

    with pytest.raises(ToolError, match="no lists"):
        await server._resolve_list(None)


async def test_bring_being_unreachable_is_not_a_missing_list(monkeypatch, authenticated, bring):
    _breaks(monkeypatch, bring, "load_lists")

    with pytest.raises(ToolError, match="Bring is unreachable"):
        await server._resolve_list(None)


async def test_list_shopping_lists_returns_the_names(authenticated, two_lists):
    assert await server.list_shopping_lists() == ["Groceries", "Weekend"]


async def test_add_items_writes_each_item_to_the_resolved_list(authenticated, bring):
    result = await server.add_items(["Milk", "Carrots"], specifications=["1 l", "500 g"])

    assert bring.saved == [
        ("uuid-groceries", "Milk", "1 l"),
        ("uuid-groceries", "Carrots", "500 g"),
    ]
    assert "Groceries" in result


async def test_missing_specifications_are_padded_rather_than_dropping_items(
    authenticated, bring
):
    """zip() stops at the shorter side, so an unpadded specifications list
    would silently swallow every item past its end."""
    await server.add_items(["Milk", "Carrots", "Bread"], specifications=["1 l"])

    assert [item for _, item, _ in bring.saved] == ["Milk", "Carrots", "Bread"]
    assert [spec for _, _, spec in bring.saved] == ["1 l", "", ""]


async def test_add_items_without_items_is_refused(authenticated, bring):
    with pytest.raises(ToolError, match="No items given"):
        await server.add_items([])

    assert bring.saved == []


async def test_get_shopping_list_separates_open_from_completed(authenticated, bring):
    bring.purchase = [FakeItem("Milk", "1 l"), FakeItem("Bread")]
    bring.recently = [FakeItem("Butter")]

    assert await server.get_shopping_list() == {
        "list": "Groceries",
        # itemId is the display name, specification the quantity.
        "open": ["Milk (1 l)", "Bread"],
        "completed": ["Butter"],
    }


async def test_complete_item_ticks_it_off_the_resolved_list(authenticated, bring):
    result = await server.complete_item("Milk")

    assert bring.completed == [("uuid-groceries", "Milk")]
    assert "Groceries" in result


@pytest.mark.parametrize(
    ("method", "call"),
    [
        ("load_lists", lambda: server.list_shopping_lists()),
        ("save_item", lambda: server.add_items(["Milk"])),
        ("get_list", lambda: server.get_shopping_list()),
        ("complete_item", lambda: server.complete_item("Milk")),
    ],
)
async def test_every_tool_reports_an_unreachable_bring(
    monkeypatch, authenticated, bring, method, call
):
    """bring-api raises the same two exceptions from every call. Uncaught they
    reach the user as a traceback, which says nothing about what to do."""
    _breaks(monkeypatch, bring, method)

    with pytest.raises(ToolError, match="Bring is unreachable"):
        await call()


def _breaks(monkeypatch, bring, method: str) -> None:
    """Makes one Bring! call fail the way an outage does."""
    from bring_api import BringRequestException

    async def fail(*args, **kwargs):
        raise BringRequestException("connection reset")

    monkeypatch.setattr(bring, method, fail)


def _credentials(**payload):
    async def fetch(bearer: str) -> dict:
        return {"username": "user@example.com", "password": "hunter2", **payload}

    return fetch
