import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { HUBSPOT_PORTAL_ID, HUBSPOT_FORM_ID } from './hubspot-config.mjs';

const formId = HUBSPOT_FORM_ID;
const portalId = HUBSPOT_PORTAL_ID;
const expectedSha = (process.env.EXPECTED_SHA || '').trim();

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  throw new Error(`EXPECTED_SHA must be a full lowercase 40-hex commit SHA; received=${JSON.stringify(expectedSha)}`);
}

// Historical note: this filename is retained temporarily because production.yml
// still references it. It is intentionally NOT a browser E2E anymore. The prior
// implementation submitted the live HubSpot form and created QA contacts in the
// commercial portal. Production verification must be zero-submit and zero-tracking.
// The workflow verifies the live production SHA immediately before invoking this
// script; this script validates the exact candidate's form/attribution contract
// without executing page JavaScript, granting consent, creating a synthetic GCLID,
// or calling any HubSpot submission endpoint.
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
const conversionEventsUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/assets/js/nvx-conversion-events.js',
  import.meta.url
);
const runtimeGovernanceUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/assets/js/nvx-runtime-governance.js',
  import.meta.url
);
const directFormUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php',
  import.meta.url
);
const pageHygieneUrl = new URL(
  '../../wp-content/themes/nuvanx-medical/inc/nvx-page-hygiene.php',
  import.meta.url
);

const [managedPage, heroAndForms, valoracionModal, conversionEvents, runtimeGovernance, directForm, pageHygiene] = await Promise.all([
  fs.readFile(managedPageUrl, 'utf8'),
  fs.readFile(heroAndFormsUrl, 'utf8'),
  fs.readFile(valoracionModalUrl, 'utf8'),
  fs.readFile(conversionEventsUrl, 'utf8'),
  fs.readFile(runtimeGovernanceUrl, 'utf8'),
  fs.readFile(directFormUrl, 'utf8'),
  fs.readFile(pageHygieneUrl, 'utf8'),
]);

function htmlOpeningTag(source, pattern) {
  const match = source.match(pattern);
  return match ? match[0] : '';
}

function styleDeclaresDisplayNone(tag) {
  const quoted = tag.match(/\bstyle\s*=\s*(["'])([\s\S]*?)\1/i);
  if (quoted) {
    return /(?:^|;)\s*display\s*:\s*none\b/i.test(quoted[2]);
  }
  const unquoted = tag.match(/\bstyle\s*=\s*([^\s>]+)/i);
  if (!unquoted) {
    return false;
  }
  return /display\s*:\s*none\b/i.test(unquoted[1]);
}

assert.match(
  managedPage,
  /id="nvx-hubspot-form"/,
  'managed valoración page must retain the canonical HubSpot section mount'
);
assert.match(
  managedPage,
  /id="nvx-hubspot-native-form"/,
  'managed valoración page must retain the canonical native HubSpot host'
);
assert.match(
  managedPage,
  /data-nvx-hubspot-native="1"/,
  'managed valoración page must identify the canonical HubSpot runtime mount'
);
assert.doesNotMatch(
  managedPage,
  /nvx-hs-lead-form/,
  'managed valoración page must not reintroduce the legacy captured non-HubSpot form'
);
assert.doesNotMatch(
  managedPage.match(/<div id="nvx-hubspot-native-form"[^>]*>/)?.[0] || '',
  /data-form-id="/,
  'managed valoración page host must not contain HubSpot identity (prevents duplicate embeds)'
);
assert.doesNotMatch(
  managedPage.match(/<div id="nvx-hubspot-native-form"[^>]*>/)?.[0] || '',
  /data-portal-id="/,
  'managed valoración page host must not contain HubSpot identity (prevents duplicate embeds)'
);

// Validate HubSpot identity on the canonical child mount in nvx-hero-and-forms.php.
assert.match(
  heroAndForms,
  /data-form-id="/,
  'canonical HubSpot mount must render a HubSpot form ID attribute'
);
assert.match(
  heroAndForms,
  /nvx_hubspot_secure_form_id/,
  'canonical HubSpot mount must resolve the form ID via the canonical secure resolver'
);
assert.match(
  heroAndForms,
  /data-portal-id="/,
  'canonical HubSpot mount must render a HubSpot portal ID attribute'
);
assert.match(
  heroAndForms,
  /nvx_hubspot_secure_portal_id/,
  'canonical HubSpot mount must resolve the portal ID via the canonical secure resolver'
);
assert.match(
  heroAndForms,
  /data-nvx-hubspot-lazy="1"/,
  'canonical HubSpot mount must have the lazy attribute for governance'
);
assert.doesNotMatch(
  heroAndForms,
  /nvx_valoracion_direct_form_markup/,
  'canonical HubSpot landing must not render the first-party fallback beside the iframe'
);
const canonicalFrame = htmlOpeningTag(
  heroAndForms,
  /<div\b[^>]*\bclass=["'][^"']*\bhs-form-frame\b[^"']*["'][^>]*>/i
);
const nativeHost = htmlOpeningTag(
  managedPage,
  /<div\b[^>]*\bid=["']nvx-hubspot-native-form["'][^>]*>/i
);
assert.ok(canonicalFrame, 'canonical HubSpot frame opening tag must exist');
assert.ok(nativeHost, 'canonical HubSpot host opening tag must exist');
assert.equal(
  styleDeclaresDisplayNone(canonicalFrame),
  false,
  'canonical HubSpot frame must not hide the conversion surface with display:none'
);
assert.equal(
  styleDeclaresDisplayNone(nativeHost),
  false,
  'canonical HubSpot host must not hide the conversion surface with display:none'
);

// The dedicated conversion route must not depend exclusively on the shared
// runtime. Its recovery bootstrap triggers the canonical owner first, repairs a
// missing frame if necessary, removes extra frames and only injects a portal
// loader when no existing owner has created one.
assert.match(
  managedPage,
  /function nvx_valoracion_hubspot_bootstrap_markup\(\): string/,
  'managed valoración page must retain the deterministic HubSpot recovery bootstrap'
);
assert.match(
  managedPage,
  /frames\[i\]\.remove\(\)/,
  'recovery bootstrap must enforce a single HubSpot frame inside the canonical host'
);
assert.match(
  managedPage,
  /#nvx-hubspot-forms-runtime,script\[data-nvx-hubspot-canonical=/,
  'recovery bootstrap must reuse the canonical loader when one already exists'
);
assert.match(
  managedPage,
  /script\.dataset\.nvxHubspotCanonical="1"/,
  'recovery loader must identify itself as the single canonical fallback owner'
);

assert.match(
  conversionEvents,
  /nvx_google_click_id/,
  'attribution runtime must retain the custom Google click ID field'
);
assert.match(
  conversionEvents,
  /var forms = attributionConfig\.forms \|\| \{\};/,
  'attribution runtime must resolve the canonical HubSpot form from localized configuration'
);
assert.match(
  conversionEvents,
  /var FORM_ID = String\(forms\.valoracion \|\| ''\)\.toLowerCase\(\);/,
  'attribution runtime must scope Google attribution to forms.valoracion'
);
assert.doesNotMatch(
  conversionEvents,
  new RegExp(formId, 'i'),
  'attribution runtime must not duplicate the canonical HubSpot form ID literal in any letter case'
);
assert.match(
  conversionEvents,
  /normalizedPath\s*===\s*['"]\/madrid\/valoracion['"]/,
  'Google attribution must remain scoped to /madrid/valoracion'
);
assert.match(
  conversionEvents,
  /hasMarketingConsent/,
  'Google attribution must remain consent-gated'
);

assert.match(
  runtimeGovernance,
  /nvx-hubspot-native-form/,
  'runtime governance must retain the canonical HubSpot mount selector'
);
assert.match(
  runtimeGovernance,
  /Formulario de valoración médica/,
  'runtime governance must retain an accessible HubSpot iframe name'
);
assert.match(
  runtimeGovernance,
  /isHubSpotRenderable/,
  'runtime must distinguish a live HubSpot iframe from a Complianz-blocked placeholder'
);
assert.match(
  runtimeGovernance,
  /text\/plain/,
  'runtime must ignore Complianz-inert HubSpot scripts when deciding the embed is loaded'
);
assert.match(
  runtimeGovernance,
  /cmplz_enable_category/,
  'runtime must retry the HubSpot embed after Complianz consent'
);

// The first-party form is retained as the modal fallback, not as a second form
// on the canonical /madrid/valoracion/ conversion surface.
assert.match(
  valoracionModal,
  /nvx_valoracion_direct_form_markup/,
  'site-wide valoración modal must retain the first-party fallback form'
);
assert.match(
  directForm,
  /'id'\s*=>\s*'firstname'/,
  'first-party form identity fields must include firstname'
);
assert.match(
  directForm,
  /name="' \. \$field\['id'\] \. '"/,
  'first-party form must emit the input name from the field id'
);
assert.match(
  directForm,
  /name="phone"/,
  'first-party form must collect a phone'
);
assert.match(
  directForm,
  /name="email"/,
  'first-party form must collect an email'
);
assert.match(
  directForm,
  /nvx_hubspot_secure_original_url/,
  'first-party form must forward leads via the canonical secure resolver URL'
);
assert.match(
  managedPage,
  /function isRenderable\(root\)/,
  'recovery bootstrap must treat a Complianz-blocked iframe as not renderable'
);

assert.match(
  pageHygiene,
  /cmplz_whitelisted_script_tags/,
  'Complianz must retain explicit HubSpot script governance'
);
assert.match(
  pageHygiene,
  /hsforms\.net/,
  'Complianz whitelist must include the HubSpot forms host'
);

console.log(`EXPECTED_SHA=${expectedSha}`);
console.log(`HUBSPOT_FORM_ID=${formId}`);
console.log(`HUBSPOT_PORTAL_ID=${portalId}`);
console.log('HUBSPOT_PRODUCTION_CONTRACT_MODE=ZERO_SUBMIT');
console.log('HUBSPOT_VALORACION_RECOVERY_BOOTSTRAP=PASS single_frame=1 single_loader=1 direct_fallback_surface=modal_only');
console.log('H1_BROWSER_E2E=PASS mode=zero-submit-static-contract');
console.log('PRODUCTION_HUBSPOT_CONTRACT=PASS zero_submit=1 javascript_executed=0 contact_created=0');
