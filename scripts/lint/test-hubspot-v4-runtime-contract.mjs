import assert from 'node:assert/strict';
import {
  REQUIRED_NATIVE_LINEAGE_FIELDS,
  assertHubSpotV4RuntimeContract,
  canonicalHubSpotFieldName,
  inspectHubSpotV4RuntimeContract,
} from '../staging2/hubspot-v4-runtime-contract.mjs';

const native = {
  '0-1/nvx_lead_id': ['11111111-1111-4111-8111-111111111111'],
  '0-1/nvx_is_test_lead': ['true'],
  '0-1/nvx_test_run_id': ['staging2-sha-abcdef123456'],
  '7-12/nvx_utm_source': ['google'],
  '0-1/nvx_google_click_id': ['GCLID-RUNTIME'],
  firstname: ['QA'],
};

assert.equal(canonicalHubSpotFieldName('7-12/nvx_utm_source'), 'nvx_utm_source');
assert.equal(canonicalHubSpotFieldName('firstname'), 'firstname');

const healthy = inspectHubSpotV4RuntimeContract(native, [
  'firstname',
  'lastname',
  'email',
  'phone',
  'LEGAL_CONSENT.subscription_type_999',
]);
assert.deepEqual(healthy.required_fields, [...REQUIRED_NATIVE_LINEAGE_FIELDS]);
assert.deepEqual(healthy.missing_fields, []);
assert.deepEqual(healthy.unexpectedly_visible_fields, []);
assert.equal(assertHubSpotV4RuntimeContract(healthy), true);

const missing = { ...native };
delete missing['0-1/nvx_test_run_id'];
const missingReport = inspectHubSpotV4RuntimeContract(missing, ['firstname']);
assert.deepEqual(missingReport.missing_fields, ['nvx_test_run_id']);
assert.throws(
  () => assertHubSpotV4RuntimeContract(missingReport),
  /missing native lineage fields: nvx_test_run_id/i
);

const visibleReport = inspectHubSpotV4RuntimeContract(native, [
  'firstname',
  '0-1/nvx_lead_id',
  '7-12/nvx_utm_source',
]);
assert.deepEqual(visibleReport.unexpectedly_visible_fields, ['nvx_lead_id', 'nvx_utm_source']);
assert.throws(
  () => assertHubSpotV4RuntimeContract(visibleReport),
  /must not be visible controls: nvx_lead_id,nvx_utm_source/i
);

const nullReport = inspectHubSpotV4RuntimeContract(null, null);
assert.deepEqual(nullReport.missing_fields, [...REQUIRED_NATIVE_LINEAGE_FIELDS]);
assert.deepEqual(nullReport.unexpectedly_visible_fields, []);

console.log('HUBSPOT_V4_RUNTIME_CONTRACT=PASS native_presence=1 hidden_runtime_visibility=1 missing_detection=1 visible_detection=1');
