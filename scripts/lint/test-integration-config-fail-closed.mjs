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
const modal = fs.readFileSync(new URL('wp-content/themes/nuvanx-medical/inc/nvx-valoracion-modal.php', repoRoot), 'utf8');
const direct = fs.readFileSync(new URL('wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php', repoRoot), 'utf8');
const diagnostic = fs.readFileSync(new URL('scripts/ci/validate-hubspot-form.sh', repoRoot), 'utf8');
const stagingDeploy = fs.readFileSync(new URL('tools/deploy/deploy-to-staging2.sh', repoRoot), 'utf8');
const stagingIdentity = JSON.parse(fs.readFileSync(new URL('lib/staging-public-integration-identities.json', repoRoot), 'utf8'));

assert.match(secure, /return '';\s*}/, 'HubSpot identity resolver must be able to return empty');
assert.match(secure, /nvx_missing_hubspot_identity/, 'Missing HubSpot identity must fail explicitly');
assert.match(secure, /nvx_hubspot_secure_identity_configured/, 'Secure bridge must expose an identity-configured contract');
assert.match(managed, /NVX_VALORACION_FORM_UNAVAILABLE secure_identity_not_configured/, 'Managed landing must fail closed when identity is absent');
assert.match(managed, /nvx_hubspot_secure_identity_configured/, 'Managed landing must require the authenticated HubSpot identity before rendering its first-party owner');
assert.match(modal, /nvx_hubspot_secure_identity_configured/, 'Modal must require the authenticated HubSpot identity before rendering its first-party owner');
assert.doesNotMatch(managed, /hs-form-frame|forms\/embed\//, 'Managed landing must not fall back to a browser HubSpot embed');
assert.doesNotMatch(modal, /hs-form-frame|forms\/embed\//, 'Modal must not fall back to a browser HubSpot embed');
assert.match(direct, /return \$failed\( 'hubspot_config', 0 \);/, 'Direct form must report missing HubSpot configuration');
assert.match(direct, /nvx_hubspot_secure_original_url/, 'Direct form transport must use the canonical secure resolver URL');
assert.match(diagnostic, /HUBSPOT_FORM_ID:\?Missing HUBSPOT_FORM_ID/, 'Diagnostic must require explicit form identity');
assert.match(diagnostic, /HUBSPOT_PORTAL:\?Missing HUBSPOT_PORTAL/, 'Diagnostic must require explicit portal identity');

// Staging always provisions the canonical pair from its governed public manifest
// before validation. A legacy branch may remain temporarily for Production-host
// compatibility, but it must never be the source selected by the normal Staging flow.
assert.match(stagingDeploy, /fail_config\(\)/, 'Staging deploy must expose a FAIL_CONFIG path');
assert.match(stagingDeploy, /exit 78/, 'Staging configuration failures must use EX_CONFIG=78');
assert.match(stagingDeploy, /wp config set NVX_HUBSPOT_PORTAL_ID/, 'Staging must provision the canonical portal identity');
assert.match(stagingDeploy, /wp config set NVX_HUBSPOT_VALORACION_FORM_ID/, 'Staging must provision the canonical form identity');
assert.match(stagingDeploy, /wp config get NVX_HUBSPOT_PORTAL_ID/, 'Staging must validate canonical portal identity from wp-config');
assert.match(stagingDeploy, /wp config get NVX_HUBSPOT_VALORACION_FORM_ID/, 'Staging must validate canonical form identity from wp-config');
assert.match(stagingDeploy, /source='canonical_wp_config'/, 'Staging verification must expose the canonical wp-config source');
assert.match(stagingDeploy, /staging-public-integration-identities\.json/, 'Staging deploy must source public identity from the governed Staging manifest');
assert.doesNotMatch(stagingDeploy, /canonical_production_wp_config|legacy_production_wp_config/, 'Staging deploy must not infer HubSpot identity from Production wp-config');
assert.match(stagingDeploy, /function nvx_hubspot_secure_identity/, 'Staging source gate must require the canonical resolver');
assert.match(stagingDeploy, /nvx_hubspot_secure_identity_configured/, 'Staging source gate must require fail-closed first-party form behavior');
assert.match(stagingDeploy, /jq -e 'type == "object"'/, 'Staging deploy must validate JSON object structure before extraction');
assert.match(stagingDeploy, /\[\[ "\$schema" == '1' \]\]/, 'Staging deploy must require schema 1');
assert.match(stagingDeploy, /\[\[ "\$theme_runtime_fallback" == 'false' \]\]/, 'Staging deploy must require theme_runtime_fallback == false');
assert.doesNotMatch(
  stagingDeploy,
  /grep -Fq ["']NVX_VALORACION_HS_FRAME_(?:PORTAL|FORM)_ID["'] \"\$modal_file\"/,
  'Staging deploy must not require legacy identity constants to be embedded in modal source',
);

assert.equal(stagingIdentity.schema, 1, 'Staging public integration manifest must use schema 1');
assert.equal(stagingIdentity.scope, 'staging2', 'Public integration manifest must be scoped only to Staging2');
assert.equal(stagingIdentity.classification, 'public_integration_identity', 'HubSpot account/form IDs must be classified as public integration identity');
assert.match(String(stagingIdentity.hubspot?.portal_id || ''), /^[0-9]{1,20}$/, 'Staging HubSpot portal ID must be syntactically valid');
assert.match(String(stagingIdentity.hubspot?.form_id || ''), /^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-5][0-9A-Fa-f]{3}-[89AaBb][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$/, 'Staging HubSpot form ID must be syntactically valid');
assert.equal(stagingIdentity.guardrails?.theme_runtime_fallback, false, 'Staging identity manifest must not authorize a theme runtime fallback');
assert.equal(stagingIdentity.guardrails?.contains_private_credentials, false, 'Staging identity manifest must not contain private credentials');
assert.equal(stagingIdentity.guardrails?.production_mutation, false, 'Staging identity manifest must forbid Production mutation');

// Verify resolver precedence/fail-closed behavior. Legacy aliases remain only
// as a temporary Production-host migration bridge and cannot override a present
// canonical layer, valid or malformed.
const TEST_PORTAL = '88888888';
const TEST_FORM = '11111111-2222-4333-8444-555555555555';
const phpRunner = `<?php
function run_case($setup, $expected_portal, $expected_form) {
  $code = "define('ABSPATH', __DIR__ . '/'); function add_filter(\\$t, \\$c, \\$p=10, \\$a=1){} function sanitize_text_field(\\$x){return \\$x;} function sanitize_key(\\$x){return \\$x;} " . $setup . " require_once 'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php'; \\$res = nvx_hubspot_secure_identity(); if (\\$res['portal_id'] !== '$expected_portal' || \\$res['form_id'] !== '$expected_form') { exit(1); }";
  $cmd = 'php -r ' . escapeshellarg($code);
  exec($cmd, $out, $ret);
  if ($ret !== 0) exit(1);
}
run_case("define('NVX_HUBSPOT_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_HUBSPOT_VALORACION_FORM_ID', '${TEST_FORM}');", "${TEST_PORTAL}", "${TEST_FORM}");
run_case("define('NVX_HUBSPOT_PORTAL_ID', 'invalid'); define('NVX_VALORACION_HS_FRAME_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_FORM_ID', '${TEST_FORM}');", "", "");
run_case("define('NVX_HUBSPOT_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_FORM_ID', '${TEST_FORM}');", "", "");
run_case("define('NVX_VALORACION_HS_FRAME_PORTAL_ID', '${TEST_PORTAL}'); define('NVX_VALORACION_HS_FRAME_FORM_ID', '${TEST_FORM}');", "${TEST_PORTAL}", "${TEST_FORM}");
run_case("putenv('NVX_HUBSPOT_PORTAL_ID=${TEST_PORTAL}'); putenv('NVX_HUBSPOT_VALORACION_FORM_ID=${TEST_FORM}');", "${TEST_PORTAL}", "${TEST_FORM}");
run_case("putenv('NVX_HUBSPOT_PORTAL_ID=invalid'); putenv('NVX_VALORACION_HS_FRAME_PORTAL_ID=${TEST_PORTAL}'); putenv('NVX_VALORACION_HS_FRAME_FORM_ID=${TEST_FORM}');", "", "");
`;

execSync('php', { input: phpRunner, cwd: repoRoot, stdio: ['pipe', 'inherit', 'inherit'] });

console.log('INTEGRATION_CONFIG_FAIL_CLOSED=PASS browser_form_fallbacks=0 staging_source=canonical resolver_legacy_alias=temporary-production-migration validated_pairs=6 public_manifest=1');
