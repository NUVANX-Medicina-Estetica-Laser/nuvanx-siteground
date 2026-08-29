#!/usr/bin/env bash
# MANUAL DIAGNOSTIC — not invoked by any CI workflow (staging.yml / production.yml).
# Owner: platform-ops. Run locally to verify HubSpot Conversations API read access is granted.
# If this becomes a CI gate, wire it explicitly into one of the two canonical workflows.
set -Eeuo pipefail

: "${HUBSPOT_ACCESS_TOKEN:?Missing HUBSPOT_ACCESS_TOKEN}"

API_BASE='https://api.hubapi.com'
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
response="$work/threads.json"

status="$(curl --silent --show-error --connect-timeout 10 --max-time 45 \
  --output "$response" \
  --write-out '%{http_code}' \
  --header "Authorization: Bearer ${HUBSPOT_ACCESS_TOKEN}" \
  --header 'Accept: application/json' \
  "$API_BASE/conversations/v3/conversations/threads?limit=1")"

if [[ "$status" != '200' ]]; then
  category="$(jq -r '.category // "unknown"' "$response" 2>/dev/null || echo unknown)"
  correlation="$(jq -r '.correlationId // "none"' "$response" 2>/dev/null || echo none)"
  echo "HUBSPOT_CONVERSATIONS_READ=FAIL status=$status category=$category correlation_id=$correlation" >&2
  exit 1
fi

jq -e '(.results // []) | type == "array"' "$response" >/dev/null || {
  echo 'HUBSPOT_CONVERSATIONS_READ=FAIL status=200 invalid_response_shape=1' >&2
  exit 1
}

returned="$(jq -r '(.results // []) | length' "$response")"
echo "HUBSPOT_CONVERSATIONS_READ=PASS endpoint=threads limit=1 returned=$returned payload=redacted"
