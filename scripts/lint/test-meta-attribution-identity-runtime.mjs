import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const runtimePath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-attribution-contract.js';
const source = fs.readFileSync(runtimePath, 'utf8');

function storage(initial = {}) {
  const values = new Map(Object.entries(initial).map(([key, value]) => [String(key), String(value)]));
  return {
    getItem: (k) => values.get(String(k)) ?? null,
    setItem: (k, v) => values.set(String(k), String(v)),
    removeItem: (k) => values.delete(String(k)),
  };
}

function run(href, { localStorage: providedLocalStorage = null, cookie = '' } = {}) {
  const location = new URL(href);
  const localStorage = providedLocalStorage || storage();
  const sessionStorage = storage();
  const document = {
    referrer: '',
    cookie,
    readyState: 'complete',
    querySelector: () => null,
    createElement: () => ({ type: '', name: '', value: '' }),
    addEventListener: () => {},
  };
  const window = {
    location: { href: location.href, hostname: location.hostname, search: location.search },
    localStorage,
    sessionStorage,
    wp_has_consent: () => true,
    crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
    nvxConversionEvents: { qa: { is_test_lead: false, test_run_id: '' } },
  };
  vm.runInNewContext(source, { window, document, URL, URLSearchParams, Date, Uint8Array, Array, Object, Set, Boolean, String, Number, JSON, RegExp, Math, console }, { filename: runtimePath });
  return { contract: window.NUVANXAttributionContract, localStorage };
}

const meta = run('https://nuvanx.com/madrid/valoracion/?fbclid=MetaClick123').contract.getConversionTouch();
assert.equal(meta.channel, 'paid_social');
assert.equal(meta.source, 'meta');
assert.equal(meta.fbclid, 'MetaClick123');
assert.match(meta.fbc, /^fb\.1\.\d{10,16}\.MetaClick123$/);
assert.equal(meta.fbp, undefined, 'FBP must remain absent when no real _fbp cookie exists');

const google = run('https://nuvanx.com/madrid/valoracion/?gclid=GoogleClick123').contract.getConversionTouch();
assert.equal(google.channel, 'paid_search');
assert.equal(google.source, 'google');
assert.equal(google.gclid, 'GoogleClick123');

const existingConversion = {
  channel: 'paid_search', source: 'google', medium: 'cpc', gclid: 'ExistingGoogleClick',
  timestamp: new Date().toISOString(), expires_at: Date.now() + 60_000,
};
const sharedStorage = storage({ nvx_conversion_touch: JSON.stringify(existingConversion) });
const malformedRun = run('https://nuvanx.com/madrid/valoracion/?fbclid=%3Cscript%3Ebad%3C%2Fscript%3E', { localStorage: sharedStorage });
const malformedConversion = malformedRun.contract.getConversionTouch();
const malformedFirst = malformedRun.contract.getFirstTouch();
assert.equal(malformedConversion.channel, 'paid_search', 'Malformed fbclid must not replace a valid conversion touch');
assert.equal(malformedConversion.source, 'google');
assert.equal(malformedConversion.gclid, 'ExistingGoogleClick');
assert.notEqual(malformedFirst?.channel, 'paid_social');
assert.notEqual(malformedFirst?.source, 'meta');
assert.equal(malformedFirst?.fbclid, undefined);

const oversizedFbp = `fb.1.1788140000000.${'A'.repeat(500)}`;
const oversizedTouch = run('https://nuvanx.com/madrid/valoracion/', { cookie: `_fbp=${oversizedFbp}` }).contract.getConversionTouch();
assert.equal(oversizedTouch.fbp, undefined, 'Complete FBP values over 512 characters must be rejected');

console.log('META_ATTRIBUTION_RUNTIME=PASS paid_social=1 malformed_rejected=1 valid_touch_preserved=1 fbc_derived=1 fbp_synthesized=0 meta_bound=512 google_contract=preserved');
