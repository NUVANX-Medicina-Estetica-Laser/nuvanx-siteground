#!/usr/bin/env bash
# MANUAL DIAGNOSTIC — not invoked by any CI workflow (staging.yml / production.yml).
# Owner: platform-ops. Run locally to verify the HubSpot form is live and unarchived.
# If this becomes a CI gate, wire it explicitly into one of the two canonical workflows.
set -Eeuo pipefail

: "${HUBSPOT_FORM_ID:?Missing HUBSPOT_FORM_ID}"
: "${HUBSPOT_PORTAL:?Missing HUBSPOT_PORTAL}"
: "${HUBSPOT_ACCESS_TOKEN:?Missing HUBSPOT_ACCESS_TOKEN}"

FORM_ID="$HUBSPOT_FORM_ID"
EXPECTED_PORTAL="$HUBSPOT_PORTAL"

[[ "$FORM_ID" =~ ^[0-9a-fA-F-]{36}$ ]] || { echo 'HUBSPOT_FORM_GATE=FAIL reason=invalid_form_id' >&2; exit 1; }
[[ "$EXPECTED_PORTAL" =~ ^[0-9]{1,20}$ ]] || { echo 'HUBSPOT_FORM_GATE=FAIL reason=invalid_portal_id' >&2; exit 1; }

response="${RUNNER_TEMP:-/tmp}/hubspot-form-${FORM_ID}.json"
status="$(
  curl --silent --show-error \
    --connect-timeout 10 \
    --max-time 30 \
    --output "$response" \
    --write-out '%{http_code}' \
    --header "Authorization: Bearer ${HUBSPOT_ACCESS_TOKEN}" \
    "https://api.hubapi.com/marketing/v3/forms/${FORM_ID}"
)"

echo "HUBSPOT_HTTP_STATUS=$status"

if [[ "$status" != '200' ]]; then
  echo 'HUBSPOT_FORM_GATE=FAIL' >&2
  jq '{status,category,message,correlationId}' "$response" 2>/dev/null || true
  exit 1
fi

jq -e --arg id "$FORM_ID" '
  .id == $id and
  ((.archived // false) == false)
' "$response" >/dev/null

name="$(jq -r '.name // ""' "$response")"
portal="$(jq -r '.portalId // ""' "$response")"

if [[ -z "$portal" ]]; then
  echo "HUBSPOT_FORM_GATE=FAIL reason=portal_not_returned_by_api" >&2
  exit 1
fi
if [[ "$portal" != "$EXPECTED_PORTAL" ]]; then
  echo "HUBSPOT_FORM_GATE=FAIL portal=$portal expected=$EXPECTED_PORTAL" >&2
  exit 1
fi

echo "HUBSPOT_FORM_GATE=PASS form_id=$FORM_ID form_name=$name portal=$portal"
