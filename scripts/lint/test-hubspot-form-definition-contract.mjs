import assert from 'node:assert/strict';
import {
  REQUIRED_NATIVE_LINEAGE_FIELDS,
  assertHubSpotFormDefinitionContract,
  embedResponseMatchesForm,
  inspectHubSpotFormDefinition,
  isHubSpotEmbedDefinitionUrl,
  waitForHubSpotEmbedDefinition,
} from '../staging2/hubspot-form-definition-contract.mjs';

const FORM_ID = '5042522a-0bc5-4381-ac3e-5aee8649b69c';
const PORTAL_ID = '147416356';

// -----------------------------------------------------------------------------
// URL Validation Tests (V4 render-definition + Regional Hosts + Legacy V3)
// -----------------------------------------------------------------------------
const validV4Url = `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`;
const validV4RegionalUrl = `https://forms-eu1.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`;
const validV3Url = `https://forms.hsforms.com/embed/v3/form/${PORTAL_ID}/${FORM_ID}/json`;
const untrustedHostUrl = `https://malicious.example.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`;
const unrelatedPathUrl = `https://forms.hsforms.com/other/api/endpoint`;

assert.equal(isHubSpotEmbedDefinitionUrl(validV4Url), true);
assert.equal(isHubSpotEmbedDefinitionUrl(validV4RegionalUrl), true);
assert.equal(isHubSpotEmbedDefinitionUrl(validV3Url), true);
assert.equal(isHubSpotEmbedDefinitionUrl(untrustedHostUrl), false);
assert.equal(isHubSpotEmbedDefinitionUrl(unrelatedPathUrl), false);

assert.equal(embedResponseMatchesForm({ url: validV4Url }, FORM_ID), true);
assert.equal(embedResponseMatchesForm({ url: validV4RegionalUrl }, FORM_ID), true);
assert.equal(embedResponseMatchesForm({ url: validV3Url }, FORM_ID), true);
assert.equal(embedResponseMatchesForm({ url: validV4Url }, 'other-form-id'), false);
assert.equal(embedResponseMatchesForm({ url: untrustedHostUrl }, FORM_ID), false);

// -----------------------------------------------------------------------------
// Forms V4 Render-Definition Fixtures & Parsing Tests
// -----------------------------------------------------------------------------
function v4Modules(overrides = {}) {
  const baseModules = [
    {
      type: 'field',
      propertyReference: { name: 'email', hubspotDefined: true },
      hidden: false,
    },
    {
      type: 'field',
      propertyReference: { name: 'firstname' },
      hidden: false,
    },
    {
      type: 'hidden',
      propertyReference: { name: '1-1/nvx_lead_id' },
    },
    {
      type: 'hidden',
      propertyReference: { name: '1-2/nvx_is_test_lead' },
    },
    {
      type: 'group',
      modules: [
        {
          type: 'hidden',
          propertyReference: { name: '1-3/nvx_test_run_id' },
        },
        {
          type: 'hidden',
          propertyReference: { name: '1-4/nvx_utm_source' },
        },
        {
          type: 'hidden',
          propertyReference: { name: '1-5/nvx_google_click_id' },
        },
      ],
    },
  ];

  if (typeof overrides === 'function') {
    return overrides(baseModules);
  }
  return baseModules;
}

function v4Payload(modules = v4Modules(), formOverrides = {}) {
  return {
    form: {
      id: FORM_ID,
      guid: FORM_ID,
      name: 'Endolift V4 Form',
      status: 'PUBLISHED',
      updatedAt: '2026-08-30T13:37:04.603Z',
      modules,
      ...formOverrides,
    },
  };
}

// 1. Healthy V4 inspection
const healthyV4 = inspectHubSpotFormDefinition(v4Payload(), FORM_ID);
assert.equal(healthyV4.source, 'hubspot_render_definition_v4');
assert.equal(healthyV4.actual_form_id, FORM_ID);
assert.equal(healthyV4.published, true);
assert.deepEqual(healthyV4.missing_fields, []);
assert.deepEqual(healthyV4.non_hidden_fields, []);
assert.equal(assertHubSpotFormDefinitionContract(healthyV4), true);

// 2. Missing field detection on V4
const missingFieldV4Payload = v4Payload(
  v4Modules((mods) => [
    mods[0],
    mods[1],
    mods[2], // has nvx_lead_id
    // nvx_is_test_lead missing
    {
      type: 'group',
      modules: [
        { type: 'hidden', propertyReference: { name: 'nvx_test_run_id' } },
        { type: 'hidden', propertyReference: { name: 'nvx_utm_source' } },
        { type: 'hidden', propertyReference: { name: 'nvx_google_click_id' } },
      ],
    },
  ])
);
const missingV4Report = inspectHubSpotFormDefinition(missingFieldV4Payload, FORM_ID);
assert.deepEqual(missingV4Report.missing_fields, ['nvx_is_test_lead']);
assert.throws(
  () => assertHubSpotFormDefinitionContract(missingV4Report),
  /missing native lineage fields: nvx_is_test_lead/i
);

// 3. Non-hidden field detection on V4
const visibleFieldV4Payload = v4Payload(
  v4Modules((mods) => [
    mods[0],
    mods[1],
    mods[2],
    {
      type: 'field', // not type hidden
      hidden: false,
      propertyReference: { name: 'nvx_is_test_lead' },
    },
    mods[4],
  ])
);
const visibleV4Report = inspectHubSpotFormDefinition(visibleFieldV4Payload, FORM_ID);
assert.deepEqual(visibleV4Report.non_hidden_fields, ['nvx_is_test_lead']);
assert.throws(
  () => assertHubSpotFormDefinitionContract(visibleV4Report),
  /must be Hidden: nvx_is_test_lead/i
);

// 4. Unpublished form detection on V4
const unpublishedV4 = inspectHubSpotFormDefinition(v4Payload(v4Modules(), { status: 'DRAFT' }), FORM_ID);
assert.equal(unpublishedV4.published, false);
assert.throws(
  () => assertHubSpotFormDefinitionContract(unpublishedV4),
  /canonical HubSpot form must remain published/i
);

// -----------------------------------------------------------------------------
// Legacy V3 & Top-Level Compatibility Tests
// -----------------------------------------------------------------------------
function legacyHiddenFields() {
  return REQUIRED_NATIVE_LINEAGE_FIELDS.map((name, index) => ({
    name: `${index + 1}-1/${name}`,
    hidden: true,
    fieldType: name === 'nvx_is_test_lead' ? 'single_checkbox' : 'single_line_text',
  }));
}

function legacyEmbedPayload(fields = legacyHiddenFields()) {
  return {
    form: {
      guid: FORM_ID,
      name: 'Endolift Legacy V3',
      isPublished: true,
      updatedAt: '2026-08-30T13:37:04.603Z',
      formFieldGroups: [{ fields }],
    },
  };
}

const healthyV3 = inspectHubSpotFormDefinition(legacyEmbedPayload(), FORM_ID);
assert.equal(healthyV3.source, 'hubspot_embed_v3');
assert.equal(healthyV3.actual_form_id, FORM_ID);
assert.deepEqual(healthyV3.missing_fields, []);
assert.deepEqual(healthyV3.non_hidden_fields, []);
assert.equal(assertHubSpotFormDefinitionContract(healthyV3), true);

const topLevel = inspectHubSpotFormDefinition({
  guid: FORM_ID,
  name: 'Legacy-compatible top-level shape',
  isPublished: true,
  formFieldGroups: [{ fields: legacyHiddenFields() }],
}, FORM_ID);
assert.equal(topLevel.source, 'hubspot_form_definition');
assert.equal(assertHubSpotFormDefinitionContract(topLevel), true);

// -----------------------------------------------------------------------------
// Realistic Forms V4 Network Interception & Retry Tests
// -----------------------------------------------------------------------------
// Test retry handling: 429 followed by 200 on V4 URL
const retryEntries429 = [
  {
    url: `https://forms-eu1.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
    response: {
      status: () => 429,
      json: async () => ({ error: 'rate_limited' }),
    },
  },
  {
    url: `https://forms-eu1.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
    response: {
      status: () => 200,
      json: async () => v4Payload(),
    },
  },
];
const retryResult429 = await waitForHubSpotEmbedDefinition(retryEntries429, FORM_ID, 100, 10);
assert.equal(retryResult429.source, `https://forms-eu1.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`);
assert.equal(retryResult429.payload.form.id, FORM_ID);

// Test retry handling: 503 followed by 200 on V4 URL
const retryEntries503 = [
  {
    url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
    response: {
      status: () => 503,
      json: async () => ({ error: 'service_unavailable' }),
    },
  },
  {
    url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
    response: {
      status: () => 200,
      json: async () => v4Payload(),
    },
  },
];
const retryResult503 = await waitForHubSpotEmbedDefinition(retryEntries503, FORM_ID, 100, 10);
assert.equal(retryResult503.payload.form.id, FORM_ID);

// Test dynamic retry: 429 initially, 200 pushed asynchronously on V4 URL
const dynamicEntries = [
  {
    url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
    response: {
      status: () => 429,
      json: async () => ({ error: 'rate_limited' }),
    },
  },
];
setTimeout(() => {
  dynamicEntries.push({
    url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
    response: {
      status: () => 200,
      json: async () => v4Payload(),
    },
  });
}, 30);
const dynamicResult = await waitForHubSpotEmbedDefinition(dynamicEntries, FORM_ID, 500, 10);
assert.equal(dynamicResult.payload.form.id, FORM_ID);

// Test transient failure on persistent 429
const persistent429Entries = [
  {
    url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
    response: {
      status: () => 429,
      json: async () => ({ error: 'rate_limited' }),
    },
  },
];
let capturedError429 = null;
try {
  await waitForHubSpotEmbedDefinition(persistent429Entries, FORM_ID, 50, 10);
} catch (error) {
  capturedError429 = error;
}
assert.ok(capturedError429, 'Persistent 429 must throw');
assert.equal(capturedError429.exitCode, 75);
assert.match(capturedError429.message, /temporarily unavailable status=429/);

// Test candidate regression when no request was emitted by the page
let capturedNoRequestError = null;
try {
  await waitForHubSpotEmbedDefinition({ requests: [], failedRequests: [], responses: [] }, FORM_ID, 50, 10);
} catch (error) {
  capturedNoRequestError = error;
}
assert.ok(capturedNoRequestError, 'Missing request must throw regression');
assert.match(capturedNoRequestError.message, /CANDIDATE_REGRESSION: HubSpot embed definition request for form .* was not emitted/);

// Test transient failure when request failed at network transport layer
let capturedTransportError = null;
try {
  await waitForHubSpotEmbedDefinition({
    requests: [{ url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}` }],
    failedRequests: [{
      url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
      errorText: 'net::ERR_CONNECTION_REFUSED',
    }],
    responses: [],
  }, FORM_ID, 50, 10);
} catch (error) {
  capturedTransportError = error;
}
assert.ok(capturedTransportError, 'Transport failure must throw transient error');
assert.equal(capturedTransportError.exitCode, 75);
assert.match(capturedTransportError.message, /transport failed for form .*: net::ERR_CONNECTION_REFUSED/);

// Test transient failure when request timed out in-flight
let capturedInFlightTimeout = null;
try {
  await waitForHubSpotEmbedDefinition({
    requests: [{ url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}` }],
    failedRequests: [],
    responses: [],
  }, FORM_ID, 50, 10);
} catch (error) {
  capturedInFlightTimeout = error;
}
assert.ok(capturedInFlightTimeout, 'In-flight timeout must throw transient error');
assert.equal(capturedInFlightTimeout.exitCode, 75);
assert.match(capturedInFlightTimeout.message, /request for form .* timed out in flight/);

// Test candidate regression on 404
let captured404Error = null;
try {
  await waitForHubSpotEmbedDefinition([
    {
      url: `https://forms.hsforms.com/embed/v4/render-definition/${PORTAL_ID}/${FORM_ID}`,
      response: {
        status: () => 404,
        json: async () => ({ error: 'not_found' }),
      },
    },
  ], FORM_ID, 50, 10);
} catch (error) {
  captured404Error = error;
}
assert.ok(captured404Error, '404 must throw regression');
assert.match(captured404Error.message, /CANDIDATE_REGRESSION: HubSpot embed definition status=404/);

console.log('HUBSPOT_FORM_DEFINITION_CONTRACT=PASS render_definition_v4=1 embed_v3=1 top_level_compat=1 missing_field_detection=1 hidden_field_detection=1 retry_429=1 retry_503=1 dynamic_retry=1 transient_persistence=1 missing_request_regression=1 transport_failure_transient=1 in_flight_timeout_transient=1');

