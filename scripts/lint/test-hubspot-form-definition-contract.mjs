import assert from 'node:assert/strict';
import {
  REQUIRED_NATIVE_LINEAGE_FIELDS,
  assertHubSpotFormDefinitionContract,
  inspectHubSpotFormDefinition,
  waitForHubSpotEmbedDefinition,
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

// Test retry handling: 429 followed by 200
const retryEntries429 = [
  {
    url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
    response: {
      status: () => 429,
      json: async () => ({ error: 'rate_limited' }),
    },
  },
  {
    url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
    response: {
      status: () => 200,
      json: async () => embedPayload(),
    },
  },
];
const retryResult429 = await waitForHubSpotEmbedDefinition(retryEntries429, FORM_ID, 100, 10);
assert.equal(retryResult429.source, `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`);
assert.equal(retryResult429.payload.form.guid, FORM_ID);

// Test retry handling: 503 followed by 200
const retryEntries503 = [
  {
    url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
    response: {
      status: () => 503,
      json: async () => ({ error: 'service_unavailable' }),
    },
  },
  {
    url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
    response: {
      status: () => 200,
      json: async () => embedPayload(),
    },
  },
];
const retryResult503 = await waitForHubSpotEmbedDefinition(retryEntries503, FORM_ID, 100, 10);
assert.equal(retryResult503.payload.form.guid, FORM_ID);

// Test dynamic retry: 429 initially, 200 pushed asynchronously
const dynamicEntries = [
  {
    url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
    response: {
      status: () => 429,
      json: async () => ({ error: 'rate_limited' }),
    },
  },
];
setTimeout(() => {
  dynamicEntries.push({
    url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
    response: {
      status: () => 200,
      json: async () => embedPayload(),
    },
  });
}, 30);
const dynamicResult = await waitForHubSpotEmbedDefinition(dynamicEntries, FORM_ID, 500, 10);
assert.equal(dynamicResult.payload.form.guid, FORM_ID);

// Test transient failure on persistent 429
const persistent429Entries = [
  {
    url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
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
    requests: [{ url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json` }],
    failedRequests: [{
      url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
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
    requests: [{ url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json` }],
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
      url: `https://forms.hsforms.com/embed/v3/form/12345/${FORM_ID}/json`,
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

console.log('HUBSPOT_FORM_DEFINITION_CONTRACT=PASS embed_v3=1 top_level_compat=1 missing_field_detection=1 hidden_field_detection=1 retry_429=1 retry_503=1 dynamic_retry=1 transient_persistence=1 missing_request_regression=1 transport_failure_transient=1 in_flight_timeout_transient=1');
