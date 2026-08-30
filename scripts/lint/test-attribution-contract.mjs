import fs from 'node:fs';

const bridgePath = 'wp-content/themes/nuvanx-medical/inc/nvx-hubspot-secure-attribution.php';
const integrationPath = 'wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php';

// nvx-hubspot-secure-attribution.php is a permanent requirement; v2 is the only valid mode.
if (!fs.existsSync(bridgePath)) {
  console.error('ATTRIBUTION_GATE_MIGRATION bridge missing — aborting');
  process.exit(1);
}

console.log('ATTRIBUTION_GATE_MIGRATION mode=v2');
await import('./test-attribution-contract-v2.mjs');
if (fs.existsSync(integrationPath)) {
  await import('./test-attribution-integration-wiring.mjs');
}
await import('./test-hubspot-v4-hidden-lineage.mjs');
await import('./test-hubspot-v4-runtime-contract.mjs');
await import('./test-lead-captured-server-relay.mjs');
await import('./test-google-attribution-relay-auth.mjs');
await import('./test-supabase-relay-queue.mjs');
await import('./test-ads-conversion-catalog.mjs');
