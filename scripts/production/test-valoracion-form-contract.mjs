import assert from 'node:assert/strict';
import {
  browserHubSpotValoracionOwners,
  canonicalValoracionFirstPartyIssues,
  firstPartyValoracionFormTags,
  firstPartyValoracionOwnerTags,
} from './valoracion-form-contract.mjs';

const canonical = [
  '<div id="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1"><form class="nvx-valoracion-direct-form" data-nvx-direct-form method="post"></form></div>',
  '<main><DIV data-nvx-first-party-owner="1" id="nvx-valoracion-first-party-form"><FORM DATA-NVX-DIRECT-FORM="1" class="other"></FORM></DIV></main>',
];

const invalid = [
  {
    html: '<div id="nvx-hubspot-native-form"><div class="hs-form-frame"></div></div>',
    issue: /first-party valoración owner|first-party valoración form|browser-owned HubSpot/,
  },
  {
    html: '<div id="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1"></div>',
    issue: /first-party valoración form/,
  },
  {
    html: '<form class="nvx-valoracion-direct-form" data-nvx-direct-form></form>',
    issue: /first-party valoración owner/,
  },
  {
    html: '<div id="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1"><form data-nvx-direct-form></form><form class="nvx-valoracion-direct-form"></form></div>',
    issue: /exactly one canonical first-party valoración form/,
  },
  {
    html: '<div id="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1"><form data-nvx-direct-form></form><iframe src="https://share-eu1.hsforms.com/x"></iframe></div>',
    issue: /zero browser-owned HubSpot/,
  },
  {
    html: '<div id="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1"><div class="hs-form-frame"></div><form data-nvx-direct-form></form></div>',
    issue: /zero browser-owned HubSpot/,
  },
  {
    html: '<div id="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1"><div id="nvx-hubspot-native-form"></div><form data-nvx-direct-form></form></div>',
    issue: /zero browser-owned HubSpot/,
  },
];

for (const [index, html] of canonical.entries()) {
  assert.deepEqual(canonicalValoracionFirstPartyIssues(html), [], `canonical case ${index + 1} must pass`);
  assert.equal(firstPartyValoracionOwnerTags(html).length, 1, `canonical case ${index + 1} owner count`);
  assert.equal(firstPartyValoracionFormTags(html).length, 1, `canonical case ${index + 1} form count`);
  assert.equal(browserHubSpotValoracionOwners(html).length, 0, `canonical case ${index + 1} browser owner count`);
}

for (const [index, test] of invalid.entries()) {
  const issues = canonicalValoracionFirstPartyIssues(test.html);
  assert.ok(issues.length > 0, `invalid case ${index + 1} must fail`);
  assert.match(issues.join('; '), test.issue, `invalid case ${index + 1} must report its structural defect`);
}

const ignoredScriptText = '<script>const x = `<div id="nvx-hubspot-native-form"><div class="hs-form-frame"></div><form data-nvx-direct-form></form>`;</script>'
  + '<div id="nvx-valoracion-first-party-form" data-nvx-first-party-owner="1"><form data-nvx-direct-form></form></div>';
assert.deepEqual(canonicalValoracionFirstPartyIssues(ignoredScriptText), [], 'script text must not create false browser-owner matches');

console.log(`VALORACION_FORM_CONTRACT_TEST=PASS canonical=${canonical.length} invalid=${invalid.length}`);
