import assert from 'node:assert/strict';

export const REQUIRED_NATIVE_LINEAGE_FIELDS = Object.freeze([
  'nvx_lead_id',
  'nvx_is_test_lead',
  'nvx_test_run_id',
  'nvx_utm_source',
  'nvx_google_click_id',
]);

function canonicalFieldName(name) {
  return String(name || '').trim().replace(/^\d+-\d+\//, '');
}

function resolveFormDefinition(payload) {
  if (!payload || typeof payload !== 'object') return {};
  if (payload.form && typeof payload.form === 'object') return payload.form;
  if (payload.formDefinition && typeof payload.formDefinition === 'object') return payload.formDefinition;
  return payload;
}

export function inspectHubSpotFormDefinition(payload, expectedFormId) {
  const expected = String(expectedFormId || '').trim().toLowerCase();
  const definition = resolveFormDefinition(payload);
  const actual = String(
    definition?.guid || definition?.id || definition?.formId || payload?.formId || ''
  ).trim().toLowerCase();

  const groups = [
    definition?.formFieldGroups,
    definition?.fieldGroups,
    payload?.formFieldGroups,
    payload?.fieldGroups,
  ].find((candidate) => Array.isArray(candidate)) || [];

  let fields = groups.flatMap((group) => Array.isArray(group?.fields) ? group.fields : []);
  if (fields.length === 0 && Array.isArray(definition?.fields)) fields = definition.fields;

  const index = new Map();
  for (const field of fields) {
    const canonical = canonicalFieldName(field?.name);
    if (!canonical) continue;
    if (!index.has(canonical)) index.set(canonical, field);
  }

  const missing = REQUIRED_NATIVE_LINEAGE_FIELDS.filter((name) => !index.has(name));
  const notHidden = REQUIRED_NATIVE_LINEAGE_FIELDS.filter((name) => {
    const field = index.get(name);
    return field && field.hidden !== true;
  });

  const publishedValue = definition?.isPublished ?? payload?.isPublished;
  const updatedAt = definition?.updatedAt ?? definition?.internalUpdatedAt ?? payload?.updatedAt ?? null;

  return {
    schema: 2,
    source: payload?.form && typeof payload.form === 'object' ? 'hubspot_embed_v3' : 'hubspot_form_definition',
    expected_form_id: expected,
    actual_form_id: actual,
    form_name: String(definition?.name || ''),
    published: publishedValue === undefined ? null : publishedValue === true,
    updated_at: updatedAt,
    required_fields: [...REQUIRED_NATIVE_LINEAGE_FIELDS],
    available_field_names: [...index.keys()].sort(),
    missing_fields: missing,
    non_hidden_fields: notHidden,
  };
}

export function assertHubSpotFormDefinitionContract(report) {
  assert.equal(
    report.actual_form_id,
    report.expected_form_id,
    `CANDIDATE_REGRESSION: HubSpot form identity mismatch expected=${report.expected_form_id} actual=${report.actual_form_id || 'missing'}`
  );
  if (report.published !== null) {
    assert.equal(report.published, true, 'CANDIDATE_REGRESSION: canonical HubSpot form must remain published');
  }
  assert.deepEqual(
    report.missing_fields,
    [],
    `CANDIDATE_REGRESSION: canonical HubSpot form missing native lineage fields: ${report.missing_fields.join(',') || 'none'}`
  );
  assert.deepEqual(
    report.non_hidden_fields,
    [],
    `CANDIDATE_REGRESSION: canonical HubSpot lineage fields must be Hidden: ${report.non_hidden_fields.join(',') || 'none'}`
  );
  return true;
}
