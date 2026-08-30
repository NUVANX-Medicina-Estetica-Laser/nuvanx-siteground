import assert from 'node:assert/strict';

const FORM_ID = '5042522a-0bc5-4381-ac3e-5aee8649b69c';
const LEAD_ID = '11111111-1111-4111-8111-111111111111';
const TEST_RUN_ID = 'staging2-sha-abcdef123456';

const api = globalThis.window?.NUVANXHubSpotAttributionSync;
assert.ok(api, 'HubSpot attribution sync API must already be exposed by integration wiring');

const calls = [];
const values = new Map([
  ['0-1/nvx_lead_id', []],
  ['0-1/nvx_is_test_lead', []],
  ['0-1/nvx_test_run_id', []],
  ['0-1/nvx_utm_source', []],
  ['0-1/nvx_google_click_id', []],
]);

const form = {
  getFormId: () => FORM_ID,
  getFormFieldValues: async () => Array.from(values.entries()).map(([name, value]) => ({ name, value })),
  getFieldValue: async (name) => values.get(name),
  setFieldValue: async (name, value) => {
    calls.push({ name, value });
    // Reproduce HubSpot Forms V4 Hidden-field contract: every lineage field in
    // the canonical form, including the QA marker backed by a checkbox CRM
    // property, is configured as Hidden and therefore accepts string[].
    if (!Array.isArray(value)) throw new TypeError('hidden field requires string[]');
    values.set(name, value);
  },
};

globalThis.window.wp_has_consent = () => true;
globalThis.window.setTimeout = setTimeout;
globalThis.window.NUVANXAttributionContract = {
  buildFormPayload: () => ({
    nvx_lead_id: LEAD_ID,
    nvx_is_test_lead: true,
    nvx_test_run_id: TEST_RUN_ID,
    nvx_utm_source: 'google',
    nvx_google_click_id: 'GCLID-HIDDEN-V4',
  }),
};
globalThis.window.HubSpotFormsV4 = {
  getForms: () => [form],
  getFormFromEvent: () => form,
};

assert.equal(await api.syncForm(form), true, 'V4 hidden fields must synchronize with the documented string[] type');

assert.deepEqual(values.get('0-1/nvx_lead_id'), [LEAD_ID]);
assert.deepEqual(values.get('0-1/nvx_is_test_lead'), ['true']);
assert.deepEqual(values.get('0-1/nvx_test_run_id'), [TEST_RUN_ID]);
assert.deepEqual(values.get('0-1/nvx_utm_source'), ['google']);
assert.deepEqual(values.get('0-1/nvx_google_click_id'), ['GCLID-HIDDEN-V4']);

const leadWrites = calls.filter((call) => call.name === '0-1/nvx_lead_id');
assert.equal(leadWrites.length, 1, 'Hidden lead id must be written once with HubSpot’s documented string[] type');
assert.deepEqual(leadWrites[0].value, [LEAD_ID]);

const qaWrites = calls.filter((call) => call.name === '0-1/nvx_is_test_lead');
assert.equal(qaWrites.length, 1, 'Hidden QA checkbox property must use HubSpot Hidden-field string[] representation');
assert.deepEqual(qaWrites[0].value, ['true']);

console.log('HUBSPOT_V4_HIDDEN_LINEAGE=PASS hidden_array_direct=1 readback_verified=1 qa_hidden_string_array=1');
