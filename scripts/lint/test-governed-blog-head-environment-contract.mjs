#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const target = path.join(root, 'scripts/staging2/governed-blog-head-contract.mjs');
const source = await fs.readFile(target, 'utf8');

const failures = [];
const requireSource = (needle, reason) => {
  if (!source.includes(needle)) failures.push(reason);
};
const forbidSource = (needle, reason) => {
  if (source.includes(needle)) failures.push(reason);
};

requireSource(
  "norm(headData.og[0] || '') !== norm(expectedCanonical)",
  'og_url_must_match_environment_canonical'
);
requireSource(
  "norm(headData.canonical[0] || '') !== norm(expectedCanonical)",
  'canonical_must_match_environment_canonical'
);
forbidSource(
  "const prod = 'https://nuvanx.com';",
  'staging_contract_must_not_hardcode_production_origin'
);
forbidSource(
  "norm(`${prod}/${post.slug}/`)",
  'og_url_must_not_force_production_origin'
);

if (failures.length > 0) {
  console.error(`GOVERNED_BLOG_HEAD_ENVIRONMENT_CONTRACT=FAIL reasons=${failures.join(',')}`);
  process.exit(1);
}

console.log('GOVERNED_BLOG_HEAD_ENVIRONMENT_CONTRACT=PASS canonical=environment-local og_url=environment-local production_hardcode=forbidden');
