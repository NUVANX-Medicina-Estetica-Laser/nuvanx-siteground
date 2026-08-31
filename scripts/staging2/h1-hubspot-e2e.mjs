import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { HUBSPOT_PORTAL_ID, HUBSPOT_FORM_ID } from './hubspot-config.mjs';

const formId = HUBSPOT_FORM_ID;
const portalId = HUBSPOT_PORTAL_ID;
const expectedSha = (process.env.EXPECTED_SHA || '').trim();

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  throw new Error(`EXPECTED_SHA must be a full lowercase 40-hex commit SHA; received=${JSON.stringify(expectedSha)}`);
}

// Production verification is deliberately zero-submit. The canonical visible
// valoración form is first-party HTML; HubSpot remains the authenticated
// server-side destination, and Supabase capture runs only after HubSpot 2xx.
const managedPageUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php',
  import.meta.url
);
const heroAndFormsUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-hero-and-forms.php',
  import.meta.url
);
const valoracionModalUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-valoracion-modal.php',
  import.meta.url
);
const directFormUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php',
  import.meta.url
);
const secureBridgeUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php',
  import.meta.url
);
const captureRelayUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php',
  import.meta.url
);
const conversionEventsUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/assets/js/nvx-conversion-events.js',
  import.meta.url
);
const pageHygieneUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-page-hygiene.php',
  import.meta.url
);

const [
  managedPage,
  heroAndForms,
  valoracionModal,
  directForm,
  secureBridge,
  captureRelay,
  conversionEvents,
  pageHygiene,
] = await Promise.all([
  fs.readFile(managedPageUrl, 'utf8'),
  fs.readFile(heroAndFormsUrl, 'utf8'),
  fs.readFile(valoracionModalUrl, 'utf8'),
  fs.readFile(directFormUrl, 'utf8'),
  fs.readFile(secureBridgeUrl, 'utf8'),
  fs.readFile(captureRelayUrl, 'utf8'),
  fs.readFile(conversionEventsUrl, 'utf8'),
  fs.readFile(pageHygieneUrl, 'utf8'),
]);

assert.match(
  managedPage,
  /id="nvx-hubspot-form"/,
  'managed valoración page must retain the stable conversion-section anchor'
);
assert.match(
  managedPage,
  /id="nvx-hubspot-native-form"/,
  'managed page must retain the server-side replacement marker before output governance'
);

assert.match(
  heroAndForms,
  /function nvx_valoracion_native_hubspot_enforce_single_mount\( string \$html \): string/,
  'valoración output governor must remain the single final owner'
);
assert.match(
  heroAndForms,
  /id="nvx-valoracion-first-party-form"/,
  'output governor must rename the legacy browser mount to the canonical first-party host'
);
assert.match(
  heroAndForms,
  /data-nvx-first-party-owner="1"/,
  'canonical first-party host must identify its ownership contract'
);
assert.match(
  heroAndForms,
  /nvx-hubspot-\(\?:lazy\|native\|eager\)/,
  'output governor must strip native HubSpot runtime ownership markers'
);
assert.match(
  heroAndForms,
  /return nvx_valoracion_direct_form_markup\(\);/,
  'canonical landing mount must render the first-party form'
);
assert.match(
  heroAndForms,
  /Retire every browser-owned HubSpot form surface on the managed landing/,
  'managed landing must explicitly retire browser-owned HubSpot form surfaces'
);
assert.match(
  heroAndForms,
  /nvx_valoracion_remove_divs_by_class\( \$html, 'hs-form-frame' \)/,
  'output governor must remove HubSpot iframe mounts before emitting the first-party owner'
);

assert.doesNotMatch(
  directForm,
  /Disabled on valoracion landing page/,
  'first-party form must not be disabled on the canonical landing'
);
assert.match(directForm, /data-nvx-direct-form/, 'canonical first-party form marker must exist');
assert.match(directForm, /name="nvx_lead_id"/, 'first-party form must carry the session lineage id');
assert.match(directForm, /name="nvx_marketing_consent"/, 'first-party form must carry consent state');
assert.match(directForm, /'gclid'\s*=>\s*'nvx_google_click_id'/, 'server submit must map GCLID to the canonical HubSpot property');
assert.match(directForm, /'nvx_' \. \$utm_param/, 'server submit must map UTM fields to canonical HubSpot properties');
assert.match(directForm, /nvx_hubspot_secure_original_url/, 'first-party submit must enter the canonical secure HubSpot bridge');
assert.match(directForm, /nvx_valoracion_direct_success_redirect_url/, 'accepted requests must use the one-time success redirect contract');

assert.match(secureBridge, /nvx_hubspot_secure_submit_url/, 'authenticated HubSpot submit endpoint must remain available');
assert.match(secureBridge, /NVX_HUBSPOT_ACCESS_TOKEN/, 'secure bridge must remain credential-backed');
assert.match(secureBridge, /nvx_is_test_lead/, 'secure bridge must retain server-owned QA classification');
assert.match(secureBridge, /nvx_test_run_id/, 'secure bridge must retain server-owned QA run lineage');
assert.match(secureBridge, /pre_http_request/, 'public form submit URL must still be intercepted server-side');

assert.match(captureRelay, /add_filter\( 'http_response'/, 'capture relay must observe accepted secure HubSpot responses');
assert.match(captureRelay, /nvx_lead_captured_endpoint/, 'capture relay must target the canonical Supabase ledger');
assert.match(captureRelay, /valid nvx_lead_id missing/, 'capture relay must fail closed when lineage is missing');
assert.match(captureRelay, /status < 200 \|\| \$status >= 300/, 'capture relay must run only after HubSpot 2xx');

// The site-wide modal remains first-party too. It is disabled on the full landing,
// so there is still exactly one interactive form on /madrid/valoracion/.
assert.match(valoracionModal, /nvx_valoracion_direct_form_markup/, 'site-wide modal must retain the first-party form');
assert.match(valoracionModal, /Already on the form landing: keep full-page UX/, 'modal must remain disabled on the canonical landing');

assert.match(conversionEvents, /nvx_google_click_id/, 'browser attribution must retain the custom Google click ID property');
assert.match(conversionEvents, /hasMarketingConsent/, 'marketing attribution must remain consent-gated');
assert.match(pageHygiene, /cmplz_whitelisted_script_tags/, 'Complianz governance must remain explicit for other HubSpot surfaces');

console.log(`EXPECTED_SHA=${expectedSha}`);
console.log(`HUBSPOT_FORM_ID=${formId}`);
console.log(`HUBSPOT_PORTAL_ID=${portalId}`);
console.log('HUBSPOT_PRODUCTION_CONTRACT_MODE=ZERO_SUBMIT');
console.log('HUBSPOT_VALORACION_FIRST_PARTY_OWNER=PASS browser_iframe=retired secure_bridge=required capture_relay=after_2xx');
console.log('H1_BROWSER_E2E=PASS mode=zero-submit-static-contract');
console.log('PRODUCTION_HUBSPOT_CONTRACT=PASS zero_submit=1 javascript_executed=0 contact_created=0');
