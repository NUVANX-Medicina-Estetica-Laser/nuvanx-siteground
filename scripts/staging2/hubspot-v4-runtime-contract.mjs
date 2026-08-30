import assert from 'node:assert/strict';
import { REQUIRED_NATIVE_LINEAGE_FIELDS } from './hubspot-form-definition-contract.mjs';

export { REQUIRED_NATIVE_LINEAGE_FIELDS };

export function canonicalHubSpotFieldName(name) {
  return String(name || '').trim().replace(/^\d+-\d+\//, '');
}

export function inspectHubSpotV4RuntimeContract(nativeFields, visibleFieldNames = []) {
  const native = nativeFields && typeof nativeFields === 'object' ? nativeFields : {};
  const nativeNames = Object.keys(native)
    .map(canonicalHubSpotFieldName)
    .filter(Boolean)
    .sort();
  const visibleNames = [...new Set(
    (Array.isArray(visibleFieldNames) ? visibleFieldNames : [])
      .map(canonicalHubSpotFieldName)
      .filter(Boolean)
  )].sort();

  const nativeSet = new Set(nativeNames);
  const visibleSet = new Set(visibleNames);
  const missingFields = REQUIRED_NATIVE_LINEAGE_FIELDS.filter((name) => !nativeSet.has(name));
  const unexpectedlyVisibleFields = REQUIRED_NATIVE_LINEAGE_FIELDS.filter((name) => visibleSet.has(name));

  return {
    schema: 1,
    source: 'hubspot_forms_v4_runtime',
    required_fields: [...REQUIRED_NATIVE_LINEAGE_FIELDS],
    native_field_names: nativeNames,
    visible_field_names: visibleNames,
    missing_fields: missingFields,
    unexpectedly_visible_fields: unexpectedlyVisibleFields,
  };
}

export function assertHubSpotV4RuntimeContract(report) {
  assert.deepEqual(
    report.missing_fields,
    [],
    `CANDIDATE_REGRESSION: canonical HubSpot V4 form missing native lineage fields: ${report.missing_fields.join(',') || 'none'}`
  );
  assert.deepEqual(
    report.unexpectedly_visible_fields,
    [],
    `CANDIDATE_REGRESSION: canonical HubSpot lineage fields must not be visible controls: ${report.unexpectedly_visible_fields.join(',') || 'none'}`
  );
  return true;
}
