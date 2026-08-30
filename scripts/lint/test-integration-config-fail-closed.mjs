import assert from 'node:assert/strict';
import fs from 'node:fs';

const repoRoot = new URL('../../', import.meta.url);
const files = [
  'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php',
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php',
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php',
  'scripts/ci/validate-hubspot-form.sh',
];

const forbidden = [
  '147416356',
  '5042522a-0bc5-4381-ac3e-5aee8649b69c',
];

for (const relative of files) {
  const path = new URL(relative, repoRoot);
  const source = fs.readFileSync(path, 'utf8');
  for (const value of forbidden) {
    assert.equal(source.includes(value), false, `${relative} must not contain production HubSpot fallback ${value}`);
  }
}

const secure = fs.readFileSync(new URL(files[0], repoRoot), 'utf8');
const managed = fs.readFileSync(new URL(files[1], repoRoot), 'utf8');
const direct = fs.readFileSync(new URL(files[2], repoRoot), 'utf8');
const diagnostic = fs.readFileSync(new URL(files[3], repoRoot), 'utf8');

assert.match(secure, /return '';\s*\n}/, 'HubSpot identity resolver must be able to return empty');
assert.match(secure, /nvx_missing_hubspot_identity/, 'Missing HubSpot identity must fail explicitly');
assert.match(secure, /nvx_hubspot_secure_identity_configured/, 'Secure bridge must expose an identity-configured contract');
assert.match(managed, /NVX_HUBSPOT_FORM_UNAVAILABLE reason=identity_not_configured/, 'Managed landing must fail closed when identity is absent');
assert.match(direct, /return \$failed\( 'hubspot_config', 0 \);/, 'Direct form must report missing HubSpot configuration');
assert.match(direct, /nvx_hubspot_secure_original_url/, 'Direct form transport must use the canonical secure resolver URL');
assert.match(diagnostic, /HUBSPOT_FORM_ID:\?Missing HUBSPOT_FORM_ID/, 'Diagnostic must require explicit form identity');
assert.match(diagnostic, /HUBSPOT_PORTAL:\?Missing HUBSPOT_PORTAL/, 'Diagnostic must require explicit portal identity');

console.log('INTEGRATION_CONFIG_FAIL_CLOSED=PASS hubspot_runtime_fallbacks=0');
