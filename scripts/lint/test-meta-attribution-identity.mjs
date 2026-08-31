import assert from 'node:assert/strict';
import fs from 'node:fs';

const runtimePath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-attribution-contract.js';
const relayPath = 'wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php';
const directPath = 'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php';
const bridgePath = 'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php';
const consentPath = 'wp-content/themes/nuvanx-medical/inc/nvx-marketing-consent.php';

const runtime = fs.readFileSync(runtimePath, 'utf8');
const relay = fs.readFileSync(relayPath, 'utf8');
const direct = fs.readFileSync(directPath, 'utf8');
const bridge = fs.readFileSync(bridgePath, 'utf8');
const consent = fs.readFileSync(consentPath, 'utf8');

assert.match(runtime, /var META_CLICK_KEYS = \['fbclid'\]/);
assert.match(runtime, /cleanFbclid\(urlClickSignal\(url, 'fbclid'\)\)\) return 'paid_social'/);
assert.match(runtime, /if \(clicks\.fbclid\) \{[\s\S]*?clicks\.fbclid = cleanFbclid\(clicks\.fbclid\);[\s\S]*?delete clicks\.fbclid/);
assert.match(runtime, /META_BROWSER_ID_MAX_LENGTH = 512/);
assert.match(runtime, /value\.length > META_BROWSER_ID_MAX_LENGTH/);
assert.match(runtime, /readCookie\('_fbc'\)/);
assert.match(runtime, /readCookie\('_fbp'\)/);
assert.match(runtime, /buildFbcFromFbclid\(touch\.fbclid, now\)/);
assert.doesNotMatch(runtime, /buildFbp|setCookie\([^\n]*_fbp|document\.cookie\s*=\s*[^\n]*_fbp/i);
assert.match(runtime, /\{ fbclid: '', fbc: '', fbp: '' \}/);

assert.match(direct, /array\( 'gclid', 'gbraid', 'wbraid', 'gclsrc', 'fbclid', 'utm_source'/);
assert.match(direct, /'fbclid' === \$param[\s\S]*?\^\[A-Za-z0-9\._~:\+-\]\{1,512\}\$/);
assert.match(direct, /nvx_marketing_consent_granted\(\)/,
  'Direct form must delegate consent to the shared server authority');

assert.match(consent, /function nvx_marketing_consent_granted\(\): bool/);
assert.match(consent, /cmplz_has_consent\( 'marketing' \)/);
assert.match(consent, /\$_COOKIE\['cmplz_marketing'\]/);
assert.doesNotMatch(consent, /\$_POST|nvx_marketing_consent/,
  'Canonical consent authority must never trust browser POST markers');

assert.match(bridge, /require_once __DIR__ \. '\/nvx-marketing-consent\.php';/);
assert.match(bridge, /\$marketing_consent = nvx_marketing_consent_granted\(\);/,
  'Secure HubSpot bridge must use the shared server authority');
assert.doesNotMatch(bridge, /\$marketing_consent\s*=\s*'1' === nvx_hubspot_secure_post_value\( 'nvx_marketing_consent'/,
  'Secure bridge must not use hidden POST consent as authority');

assert.match(relay, /\$marketing_consent = function_exists\( 'nvx_marketing_consent_granted' \) && nvx_marketing_consent_granted\(\);/);
assert.doesNotMatch(relay, /'1' === nvx_hubspot_secure_post_value\( 'nvx_marketing_consent'/);
assert.match(relay, /function nvx_lead_captured_meta_identity\( bool \$marketing_consent \): array/);
assert.match(relay, /if \( ! \$marketing_consent \) \{\s*return array\(\);/);
assert.match(relay, /strlen\( \$fbclid \) <= 512/);
assert.match(relay, /strlen\( \$value \) <= 512/);
assert.match(relay, /\$_COOKIE\[ \$cookie_name \]/);
assert.match(relay, /foreach \( nvx_lead_captured_meta_identity\( true \) as \$key => \$value \)/);
assert.doesNotMatch(relay, /nvx_meta_fbc|nvx_meta_fbp/);

console.log('META_ATTRIBUTION_IDENTITY=PASS consent=single-server-owner fbclid=validated fbc=bounded fbp=real-only nojs=preserved');
