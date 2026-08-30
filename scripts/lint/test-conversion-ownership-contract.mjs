import assert from 'node:assert/strict';
import fs from 'node:fs';

// Resolve from this module so the release contract is independent of the caller's cwd.
const repoRoot = new URL('../../', import.meta.url);
const relayPath = new URL('wp-content/themes/nuvanx-medical/assets/js/nvx-conversion-events.js', repoRoot);
const catalogPath = new URL('wp-content/themes/nuvanx-medical/inc/data/ads-conversion-catalog.json', repoRoot);
const gtmPath = new URL('wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php', repoRoot);
const retiredPublisherPath = new URL('scripts/seo/setup-gtm-conversion-trigger.js', repoRoot);
const seoReadmePath = new URL('scripts/seo/README.md', repoRoot);

assert.equal(fs.existsSync(relayPath), true, 'Conversion relay must exist');
assert.equal(fs.existsSync(catalogPath), true, 'Ads conversion governed mirror must exist');
const relay = fs.readFileSync(relayPath, 'utf8');
const catalog = JSON.parse(fs.readFileSync(catalogPath, 'utf8'));
const gtm = fs.readFileSync(gtmPath, 'utf8');
const googleAds = catalog?.google_ads || {};
const phoneWhatsApp = googleAds?.phone_whatsapp || {};
const phoneWhatsAppSendTo = phoneWhatsApp?.send_to || '';

assert.equal(catalog.schema, 2, 'Ads conversion governed mirror schema must be 2');
assert.equal(googleAds.authority, 'google_ads', 'Google Ads, not the repository, must be declared as the conversion authority');
assert.equal(googleAds.owner, 'marketing-ops', 'The marketing owner must be explicit');
assert.equal(googleAds.account_id, '8201489748', 'The governed mirror must identify the owning Ads account');
assert.equal(phoneWhatsApp.conversion_action_id, '7717851061', 'The governed mirror must identify the live conversion action');
assert.equal(phoneWhatsApp.conversion_action_name, 'Clic en teléfono o WhatsApp', 'The governed mirror must name the live conversion action');
assert.equal(phoneWhatsApp.status_at_verification, 'ENABLED', 'Only an enabled live conversion may be mirrored');
assert.equal(phoneWhatsApp.verification_method, 'google_ads_api', 'Verification must be against Google Ads, not another repository value');
assert.match(phoneWhatsApp.last_live_verified_at || '', /^20\d{2}-\d{2}-\d{2}$/, 'Live verification date must be recorded');
assert.match(phoneWhatsAppSendTo, /^AW-[0-9]{8,12}\/[A-Za-z0-9_-]+$/, 'Governed mirror must expose a valid Ads send_to');
assert.equal(phoneWhatsAppSendTo, 'AW-18236597403/qut3CLWflOAcEJvJ8fdD', 'The currently live 820 phone/WhatsApp conversion must remain mirrored');

assert.match(relay, /emit\('generate_lead'/, 'Successful HubSpot submissions must emit the canonical GA4 event');
assert.match(relay, /var signalName = 'nvx_conversion_signal'/, 'The data-layer relay contract must remain available for GA4/GTM routing');
assert.doesNotMatch(relay, /nvx_valoracion_success/, 'Legacy form-success event must not remain available as a direct Ads trigger');
assert.doesNotMatch(relay, /AW-18182220789\//, 'Canonical form measurement must not call the 908 direct Ads tag');
assert.doesNotMatch(relay, /4BC2CKSat8YcEPXX-t1D|86RgCI2dht4cEPXX-t1D/, 'Known legacy 908 form conversion labels must not return to the relay');
assert.doesNotMatch(relay, /AW-18236597403\/qut3CLWflOAcEJvJ8fdD/, 'Theme JS must not hardcode the 820 send_to');
assert.match(relay, /config\.ads && config\.ads\.phone_whatsapp_send_to/, 'Phone/WhatsApp measurement must read the server-injected governed value');
assert.match(gtm, /nvx_ads_conversion_client_context/, 'GTM context must inject the governed Ads mirror into the browser runtime');
assert.match(gtm, /window\.nvxConversionEvents\.ads=Object\.assign/, 'Server Ads context must be exposed to the browser runtime');

assert.equal(fs.existsSync(retiredPublisherPath), false, 'The retired direct-form GTM publisher must not be present');
const readme = fs.readFileSync(seoReadmePath, 'utf8');
assert.match(readme, /HubSpot successful submit → GA4 generate_lead → downstream Google Ads import/,
  'Documentation must state the stable canonical ownership path without duplicating account-specific configuration');
assert.doesNotMatch(readme, /node scripts\/seo\/setup-gtm-conversion-trigger\.js/, 'Documentation must not advertise an executable retired publisher');
assert.doesNotMatch(readme, /GTM_CONFIRM_PUBLISH=yes/, 'Documentation must not retain live-publisher execution instructions');

console.log('CONVERSION_OWNERSHIP_CONTRACT=PASS authority=google_ads phone_whatsapp_820=live-verified');
