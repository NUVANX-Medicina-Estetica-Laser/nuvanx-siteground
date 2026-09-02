import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const catalogPath = 'wp-content/themes/nuvanx-medical/inc/data/ads-conversion-catalog.json';
const catalogPhp = 'wp-content/themes/nuvanx-medical/inc/nvx-ads-conversion-catalog.php';
const gtmPath = 'wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php';
const bootstrapPath = 'wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php';

assert.equal(fs.existsSync(catalogPath), true, 'Ads conversion catalog must exist');
assert.equal(fs.existsSync(catalogPhp), true, 'Ads conversion catalog loader must exist');

const catalog = JSON.parse(fs.readFileSync(catalogPath, 'utf8'));
const sendTo = catalog?.google_ads?.phone_whatsapp?.send_to || catalog?.google_ads?.phone_whatsapp_send_to || '';
assert.equal(catalog.schema, 2);
assert.match(sendTo, /^AW-[0-9]{8,12}\/[A-Za-z0-9_-]+$/);

const gtm = fs.readFileSync(gtmPath, 'utf8');
const bootstrap = fs.readFileSync(bootstrapPath, 'utf8');
assert.doesNotMatch(gtm, /require_once.*nvx-ads-conversion-catalog/,
  'GTM integration must not laterally load ads catalog (bootstrap manifest owns this)');
assert.match(bootstrap, /'inc\/nvx-ads-conversion-catalog\.php'/,
  'Ads conversion catalog must be loaded from bootstrap manifest');
assert.match(gtm, /nvx_ads_conversion_client_context/);

const sendToPattern = /AW-[0-9]{8,12}\/[A-Za-z0-9_-]+/g;
const allowed = new Set([
  path.normalize(catalogPath),
  path.normalize('scripts/lint/test-ads-conversion-catalog.mjs'),
  path.normalize('scripts/lint/test-conversion-ownership-contract.mjs'),
]);

const roots = [
  'wp-content/themes/nuvanx-medical',
  'scripts',
];
const offenders = [];

function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'vendor' || entry.name === 'node_modules' || entry.name === 'dist') continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full);
      continue;
    }
    if (!/\.(js|mjs|php)$/.test(entry.name)) continue;
    const relative = path.normalize(full);
    if (allowed.has(relative)) continue;
    const source = fs.readFileSync(full, 'utf8');
    if (sendToPattern.test(source)) {
      offenders.push(relative);
    }
    sendToPattern.lastIndex = 0;
  }
}

for (const root of roots) walk(root);

assert.deepEqual(
  offenders,
  [],
  `Hardcoded Google Ads send_to IDs are forbidden outside the catalog: ${offenders.join(', ')}`,
);

console.log(`ADS_CONVERSION_CATALOG=PASS send_to=${sendTo} hardcoded_js_php=0`);
