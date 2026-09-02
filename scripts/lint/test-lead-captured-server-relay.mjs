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
assert.match(bridge, /require_once (?:\$dependency|__DIR__ \. '\/nvx-marketing-consent\.php');/,
  'Secure bridge must load the shared consent owner before any attribution filtering');

assert.match(relay, /add_filter\(\s*'http_response',\s*'nvx_lead_captured_on_http_response',\s*10,\s*3\s*\)/,
  'Relay must observe completed HTTP responses rather than browser events');
assert.match(relay, /hash_equals\(\s*\$hubspot_url,\s*\$url\s*\)|nvx_hubspot_secure_submit_url\(\) !== \$url/,
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
  assert.match(relay, /nvx_lead_captured_derive_hmac_key/);
  assert.match(relay, /hash_hmac\(\s*'sha256',\s*\$timestamp\s*\.\s*'\.'\s*\.\s*\$body,\s*nvx_lead_captured_derive_hmac_key\(\s*\$token\s*\)\s*\)/);
  assert.match(relay, /'x-nvx-timestamp'\s*=>\s*\$timestamp/);
  assert.match(relay, /'x-nvx-signature'\s*=>\s*\$signature/);
  assert.match(relay, /nvx_supabase_relay_dispatch\(\s*'lead_captured'/);
  assert.match(relay, /nvx_rt_boot_/, 'Runtime bootstrap cache transient must use token-specific prefix');
  assert.match(relay, /hash\(\s*'sha256',\s*'nvx_runtime_bootstrap\|'\s*\.\s*\$token\s*\)/, 'Bootstrap transient key must derive from token hash');
  assert.doesNotMatch(relay, /nvx_runtime_bootstrap_ok_v1/, 'Global static bootstrap transient key is forbidden');
}

assert.match(relay, /\$email_hash\s*=\s*hash\(\s*'sha256',\s*strtolower\(\s*trim\(\s*\$email\s*\)\s*\)\s*\);/);
assert.match(relay, /unset\( \$email \);/);
assert.doesNotMatch(relay, /['"](?:treatment|condition|procedure|diagnosis|body_area)['"]/i);

const payloadStart = relay.indexOf('$relay_payload = array(');
const encodeStart = relay.indexOf('$body = wp_json_encode(', payloadStart);
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
  assert.match(relay, /\$marketing_consent\s*=\s*function_exists\( 'nvx_marketing_consent_granted' \)[\s\S]*?nvx_marketing_consent_granted\(\);/,
    'Capture relay must use the same shared server decision');
  assert.doesNotMatch(relay, /'1' === nvx_hubspot_secure_post_value\( 'nvx_marketing_consent'/,
    'Browser POST must not be the consent authority for attribution relay');
  assert.match(payloadBlock, /'marketing_consent'\s*=>\s*\$marketing_consent/);
  assert.match(payloadBlock, /'first_attribution'\s*=>\s*\$first_attribution/);
  assert.match(payloadBlock, /'conversion_attribution'\s*=>\s*\$conversion_attribution/);
  assert.match(relay, /\$first_attribution = \$marketing_consent[\s\S]*?nvx_lead_captured_attribution/);
  assert.match(relay, /\$conversion_attribution = \$marketing_consent[\s\S]*?nvx_lead_captured_attribution/);
}
if (hmacAware) assert.equal(consentAware, true);

assert.doesNotMatch(relay, /Snippet:|substr\(\s*\$body|json_last_error_msg\(\)/);
if (hmacAware) {
  assert.match(relay, /runtime bootstrap HTTP failure; status=%d/);
  const logLines = relay.split('\n').filter((line) => line.includes('error_log') || line.includes('sprintf('));
  assert.doesNotMatch(logLines.join('\n'), /\$token|Authorization|x-nvx-signature/);
}

assert.match(relay, /'nvx_is_test_lead'\s*=>\s*\$is_test/);
assert.match(relay, /'nvx_test_run_id'\s*=>/);
assert.match(relay, /'nvx_lead_id'\s*=>\s*\$lead_id/);
assert.doesNotMatch(relay, /graph\.facebook\.com|functions\/v1\/web-events|googleads\.|crm\/v3\/objects\/deals/i);

// 1C-2 Contractual tests:
assert.ok(relay.indexOf('nvx_lead_captured_build_relay_body') < relay.indexOf('nvx_supabase_relay_dispatch'), 'LEAD_CAPTURE_BUILD_BEFORE_BOOTSTRAP');
assert.match(relay, /nvx_supabase_relay_dispatch\(\s*'lead_captured',\s*\$relay_body\s*\)/, 'LEAD_CAPTURE_DISPATCH_ONLY');
assert.doesNotMatch(relay, /401 === \$relay_status/, 'LEAD_CAPTURE_NO_DIRECT_RETRY');
assert.match(relay, /\$status < 200 \|\| \$status >= 300[\s\S]*?return \$response;/, 'LEAD_CAPTURE_NON_2XX_NOOP');
assert.match(relay, /hash\(\s*'sha256',\s*strtolower\(\s*trim\(\s*\$email\s*\)\s*\)\s*\)/, 'LEAD_CAPTURE_EMAIL_HASH_ONLY');
assert.doesNotMatch(relay, /['"](?:treatment|condition|procedure|diagnosis|body_area)['"]/i, 'LEAD_CAPTURE_NO_CLINICAL_PII');
assert.match(relay, /\^staging2-\[A-Za-z0-9._:-\]\{1,110\}\$\/D/, 'LEAD_CAPTURE_QA_INVARIANT');
assert.match(relay, /define\(\s*'NVX_LEAD_CAPTURED_MAX_BODY_BYTES',\s*32768\s*\)/, 'LEAD_CAPTURE_MAX_32768');
assert.match(relay, /nvx_lead_captured_is_validated_direct_form_request/, 'LEAD_CAPTURE_BROWSER_META_REQUIRES_NONCE');

console.log(`LEAD_CAPTURED_SERVER_RELAY=PASS auth=${hmacAware ? 'hubspot-hmac' : 'legacy-secret'} consent=${consentAware ? 'single-server-owner' : 'legacy'} dispatch=outbox max_body=32768`);
