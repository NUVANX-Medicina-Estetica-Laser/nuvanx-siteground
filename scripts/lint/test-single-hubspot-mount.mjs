import './test-attribution-contract.mjs';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const managedPage = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php',
  'utf8',
);
const heroGovernance = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-hero-and-forms.php',
  'utf8',
);
const ctaComponents = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-cta-components.php',
  'utf8',
);
const modal = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-modal.php',
  'utf8',
);
const runtime = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/js/nvx-runtime-governance.js',
  'utf8',
);
const directForm = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php',
  'utf8',
);
const secureBridge = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php',
  'utf8',
);
const captureRelay = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-lead-captured-relay.php',
  'utf8',
);

assert.match(managedPage, /id="nvx-hubspot-form"/, 'Managed valoración page must retain its stable conversion-section anchor');
assert.match(managedPage, /id="nvx-valoracion-first-party-form"/, 'Managed valoración page must render the canonical first-party owner directly');
assert.match(managedPage, /data-nvx-first-party-owner="1"/, 'Managed first-party owner must be explicit');
assert.match(managedPage, /nvx_valoracion_direct_form_markup\(\)/, 'Managed landing must render the canonical direct form');
assert.doesNotMatch(managedPage, /nvx-hubspot-native-form/, 'Retired HubSpot browser host must not remain in managed source');
assert.doesNotMatch(managedPage, /hs-form-frame/, 'Managed source must not define HubSpot browser frames');
assert.doesNotMatch(managedPage, /forms\/embed\//, 'Managed source must not define HubSpot form embed loaders');
assert.doesNotMatch(managedPage, /nvx_valoracion_hubspot_bootstrap_markup/, 'Retired inline HubSpot bootstrap must be removed');

assert.doesNotMatch(ctaComponents, /nvx-valoracion-first-party-owner\.php/, 'CTA components must not load a compatibility owner module');
assert.match(heroGovernance, /function nvx_hero_insert_media_figure\( string \$content, string \$figure \): string/, 'Canonical hero media helper must remain present');
assert.match(heroGovernance, /return nvx_hero_insert_media_figure\( \$content, \$figure \);/, 'Hero media injection must continue to call its canonical helper');
assert.doesNotMatch(heroGovernance, /nvx_valoracion_native_hubspot_/, 'Hero governance must not retain retired HubSpot mount functions');
assert.doesNotMatch(heroGovernance, /hs-form-frame/, 'Hero governance must not retain browser form embeds');

assert.match(modal, /id="nvx-valoracion-modal-form"/, 'Site-wide modal must retain one form owner');
assert.match(modal, /data-nvx-first-party-owner="1"/, 'Modal owner must be explicitly first-party');
assert.match(modal, /nvx_valoracion_direct_form_markup\(\)/, 'Modal must render the canonical direct form');
assert.doesNotMatch(modal, /hs-form-frame/, 'Modal must define zero HubSpot browser frames');
assert.doesNotMatch(modal, /forms\/embed\//, 'Modal must define zero HubSpot form embed loaders');
assert.match(modal, /'hubspotPortalId'\s*=>\s*\$portal_id/, 'Browser config may expose only the public portal ID for consented analytics');
assert.doesNotMatch(modal, /hubspotFormId|hubspotRegion/, 'Browser modal config must not expose HubSpot form identity or frame region');
assert.match(runtime, /js\.hs-scripts\.com/, 'Consented global HubSpot analytics loader must remain available');
assert.doesNotMatch(runtime, /forms\/embed\/|forms\/v2\.js|hs-form-frame|hbspt\.forms/, 'Runtime must not contain a browser HubSpot forms engine');

assert.match(directForm, /data-nvx-direct-form/, 'First-party form marker must exist');
assert.doesNotMatch(directForm, /Disabled on valoracion landing page/, 'First-party form must remain available on the full valoración landing');
assert.match(directForm, /name="nvx_lead_id"/, 'First-party form must carry the browser session lineage ID');
assert.match(directForm, /name="nvx_marketing_consent"/, 'First-party form must carry explicit marketing consent state');
assert.match(directForm, /'gclid'\s*=>\s*'nvx_google_click_id'/, 'Server submit must map GCLID into the governed HubSpot property');
assert.match(directForm, /nvx_hubspot_secure_original_url/, 'First-party submit must enter the canonical secure HubSpot bridge');

assert.match(secureBridge, /pre_http_request/, 'HubSpot public submit requests must still be intercepted server-side');
assert.match(secureBridge, /NVX_HUBSPOT_ACCESS_TOKEN/, 'Authenticated HubSpot transport must remain credential-backed');
assert.match(secureBridge, /nvx_is_test_lead/, 'QA classification must remain server-owned');
assert.match(secureBridge, /nvx_test_run_id/, 'QA run lineage must remain server-owned');
assert.match(captureRelay, /add_filter\( 'http_response'/, 'Durable capture relay must observe accepted secure HubSpot responses');
assert.match(captureRelay, /status < 200 \|\| \$status >= 300/, 'Supabase capture must be suppressed unless HubSpot returned 2xx');
assert.match(captureRelay, /valid nvx_lead_id missing/, 'Durable relay must fail closed without canonical lineage');

console.log('HUBSPOT_SINGLE_MOUNT_STATIC=PASS landing_first_party=1 modal_first_party=1 browser_frames=0 embeds=0 analytics_portal_only=1 hero_helper=preserved secure_hubspot_transport=1 capture_after_2xx=1 qa_server_owned=1');
