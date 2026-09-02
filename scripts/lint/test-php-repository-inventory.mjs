import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const tracked = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8' })
  .split('\0')
  .filter(Boolean)
  .filter((file) => file.endsWith('.php'))
  .sort();

const buckets = {
  theme: [],
  scripts: [],
  tools: [],
  lib: [],
  root: [],
  other: [],
};

for (const file of tracked) {
  if (file.startsWith('wp-content/themes/nuvanx-medical/')) buckets.theme.push(file);
  else if (file.startsWith('scripts/')) buckets.scripts.push(file);
  else if (file.startsWith('tools/')) buckets.tools.push(file);
  else if (file.startsWith('lib/')) buckets.lib.push(file);
  else if (!file.includes('/')) buckets.root.push(file);
  else buckets.other.push(file);
}

assert.ok(tracked.length > 0, 'Expected at least one tracked PHP file');
assert.equal(buckets.other.length, 0,
  `Unclassified tracked PHP paths require explicit ownership review: ${buckets.other.join(', ')}`);
assert.ok(buckets.theme.length >= 100,
  `Theme PHP inventory unexpectedly small: ${buckets.theme.length}`);
assert.ok(buckets.scripts.length > 0, 'Expected tracked PHP QA scripts');
assert.ok(buckets.tools.length > 0, 'Expected tracked PHP tooling/migrations');
assert.ok(buckets.lib.length > 0, 'Expected tracked shared PHP libraries');

const classifiedTotal = buckets.theme.length
  + buckets.scripts.length
  + buckets.tools.length
  + buckets.lib.length
  + buckets.root.length;
assert.equal(classifiedTotal, tracked.length, 'Every tracked PHP file must have exactly one owner bucket');

const themeSources = new Map(
  buckets.theme.map((file) => [file, fs.readFileSync(file, 'utf8')]),
);
const strictTypesFiles = [...themeSources]
  .filter(([, source]) => /declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/.test(source))
  .map(([file]) => file);
const directErrorLogFiles = [...themeSources]
  .filter(([, source]) => /\berror_log\s*\(/.test(source))
  .map(([file]) => file);
const directReviewProvenanceFiles = [...themeSources]
  .filter(([, source]) => /\[['"](?:reviewedBy|lastReviewed)['"]\]\s*=/.test(source))
  .map(([file]) => file);

console.log(
  `PHP_REPOSITORY_INVENTORY=PASS total=${tracked.length}`
  + ` theme=${buckets.theme.length}`
  + ` scripts=${buckets.scripts.length}`
  + ` tools=${buckets.tools.length}`
  + ` lib=${buckets.lib.length}`
  + ` root=${buckets.root.length}`
  + ` other=${buckets.other.length}`
);
console.log(
  `PHP_RESIDUAL_AUDIT_METRICS=REPORT theme=${buckets.theme.length}`
  + ` strict_types=${strictTypesFiles.length}`
  + ` direct_error_log_files=${directErrorLogFiles.length}`
  + ` direct_review_provenance_files=${directReviewProvenanceFiles.length}`
);
console.log(`PHP_STRICT_TYPES_FILES=${strictTypesFiles.join(',')}`);
console.log(`PHP_DIRECT_ERROR_LOG_FILES=${directErrorLogFiles.join(',')}`);
console.log(`PHP_DIRECT_REVIEW_PROVENANCE_FILES=${directReviewProvenanceFiles.join(',')}`);
