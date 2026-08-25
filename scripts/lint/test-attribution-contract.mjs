import fs from 'node:fs';

const bridgePath = 'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php';
const integrationPath = 'wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php';
const googleRelayAuthPath = 'wp-content/themes/nuvanx-medical/inc/nvx-google-attribution-relay-auth.php';
const mode = fs.existsSync(bridgePath) ? 'v2' : 'legacy';

console.log(`ATTRIBUTION_GATE_MIGRATION mode=${mode}`);
await import(mode === 'v2' ? './test-attribution-contract-v2.mjs' : './test-attribution-contract-legacy.mjs');
if (mode === 'v2' && fs.existsSync(integrationPath)) {
  await import('./test-attribution-integration-wiring.mjs');
}
if (mode === 'v2') {
  await import('./test-hubspot-v4-hidden-lineage.mjs');
  await import('./test-lead-captured-server-relay.mjs');
  if (fs.existsSync(googleRelayAuthPath)) {
    await import('./test-google-attribution-relay-auth.mjs');
  }
}
