import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');
const bootstrap = read('wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php');
const consent = read('wp-content/themes/nuvanx-medical/inc/nvx-marketing-consent.php');
const hubspot = read('wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php');
const googleAuth = read('wp-content/themes/nuvanx-medical/inc/nvx-google-attribution-relay-auth.php');
const attribution = read('wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php');
const lead = read('wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php');

const modules = [
  'inc/nvx-marketing-consent.php',
  'inc/nvx-ads-conversion-catalog.php',
  'inc/nvx-hubspot-secure-attribution.php',
  'inc/nvx-gtm-integration.php',
  'inc/nvx-attribution-integration.php',
  'inc/nvx-supabase-relay-queue.php',
  'inc/nvx-lead-captured-relay.php',
  'inc/nvx-google-attribution-relay-auth.php',
];

let previous = -1;
for (const module of modules) {
  const offset = bootstrap.indexOf(`'${module}'`);
  assert.ok(offset > previous, `Canonical transport manifest order broken at ${module}`);
  previous = offset;
}

assert.match(consent, /if \( ! function_exists\( 'cmplz_has_consent' \) \) \{\s*return false;/,
  'Server consent must fail closed without the Complianz API');
assert.doesNotMatch(consent, /\$_COOKIE|\$_POST|\$_GET|\$_REQUEST/,
  'Consent authority must not read client-controlled markers directly');

assert.match(hubspot, /nvx_marketing_consent_granted\(\)/,
  'HubSpot bridge must consume the canonical consent authority');
assert.match(attribution, /nvx_marketing_consent_granted\(\)/,
  'Google attribution relay must consume the canonical consent authority');
assert.match(lead, /nvx_marketing_consent_granted\(\)/,
  'Lead-captured relay must consume the canonical consent authority');

assert.match(googleAuth, /hash_hmac\(\s*'sha256'/,
  'Google attribution transport must remain HMAC signed');
assert.match(googleAuth, /hash_equals\( \$expected, \$signature \)/,
  'Google attribution transport must verify signatures fail closed');
assert.match(googleAuth, /nvx_google_attribution_signing_credential\(\)/,
  'Signing must remain tied to a server-only credential');

// Bootstrap is the sole module loader: lateral loaders are strictly forbidden.
assert.doesNotMatch(hubspot, /require_once\s+\$dependency;|nvx_hubspot_secure_load_dependencies/,
  'HubSpot module must not contain lateral dependency loader; bootstrap is sole module loader');

console.log('PHP_INTEGRATIONS_TRANSPORT=PASS modules=8 consent=server-api-fail-closed signing=hmac ownership=ordered');
