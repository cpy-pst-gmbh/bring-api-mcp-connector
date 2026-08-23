#!/usr/bin/env bash
#
# Walks the full authorization code + PKCE flow against a locally running
# Symfony app and prints the access token, so the MCP server can be exercised
# without Claude in the loop.
#
# Usage: tools/dev-token.sh <base-url> <bring-email> <bring-password> [client-id] [redirect-uri]
#
# Signing in goes through Bring!, so these have to be real Bring! credentials.
# The first run for an address creates the account.
set -euo pipefail

BASE="${1:?usage: dev-token.sh <base-url> <email> <password> [client-id] [redirect-uri]}"
EMAIL="${2:?missing email}"
PASSWORD="${3:?missing password}"
CLIENT_ID="${4:-claude-connector}"
REDIRECT_URI="${5:-https://claude.ai/api/mcp/auth_callback}"

BASE="${BASE%/}"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

# Any 43-128 character string from the RFC 7636 alphabet works as a verifier.
VERIFIER="dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"
CHALLENGE="$(php -r 'echo rtrim(strtr(base64_encode(hash("sha256", $argv[1], true)), "+/", "-_"), "=");' "$VERIFIER")"

authorize_query() {
  printf '/authorize?response_type=code&client_id=%s&redirect_uri=%s&scope=bring&state=dev&code_challenge=%s&code_challenge_method=S256' \
    "$CLIENT_ID" "$(php -r 'echo rawurlencode($argv[1]);' "$REDIRECT_URI")" "$CHALLENGE"
}

# Stateless CSRF accepts a matching Origin when the double-submit cookie is
# missing, which is the path a non-browser client takes.
post() {
  curl -sS -b "$JAR" -c "$JAR" -H "Origin: $BASE" -X POST "$@"
}

get() {
  curl -sS -b "$JAR" -c "$JAR" "$@"
}

# 1. Sign in
get "$BASE/login" -o /dev/null
post "$BASE/login" \
  --data-urlencode "_username=$EMAIL" \
  --data-urlencode "_password=$PASSWORD" \
  --data-urlencode "_csrf_token=csrf-token" -o /dev/null

QUERY="$(authorize_query)"

# 2. First pass through /authorize parks the request and redirects to /consent
get "$BASE$QUERY" -o /dev/null

# A failing grep here is a real outcome (not signed in, no consent screen),
# so it must not trip pipefail before the check below runs.
CONSENT_TOKEN="$(get "$BASE/consent" | grep -oE 'name="_token"[^>]*' | grep -oP 'value="\K[^"]+' | head -1 || true)"

if [ -z "$CONSENT_TOKEN" ]; then
  echo "Could not reach the consent screen. Wrong password, or no authorization request is pending." >&2
  exit 1
fi

# 3. Approve, which sends us back to /authorize
post "$BASE/consent" \
  --data-urlencode "decision=approve" \
  --data-urlencode "_token=$CONSENT_TOKEN" -o /dev/null

# 4. Second pass issues the code
LOCATION="$(get -o /dev/null -w '%{redirect_url}' "$BASE$QUERY")"
CODE="$(printf '%s' "$LOCATION" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')"

if [ -z "$CODE" ]; then
  case "$LOCATION" in
    */consent*)
      echo "Consent was not granted." >&2
      ;;
    */login*)
      echo "Not signed in. Bring! rejected the credentials, or could not be reached." >&2
      ;;
    *)
      echo "No authorization code in the redirect: $LOCATION" >&2
      ;;
  esac
  exit 1
fi

# 5. Exchange it
curl -sS -X POST "$BASE/token" \
  -d "grant_type=authorization_code" \
  -d "client_id=$CLIENT_ID" \
  -d "redirect_uri=$REDIRECT_URI" \
  -d "code_verifier=$VERIFIER" \
  --data-urlencode "code=$CODE" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["access_token"]) if "access_token" in d else sys.exit("token endpoint: " + str(d))'
