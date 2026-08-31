import assert from 'node:assert/strict';
import fs from 'node:fs';

const runtimePath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-attribution-contract.js';
const relayPath = 'wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php';
const directPath = 'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php';

const runtime = fs.readFileSync(runtimePath, 'utf8');
const relay = fs.readFileSync(relayPath, 'utf8');
const direct = fs.readFileSync(directPath, 'utf8');

assert.match(runtime, /var META_CLICK_KEYS = \['fbclid'\]/,
  'Meta click identity must remain separate from the canonical Google CLICK_KEYS contract');
assert.match(runtime, /cleanFbclid\(urlClickSignal\(url, 'fbclid'\)\)\) return 'paid_social'/,
  'A bare fbclid may classify as paid social only after format validation');
assert.match(runtime, /if \(clicks\.fbclid\) \{[\s\S]*?clicks\.fbclid = cleanFbclid\(clicks\.fbclid\);[\s\S]*?delete clicks\.fbclid/,
  'Malformed Meta click evidence must be removed before source inference and touch storage');
assert.match(runtime, /if \(urlClickSignal\(url, 'gclid'\) \|\| urlClickSignal\(url, 'gbraid'\) \|\| urlClickSignal\(url, 'wbraid'\)\) return 'paid_search'/,
  'Google click identifiers must classify as paid search without requiring UTM decoration');
assert.match(runtime, /META_BROWSER_ID_MAX_LENGTH = 512/,
  'The complete Meta browser identifier must be bounded to 512 characters');
assert.match(runtime, /value\.length > META_BROWSER_ID_MAX_LENGTH/,
  'FBC and FBP must be rejected when the complete identifier exceeds the canonical bound');
assert.match(runtime, /readCookie\('_fbc'\)/,
  'FBC must prefer the real consented Meta cookie when present');
assert.match(runtime, /readCookie\('_fbp'\)/,
  'FBP must be read only from the real consented Meta cookie');
assert.match(runtime, /buildFbcFromFbclid\(touch\.fbclid, now\)/,
  'FBC may be deterministically derived from real fbclid evidence and capture time');
assert.doesNotMatch(runtime, /buildFbp|setCookie\([^\n]*_fbp|document\.cookie\s*=\s*[^\n]*_fbp/i,
  'FBP must never be fabricated or written by NUVANX attribution code');
assert.match(runtime, /function syncDirectFormMetaIdentity\(\)/,
  'First-party form must receive the consented Meta lineage for server relay');
assert.match(runtime, /\{ fbclid: '', fbc: '', fbp: '' \}/,
  'Meta lineage hidden fields must clear when marketing consent is absent or revoked');

assert.match(direct, /array\( 'gclid', 'gbraid', 'wbraid', 'gclsrc', 'fbclid', 'utm_source'/,
  'Server-rendered no-JS form must preserve fbclid alongside Google click identifiers');
assert.match(direct, /'fbclid' === \$param[\s\S]*?\^\[A-Za-z0-9\._~:\+-\]\{1,512\}\$/,
  'Server-rendered fbclid must be validated and bounded before entering the first-party POST');

assert.match(relay, /function nvx_lead_captured_server_marketing_consent\(\): bool/,
  'Relay must have one authoritative server-side consent owner');
assert.match(relay, /nvx_valoracion_has_marketing_consent\(\)/,
  'Relay marketing consent must be re-evaluated from the server-verifiable Complianz contract');
assert.match(relay, /if \( ! nvx_lead_captured_server_marketing_consent\(\)/,
  'Meta cookies and POST values must not be read when authoritative marketing consent is denied');
assert.match(relay, /\$marketing_consent = nvx_lead_captured_server_marketing_consent\(\);/,
  'Canonical capture consent must not trust the browser nvx_marketing_consent marker');
assert.doesNotMatch(relay, /'1' === nvx_hubspot_secure_post_value\( 'nvx_marketing_consent'/,
  'Relay must not use raw browser POST as the marketing-consent authority');
assert.match(relay, /function nvx_lead_captured_meta_identity\(\): array/,
  'Server relay must validate Meta browser identity before including it in Supabase capture payloads');
assert.match(relay, /nvx_hubspot_secure_post_value\( 'fbclid', 512 \)/,
  'Relay must accept fbclid only from the bounded first-party request');
assert.match(relay, /nvx_hubspot_secure_post_value\( \$key, 512 \)/,
  'Relay must bound the complete posted FBC/FBP value before validation');
assert.match(relay, /strlen\( \$value \) <= 512/,
  'Cookie fallback FBC/FBP must also satisfy the full 512-character bound');
assert.match(relay, /\$_COOKIE\[ \$cookie_name \]/,
  'Relay may fall back to real first-party request cookies for FBC/FBP only after server consent');
assert.match(relay, /if \( 'nvx_conversion_' === \$prefix \)/,
  'Meta browser identity belongs to the consented conversion attribution object');
assert.match(relay, /foreach \( nvx_lead_captured_meta_identity\(\) as \$key => \$value \)/,
  'Validated Meta identity must be merged into conversion attribution exactly once');
assert.doesNotMatch(relay, /nvx_meta_fbc|nvx_meta_fbp/,
  'FBC/FBP must not create duplicate custom HubSpot transport properties');

console.log('META_ATTRIBUTION_IDENTITY=PASS contract=server-consent fbclid=validated fbc=bounded fbp=real-cookie-only nojs=preserved');
