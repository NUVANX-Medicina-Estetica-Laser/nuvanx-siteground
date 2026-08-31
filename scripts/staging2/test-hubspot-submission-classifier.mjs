import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {
  HUBSPOT_PORTAL_ID,
  HUBSPOT_FORM_ID,
  HUBSPOT_PRODUCTION_FORBIDDEN_PATTERNS,
} from './hubspot-config.mjs';
import {
  shouldBlockHubSpotRequest,
  classifyHubSpotSubmissionRequest,
} from './hubspot-submission-classifier.mjs';

const portalId = HUBSPOT_PORTAL_ID;
const formId = HUBSPOT_FORM_ID;

const classify = (method, url) => classifyHubSpotSubmissionRequest({ method, url, portalId, formId });
const shouldBlock = (method, url) => shouldBlockHubSpotRequest({ method, url });

// 1. Unauthenticated v3 submit endpoint
const res1 = classify('POST', `https://api.hsforms.com/submissions/v3/integration/submit/${portalId}/${formId}`);
assert.equal(res1.isSubmission, true, 'unauthenticated v3 submit endpoint must be classified as submission');
assert.equal(res1.isConfirmedSubmission, true);
assert.equal(res1.shouldBlock, true);
assert.equal(res1.reason, 'exact-v3-submit-endpoint');
assert.equal(res1.hostname, 'api.hsforms.com');
assert.equal(res1.pathname, `/submissions/v3/integration/submit/${portalId}/${formId}`);

// 2. Secure v3 submit endpoint
const res2 = classify('POST', `https://api.hsforms.com/submissions/v3/integration/secure/submit/${portalId}/${formId}`);
assert.equal(res2.isSubmission, true, 'secure v3 submit endpoint must be blocked and classified');
assert.equal(res2.isConfirmedSubmission, true);
assert.equal(res2.shouldBlock, true);

// 3. Async v3 submit endpoint
const res3 = classify('POST', `https://api.hsforms.com/submissions/v3/integration/async/submit/${portalId}/${formId}`);
assert.equal(res3.isSubmission, true, 'async v3 submit endpoint must be blocked and classified');
assert.equal(res3.isConfirmedSubmission, true);
assert.equal(res3.shouldBlock, true);

// 4. Forms Next multipart submit endpoint
const res4 = classify('POST', `https://api.hsforms.com/submissions/v3/public/submit/formsnext/multipart/${portalId}/${formId}`);
assert.equal(res4.isSubmission, true, 'formsnext multipart submit endpoint must be blocked and classified');
assert.equal(res4.isConfirmedSubmission, true);
assert.equal(res4.shouldBlock, true);

// 5. Legacy v2 uploads submit endpoint
const res5 = classify('POST', `https://forms.hsforms.com/uploads/form/v2/${portalId}/${formId}`);
assert.equal(res5.isSubmission, true, 'legacy v2 uploads submit endpoint must be blocked and classified');
assert.equal(res5.isConfirmedSubmission, true);
assert.equal(res5.shouldBlock, true);

// 6. Regional hsforms.com submit endpoint
const res6 = classify('POST', `https://api-eu1.hsforms.com/submissions/v3/integration/submit/${portalId}/${formId}`);
assert.equal(res6.isSubmission, true, 'regional hsforms submit endpoint must be blocked and classified');
assert.equal(res6.hostname, 'api-eu1.hsforms.com');

// 7. hsforms.net host variant
const res7 = classify('POST', `https://api.hsforms.net/submissions/v3/integration/submit/${portalId}/${formId}`);
assert.equal(res7.isSubmission, true, 'hsforms.net submit endpoint must be blocked and classified');
assert.equal(res7.hostname, 'api.hsforms.net');

// 8. hubspot.com domain submit endpoint
const res8 = classify('POST', `https://api.hubspot.com/submissions/v3/integration/submit/${portalId}/${formId}`);
assert.equal(res8.isSubmission, true, 'hubspot.com domain submit endpoint must be blocked and classified');
assert.equal(res8.hostname, 'api.hubspot.com');

// 9. Unconfirmed submission for another form on page: blocked for safety, but not confirmed as our form
const res9 = classify('POST', `https://api.hsforms.com/submissions/v3/integration/submit/${portalId}/00000000-0000-0000-0000-000000000000`);
assert.equal(res9.isSubmission, false, 'another form must not be classified as this form submission');
assert.equal(res9.isConfirmedSubmission, false);
assert.equal(res9.shouldBlock, true, 'another form must still be aborted for safety');
assert.equal(res9.reason, 'unconfirmed-submission-blocked-for-safety');

// 10. Future /submissions/v4/ path: blocked for safety, but not confirmed as our form
const res10 = classify('POST', `https://api.hsforms.com/submissions/v4/integration/submit/${portalId}/${formId}`);
assert.equal(res10.isSubmission, false);
assert.equal(res10.shouldBlock, true, 'future v4 submission route must still be aborted for safety');

// 11. Case-insensitive method and path handling
const res11 = classify('post', `https://api.hsforms.com/Submissions/v3/Integration/Submit/${portalId}/${formId}`);
assert.equal(res11.isSubmission, true, 'classifier should be case-insensitive for method and path');
assert.equal(res11.shouldBlock, true);

// 12. GET method is not blocked
const res12 = classify('GET', `https://api.hsforms.com/submissions/v3/integration/submit/${portalId}/${formId}`);
assert.equal(res12.isSubmission, false, 'GET is not a submission');
assert.equal(res12.shouldBlock, false);
assert.equal(res12.reason, 'method');
assert.equal(res12.method, 'GET');

// 13. Malformed URL yields reason: 'url'
const res13 = classify('POST', 'not a valid url: // %%%');
assert.equal(res13.isSubmission, false, 'malformed URL must not be classified as a submission');
assert.equal(res13.shouldBlock, false);
assert.equal(res13.reason, 'url');

// 14. Form bootstrap URL is not itself a submission. Browser governance is
// separately required to keep this endpoint unused by the public runtime.
const bootstrapUrl = `https://forms.hsforms.com/embed/v3/form/${portalId}/${formId}`;
const res14 = classify('POST', bootstrapUrl);
assert.equal(res14.isSubmission, false, 'form bootstrap URL must not be classified as a submission');
assert.equal(res14.shouldBlock, false, 'form bootstrap URL is outside the submission classifier scope');
assert.equal(res14.reason, 'path');
assert.equal(res14.hostname, 'forms.hsforms.com');
assert.equal(res14.pathname, `/embed/v3/form/${portalId}/${formId}`);

// 15. Telemetry must not be blocked by the submission classifier.
const res15 = classify('POST', `https://forms.hsforms.com/telemetry?formId=${formId}`);
assert.equal(res15.isSubmission, false, 'telemetry containing the form ID must not be classified as a submission');
assert.equal(res15.shouldBlock, false);
assert.equal(res15.reason, 'path');

// 16. Lookalike endpoint on external host must not be blocked
const res16 = classify('POST', `https://example.com/submissions/v3/integration/submit/${portalId}/${formId}`);
assert.equal(res16.isSubmission, false, 'lookalike endpoint on another host must not be blocked');
assert.equal(res16.shouldBlock, false);
assert.equal(res16.reason, 'host');
assert.equal(res16.hostname, 'example.com');
assert.equal(res16.pathname, `/submissions/v3/integration/submit/${portalId}/${formId}`);

// 17. shouldBlock predicate standalone check
assert.equal(shouldBlock('POST', `https://api.hsforms.com/submissions/v3/integration/submit/${portalId}/${formId}`).shouldBlock, true);
assert.equal(shouldBlock('GET', `https://api.hsforms.com/submissions/v3/integration/submit/${portalId}/${formId}`).shouldBlock, false);
assert.equal(shouldBlock('POST', bootstrapUrl).shouldBlock, false);

// Production release verification must never create a contact or synthetic
// attribution event. Keep this source-level regression beside the submission
// classifier so every production-eligible Staging acceptance executes it.
const productionProbe = await fs.readFile(new URL('./h1-hubspot-e2e.mjs', import.meta.url), 'utf8');

for (const [pattern, message] of HUBSPOT_PRODUCTION_FORBIDDEN_PATTERNS) {
  assert.doesNotMatch(productionProbe, pattern, message);
}
assert.match(
  productionProbe,
  /HUBSPOT_PRODUCTION_CONTRACT_MODE=ZERO_SUBMIT/,
  'production HubSpot probe must declare the zero-submit contract'
);
assert.match(
  productionProbe,
  /PRODUCTION_HUBSPOT_CONTRACT=PASS/,
  'production HubSpot probe must expose an auditable zero-submit PASS marker'
);

// Browser HubSpot form rendering is retired. Runtime governance may load only
// HubSpot's global analytics script after marketing consent; the visible form is
// first-party HTML and its submission is bridged server-side.
const runtimeGovernance = await fs.readFile(
  new URL('../../wp-content/themes/nuvanx-medical/assets/js/nvx-runtime-governance.js', import.meta.url),
  'utf8'
);
const directForm = await fs.readFile(
  new URL('../../wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php', import.meta.url),
  'utf8'
);

assert.match(
  runtimeGovernance,
  /function loadHubSpotGlobalTracking\(\)/,
  'runtime must keep HubSpot global tracking isolated from form rendering'
);
assert.match(
  runtimeGovernance,
  /script\.src = 'https:\/\/js\.hs-scripts\.com\/' \+ portalId \+ '\.js'/,
  'HubSpot browser ownership must be limited to the consented global analytics script'
);
assert.doesNotMatch(
  runtimeGovernance,
  /hbspt\.forms\.create|forms\.hsforms\.com\/embed|\.hs-form-frame|iframe\s*\[[^\]]*hsforms/i,
  'retired HubSpot browser-form owners must not return to runtime governance'
);
assert.match(
  directForm,
  /data-nvx-direct-form/,
  'canonical first-party valoración form marker must remain in source'
);
assert.match(
  directForm,
  /First-party valoración form\./,
  'first-party valoración source must remain the visible-form owner'
);

console.log('HUBSPOT_SUBMISSION_CLASSIFIER_TEST=PASS cases=17');
console.log(`HUBSPOT_PRODUCTION_ZERO_SUBMIT_GUARD=PASS forbidden_patterns=${HUBSPOT_PRODUCTION_FORBIDDEN_PATTERNS.length}`);
console.log('HUBSPOT_BROWSER_FORM_RETIREMENT_CONTRACT=PASS iframe_owner=0 first_party_owner=1 analytics_owner=consented_global_only');
