import assert from 'node:assert/strict';
import { EX_TEMPFAIL } from './siteground-transient-classifier.mjs';

export const REQUIRED_NATIVE_LINEAGE_FIELDS = Object.freeze([
  'nvx_lead_id',
  'nvx_is_test_lead',
  'nvx_test_run_id',
  'nvx_utm_source',
  'nvx_google_click_id',
]);

const HUBSPOT_FORM_V2_BASE = 'https://api.hubapi.com/forms/v2/forms';

function transientError(message) {
  const error = new Error(message);
  error.exitCode = EX_TEMPFAIL;
  return error;
}

function canonicalFieldName(name) {
  return String(name || '').trim().replace(/^\d+-\d+\//, '');
}

export async function fetchHubSpotFormDefinition(formId, options = {}) {
  const normalizedFormId = String(formId || '').trim().toLowerCase();
  assert.match(
    normalizedFormId,
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    'Canonical HubSpot form id must be a UUID before form-definition verification'
  );

  const timeoutMs = Number(options.timeoutMs || 12_000);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);
  const url = `${HUBSPOT_FORM_V2_BASE}/${encodeURIComponent(normalizedFormId)}`;

  let response;
  try {
    response = await fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      redirect: 'error',
      signal: controller.signal,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    throw transientError(`HubSpot form-definition transport unavailable: ${message}`);
  } finally {
    clearTimeout(timeout);
  }

  if (response.status === 429 || response.status >= 500) {
    throw transientError(`HubSpot form-definition endpoint transient status=${response.status}`);
  }
  if (!response.ok) {
    throw new Error(`CANDIDATE_REGRESSION: HubSpot form-definition endpoint status=${response.status}`);
  }

  let payload;
  try {
    payload = await response.json();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    throw new Error(`CANDIDATE_REGRESSION: HubSpot form-definition response is not valid JSON: ${message}`);
  }

  return payload;
}

export function inspectHubSpotFormDefinition(payload, expectedFormId) {
  const expected = String(expectedFormId || '').trim().toLowerCase();
  const actual = String(payload?.guid || payload?.id || '').trim().toLowerCase();
  const groups = Array.isArray(payload?.formFieldGroups)
    ? payload.formFieldGroups
    : (Array.isArray(payload?.fieldGroups) ? payload.fieldGroups : []);

  const fields = groups.flatMap((group) => Array.isArray(group?.fields) ? group.fields : []);
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

  return {
    schema: 1,
    expected_form_id: expected,
    actual_form_id: actual,
    form_name: String(payload?.name || ''),
    published: payload?.isPublished === undefined ? null : payload.isPublished === true,
    updated_at: payload?.updatedAt ?? null,
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
