import './test-attribution-contract.mjs';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const managedPage = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php',
  'utf8',
);
const mountGovernance = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-hero-and-forms.php',
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
const runtimeConfig = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-document-governance.php',
  'utf8',
);

// The managed PHP page keeps one deterministic server-side placeholder. The
// final output governor rewrites it before the response reaches the browser.
const managedHosts = managedPage.match(/<div id="nvx-hubspot-native-form"[^>]*>/g) || [];
assert.equal(managedHosts.length, 1, 'Managed valoración page must define exactly one server-side replacement host');
assert.doesNotMatch(
  managedHosts[0],
  /data-(?:form|portal)-id=/,
  'Presentation host must never hardcode the HubSpot account/form identity',
);

assert.match(
  mountGovernance,
  /function nvx_valoracion_native_hubspot_enforce_single_mount\( string \$html \): string/,
  'Final valoración output must remain governed by one canonical output owner',
);
assert.match(
  mountGovernance,
  /id="nvx-valoracion-first-party-form"/,
  'Output governance must rename the legacy browser mount to the first-party owner host',
);
assert.match(
  mountGovernance,
  /data-nvx-first-party-owner="1"/,
  'First-party output owner must be explicitly marked',
);
assert.match(
  mountGovernance,
  /nvx-hubspot-\(\?:lazy\|native\|eager\)/,
  'Output governance must strip browser-native HubSpot ownership attributes',
);
assert.match(
  mountGovernance,
  /nvx_valoracion_remove_divs_by_class\( \$html, 'hs-form-frame' \)/,
  'Output governance must remove declarative HubSpot browser frames from the managed landing',
);
assert.match(
  mountGovernance,
  /nvx_valoracion_remove_divs_by_class\( \$html, 'hbspt-form' \)/,
  'Output governance must remove legacy HubSpot browser mounts from the managed landing',
);
assert.match(
  mountGovernance,
  /return nvx_valoracion_direct_form_markup\(\);/,
  'The canonical landing mount must render the first-party form',
);

const declarativeMounts = mountGovernance.match(/class="hs-form-frame"/g) || [];
assert.equal(
  declarativeMounts.length,
  0,
  'Managed landing governance must define zero declarative HubSpot browser frames',
);

assert.match(directForm, /data-nvx-direct-form/, 'First-party form marker must exist');
assert.doesNotMatch(
  directForm,
  /Disabled on valoracion landing page/,
  'First-party form must remain available on the full valoración landing',
);
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

// Account/form identity remains server-configured for transport and analytics;
// the browser no longer owns the visible form lifecycle on this route.
assert.match(
  runtimeConfig,
  /'hubspotPortalId'\s*=>\s*\(string\) \$hubspot_config\['portal_id'\]/,
  'Runtime configuration must continue to expose the validated HubSpot portal identity',
);
assert.match(
  runtimeConfig,
  /'hubspotFormId'\s*=>\s*\(string\) \$hubspot_config\['form_id'\]/,
  'Runtime configuration must continue to expose the validated HubSpot form identity',
);

console.log('HUBSPOT_SINGLE_MOUNT_STATIC=PASS managed_hosts=1 browser_frames=0 first_party_owner=1 secure_hubspot_transport=1 capture_after_2xx=1 qa_server_owned=1');
