import assert from 'node:assert/strict';
import {
  REQUIRED_NATIVE_LINEAGE_FIELDS,
  assertHubSpotFormDefinitionContract,
  inspectHubSpotFormDefinition,
} from '../staging2/hubspot-form-definition-contract.mjs';

const FORM_ID = '5042522a-0bc5-4381-ac3e-5aee8649b69c';

function hiddenFields() {
  return REQUIRED_NATIVE_LINEAGE_FIELDS.map((name, index) => ({
    name: `${index + 1}-1/${name}`,
    hidden: true,
    fieldType: name === 'nvx_is_test_lead' ? 'single_checkbox' : 'single_line_text',
  }));
}

function embedPayload(fields = hiddenFields()) {
  return {
    form: {
      guid: FORM_ID,
      name: 'Endolift',
      isPublished: true,
      updatedAt: '2026-08-30T13:37:04.603Z',
      formFieldGroups: [{ fields }],
    },
  };
}

const healthy = inspectHubSpotFormDefinition(embedPayload(), FORM_ID);
assert.equal(healthy.source, 'hubspot_embed_v3');
assert.equal(healthy.actual_form_id, FORM_ID);
assert.deepEqual(healthy.missing_fields, []);
assert.deepEqual(healthy.non_hidden_fields, []);
assert.equal(assertHubSpotFormDefinitionContract(healthy), true);

const missingQa = hiddenFields().filter((field) => !field.name.endsWith('/nvx_is_test_lead'));
const missingReport = inspectHubSpotFormDefinition(embedPayload(missingQa), FORM_ID);
assert.deepEqual(missingReport.missing_fields, ['nvx_is_test_lead']);
assert.throws(
  () => assertHubSpotFormDefinitionContract(missingReport),
  /missing native lineage fields/i
);

const visibleQa = hiddenFields().map((field) => (
  field.name.endsWith('/nvx_is_test_lead') ? { ...field, hidden: false } : field
));
const visibleReport = inspectHubSpotFormDefinition(embedPayload(visibleQa), FORM_ID);
assert.deepEqual(visibleReport.non_hidden_fields, ['nvx_is_test_lead']);
assert.throws(
  () => assertHubSpotFormDefinitionContract(visibleReport),
  /must be Hidden/i
);

const topLevel = inspectHubSpotFormDefinition({
  guid: FORM_ID,
  name: 'Legacy-compatible shape',
  isPublished: true,
  formFieldGroups: [{ fields: hiddenFields() }],
}, FORM_ID);
assert.equal(topLevel.source, 'hubspot_form_definition');
assert.equal(assertHubSpotFormDefinitionContract(topLevel), true);

console.log('HUBSPOT_FORM_DEFINITION_CONTRACT=PASS embed_v3=1 top_level_compat=1 missing_field_detection=1 hidden_field_detection=1');
