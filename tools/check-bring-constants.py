#!/usr/bin/env python3
"""Compares the Bring! constants in app/.env against the installed bring-api.

The Symfony side talks to Bring! directly — it has to, because signing in
happens before any MCP token exists — so the base URL and the request headers
live in two places: bring_api.const on the Python side and app/.env on the PHP
side. Bumping bring-api can silently move them apart.

Run without arguments to report; run with --fix to rewrite app/.env.

    tools/check-bring-constants.py
    tools/check-bring-constants.py --fix

Exits 0 when the two agree, 1 when they drift, 2 when it cannot tell.

This only guards the constants. A changed endpoint path or response shape slips
straight past it — those surface as a failing login or an empty list.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
ENV_FILE = REPO_ROOT / "app" / ".env"

# Env var in app/.env -> where the value comes from in bring_api.const.
# A header name means DEFAULT_HEADERS[name]; None means the module attribute.
MAPPING: dict[str, tuple[str, str | None]] = {
    "BRING_API_BASE_URL": ("API_BASE_URL", None),
    "BRING_API_KEY": ("DEFAULT_HEADERS", "X-BRING-API-KEY"),
    "BRING_CLIENT": ("DEFAULT_HEADERS", "X-BRING-CLIENT"),
    "BRING_APPLICATION": ("DEFAULT_HEADERS", "X-BRING-APPLICATION"),
    "BRING_COUNTRY": ("DEFAULT_HEADERS", "X-BRING-COUNTRY"),
}


def upstream_values() -> dict[str, str]:
    try:
        from bring_api import const
    except ImportError:
        sys.exit(
            "bring-api is not importable. Install it first:\n"
            "  python -m venv .venv && .venv/bin/pip install -r mcp/requirements.txt\n"
            "and run this script with that interpreter."
        )

    values: dict[str, str] = {}

    for env_name, (attribute, key) in MAPPING.items():
        source = getattr(const, attribute, None)

        if source is None:
            sys.exit(f"bring_api.const has no {attribute} — the package layout changed.")

        if key is None:
            values[env_name] = str(source)
            continue

        if key not in source:
            sys.exit(f"bring_api.const.{attribute} has no {key!r} — the package layout changed.")

        values[env_name] = str(source[key])

    return values


def env_values(text: str) -> dict[str, str]:
    found: dict[str, str] = {}

    for name in MAPPING:
        match = re.search(rf"^{re.escape(name)}=(.*)$", text, re.MULTILINE)
        if match:
            found[name] = match.group(1).strip().strip('"').strip("'")

    return found


def rewrite(text: str, updates: dict[str, str]) -> str:
    for name, value in updates.items():
        text = re.sub(
            rf"^{re.escape(name)}=.*$",
            f"{name}={value}",
            text,
            count=1,
            flags=re.MULTILINE,
        )

    return text


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--fix",
        action="store_true",
        help="rewrite app/.env with the values from bring-api",
    )
    args = parser.parse_args()

    if not ENV_FILE.exists():
        sys.exit(f"{ENV_FILE} not found.")

    text = ENV_FILE.read_text()
    upstream = upstream_values()
    current = env_values(text)

    missing = sorted(set(MAPPING) - set(current))
    drifted = {n: v for n, v in upstream.items() if n in current and current[n] != v}

    width = max(len(n) for n in MAPPING)

    for name in MAPPING:
        if name in missing:
            print(f"{name:<{width}}  MISSING from app/.env")
        elif name in drifted:
            print(f"{name:<{width}}  DRIFT  {current[name]!r} -> {upstream[name]!r}")
        else:
            print(f"{name:<{width}}  ok")

    if missing:
        print(f"\n{len(missing)} variable(s) missing from app/.env — add them by hand.")
        return 2

    if not drifted:
        print("\napp/.env matches the installed bring-api.")
        return 0

    if not args.fix:
        print(f"\n{len(drifted)} value(s) drifted. Re-run with --fix to update app/.env.")
        return 1

    ENV_FILE.write_text(rewrite(text, drifted))
    print(f"\nUpdated {len(drifted)} value(s) in app/.env.")
    print("Symfony reads .env at build time — clear the cache before this takes effect.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
