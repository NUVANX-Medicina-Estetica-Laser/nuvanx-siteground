import assert from 'node:assert/strict';
import fs from 'node:fs';

const relayPath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-conversion-events.js';
const retiredPublisherPath = 'scripts/seo/setup-gtm-conversion-trigger.js';
const seoReadmePath = 'scripts/seo/README.md';

assert.equal(fs.existsSync(relayPath), true, 'Conversion relay must exist');
const relay = fs.readFileSync(relayPath, 'utf8');

assert.match(relay, /emit\('generate_lead'/, 'Successful HubSpot submissions must emit the canonical GA4 event');
assert.match(relay, /var signalName = 'nvx_conversion_signal'/, 'The data-layer relay contract must remain available for GA4/GTM routing');
assert.doesNotMatch(relay, /nvx_valoracion_success/, 'Legacy form-success event must not remain available as a direct Ads trigger');
assert.doesNotMatch(relay, /AW-18182220789\//, 'Canonical form measurement must not call the 908 direct Ads tag');
assert.match(relay, /AW-18236597403\/qut3CLWflOAcEJvJ8fdD/, 'The separate 820 phone/WhatsApp measurement must remain explicit');

assert.equal(fs.existsSync(retiredPublisherPath), false, 'The retired direct-form GTM publisher must not be present');
const readme = fs.readFileSync(seoReadmePath, 'utf8');
assert.match(readme, /HubSpot successful submit → GA4 generate_lead → Google Ads 908 import/,
  'Documentation must state the canonical ownership path');
assert.doesNotMatch(readme, /node scripts\/seo\/setup-gtm-conversion-trigger\\.js/, 'Documentation must not advertise an executable retired publisher');

console.log('CONVERSION_OWNERSHIP_CONTRACT=PASS canonical=ga4_generate_lead ads908_direct_form=disabled phone_whatsapp_820=preserved');
