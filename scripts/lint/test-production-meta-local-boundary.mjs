import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const boundaryPath = path.join(root, 'scripts/production/verify-production-boundary.mjs');
const source = fs.readFileSync(boundaryPath, 'utf8');

const requiredLocalRoutes = [
  '/clinicas-de-medicina-estetica-nuvanx/',
  '/medicina-estetica-chamberi/',
  '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/',
];

const failures = [];
const nodeRouteInventory = source.match(/const routes = \[([\s\S]*?)\];/)?.[1] ?? '';
const siteGroundRouteInventory = source.match(/for route in [\\]\n([\s\S]*?)\ndo/)?.[1] ?? '';
for (const route of requiredLocalRoutes) {
  const inNodeRoutes = nodeRouteInventory.includes(`'${route}'`);
  const inSiteGroundRoutes = siteGroundRouteInventory.includes(`'${route}'`);
  if (!inNodeRoutes || !inSiteGroundRoutes) {
    failures.push(`${route}: expected in both JS and SiteGround route inventories`);
  }
}

if (!source.includes('routes=12 identity_fields=4 render_contract=pass meta_no_consent=pass')) {
  failures.push('SiteGround boundary summary must report routes=12');
}
if (!source.includes('metaNoConsentIssues(html, response.headers)')) {
  failures.push('external route loop must retain Meta no-consent checks');
}
if (!source.includes("^set-cookie:[[:space:]]*(_fbp|_fbc)=")) {
  failures.push('SiteGround route loop must retain raw _fbp/_fbc Set-Cookie rejection');
}

if (failures.length > 0) {
  console.error('PRODUCTION_META_LOCAL_BOUNDARY=FAIL');
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log(`PRODUCTION_META_LOCAL_BOUNDARY=PASS local_routes=${requiredLocalRoutes.length} total_boundary_routes=12 dual_path=1`);
