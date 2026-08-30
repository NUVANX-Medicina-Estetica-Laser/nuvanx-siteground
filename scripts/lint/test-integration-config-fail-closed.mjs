import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const repoRoot = new URL('../../', import.meta.url);
const files = [
  'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php',
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php',
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php',
  'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-modal.php',
  'wp-content/themes/nuvanx-medical/inc/nvx-hero-and-forms.php',
  'scripts/ci/validate-hubspot-form.sh',
  'tools/deploy/deploy-to-staging2.sh',
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

const secure = fs.readFileSync(new URL('wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php', repoRoot), 'utf8');
const managed = fs.readFileSync(new URL('wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php', repoRoot), 'utf8');
const direct = fs.readFileSync(new URL('wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php', repoRoot), 'utf8');
const diagnostic = fs.readFileSync(new URL('scripts/ci/validate-hubspot-form.sh', repoRoot), 'utf8');
const stagingDeploy = fs.readFileSync(new URL('tools/deploy/deploy-to-staging2.sh', repoRoot), 'utf8');

assert.match(secure, /return '';\s*\n}/, 'HubSpot identity resolver must be able to return empty');
assert.match(secure, /nvx_missing_hubspot_identity/, 'Missing HubSpot identity must fail explicitly');
assert.match(secure, /nvx_hubspot_secure_identity_configured/, 'Secure bridge must expose an identity-configured contract');
assert.match(managed, /NVX_HUBSPOT_FORM_UNAVAILABLE reason=identity_not_configured/, 'Managed landing must fail closed when identity is absent');
assert.match(direct, /return \$failed\( 'hubspot_config', 0 \);/, 'Direct form must report missing HubSpot configuration');
assert.match(direct, /nvx_hubspot_secure_original_url/, 'Direct form transport must use the canonical secure resolver URL');
assert.match(diagnostic, /HUBSPOT_FORM_ID:\?Missing HUBSPOT_FORM_ID/, 'Diagnostic must require explicit form identity');
assert.match(diagnostic, /HUBSPOT_PORTAL:\?Missing HUBSPOT_PORTAL/, 'Diagnostic must require explicit portal identity');

// Staging deploy must validate the same resolver contract and classify missing
// environment identity as configuration, never require theme-owned legacy defaults.
assert.match(stagingDeploy, /fail_config\(\)/, 'Staging deploy must expose a FAIL_CONFIG path');
assert.match(stagingDeploy, /exit 78/, 'Staging configuration failures must use EX_CONFIG=78');
assert.match(stagingDeploy, /wp config get NVX_HUBSPOT_PORTAL_ID/, 'Staging must read canonical portal identity from wp-config');
assert.match(stagingDeploy, /wp config get NVX_HUBSPOT_VALORACION_FORM_ID/, 'Staging must read canonical form identity from wp-config');
assert.match(stagingDeploy, /function nvx_hubspot_secure_identity/, 'Staging source gate must require the canonical resolver');
assert.match(stagingDeploy, /nvx_hubspot_secure_identity_configured/, 'Staging source gate must require fail-closed modal behavior');
assert.doesNotMatch(
  stagingDeploy,
  /grep -Fq ["']NVX_VALORACION_HS_FRAME_(?:PORTAL|FORM)_ID["'] \"\$modal_file\"/,
  'Staging deploy must not require legacy identity constants to be embedded in modal source',
);

// Verify runtime fail-closed behavior across priority layers via PHP subprocesses.
const TEST_PORTAL = '88888888';
const TEST_FORM = '11111111-2222-4333-8444-555555555555';
const phpRunner = `<?php
function run_case($setup, $expected_portal, $expected_form) {
  $code = "define('ABSPATH', __DIR__ . '/'); function add_filter(\\$t, \\$c, \\$p=10, \\$a=1){} " . $setup . " require_once 'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php'; \\$res = nvx_hubspot_secure_identity(); if (\\$res['portal_id'] !== '$expected_portal' || \\$res['form_id'] !== '$expected_form') { exit(1); }";
  $cmd = 'php -r ' . escapeshellarg($code);
  exec($cmd, $out, $ret);
  if ($ret !== 0) {
    exit(1);
  }
}
run_case("define('NVX_HUBSPOT_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_HUBSPOT_VALORACION_FORM_ID', '${TEST_FORM}');", "${TEST_PORTAL}", "${TEST_FORM}");
run_case("define('NVX_HUBSPOT_PORTAL_ID', 'invalid'); define('NVX_VALORACION_HS_FRAME_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_FORM_ID', '${TEST_FORM}');", "", "");
run_case("define('NVX_HUBSPOT_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_FORM_ID', '${TEST_FORM}');", "", "");
run_case("define('NVX_VALORACION_HS_FRAME_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_FORM_ID', '${TEST_FORM}');", "${TEST_PORTAL}", "${TEST_FORM}");
run_case("putenv('NVX_HUBSPOT_PORTAL_ID=${TEST_PORTAL}'); putenv('NVX_HUBSPOT_VALORACION_FORM_ID=${TEST_FORM}');", "${TEST_PORTAL}", "${TEST_FORM}");
run_case("putenv('NVX_HUBSPOT_PORTAL_ID=invalid'); putenv('NVX_VALORACION_HS_FRAME_PORTAL_ID=${TEST_PORTAL}'); putenv('NVX_VALORACION_HS_FRAME_FORM_ID=${TEST_FORM}');", "", "");
`;

execSync('php', { input: phpRunner, cwd: repoRoot, stdio: ['pipe', 'inherit', 'inherit'] });

console.log('INTEGRATION_CONFIG_FAIL_CLOSED=PASS hubspot_runtime_fallbacks=0 validated_pairs=6 staging_config_gate=1');
