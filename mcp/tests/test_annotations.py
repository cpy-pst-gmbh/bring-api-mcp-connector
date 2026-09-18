"""Every tool declares all four behaviour hints explicitly.

Hosts use them to decide what to confirm with the user, and some directories
reject a tool with any hint missing. A default is not the same as a decision.
"""

from __future__ import annotations

import pytest
import server

EXPECTED = {
    "list_shopping_lists": (True, False, True, True),
    "add_items": (False, False, False, True),
    "get_shopping_list": (True, False, True, True),
    "complete_item": (False, True, True, True),
}


async def test_every_tool_is_covered():
    tools = await server.mcp.list_tools()

    assert {tool.name for tool in tools} == set(EXPECTED)


@pytest.mark.parametrize("name", EXPECTED)
async def test_all_four_hints_are_set(name):
    tool = await server.mcp.get_tool(name)
    hints = tool.annotations

    assert (
        hints.readOnlyHint,
        hints.destructiveHint,
        hints.idempotentHint,
        hints.openWorldHint,
    ) == EXPECTED[name]
