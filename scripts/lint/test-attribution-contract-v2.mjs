import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import vm from 'node:vm';

const runtimePath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-attribution-contract.js';
const relayPath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-conversion-events.js';
const directPath = 'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php';
const gtmPath = 'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php';
const bridgePath = 'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php';
const provisionerPath = 'scripts/ci/provision-hubspot-attribution-contract.sh';

const managedV2 = [
  'nvx_lead_id',
  'nvx_is_test_lead',
  'nvx_test_run_id',
  'nvx_first_channel',
  'nvx_first_source',
  'nvx_first_medium',
  'nvx_first_campaign_id',
  'nvx_first_referrer_domain',
  'nvx_first_landing_url',
  'nvx_first_timestamp',
  'nvx_conversion_channel',
  'nvx_conversion_source',
  'nvx_conversion_medium',
  'nvx_conversion_campaign_id',
  'nvx_conversion_landing_url',
  'nvx_conversion_timestamp',
];

if (fs.existsSync(provisionerPath)) {
  const bashLint = spawnSync('bash', ['-n', provisionerPath], { encoding: 'utf8' });
  assert.equal(
    bashLint.status,
    0,
    `HubSpot attribution provisioner must pass bash -n: ${bashLint.stderr || bashLint.stdout || 'unknown error'}`,
  );
  const provisioner = fs.readFileSync(provisionerPath, 'utf8');
  assert.match(provisioner, /managed_properties=\(/,
    'Schema v2 must distinguish provisioner-owned properties from pre-existing attribution properties');
  assert.match(provisioner, /required_existing_properties=\(/,
    'Existing UTM/click metadata must remain fail-closed drift dependencies');
  assert.match(provisioner, /property_type\[nvx_is_test_lead\]='bool'/,
    'QA gate must be a native HubSpot boolean property');
  assert.match(provisioner, /property_field_type\[nvx_is_test_lead\]='booleancheckbox'/,
    'QA gate property schema must use HubSpot booleancheckbox semantics');
  assert.match(provisioner, /value:\s*"true"/,
    'HubSpot boolean property creation must declare the required true option');
  assert.match(provisioner, /value:\s*"false"/,
    'HubSpot boolean property creation must declare the required false option');
  assert.match(provisioner, /options_ok/,
    'Schema verification must validate boolean options instead of type alone');
  assert.doesNotMatch(
    provisioner,
    /local name="\$1"[^\n]*out="\$work\/property-\$\{name\}\.json"/,
    'set -u safe functions must not expand a local variable in the same declaration that assigns it',
  );
  assert.match(provisioner, /local name="\$1" expected_type="\$2" expected_field_type="\$3"\n\s*local out="\$work\/property-\$\{name\}\.json"/,
    'check_property must assign name before deriving the response path');
  assert.match(provisioner, /check_existing_string_property\(\) \{\n\s*local name="\$1"\n\s*local out="\$work\/property-\$\{name\}\.json"/,
    'existing-property check must assign name before deriving the response path');
  assert.match(provisioner, /form_field_type='single_line_text'/,
    'Forms v3 text fields must use the single_line_text field type id');
  assert.match(provisioner, /form_field_type='single_checkbox'/,
    'Forms v3 boolean fields must use the single_checkbox field type id');
  assert.match(provisioner, /--arg fieldType "\$form_field_type"/,
    'Form patch must use the Forms v3 field type mapping, not CRM property fieldType values');
  assert.match(provisioner, /FORM_MAX_FIELDS_PER_GROUP=3/,
    'Forms v3 writer must codify the live maximum of three fields per field group');
  assert.match(provisioner, /normalize_form_groups_for_write\(\)/,
    'Provisioner must normalize legacy oversized field groups before writing Forms v3');
  assert.match(provisioner, /\(\$group \+ \{fields: \[\$field\]\}\)/,
    'Oversized legacy groups must preserve each existing field object verbatim while splitting layout');
  assert.match(provisioner, /verify_visible_form_baseline\(\)/,
    'Form normalization must protect the four visible required identity fields');
  assert.match(provisioner, /range\(0; \(\$fields \| length\); \$max\)/,
    'New hidden fields must be chunked into write-valid groups instead of one oversized default group');
  assert.match(provisioner, /HUBSPOT_FORM_GROUP_CONTRACT=FAIL/,
    '--check must fail closed on a legacy group that Forms v3 would refuse to write');
  assert.match(provisioner, /HUBSPOT_FORM_GROUP_CONTRACT=PASS/,
    'Post-apply verification must prove the canonical form is write-valid');
  assert.match(provisioner, /verify_required_hidden_form_fields\(\)/,
    'Post-apply verification must enforce exactly one hidden optional field per attribution property');
  assert.match(provisioner, /HUBSPOT_FORM_HIDDEN_FIELD_CONTRACT=PASS/,
    'Successful reconciliation must prove hidden attribution fields are unique and semantically typed');
  assert.match(provisioner, /count=\$count hidden_count=\$hidden_count required_count=\$required_count/,
    'Schema verification must report duplicate, visibility and required-state drift precisely');
  assert.match(provisioner, /expected_form_field_type\(\)/,
    'Schema verification must distinguish the native QA checkbox from string metadata fields');
  assert.match(provisioner, /NUVANX_CONFIRM:-.*yes/,
    'HubSpot mutation must continue requiring explicit NUVANX_CONFIRM=yes');
  assert.match(provisioner, /HUBSPOT_MANAGED_PROPERTY_CONTRACT=FAIL missing=/,
    '--check must fail explicitly when managed schema is absent');
  assert.match(provisioner, /HUBSPOT_ATTRIBUTION_CONTRACT=PASS .*schema=v2/,
    'Successful reconciliation must identify schema v2');
  assert.doesNotMatch(provisioner, /\bhs_google_click_id\b/,
    'Native HubSpot Google click property must remain opportunistic, not a provisioning dependency');

  for (const name of managedV2) {
    assert.match(provisioner, new RegExp(`\\b${name}\\b`), `Schema v2 provisioner must own ${name}`);
  }
  console.log(`HUBSPOT_ATTRIBUTION_PROVISIONER_SYNTAX=PASS schema=v2 managed=${managedV2.length} bool_options=1 nounset_safe=1 forms_v3_types=1 form_group_normalization=1`);
}

function memoryStorage() {
  const values = new Map();
  return {
    getItem: (key) => values.has(String(key)) ? values.get(String(key)) : null,
    setItem: (key, value) => values.set(String(key), String(value)),
    removeItem: (key) => values.delete(String(key)),
  };
}

function executeRuntime(runtimeSource, {
  consent,
  href,
  referrer,
  qa = { is_test_lead: false, test_run_id: '' },
  localStorage: providedLocalStorage = null,
  sessionStorage: providedSessionStorage = null,
}) {
  const localStorage = providedLocalStorage || memoryStorage();
  const sessionStorage = providedSessionStorage || memoryStorage();
  const location = new URL(href);
  const window = {
    nvxConversionEvents: {
      forms: { valoracion: '5042522a-0bc5-4381-ac3e-5aee8649b69c' },
      qa,
    },
    location: {
      href: location.href,
      hostname: location.hostname,
      pathname: location.pathname,
      search: location.search,
    },
    localStorage,
    sessionStorage,
    wp_has_consent: () => consent,
    crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
    addEventListener: () => {},
  };
  const document = {
    referrer,
    readyState: 'complete',
    querySelector: () => null,
    createElement: () => ({ type: '', name: '', value: '' }),
    addEventListener: () => {},
  };
  const context = vm.createContext({
    window, document, URL, URLSearchParams, Date, Uint8Array, Array, Object, Set,
    Boolean, String, Number, JSON, RegExp, Math, console,
  });

  if (!runtimeSource || typeof runtimeSource !== 'string') {
    throw new Error('Invalid runtime source: must be a non-empty string');
  }
  if (runtimeSource.length > 1000000) {
    throw new Error('Runtime source too large for safe execution');
  }

  vm.runInContext(runtimeSource, context, { filename: runtimePath }); // NOSONAR - safe internal test sandbox
  return { window, document, localStorage, sessionStorage };
}

if (!fs.existsSync(runtimePath)) {
  console.log('ATTRIBUTION_CONTRACT=SKIP runtime_absent=1');
} else {
  assert.equal(fs.existsSync(bridgePath), true, 'Authenticated HubSpot attribution bridge must exist with Runtime Contract v2');
  assert.equal(fs.existsSync(relayPath), true, 'Google attribution relay must exist with Runtime Contract v2');

  const runtime = fs.readFileSync(runtimePath, 'utf8');
  const relay = fs.readFileSync(relayPath, 'utf8');
  const direct = fs.readFileSync(directPath, 'utf8');
  const gtm = fs.readFileSync(gtmPath, 'utf8');
  const bridge = fs.readFileSync(bridgePath, 'utf8');

  assert.match(runtime, /var UTM_KEYS = \['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'\]/,
    'Runtime must own the complete five-field UTM contract');
  assert.match(runtime, /var CLICK_KEYS = \['gclid', 'gbraid', 'wbraid', 'gclsrc'\]/,
    'Runtime must own the canonical Google click-id set');
  assert.match(runtime, /var ATTR_TTL_MS = 90 \* 24 \* 60 \* 60 \* 1000/,
    'Consented attribution storage must retain the documented 90-day TTL');
  assert.match(runtime, /var FIRST_TOUCH_KEY = 'nvx_first_touch'/,
    'Runtime must persist a distinct first-touch snapshot');
  assert.match(runtime, /var CONVERSION_TOUCH_KEY = 'nvx_conversion_touch'/,
    'Runtime must persist a distinct conversion-touch snapshot');
  assert.match(runtime, /var LEAD_SESSION_KEY = 'nvx_lead_id'/,
    'Lead lineage must be session-scoped');
  assert.match(runtime, /function isUuidV4\(/,
    'Runtime must validate stored lead lineage as UUID v4');
  assert.match(runtime, /function createUuidV4\(/,
    'Runtime must create UUID v4 lineage even when randomUUID is unavailable');
  assert.doesNotMatch(runtime, /['"]lead_[^'"]*/,
    'Runtime must not fall back to non-UUID lead identifiers');
  assert.match(runtime, /nvx_is_test_lead:\s*qa\.is_test_lead === true/,
    'Embed QA marker must originate from server-provided context');
  assert.match(runtime, /key === 'nvx_is_test_lead' \? Boolean\(rawValue\)/,
    'HubSpot V4 QA checkbox must receive a boolean');
  assert.match(runtime, /if \(!available\.has\(fieldName\)\) return;/,
    'Missing HubSpot fields must be skipped inside the forEach callback rather than aborting payload construction');
  assert.match(runtime, /utm_source:\s*conversion\.source \|\| first\.source/,
    'Generic UTM source must represent conversion touch, then fall back to first touch');
  assert.match(runtime, /gclid:\s*conversion\.gclid \|\| first\.gclid/,
    'Generic GCLID must represent conversion touch, then fall back to first touch');

  for (const name of managedV2) {
    assert.match(runtime, new RegExp(`\\b${name}\\b`), `Runtime must represent ${name}`);
  }

  assert.match(gtm, /function nvx_attribution_qa_context\(\): array/,
    'WordPress must own deterministic QA identity server-side');
  assert.match(gtm, /nvx_environment_is_staging2\(\)/,
    'Staging2 must be the only automatic test-lead environment');
  assert.match(gtm, /window\.nvxConversionEvents\.qa=Object\.assign/,
    'Server QA context must be exposed to the browser runtime');
  assert.match(gtm, /require_once __DIR__ \. '\/nvx-hubspot-secure-attribution\.php'/,
    'Authenticated HubSpot bridge must be loaded from the analytics integration owner');

  assert.match(direct, /name=\\?"nvx_lead_id\\?"/,
    'Direct form must carry the browser lineage UUID');
  assert.match(direct, /name=\\?"nvx_marketing_consent\\?"/,
    'Direct form must carry an explicit marketing-consent marker separate from processing consent');
  assert.match(direct, /function nvx_valoracion_is_uuid_v4\(/,
    'Direct form must validate browser lineage UUID v4');
  assert.match(direct, /\$_POST\['nvx_lead_id'\]/,
    'Direct form must prefer the browser contract lineage value');
  assert.match(direct, /wp_generate_uuid4\(\)/,
    'No-JS direct form submissions must receive a fresh server UUID v4');
  assert.doesNotMatch(direct, /get_transient\(\s*'nvx_valoracion_lead_id'/,
    'Lead lineage must never use a site-global transient');
  assert.match(direct, /function nvx_valoracion_has_marketing_consent\(\): bool/,
    'Direct form must separate marketing attribution consent from lead processing consent');
  assert.match(direct, /if \( \$marketing_consent \) \{/,
    'Marketing fields must only be appended when marketing consent exists');
  assert.match(direct, /if \( \$marketing_consent && isset\( \$_COOKIE\['hubspotutk'\] \) \)/,
    'HubSpot tracking cookie must be forwarded only with marketing consent');

  assert.match(bridge, /https:\/\/api\.hsforms\.com\/submissions\/v3\/integration\/submit\//,
    'Bridge must intercept the exact api.hsforms.com public transport used by the direct form');
  assert.match(bridge, /https:\/\/api\.hsforms\.com\/submissions\/v3\/integration\/secure\/submit\//,
    'Secure bridge must use the authenticated Forms API endpoint');
  assert.match(bridge, /function nvx_hubspot_secure_filter_fields\(/,
    'Bridge must filter server-owned and consent-gated fields explicitly');
  assert.match(bridge, /function nvx_hubspot_secure_server_owned_fields\(\): array/,
    'Bridge must define the privileged QA field set');
  const serverOwnedMatch = bridge.match(/function nvx_hubspot_secure_server_owned_fields\(\): array \{([\s\S]*?)\n\}/);
  assert.ok(serverOwnedMatch, 'Server-owned QA field function must be parseable by the gate');
  assert.match(serverOwnedMatch[1], /'nvx_is_test_lead'/,
    'QA marker must be server-owned');
  assert.match(serverOwnedMatch[1], /'nvx_test_run_id'/,
    'QA run id must be server-owned');
  assert.doesNotMatch(serverOwnedMatch[1], /'nvx_lead_id'/,
    'First-party lead lineage must not be destroyed as a privileged QA field');
  assert.match(bridge, /'nvx_lead_id' === \$name[\s\S]*?nvx_hubspot_secure_is_uuid_v4/,
    'Bridge must preserve only a validated UUID v4 lead lineage');
  assert.match(bridge, /\$marketing_consent = nvx_marketing_consent_granted\(\);/,
    'Bridge must read marketing consent from the shared server authority');
  assert.doesNotMatch(bridge, /\$marketing_consent\s*=\s*'1' === nvx_hubspot_secure_post_value\( 'nvx_marketing_consent'/,
    'Bridge must not use the hidden POST consent field as authority');
  assert.doesNotMatch(bridge, /nvx_no_consent|Marketing attribution requires explicit consent/,
    'Missing marketing consent must not block first-party lead creation');
  assert.match(bridge, /nvx_hubspot_secure_filter_fields\( \$fields, \$marketing_consent \)/,
    'Bridge must apply consent-aware field filtering');
  assert.match(bridge, /nvx_hubspot_secure_append_qa\( \$fields \)/,
    'QA identity must be rebuilt from the server environment');
  assert.doesNotMatch(bridge, /nvx_hubspot_secure_post_value\( 'nvx_is_test_lead'/,
    'Browser POST data must never enable test-lead mode');
  assert.match(bridge, /nvx_environment_is_staging2\(\) && ! nvx_hubspot_secure_payload_is_staging_qa\( \$payload \)/,
    'Staging2 must fail closed unless the rebuilt payload is server-owned QA');
  assert.doesNotMatch(bridge, /function nvx_hubspot_secure_allow_staging_qa_outbound/,
    'The unreachable two-stage staging exception must not return');
  assert.doesNotMatch(bridge, /skipValidation/,
    'Deprecated Forms API skipValidation must not be used');
  assert.doesNotMatch(bridge, /pat-eu1-[A-Za-z0-9-]{20,}/,
    'No HubSpot credential may be hardcoded into source');
  const securePostCalls = bridge.match(/wp_remote_post\(/g) || [];
  assert.equal(securePostCalls.length, 1,
    'Secure bridge must perform exactly one authenticated HubSpot network POST');
  const appendQaPosition = bridge.indexOf('nvx_hubspot_secure_append_qa( $fields )');
  const stagingGuardPosition = bridge.indexOf("nvx_environment_is_staging2() && ! nvx_hubspot_secure_payload_is_staging_qa( $payload )");
  const networkPosition = bridge.indexOf('return wp_remote_post(');
  assert.ok(appendQaPosition >= 0 && stagingGuardPosition > appendQaPosition && networkPosition > stagingGuardPosition,
    'Staging QA validation must run after server QA reconstruction and before network transport');

  assert.match(relay, /function getNvxLeadId\(\)/,
    'Relay must obtain the canonical browser lineage UUID');
  assert.match(relay, /nvx_lead_id:\s*getNvxLeadId\(\) \|\| null/,
    'HubSpot V4 relay must send nvx_lead_id to Supabase');
  assert.match(relay, /nvxLeadId:\s*getNvxLeadId\(\)/,
    'Legacy relay must capture the same lineage before submit');
  assert.match(relay, /nvx_lead_id:\s*pending\.nvxLeadId \|\| getNvxLeadId\(\) \|\| null/,
    'Legacy success relay must send the captured lineage to Supabase');
  assert.match(relay, /params\.get\('gclid'\) \|\| conversion\.gclid \|\| first\.gclid/,
    'Relay must retain GCLID after internal navigation by falling back to stored attribution');

  const organic = executeRuntime(runtime, {
    consent: true,
    href: 'https://nuvanx.com/endolift/?foo=bar',
    referrer: 'https://www.google.com/search?q=endolift',
  });
  const contract = organic.window.NUVANXAttributionContract;
  const first = contract.getFirstTouch();
  assert.equal(first.channel, 'organic_search');
  assert.equal(first.source, 'google');
  assert.equal(first.medium, 'organic');
  assert.equal(first.landing_url, 'https://nuvanx.com/endolift/');
  assert.equal(first.referrer_domain, 'www.google.com');
  assert.equal(contract.getLeadId(), '11111111-1111-4111-8111-111111111111');

  organic.window.location.href = 'https://nuvanx.com/madrid/valoracion/?utm_source=google&utm_medium=cpc&utm_campaign=brand&utm_content=cta&utm_term=endolift&gclid=GCLID123';
  organic.window.location.hostname = 'nuvanx.com';
  organic.window.location.pathname = '/madrid/valoracion/';
  organic.window.location.search = '?utm_source=google&utm_medium=cpc&utm_campaign=brand&utm_content=cta&utm_term=endolift&gclid=GCLID123';
  organic.document.referrer = 'https://www.google.com/';
  const paidConversion = contract.getConversionTouch();
  assert.equal(contract.getFirstTouch().channel, 'organic_search', 'paid return must not overwrite first touch');
  assert.equal(paidConversion.channel, 'paid_search');
  assert.equal(paidConversion.source, 'google');
  assert.equal(paidConversion.gclid, 'GCLID123');
  assert.equal(paidConversion.campaign_id, 'brand');
  assert.equal(paidConversion.utm_content, 'cta');
  assert.equal(paidConversion.utm_term, 'endolift');

  const payload = contract.buildFormPayload(new Set([
    'nvx_lead_id',
    'nvx_is_test_lead',
    'nvx_test_run_id',
    'nvx_utm_source',
    'nvx_utm_medium',
    'nvx_utm_campaign',
    'nvx_utm_content',
    'nvx_utm_term',
    'nvx_google_click_id',
    'nvx_first_source',
    'nvx_conversion_source',
  ]));
  assert.equal(payload.nvx_lead_id, '11111111-1111-4111-8111-111111111111');
  assert.equal(payload.nvx_utm_source, 'google');
  assert.equal(payload.nvx_utm_medium, 'cpc');
  assert.equal(payload.nvx_utm_campaign, 'brand');
  assert.equal(payload.nvx_utm_content, 'cta');
  assert.equal(payload.nvx_utm_term, 'endolift');
  assert.equal(payload.nvx_google_click_id, 'GCLID123');
  assert.equal(payload.nvx_first_source, 'google');
  assert.equal(payload.nvx_conversion_source, 'google');

  organic.window.location.href = 'https://nuvanx.com/madrid/valoracion/';
  organic.window.location.search = '';
  organic.document.referrer = 'https://www.nuvanx.com/endolift/';
  const internalConversion = contract.getConversionTouch();
  assert.equal(internalConversion.channel, 'paid_search', 'internal navigation must preserve last acquisition touch');
  assert.equal(internalConversion.gclid, 'GCLID123', 'internal navigation must preserve conversion click id');

  const sharedLocalStorage = memoryStorage();
  const sharedSessionStorage = memoryStorage();
  const noConsent = executeRuntime(runtime, {
    consent: false,
    href: 'https://nuvanx.com/?utm_source=google&utm_medium=cpc&gclid=NOPE',
    referrer: 'https://www.google.com/',
    localStorage: sharedLocalStorage,
    sessionStorage: sharedSessionStorage,
  });
  assert.equal(noConsent.window.NUVANXAttributionContract.getFirstTouch(), null);
  assert.equal(noConsent.window.NUVANXAttributionContract.getConversionTouch(), null);
  assert.equal(sharedLocalStorage.getItem('nvx_first_touch'), null);
  assert.equal(sharedLocalStorage.getItem('nvx_conversion_touch'), null);
  assert.equal(noConsent.window.NUVANXAttributionContract.getLeadId(), '11111111-1111-4111-8111-111111111111',
    'First-party lead lineage must exist without marketing consent');

  const staging2Qa = executeRuntime(runtime, {
    consent: true,
    href: 'https://staging2.nuvanx.com/madrid/valoracion/',
    referrer: 'https://staging2.nuvanx.com/madrid/',
    qa: { is_test_lead: true, test_run_id: 'staging2-e2e-lint-001' },
  });
  const stagingPayload = staging2Qa.window.NUVANXAttributionContract.buildFormPayload(new Set([
    'nvx_lead_id', 'nvx_is_test_lead', 'nvx_test_run_id',
  ]));
  assert.equal(stagingPayload.nvx_is_test_lead, true);
  assert.equal(stagingPayload.nvx_test_run_id, 'staging2-e2e-lint-001');
  assert.equal(stagingPayload.nvx_lead_id, '11111111-1111-4111-8111-111111111111');

  
  // 1. Malformed Google click on internal nav should not overwrite
  organic.window.location.href = 'https://nuvanx.com/madrid/valoracion/?gclid=BAD%3CCLICK';
  organic.window.location.search = '?gclid=BAD%3CCLICK';
  organic.document.referrer = 'https://www.nuvanx.com/endolift/';
  const postMalformedConversion = contract.getConversionTouch();
  assert.equal(postMalformedConversion.channel, 'paid_search', 'malformed click on internal nav must preserve last acquisition touch');
  assert.equal(postMalformedConversion.gclid, 'GCLID123', 'malformed click on internal nav must preserve conversion click id');

  // 2. Local storage throws, session storage persists
  const throwingLocalStorage = memoryStorage();
  throwingLocalStorage.setItem = () => { throw new Error('QuotaExceededError'); };
  const robustSessionStorage = memoryStorage();
  const throwingEnv = executeRuntime(runtime, {
    consent: true,
    href: 'https://nuvanx.com/?gclid=GCLID_THROW',
    referrer: 'https://www.google.com/',
    localStorage: throwingLocalStorage,
    sessionStorage: robustSessionStorage,
  });
  const pendingThrow = throwingEnv.sessionStorage.getItem('nvx_pending_google_conversion_touch');
  assert.ok(pendingThrow, 'Pending Google touch must remain in session storage if local storage throws');
  assert.match(pendingThrow, /GCLID_THROW/, 'Pending Google touch must contain the correct click ID');

  console.log('STAGING2_E2E_QA_GATE=PASS is_test_lead=true test_run_id=staging2-prefixed server_owned=1');
  console.log('ATTRIBUTION_RUNTIME_BEHAVIOR=PASS first=organic_search conversion=paid_search internal_preserves_paid=1 no_consent_attribution=0 first_party_lineage=1');
  console.log('HUBSPOT_SECURE_ATTRIBUTION_STATIC=PASS canonical_transport=1 secure_endpoint=1 lead_independent_of_marketing=1 qa_server_owned=1 staging_fail_closed=1');
  console.log('GOOGLE_CLICK_LINEAGE_RELAY=PASS v4=1 legacy=1 persisted_click_fallback=1');
  console.log('ATTRIBUTION_CONTRACT=PASS schema=v2 lead_id=uuidv4 first_touch=1 conversion_touch=1 utm_fields=5 click_ids=4 consent_boundary=split qa_gate=1 secure_submit=1 supabase_lineage=1');
}
