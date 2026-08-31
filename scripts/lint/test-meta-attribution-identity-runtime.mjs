import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const runtimePath = 'wp-content/themes/nuvanx-medical/assets/js/nvx-attribution-contract.js';
const source = fs.readFileSync(runtimePath, 'utf8');

function storage() {
  const values = new Map();
  return { getItem: (k) => values.get(k) ?? null, setItem: (k, v) => values.set(k, String(v)), removeItem: (k) => values.delete(k) };
}

function run(href) {
  const location = new URL(href);
  const localStorage = storage();
  const sessionStorage = storage();
  const document = {
    referrer: '',
    cookie: '',
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
  return window.NUVANXAttributionContract.getConversionTouch();
}

const meta = run('https://nuvanx.com/madrid/valoracion/?fbclid=MetaClick123');
assert.equal(meta.channel, 'paid_social');
assert.equal(meta.source, 'meta');
assert.equal(meta.fbclid, 'MetaClick123');
assert.match(meta.fbc, /^fb\.1\.\d{10,16}\.MetaClick123$/);
assert.equal(meta.fbp, undefined, 'FBP must remain absent when no real _fbp cookie exists');

const google = run('https://nuvanx.com/madrid/valoracion/?gclid=GoogleClick123');
assert.equal(google.channel, 'paid_search');
assert.equal(google.source, 'google');
assert.equal(google.gclid, 'GoogleClick123');

console.log('META_ATTRIBUTION_RUNTIME=PASS paid_social=1 fbc_derived=1 fbp_synthesized=0 google_contract=preserved');
