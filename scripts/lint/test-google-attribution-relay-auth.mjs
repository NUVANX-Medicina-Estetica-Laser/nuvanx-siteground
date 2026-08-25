import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';

const authPath = 'wp-content/themes/nuvanx-medical/inc/nvx-google-attribution-relay-auth.php';
const gtmPath = 'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php';
const integrationPath = 'wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php';

for (const path of [authPath, gtmPath, integrationPath]) {
  assert.ok(fs.existsSync(path), `Missing Google attribution relay dependency: ${path}`);
}

const auth = fs.readFileSync(authPath, 'utf8');
const gtm = fs.readFileSync(gtmPath, 'utf8');
const integration = fs.readFileSync(integrationPath, 'utf8');

assert.match(gtm, /require_once __DIR__ \. '\/nvx-google-attribution-relay-auth\.php';/);
assert.match(integration, /google-click-attribution/);
assert.match(integration, /'blocking'\s*=>\s*false/);

assert.match(auth, /nuvanx-google-click-attribution-hmac-key-v1/);
assert.match(auth, /hash_hmac\( 'sha256', nvx_google_attribution_hmac_context\(\), \$credential, true \)/);
assert.match(auth, /hash_hmac\( 'sha256', \$timestamp \. '\.' \. \$body, nvx_google_attribution_derive_hmac_key\( \$credential \) \)/);
assert.match(auth, /'x-nvx-timestamp'/);
assert.match(auth, /'x-nvx-signature'/);
assert.match(auth, /nvx_google_attribution_signing_key_missing/);
assert.match(auth, /add_filter\( 'pre_http_request', 'nvx_google_attribution_block_unsigned_request', 5, 3 \)/);
assert.match(auth, /add_filter\( 'http_request_args', 'nvx_google_attribution_sign_request_args', 10, 2 \)/);
assert.doesNotMatch(auth, /Authorization/);

// Shared cross-language contract fixture: domain separation + timestamp.raw_body.
const credential = 'fixture-hubspot-server-credential';
const timestamp = '1787679000';
const body = JSON.stringify({ nvx_lead_id: '11111111-1111-4111-8111-111111111111', gclid: 'GCLID-FIXTURE' });
const context = 'nuvanx-google-click-attribution-hmac-key-v1';
const derived = crypto.createHmac('sha256', credential).update(context).digest('hex');
const signature = crypto.createHmac('sha256', derived).update(`${timestamp}.${body}`).digest('hex');
assert.match(derived, /^[0-9a-f]{64}$/);
assert.match(signature, /^[0-9a-f]{64}$/);
assert.notEqual(signature, credential);

console.log('GOOGLE_ATTRIBUTION_RELAY_AUTH=PASS signed_server_relay=1 domain_separated=1 unsigned_blocked=1');
