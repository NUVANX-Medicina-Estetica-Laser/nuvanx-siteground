import assert from 'node:assert/strict';
import fs from 'node:fs';

const FORM_ID = '5042522a-0bc5-4381-ac3e-5aee8649b69c';
const syncPath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-hubspot-attribution-sync.js';
const integrationPath = 'wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php';
const gtmPath = 'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php';
const directPath = 'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php';

for (const path of [syncPath, integrationPath, gtmPath, directPath]) {
  assert.ok(fs.existsSync(path), `Missing attribution integration dependency: ${path}`);
}

const syncSource = fs.readFileSync(syncPath, 'utf8');
const integration = fs.readFileSync(integrationPath, 'utf8');
const gtm = fs.readFileSync(gtmPath, 'utf8');
const direct = fs.readFileSync(directPath, 'utf8');

assert.match(syncSource, /buildFormPayload\(new Set\(index\.keys\(\)\)\)/);
assert.match(syncSource, /form\.setFieldValue\(actualName,/);
assert.match(syncSource, /'nvx_lead_id'/);
assert.match(syncSource, /'nvx_is_test_lead'/);
assert.match(syncSource, /'nvx_test_run_id'/);
assert.match(syncSource, /await Promise\.resolve\(form\.setFieldValue\(actualName,/,
  'HubSpot V4 field writes must complete before lineage validation continues');
assert.match(syncSource, /await setField\(form, index, propertyName, payload\[propertyName\]\)/,
  'Canonical payload fields must be synchronized sequentially');
assert.match(syncSource, /!marketingConsent && !FIRST_PARTY_FIELDS\.has\(propertyName\)/);
assert.match(syncSource, /hs-form-event:on-ready/);
assert.match(syncSource, /wp_listen_for_consent_change/);
assert.doesNotMatch(syncSource, new RegExp(FORM_ID));

assert.match(integration, /array\( 'nvx-attribution-contract' \)/);
assert.match(integration, /pre_http_request', 'nvx_attribution_relay_direct_form_after_hubspot', 20, 3/);
assert.match(integration, /nvx_hubspot_secure_original_url\(\) !== \$url/);
assert.match(integration, /\$hubspot_status < 200 \|\| \$hubspot_status >= 300/);
assert.match(integration, /\$_POST\['nvx_marketing_consent'\]/);
assert.match(integration, /\$args\['body'\]/);
assert.match(integration, /\$fields\['nvx_lead_id'\]/);
assert.match(integration, /'submission_id'\s*=>\s*\$submission_id/);
assert.match(integration, /'nvx_lead_id'\s*=>\s*\$lead_id/);
assert.match(integration, /\$form_id = nvx_hubspot_secure_form_id\(\)/);
assert.doesNotMatch(integration, new RegExp(FORM_ID));
assert.match(integration, /return 'https:\/\/ssvvuuysgxyqvmovrlvk\.supabase\.co\/functions\/v1\/google-click-attribution';/);
assert.match(integration, /NVX_ATTRIBUTION_COLLECTOR_ENDPOINT/);
assert.match(integration, /NVX_ATTRIBUTION_COLLECTOR_ALLOWED_HOSTS/);
assert.match(integration, /nvxAttributionMarketingFields/);
assert.match(integration, /'timeout'\s*=>\s*0\.5/);
assert.match(integration, /'blocking'\s*=>\s*false/);
assert.match(syncSource, /typeof value === 'boolean'\) return value;/);
assert.doesNotMatch(integration, /NVX_GOOGLE_CLICK_ATTRIBUTION_ENDPOINT/);
const collectorPayload = integration.match(/\$collector_payload = array\(([\s\S]*?)\n\t\);/)?.[1] || '';
assert.ok(collectorPayload);
assert.doesNotMatch(collectorPayload, /applied_lead_id/);
assert.match(integration, /'Origin'\s*=>\s*\$origin/);
assert.match(integration, /origin_not_allowed/);
assert.match(gtm, /nvx_hubspot_secure_form_id/);
assert.match(gtm, /require_once __DIR__ \. '\/nvx-attribution-integration\.php'/);
assert.match(direct, /nvx_lead_id/);

const listeners = new Map();
let consent = false;
const writes = new Map();
const fields = [
  { name: '0-1/nvx_lead_id', value: '' },
  { name: '0-1/nvx_is_test_lead', value: false },
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

await api.syncForm(form);
assert.deepEqual(writes.get('0-1/nvx_lead_id'), ['11111111-1111-4111-8111-111111111111']);
assert.equal(typeof writes.get('0-1/nvx_is_test_lead'), 'boolean');
assert.equal(writes.get('0-1/nvx_is_test_lead'), true);
assert.deepEqual(writes.get('7-12/nvx_utm_source'), []);
assert.deepEqual(writes.get('0-1/nvx_google_click_id'), []);

// HubSpot V4 Single checkbox consumes native booleans, not 'true'/'false' strings.
globalThis.window.NUVANXAttributionContract.buildFormPayload = () => ({
  nvx_lead_id: '11111111-1111-4111-8111-111111111111',
  nvx_is_test_lead: false,
  nvx_test_run_id: '',
});
writes.clear();
await api.syncForm(form);
assert.equal(typeof writes.get('0-1/nvx_is_test_lead'), 'boolean');
assert.equal(writes.get('0-1/nvx_is_test_lead'), false);
globalThis.window.NUVANXAttributionContract.buildFormPayload = buildQaTruePayload;

writes.clear();
const other = { ...form, getFormId: () => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' };
assert.equal(await api.syncForm(other), false);
assert.equal(writes.size, 0);

consent = true;
writes.clear();
await api.syncForm(form);
assert.equal(typeof writes.get('0-1/nvx_is_test_lead'), 'boolean');
assert.equal(writes.get('0-1/nvx_is_test_lead'), true);
assert.deepEqual(writes.get('7-12/nvx_utm_source'), ['google']);
assert.deepEqual(writes.get('0-1/nvx_google_click_id'), ['GCLID-TEST']);

consent = false;
writes.clear();
listeners.get('wp_listen_for_consent_change')?.();
await new Promise((resolve) => setTimeout(resolve, 80));
assert.deepEqual(writes.get('0-1/nvx_lead_id'), ['11111111-1111-4111-8111-111111111111']);
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

// This regression relies on the public sync API that this integration wiring
// installs, so load it only after the API contract above has been exercised.
await import('./test-hubspot-v4-hidden-lineage.mjs');

console.log('ATTRIBUTION_INTEGRATION_WIRING=PASS lineage=1 hidden_fields=string_array consent_split=1 qa_boolean=native_true+false canonical_form=1 async_field_writes=awaited async_rejections=contained applied_lead_id=untouched');
