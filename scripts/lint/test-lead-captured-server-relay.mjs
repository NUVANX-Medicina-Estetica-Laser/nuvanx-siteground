import fs from 'node:fs';
import assert from 'node:assert/strict';

const gtmPath = 'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php';
const relayPath = 'wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php';
const bridgePath = 'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php';
const consentPath = 'wp-content/themes/nuvanx-medical/inc/nvx-marketing-consent.php';

assert.equal(fs.existsSync(relayPath), true, 'Canonical lead-captured relay must exist');
assert.equal(fs.existsSync(consentPath), true, 'Shared server-side consent owner must exist');
const gtm = fs.readFileSync(gtmPath, 'utf8');
const relay = fs.readFileSync(relayPath, 'utf8');
const bridge = fs.readFileSync(bridgePath, 'utf8');
const consent = fs.readFileSync(consentPath, 'utf8');

const secureRequire = gtm.indexOf("require_once __DIR__ . '/nvx-hubspot-secure-attribution.php';");
const relayRequire = gtm.indexOf("require_once __DIR__ . '/nvx-lead-captured-relay.php';");
assert.ok(secureRequire >= 0, 'Secure HubSpot bridge must remain loaded');
assert.ok(relayRequire > secureRequire, 'Lead-captured relay must load after the secure HubSpot bridge');
assert.match(bridge, /require_once __DIR__ \. '\/nvx-marketing-consent\.php';/,
  'Secure bridge must load the shared consent owner before any attribution filtering');

assert.match(relay, /add_filter\( 'http_response', 'nvx_lead_captured_on_http_response', 10, 3 \)/,
  'Relay must observe completed HTTP responses rather than browser events');
assert.match(relay, /nvx_hubspot_secure_submit_url\(\) !== \$url/,
  'Relay must scope itself to the authenticated HubSpot transport only');
assert.match(relay, /\$status < 200 \|\| \$status >= 300/,
  'Relay must require a real 2xx HubSpot response before recording a capture');

const hmacAware = /runtime-bootstrap/.test(relay) || /nvx_lead_captured_derive_hmac_key/.test(relay);
if (hmacAware) {
  assert.match(relay, /https:\/\/[a-z0-9-]+\.supabase\.co\/functions\/v1\/runtime-bootstrap/);
  assert.match(relay, /https:\/\/[a-z0-9-]+\.supabase\.co\/functions\/v1\/lead-captured/);
  assert.match(relay, /defined\( 'NVX_HUBSPOT_ACCESS_TOKEN' \)/);
  assert.doesNotMatch(relay, /NUVANX_LEAD_CAPTURE_SECRET/);
  assert.match(relay, /'Authorization'\s*=>\s*'Bearer '\s*\.\s*\$token/);
  assert.match(relay, /nuvanx-lead-capture-hmac-key-v1/);
  assert.match(relay, /\$hmac_key\s*=\s*nvx_lead_captured_derive_hmac_key/);
  assert.match(relay, /hash_hmac\(\s*'sha256',\s*\$timestamp\s*\.\s*'\.'\s*\.\s*\$body,\s*\$hmac_key\s*\)/);
  assert.match(relay, /'x-nvx-timestamp'\s*=>\s*\$timestamp/);
  assert.match(relay, /'x-nvx-signature'\s*=>\s*\$signature/);
  assert.match(relay, /401 === \$relay_status \|\| 503 === \$relay_status/);
  assert.match(relay, /nvx_supabase_relay_queue_enqueue\(\s*'lead_captured'/);
  const forcedBootstrap = relay.match(/nvx_lead_captured_bootstrap_runtime\( \$token, true \)/g) || [];
  assert.equal(forcedBootstrap.length, 1);
}

assert.match(relay, /\$email_hash\s*=\s*'' !== \$email \? hash\( 'sha256', \$email \) : null;/);
assert.match(relay, /unset\( \$email \);/);
assert.doesNotMatch(relay, /['"](?:treatment|condition|procedure|diagnosis|body_area)['"]/i);

const payloadStart = relay.indexOf('$relay_payload = array(');
const encodeStart = relay.indexOf('$relay_body = wp_json_encode(', payloadStart);
assert.ok(payloadStart >= 0 && encodeStart > payloadStart, 'Canonical relay payload block must be parseable');
const payloadBlock = relay.slice(payloadStart, encodeStart);
assert.match(payloadBlock, /'email_hash'\s*=>\s*\$email_hash/);
assert.doesNotMatch(payloadBlock, /['"](?:email|phone|phone_number|name|first_name|last_name|full_name|token|authorization)['"]\s*=>/i);

const consentAware = /'marketing_consent'\s*=>/.test(payloadBlock);
if (consentAware) {
  assert.match(consent, /function nvx_marketing_consent_granted\(\): bool/,
    'There must be one shared server-side marketing-consent owner');
  assert.doesNotMatch(consent, /\$_POST/,
    'Shared authority must not trust a browser POST marker');
  assert.doesNotMatch(consent, /nvx_hubspot_secure_post_value|'\s*nvx_marketing_consent\s*'/,
    'Shared authority must not read the hidden POST consent field');
  assert.match(bridge, /\$marketing_consent\s*=\s*nvx_marketing_consent_granted\(\);/,
    'HubSpot bridge must use the shared server decision');
  assert.doesNotMatch(bridge, /\$marketing_consent\s*=\s*'1' === nvx_hubspot_secure_post_value/,
    'HubSpot bridge must not use hidden consent as authority');
  assert.match(relay, /\$marketing_consent\s*=\s*function_exists\( 'nvx_marketing_consent_granted' \) && nvx_marketing_consent_granted\(\);/,
    'Capture relay must use the same shared server decision');
  assert.doesNotMatch(relay, /'1' === nvx_hubspot_secure_post_value\( 'nvx_marketing_consent'/,
    'Browser POST must not be the consent authority for attribution relay');
  assert.match(payloadBlock, /'marketing_consent'\s*=>\s*\$marketing_consent/);
  assert.match(payloadBlock, /'first_attribution'\s*=>\s*\$first_attribution/);
  assert.match(payloadBlock, /'conversion_attribution'\s*=>\s*\$conversion_attribution/);
  assert.match(relay, /\$first_attribution\s*=\s*\$marketing_consent \? nvx_lead_captured_attribution/);
  assert.match(relay, /\$conversion_attribution\s*=\s*\$marketing_consent \? nvx_lead_captured_attribution/);
  assert.match(relay, /false === \$relay_body/);
}
if (hmacAware) assert.equal(consentAware, true);

assert.match(relay, /HubSpot response IDs unavailable; status=%d json_error=%d/);
assert.doesNotMatch(relay, /Snippet:|substr\(\s*\$body|json_last_error_msg\(\)/);
assert.match(relay, /relay transport failure; wp_error_code=%s/);
assert.match(relay, /relay HTTP failure; status=%d/);
if (hmacAware) {
  assert.match(relay, /runtime bootstrap HTTP failure; status=%d/);
  const logLines = relay.split('\n').filter((line) => line.includes('error_log') || line.includes('sprintf('));
  assert.doesNotMatch(logLines.join('\n'), /\$token|Authorization|x-nvx-signature/);
}

assert.match(relay, /'nvx_is_test_lead'\s*=>\s*\$is_test/);
assert.match(relay, /'nvx_test_run_id'\s*=>/);
assert.match(relay, /'nvx_lead_id'\s*=>\s*\$lead_id/);
assert.doesNotMatch(relay, /graph\.facebook\.com|functions\/v1\/web-events|googleads\.|crm\/v3\/objects\/deals/i);

console.log(`LEAD_CAPTURED_SERVER_RELAY=PASS auth=${hmacAware ? 'hubspot-hmac' : 'legacy-secret'} consent=${consentAware ? 'single-server-owner' : 'legacy'}`);
