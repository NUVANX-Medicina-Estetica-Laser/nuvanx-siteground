import assert from 'node:assert/strict';
import fs from 'node:fs';

// Resolve from this module so the release contract is independent of the caller's cwd.
const repoRoot = new URL('../../', import.meta.url);
const relayPath = new URL('wp-content/themes/nuvanx-medical/assets/js/nvx-conversion-events.js', repoRoot);
const retiredPublisherPath = new URL('scripts/seo/setup-gtm-conversion-trigger.js', repoRoot);
const seoReadmePath = new URL('scripts/seo/README.md', repoRoot);

assert.equal(fs.existsSync(relayPath), true, 'Conversion relay must exist');
const relay = fs.readFileSync(relayPath, 'utf8');

assert.match(relay, /emit\('generate_lead'/, 'Successful HubSpot submissions must emit the canonical GA4 event');
assert.match(relay, /var signalName = 'nvx_conversion_signal'/, 'The data-layer relay contract must remain available for GA4/GTM routing');
assert.doesNotMatch(relay, /nvx_valoracion_success/, 'Legacy form-success event must not remain available as a direct Ads trigger');
assert.doesNotMatch(relay, /AW-18182220789\//, 'Canonical form measurement must not call the 908 direct Ads tag');
assert.doesNotMatch(relay, /4BC2CKSat8YcEPXX-t1D|86RgCI2dht4cEPXX-t1D/, 'Known legacy 908 form conversion labels must not return to the relay');
assert.match(relay, /AW-18236597403\/qut3CLWflOAcEJvJ8fdD/, 'The separate 820 phone/WhatsApp measurement must remain explicit');

assert.equal(fs.existsSync(retiredPublisherPath), false, 'The retired direct-form GTM publisher must not be present');
const readme = fs.readFileSync(seoReadmePath, 'utf8');
assert.match(readme, /HubSpot successful submit → GA4 generate_lead → Google Ads 908 import/,
  'Documentation must state the canonical ownership path');
assert.doesNotMatch(readme, /node scripts\/seo\/setup-gtm-conversion-trigger\.js/, 'Documentation must not advertise an executable retired publisher');
assert.doesNotMatch(readme, /GTM_CONFIRM_PUBLISH=yes/, 'Documentation must not retain live-publisher execution instructions');

console.log('CONVERSION_OWNERSHIP_CONTRACT=PASS canonical=ga4_generate_lead ads908_direct_form=disabled phone_whatsapp_820=preserved');
