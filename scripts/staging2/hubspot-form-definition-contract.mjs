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
    if (!trustedHost) return false;
    const pathname = url.pathname.toLowerCase();
    // V4: /embed/v4/render-definition/{portalId}/{formId}
    if (/\/embed\/v4\/render-definition\/[^/]+\/[^/]+(?:\/.*)?$/i.test(pathname)) {
      return true;
    }
    // V3: /embed/v3/form/{portalId}/{formId}/json
    if (/\/embed\/v3\/form\/[^/]+\/[^/]+\/json$/i.test(pathname)) {
      return true;
    }
    return false;
  } catch {
    return false;
  }
}

export function embedResponseMatchesForm(entry, formId) {
  try {
    const url = new URL(entry?.url || '');
    const host = url.hostname.toLowerCase();
    const trustedHost = host === 'forms.hsforms.com' || host.endsWith('.hsforms.com');
    if (!trustedHost) return false;
    const targetId = String(formId || '').toLowerCase().trim();
    if (!targetId) return false;
    const pathname = url.pathname.toLowerCase();

    // V4: /embed/v4/render-definition/{portalId}/{formId}
    const v4Match = pathname.match(/\/embed\/v4\/render-definition\/[^/]+\/([^/?#]+)/i);
    if (v4Match && v4Match[1].toLowerCase() === targetId) {
      return true;
    }

    // V3: /embed/v3/form/{portalId}/{formId}/json
    const v3Match = pathname.match(/\/embed\/v3\/form\/[^/]+\/([^/?#]+)\/json$/i);
    if (v3Match && v3Match[1].toLowerCase() === targetId) {
      return true;
    }

    return false;
  } catch {
    return false;
  }
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeTracker(target) {
  if (Array.isArray(target)) {
    return {
      requests: target,
      failedRequests: [],
      responses: target,
    };
  }
  return {
    requests: Array.isArray(target?.requests) ? target.requests : [],
    failedRequests: Array.isArray(target?.failedRequests) ? target.failedRequests : [],
    responses: Array.isArray(target?.responses) ? target.responses : [],
  };
}

export async function waitForHubSpotEmbedDefinition(
  entriesOrTracker,
  formId,
  timeoutMs = 15_000,
  pollIntervalMs = 50
) {
  const deadline = Date.now() + timeoutMs;

  while (true) {
    const tracker = normalizeTracker(entriesOrTracker);
    const matchingResponses = tracker.responses.filter((candidate) =>
      embedResponseMatchesForm(candidate, formId)
    );

    const successfulEntry = matchingResponses.find((candidate) => {
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
        payload = typeof successfulEntry.response?.json === 'function'
          ? await successfulEntry.response.json()
          : successfulEntry.response?.json;
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
      const matchingRequests = tracker.requests.filter((candidate) =>
        embedResponseMatchesForm(candidate, formId)
      );
      const matchingFailed = tracker.failedRequests.filter((candidate) =>
        embedResponseMatchesForm(candidate, formId)
      );

      // If the page never attempted to request the form embed, classify as candidate code regression.
      if (matchingRequests.length === 0 && matchingFailed.length === 0 && matchingResponses.length === 0) {
        assert.fail(
          `CANDIDATE_REGRESSION: HubSpot embed definition request for form ${formId} was not emitted by the page before timeout`
        );
      }

      // If a transport/network layer failure was captured, classify as transient infrastructure error.
      if (matchingFailed.length > 0) {
        const failReason = matchingFailed[0].errorText || 'network_failure';
        const error = new Error(
          `HubSpot embed definition transport failed for form ${formId}: ${failReason}`
        );
        error.exitCode = EX_TEMPFAIL;
        throw error;
      }

      // If matching responses were captured, inspect the last observed status.
      if (matchingResponses.length > 0) {
        const lastEntry = matchingResponses[matchingResponses.length - 1];
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
          payload = typeof lastEntry.response?.json === 'function'
            ? await lastEntry.response.json()
            : lastEntry.response?.json;
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

      // A request was emitted but timed out in-flight before response or failure was received.
      const error = new Error(
        `HubSpot embed definition request for form ${formId} timed out in flight`
      );
      error.exitCode = EX_TEMPFAIL;
      throw error;
    }

    await delay(pollIntervalMs);
  }
}

function extractFieldsFromModules(modules) {
  if (!Array.isArray(modules)) return [];
  const fields = [];

  function traverse(list) {
    if (!Array.isArray(list)) return;
    for (const item of list) {
      if (!item || typeof item !== 'object') continue;

      const propName = item?.propertyReference?.name
        || item?.propertyReference?.propertyName
        || item?.propertyReference?.property
        || item?.name
        || item?.propertyName
        || item?.property?.name
        || item?.field?.name
        || item?.fieldName;

      const isHiddenType = String(item?.type || '').toLowerCase() === 'hidden'
        || String(item?.moduleType || '').toLowerCase() === 'hidden';
      const isExplicitHidden = item?.hidden === true || item?.field?.hidden === true;

      if (propName) {
        fields.push({
          name: String(propName),
          hidden: isHiddenType || isExplicitHidden,
          fieldType: item?.fieldType || item?.type,
          raw: item,
        });
      }

      if (Array.isArray(item?.modules)) traverse(item.modules);
      if (Array.isArray(item?.children)) traverse(item.children);
      if (Array.isArray(item?.fields)) traverse(item.fields);
      if (Array.isArray(item?.formFieldGroups)) traverse(item.formFieldGroups);
      if (Array.isArray(item?.fieldGroups)) traverse(item.fieldGroups);
    }
  }

  traverse(modules);
  return fields;
}

export function inspectHubSpotFormDefinition(payload, expectedFormId) {
  const expected = String(expectedFormId || '').trim().toLowerCase();
  const definition = resolveFormDefinition(payload);
  const actual = String(
    definition?.guid || definition?.id || definition?.formId || payload?.guid || payload?.id || payload?.formId || ''
  ).trim().toLowerCase();

  let fields = [];
  let isV4Modules = false;

  if (Array.isArray(definition?.modules) || Array.isArray(payload?.modules)) {
    isV4Modules = true;
    fields = extractFieldsFromModules(definition?.modules || payload?.modules);
  }

  if (fields.length === 0) {
    const groups = [
      definition?.formFieldGroups,
      definition?.fieldGroups,
      payload?.formFieldGroups,
      payload?.fieldGroups,
    ].find((candidate) => Array.isArray(candidate)) || [];

    fields = groups.flatMap((group) => Array.isArray(group?.fields) ? group.fields : []);
    if (fields.length === 0 && Array.isArray(definition?.fields)) fields = definition.fields;
    if (fields.length === 0 && Array.isArray(payload?.fields)) fields = payload.fields;
  }

  const index = new Map();
  for (const field of fields) {
    const canonical = canonicalFieldName(field?.name);
    if (!canonical) continue;
    if (!index.has(canonical)) index.set(canonical, field);
  }

  const missing = REQUIRED_NATIVE_LINEAGE_FIELDS.filter((name) => !index.has(name));
  const notHidden = REQUIRED_NATIVE_LINEAGE_FIELDS.filter((name) => {
    const field = index.get(name);
    return !field || field.hidden !== true;
  });

  const rawStatus = definition?.status || payload?.status;
  let publishedValue = definition?.isPublished ?? payload?.isPublished;
  if (publishedValue === undefined && typeof rawStatus === 'string') {
    publishedValue = rawStatus.toUpperCase() === 'PUBLISHED';
  }

  const updatedAt = definition?.updatedAt ?? definition?.internalUpdatedAt ?? payload?.updatedAt ?? null;

  let source = 'hubspot_form_definition';
  if (isV4Modules) {
    source = 'hubspot_render_definition_v4';
  } else if (payload?.form && typeof payload.form === 'object') {
    source = 'hubspot_embed_v3';
  }

  return {
    schema: 2,
    source,
    expected_form_id: expected,
    actual_form_id: actual,
    form_name: String(definition?.name || payload?.name || ''),
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
