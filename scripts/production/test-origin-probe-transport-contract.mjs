import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, 'verify-production-boundary.mjs'), 'utf8');

const required = [
  'http://localhost$route',
  '-H "Host: $EXPECTED_HOST"',
  "-H 'X-Forwarded-Proto: https'",
  "-H 'X-Forwarded-Port: 443'",
  "--proto '=http'",
  'reason=origin_not_loopback',
  "http://localhost/*|http://localhost",
];

for (const marker of required) {
  if (!source.includes(marker)) {
    console.error(`ORIGIN_PROBE_TRANSPORT_CONTRACT=FAIL missing=${marker}`);
    process.exit(1);
  }
}

const originBlock = source.match(/if \[\[ "\$probe_mode" == 'origin' \]\]; then([\s\S]*?)else\n\s*result=/)?.[1] || '';
if (!originBlock) {
  console.error('ORIGIN_PROBE_TRANSPORT_CONTRACT=FAIL reason=origin_block_missing');
  process.exit(1);
}
if (originBlock.includes('--resolve "$EXPECTED_HOST:443:127.0.0.1"')) {
  console.error('ORIGIN_PROBE_TRANSPORT_CONTRACT=FAIL reason=loopback_tls_resolve_restored');
  process.exit(1);
}
if (/\bcurl\b[^\n]*\s-L(?:\s|$)/.test(originBlock)) {
  console.error('ORIGIN_PROBE_TRANSPORT_CONTRACT=FAIL reason=origin_redirect_follow_forbidden');
  process.exit(1);
}
if (!source.includes("--proto '=https' --proto-redir '=https'")) {
  console.error('ORIGIN_PROBE_TRANSPORT_CONTRACT=FAIL reason=public_edge_https_contract_missing');
  process.exit(1);
}

console.log('ORIGIN_PROBE_TRANSPORT_CONTRACT=PASS local_http_vhost=true redirect_escape=false public_edge_https=true');
