import './test-attribution-contract.mjs';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const managedPage = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php',
  'utf8',
);
const owner = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-first-party-owner.php',
  'utf8',
);
const heroGovernance = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-hero-and-forms.php',
  'utf8',
);
const ctaBootstrap = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-cta-components.php',
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

const managedHosts = managedPage.match(/<div id="nvx-hubspot-native-form"[^>]*>/g) || [];
assert.equal(managedHosts.length, 1, 'Managed valoración page must define exactly one server-side replacement host');
assert.doesNotMatch(
  managedHosts[0],
  /data-(?:form|portal)-id=/,
  'Presentation host must never hardcode the HubSpot account/form identity',
);

assert.match(
  ctaBootstrap,
  /require_once __DIR__ \. '\/nvx-valoracion-first-party-owner\.php';/,
  'First-party owner must load before the hero compatibility layer',
);
assert.match(
  heroGovernance,
  /if \( ! function_exists\( 'nvx_valoracion_native_hubspot_enforce_single_mount' \) \)/,
  'Hero compatibility layer must remain conditional so the explicit owner can replace it cleanly',
);
assert.match(
  owner,
  /function nvx_valoracion_native_hubspot_enforce_single_mount\( string \$html \): string/,
  'Final valoración output must be governed by the explicit first-party owner',
);
assert.match(owner, /id="nvx-valoracion-first-party-form"/, 'Output owner must rename the browser mount');
assert.match(owner, /data-nvx-first-party-owner="1"/, 'First-party output host must identify its owner');
assert.match(
  owner,
  /nvx-hubspot-\(\?:lazy\|native\|eager\)/,
  'Output owner must strip browser-native HubSpot ownership attributes',
);
assert.match(
  owner,
  /nvx_valoracion_remove_divs_by_class\( \$html, 'hs-form-frame' \)/,
  'Output owner must remove declarative HubSpot browser frames',
);
assert.match(
  owner,
  /nvx_valoracion_remove_divs_by_class\( \$html, 'hbspt-form' \)/,
  'Output owner must remove legacy HubSpot browser mounts',
);
assert.match(owner, /return nvx_valoracion_direct_form_markup\(\);/, 'Landing mount must render the first-party form');
assert.match(
  owner,
  /\$replacement = \(int\) \$range\['start'\] === \$first_start \? \$canonical : '';/,
  'Canonical first-party mount must replace the original range in place rather than use a stale offset',
);

const ownerDeclarativeMounts = owner.match(/class="hs-form-frame"/g) || [];
assert.equal(ownerDeclarativeMounts.length, 0, 'First-party owner must define zero HubSpot browser frames');

// Preserve unrelated hero behavior exactly rather than duplicating or deleting it.
assert.match(
  heroGovernance,
  /function nvx_hero_insert_media_figure\( string \$content, string \$figure \): string/,
  'Canonical hero media helper must remain present',
);
assert.match(
  heroGovernance,
  /return nvx_hero_insert_media_figure\( \$content, \$figure \);/,
  'Hero media injection must continue to call its canonical helper',
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

console.log('HUBSPOT_SINGLE_MOUNT_STATIC=PASS managed_hosts=1 browser_frames=0 first_party_owner=1 hero_helper=preserved secure_hubspot_transport=1 capture_after_2xx=1 qa_server_owned=1');
