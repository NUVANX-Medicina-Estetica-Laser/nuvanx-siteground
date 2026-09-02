import assert from 'node:assert/strict';
import fs from 'node:fs';

const FORM_ID = '5042522a-0bc5-4381-ac3e-5aee8649b69c';
const syncPath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-hubspot-attribution-sync.js';
const integrationPath = 'wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php';
const gtmPath = 'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php';
const directPath = 'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php';
const bootstrapPath = 'wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php';

for (const path of [syncPath, integrationPath, gtmPath, directPath]) {
  assert.ok(fs.existsSync(path), `Missing attribution integration dependency: ${path}`);
}

const syncSource = fs.readFileSync(syncPath, 'utf8');
const integration = fs.readFileSync(integrationPath, 'utf8');
const gtm = fs.readFileSync(gtmPath, 'utf8');
const direct = fs.readFileSync(directPath, 'utf8');
const bootstrap = fs.readFileSync(bootstrapPath, 'utf8');

assert.match(syncSource, /buildFormPayload\(new Set\(index\.keys\(\)\)\)/);
assert.match(syncSource, /form\.setFieldValue\(actualName,/);
assert.match(syncSource, /'nvx_lead_id'/);
assert.match(syncSource, /'nvx_is_test_lead'/);
assert.match(syncSource, /'nvx_test_run_id'/);
assert.match(syncSource, /HIDDEN_BOOLEAN_FIELDS/,
  'HubSpot sync must distinguish hidden QA checkbox properties from visible Single checkbox fields');
assert.match(syncSource, /HIDDEN_BOOLEAN_FIELDS\.has\(propertyName\).*\['true'\].*\['false'\]|HIDDEN_BOOLEAN_FIELDS\.has\(propertyName\).*'true'.*'false'/s,
  'Hidden QA marker must use HubSpot Hidden-field string[] representation');
assert.match(syncSource, /await Promise\.resolve\(form\.setFieldValue\(actualName,/,
  'HubSpot V4 field writes must complete before lineage validation continues');
assert.match(syncSource, /await setField\(form, index, propertyName, payload\[propertyName\]\)/,
  'Canonical payload fields must be synchronized sequentially');
assert.match(syncSource, /!marketingConsent && !FIRST_PARTY_FIELDS\.has\(propertyName\)/);
assert.match(syncSource, /hs-form-event:on-ready/);
assert.match(syncSource, /wp_listen_for_consent_change/);
assert.doesNotMatch(syncSource, new RegExp(FORM_ID));

assert.match(integration, /array\(\s*'nvx-attribution-contract'\s*\)/);
assert.match(integration, /add_filter\(\s*'pre_http_request',\s*'nvx_attribution_relay_direct_form_after_hubspot',\s*20,\s*3\s*\)/);
assert.match(integration, /hash_equals\(\s*\(string\)\s*nvx_hubspot_secure_original_url\(\),\s*\$url\s*\)/);
assert.match(integration, /\$hubspot_status < 200[\s\S]*?\|\|[\s\S]*?\$hubspot_status >= 300/);
assert.match(integration, /\$args\['body'\]/);
assert.match(integration, /\$fields\['nvx_lead_id'\]/);
assert.match(integration, /'submission_id'\s*=>\s*\$submission_id/);
assert.match(integration, /'nvx_lead_id'\s*=>\s*\$lead_id/);
assert.match(integration, /\$form_id = strtolower\(\s*trim\(\s*\(string\)\s*nvx_hubspot_secure_form_id\(\)\s*\)\s*\);/);
assert.doesNotMatch(integration, new RegExp(FORM_ID));
assert.match(integration, /return 'https:\/\/ssvvuuysgxyqvmovrlvk\.supabase\.co\/functions\/v1\/google-click-attribution';/);
assert.match(integration, /NVX_ATTRIBUTION_COLLECTOR_ENDPOINT/);
assert.match(integration, /function nvx_attribution_collector_allowed_hosts/);
assert.match(integration, /nvxAttributionMarketingFields/);
assert.match(integration, /nvx_attribution_submission_id_from_lead/);
assert.match(integration, /nvx_supabase_relay_dispatch\(\s*'google_click'/);

// 1C-3 Contractual Tests
assert.match(integration, /nvx_marketing_consent_granted/, 'GOOGLE_CLICK_SERVER_CONSENT_ONLY');
assert.doesNotMatch(integration, /\$_POST\['nvx_marketing_consent'\]/, 'CLIENT_MARKETING_HIDDEN_FIELD_IGNORED');
assert.match(integration, /nvx_attribution_is_direct_form_request/, 'GOOGLE_CLICK_DIRECT_FORM_NONCE_REQUIRED');

assert.match(integration, /nvx_attribution_submission_id_from_lead/, 'GOOGLE_CLICK_DETERMINISTIC_SUBMISSION_ID');
assert.doesNotMatch(integration, /wp_generate_uuid4/, 'GOOGLE_CLICK_NO_RANDOM_SUBMISSION_ID_FALLBACK');

assert.match(integration, /int \$max_length = 512/, 'GOOGLE_CLICK_GCLID_MAX_512');
assert.match(integration, /nvx_attribution_clean_click_id\([\s\S]*?128\s*\)/, 'GOOGLE_CLICK_GCLSRC_MAX_128');

assert.match(integration, /define\(\s*'NVX_ATTRIBUTION_COLLECTOR_MAX_BODY_BYTES',\s*8192\s*\)/, 'GOOGLE_CLICK_MAX_BODY_8192');

assert.match(integration, /'https' !== \$scheme/, 'GOOGLE_CLICK_HTTPS_FIRST_PARTY_LANDING');
assert.match(integration, /'https:\/\/'\s*\.\s*\$host\s*\.\s*\$path/, 'GOOGLE_CLICK_QUERY_FRAGMENT_STRIPPED');

assert.match(integration, /function nvx_attribution_collector_allowed_hosts/, 'GOOGLE_CLICK_CANONICAL_ORIGIN_ONLY');
assert.doesNotMatch(integration, /NVX_ATTRIBUTION_COLLECTOR_ALLOWED_HOSTS/, 'GOOGLE_CLICK_NO_CONFIGURABLE_UNSUPPORTED_ORIGIN');

assert.match(integration, /nvx_supabase_relay_dispatch\(\s*'google_click'/, 'GOOGLE_CLICK_OUTBOX_REQUIRED');
assert.doesNotMatch(integration, /wp_remote_post\(\s*nvx_attribution_collector_endpoint\(\)/, 'GOOGLE_CLICK_NO_UNSIGNED_HTTP_FALLBACK');

assert.match(integration, /\$hubspot_status < 200[\s\S]*?\|\|[\s\S]*?\$hubspot_status >= 300[\s\S]*?return \$preempt;/, 'GOOGLE_CLICK_HUBSPOT_FAILURE_NOOP');
assert.match(integration, /! \$marketing_consent[\s\S]*?return \$preempt;/, 'GOOGLE_CLICK_NO_CONSENT_NOOP');
assert.match(integration, /''\s*===\s*\$gclid\s*&&\s*''\s*===\s*\$gbraid\s*&&\s*''\s*===\s*\$wbraid[\s\S]*?return \$preempt;/, 'GOOGLE_CLICK_NO_CLICK_ID_NOOP');

assert.match(integration, /\^staging2-sha-\[A-Za-z0-9._:-\]\{4,80\}\$\/D/, 'GOOGLE_CLICK_QA_SERVER_OWNED');
assert.match(integration, /\$test_run_id = '';/, 'GOOGLE_CLICK_PRODUCTION_NO_FAKE_QA');
assert.match(syncSource, /typeof value === 'boolean'\) return value;/,
  'Visible Single checkbox fields must retain HubSpot native boolean support');
assert.doesNotMatch(integration, /NVX_GOOGLE_CLICK_ATTRIBUTION_ENDPOINT/);
const collectorPayload = integration.match(/\$collector_payload = array\(([\s\S]*?)\n\t\);/)?.[1] || '';
assert.ok(collectorPayload);
assert.doesNotMatch(collectorPayload, /applied_lead_id/);
assert.match(integration, /'Origin'\s*=>\s*\$origin/);
assert.match(integration, /origin_not_allowed/);
assert.match(gtm, /nvx_hubspot_secure_form_id/);
assert.doesNotMatch(gtm, /require_once.*nvx-attribution-integration/,
  'GTM integration must not laterally load attribution integration (bootstrap manifest owns this)');
assert.match(bootstrap, /'inc\/nvx-attribution-integration\.php'/,
  'Attribution integration must be loaded from bootstrap manifest');
assert.match(direct, /nvx_lead_id/);

const listeners = new Map();
let consent = false;
const writes = new Map();
const fields = [
  { name: '0-1/nvx_lead_id', value: '' },
  { name: '0-1/nvx_is_test_lead', value: [] },
  { name: '0-1/nvx_test_run_id', value: '' },
  { name: '7-12/nvx_utm_source', value: 'stale-source' },
  { name: '0-1/nvx_google_click_id', value: 'STALE-GCLID' },
];
const form = {
  getFormId: () => FORM_ID,
  getFormFieldValues: async () => fields,
  setFieldValue: (name, value) => new Promise((resolve) => {
    setTimeout(() => {
      writes.set(name, value);
      resolve();
    }, 0);
  }),
};

const buildQaTruePayload = () => ({
  nvx_lead_id: '11111111-1111-4111-8111-111111111111',
  nvx_is_test_lead: true,
  nvx_test_run_id: 'staging2-sha-test',
  nvx_utm_source: 'google',
  nvx_google_click_id: 'GCLID-TEST',
});

globalThis.window = {
  nvxConversionEvents: { forms: { valoracion: FORM_ID } },
  wp_has_consent: () => consent,
  NUVANXAttributionContract: {
    buildFormPayload: buildQaTruePayload,
  },
  HubSpotFormsV4: {
    getForms: () => [form],
    getFormFromEvent: () => form,
  },
  addEventListener: (name, callback) => listeners.set(name, callback),
};
globalThis.document = {
  readyState: 'loading',
  addEventListener: (name, callback) => listeners.set(name, callback),
};

await import(new URL('../../wp-content/themes/nuvanx-medical/assets/js/nvx-hubspot-attribution-sync.js', import.meta.url).href);
const api = globalThis.window.NUVANXHubSpotAttributionSync;
assert.equal(api.canonicalPropertyName('7-12/nvx_utm_source'), 'nvx_utm_source');
assert.equal(api.HIDDEN_BOOLEAN_FIELDS.has('nvx_is_test_lead'), true);

await api.syncForm(form);
assert.deepEqual(writes.get('0-1/nvx_lead_id'), ['11111111-1111-4111-8111-111111111111']);
assert.deepEqual(writes.get('0-1/nvx_is_test_lead'), ['true']);
assert.deepEqual(writes.get('7-12/nvx_utm_source'), []);
assert.deepEqual(writes.get('0-1/nvx_google_click_id'), []);

// nvx_is_test_lead is a checkbox-backed CRM property, but it is configured as
// Hidden in the canonical form and therefore uses HubSpot Hidden-field string[].
globalThis.window.NUVANXAttributionContract.buildFormPayload = () => ({
  nvx_lead_id: '11111111-1111-4111-8111-111111111111',
  nvx_is_test_lead: false,
  nvx_test_run_id: '',
});
writes.clear();
await api.syncForm(form);
assert.deepEqual(writes.get('0-1/nvx_is_test_lead'), ['false']);
globalThis.window.NUVANXAttributionContract.buildFormPayload = buildQaTruePayload;

writes.clear();
const other = { ...form, getFormId: () => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' };
assert.equal(await api.syncForm(other), false);
assert.equal(writes.size, 0);

consent = true;
writes.clear();
await api.syncForm(form);
assert.deepEqual(writes.get('0-1/nvx_is_test_lead'), ['true']);
assert.deepEqual(writes.get('7-12/nvx_utm_source'), ['google']);
assert.deepEqual(writes.get('0-1/nvx_google_click_id'), ['GCLID-TEST']);

consent = false;
writes.clear();
listeners.get('wp_listen_for_consent_change')?.();
await new Promise((resolve) => setTimeout(resolve, 80));
assert.deepEqual(writes.get('0-1/nvx_lead_id'), ['11111111-1111-4111-8111-111111111111']);
assert.deepEqual(writes.get('0-1/nvx_is_test_lead'), ['true']);
assert.deepEqual(writes.get('7-12/nvx_utm_source'), []);
assert.deepEqual(writes.get('0-1/nvx_google_click_id'), []);

// Both asynchronous entry points must consume a rejection returned by syncForm.
// Force a rejection after buildFormPayload returns so the outer async function,
// rather than its internal guarded calls, is what rejects.
const originalBuildFormPayload = globalThis.window.NUVANXAttributionContract.buildFormPayload;
let ownKeysCalls = 0;
globalThis.window.NUVANXAttributionContract.buildFormPayload = () => new Proxy({}, {
  ownKeys() {
    ownKeysCalls += 1;
    throw new Error('forced-attribution-sync-rejection');
  },
});
const unhandled = [];
const onUnhandled = (reason) => unhandled.push(reason);
process.on('unhandledRejection', onUnhandled);
try {
  listeners.get('hs-form-event:on-ready')?.({ detail: { formId: FORM_ID } });
  listeners.get('wp_listen_for_consent_change')?.();
  await new Promise((resolve) => setTimeout(resolve, 20));
} finally {
  process.off('unhandledRejection', onUnhandled);
  globalThis.window.NUVANXAttributionContract.buildFormPayload = originalBuildFormPayload;
}
assert.equal(ownKeysCalls, 2, 'Both HubSpot async entry points must exercise syncForm');
assert.equal(unhandled.length, 0, 'HubSpot async sync entry points must consume rejected promises');

// These regressions rely on the public sync API and form-contract helper used by
// Staging lineage acceptance, so load them only after the base API is installed.
await import('./test-hubspot-v4-hidden-lineage.mjs');
await import('./test-hubspot-form-definition-contract.mjs');

console.log('ATTRIBUTION_INTEGRATION_WIRING=PASS lineage=1 hidden_fields=string_array consent_split=1 qa_hidden_string_array=true+false canonical_form=1 async_field_writes=awaited async_rejections=contained applied_lead_id=untouched embed_contract_tested=1');
