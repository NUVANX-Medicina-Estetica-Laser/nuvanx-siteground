import assert from 'node:assert/strict';
import { EX_TEMPFAIL } from './siteground-transient-classifier.mjs';

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

export function isHubSpotEmbedDefinitionUrl(value) {
  try {
    const url = new URL(value);
    const host = url.hostname.toLowerCase();
    const trustedHost = host === 'forms.hsforms.com' || host.endsWith('.hsforms.com');
    return trustedHost && /\/embed\/v3\/form\/[^/]+\/[^/]+\/json$/i.test(url.pathname);
  } catch {
    return false;
  }
}

export function embedResponseMatchesForm(entry, formId) {
  try {
    const pathname = new URL(entry?.url || '').pathname.toLowerCase();
    return pathname.endsWith(`/${String(formId || '').toLowerCase()}/json`);
  } catch {
    return false;
  }
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export async function waitForHubSpotEmbedDefinition(entries, formId, timeoutMs = 15_000, pollIntervalMs = 50) {
  const deadline = Date.now() + timeoutMs;

  while (true) {
    const matching = entries.filter((candidate) => embedResponseMatchesForm(candidate, formId));

    const successfulEntry = matching.find((candidate) => {
      try {
        const status = typeof candidate.response?.status === 'function'
          ? candidate.response.status()
          : candidate.response?.status;
        return status === 200;
      } catch {
        return false;
      }
    });

    if (successfulEntry) {
      let payload;
      try {
        payload = typeof successfulEntry.response.json === 'function'
          ? await successfulEntry.response.json()
          : successfulEntry.response.json;
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        throw new Error(`CANDIDATE_REGRESSION: HubSpot embed definition is not valid JSON: ${message}`);
      }

      const parsedUrl = new URL(successfulEntry.url);
      return {
        payload,
        source: `${parsedUrl.origin}${parsedUrl.pathname}`,
      };
    }

    if (Date.now() >= deadline) {
      if (matching.length === 0) {
        const error = new Error(
          `HubSpot embed definition response for form ${formId} was not captured before timeout`
        );
        error.exitCode = EX_TEMPFAIL;
        throw error;
      }

      const lastEntry = matching[matching.length - 1];
      const status = typeof lastEntry.response?.status === 'function'
        ? lastEntry.response.status()
        : lastEntry.response?.status;

      if (status === 429 || status >= 500) {
        const error = new Error(`HubSpot embed definition temporarily unavailable status=${status}`);
        error.exitCode = EX_TEMPFAIL;
        throw error;
      }

      assert.equal(status, 200, `CANDIDATE_REGRESSION: HubSpot embed definition status=${status}`);

      let payload;
      try {
        payload = typeof lastEntry.response.json === 'function'
          ? await lastEntry.response.json()
          : lastEntry.response.json;
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        throw new Error(`CANDIDATE_REGRESSION: HubSpot embed definition is not valid JSON: ${message}`);
      }

      const parsedUrl = new URL(lastEntry.url);
      return {
        payload,
        source: `${parsedUrl.origin}${parsedUrl.pathname}`,
      };
    }

    await delay(pollIntervalMs);
  }
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
