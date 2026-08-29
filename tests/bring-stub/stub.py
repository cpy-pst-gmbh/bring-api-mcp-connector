"""A stand-in for the two Bring! endpoints the Symfony app talks to.

There is no local password in this application: an account exists only once
Bring! has confirmed one. That makes every test past the login form depend on a
real Bring! account, and those tests skip themselves when none is configured —
which is most of the time, and exactly the half of the suite that covers the
pages under `/account`.

This serves the same two endpoints against fixed credentials, so the suite can
run everywhere. It deliberately reproduces the *status codes* the real API
returns, because `App\\Domain\\Client\\BringApiClient` reads meaning into them:

    401  known address, wrong password        -> rejected
    400  unknown address ("Invalid Email.")   -> rejected, not an outage
    2xx  with uuid + access_token             -> accepted
    anything else                             -> "unreachable", magic-link path

What it does NOT do is prove the real API still looks like this. Bring! has no
official API and the client is reverse-engineered; if the format changes, this
stub keeps answering happily. That check is the run with real credentials, and
`tools/check-bring-constants.py` for the constants.

Standard library only, so it needs no image of its own.
"""

from __future__ import annotations

import json
import logging
import os
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs

EMAIL = os.environ.get("STUB_EMAIL", "stub@example.test")
PASSWORD = os.environ.get("STUB_PASSWORD", "stub-password")
LISTS = [name for name in os.environ.get("STUB_LISTS", "Shopping,Hardware store").split(",") if name]
PORT = int(os.environ.get("STUB_PORT", "8081"))

# Any stable value will do — the app stores it only to build the lists URL.
SESSION_UUID = "00000000-0000-4000-8000-000000000001"
ACCESS_TOKEN = "stub-access-token"

# The client builds this from BRING_API_BASE_URL, so the /rest/ prefix is part
# of what is being exercised.
AUTH_PATH = "/rest/v2/bringauth"
LISTS_PATH = re.compile(r"^/rest/bringusers/([^/]+)/lists$")

logging.basicConfig(level=logging.INFO, format="%(asctime)s bring-stub %(message)s")
log = logging.getLogger(__name__)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_POST(self) -> None:  # noqa: N802 - name fixed by BaseHTTPRequestHandler
        if self.path != AUTH_PATH:
            self._send(404, {"message": "Not found."})
            return

        form = parse_qs(self._body())
        email = (form.get("email") or [""])[0]
        password = (form.get("password") or [""])[0]

        if email != EMAIL:
            # The real API answers 400 here, and the client treats that as a
            # verdict on the credentials rather than as an outage.
            self._send(400, {"message": "Invalid Email."})
            return

        if password != PASSWORD:
            self._send(401, {"message": "Invalid credentials."})
            return

        self._send(
            200,
            {
                "uuid": SESSION_UUID,
                "publicUuid": SESSION_UUID,
                "email": EMAIL,
                "name": "Stub",
                "access_token": ACCESS_TOKEN,
                "refresh_token": "stub-refresh-token",
                "token_type": "Bearer",
                "expires_in": 604800,
            },
        )

    def do_GET(self) -> None:  # noqa: N802 - name fixed by BaseHTTPRequestHandler
        match = LISTS_PATH.match(self.path)

        if match is None:
            self._send(404, {"message": "Not found."})
            return

        # The real endpoint rejects an unauthenticated read, and the client
        # always sends the bearer token it got from the login.
        if self.headers.get("Authorization") != f"Bearer {ACCESS_TOKEN}":
            self._send(401, {"message": "Unauthorized."})
            return

        self._send(
            200,
            {
                "lists": [
                    {
                        "listUuid": f"{SESSION_UUID[:-1]}{index}",
                        "name": name,
                        "theme": "ch.publisheria.bring.theme.home",
                    }
                    for index, name in enumerate(LISTS, start=2)
                ],
            },
        )

    def _body(self) -> str:
        length = int(self.headers.get("Content-Length") or 0)

        return self.rfile.read(length).decode("utf-8") if length else ""

    def _send(self, status: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")

        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt: str, *args) -> None:
        # The default writes to stderr unformatted; this keeps the container
        # log readable when a test fails and someone reads it back.
        log.info("%s %s", self.command, self.path)


if __name__ == "__main__":
    log.info("listening on :%d as %s with lists %s", PORT, EMAIL, ", ".join(LISTS))
    ThreadingHTTPServer(("", PORT), Handler).serve_forever()
