#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const repoRoot = process.cwd();
const tracked = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8' })
  .split('\0')
  .filter(Boolean)
  .sort();
const trackedSet = new Set(tracked);

const failures = [];
const report = [];
const fail = (kind, value) => failures.push(`${kind}: ${value}`);

const residuePatterns = [
  /(?:^|\/)(?:scratch|tmp|temp|backup|backups)(?:\/|$)/i,
  /(?:\.bak|\.old|\.orig|\.rej|\.tmp|\.swp|~)$/i,
  /(?:^|\/)\.DS_Store$/,
  /(?:^|\/)Thumbs\.db$/i,
];

for (const file of tracked) {
  if (residuePatterns.some((pattern) => pattern.test(file))) {
    fail('TRACKED_RESIDUE', file);
  }

  const absolute = path.join(repoRoot, file);
  let stat;
  try {
    stat = fs.statSync(absolute);
  } catch {
    fail('TRACKED_PATH_MISSING_FROM_WORKTREE', file);
    continue;
  }
  if (stat.isFile() && stat.size === 0) {
    fail('EMPTY_TRACKED_FILE', file);
  }
}

const rootCode = tracked.filter((file) =>
  !file.includes('/') && /\.(?:php|js|mjs|cjs|py|sh)$/i.test(file),
);
for (const file of rootCode) {
  fail('ROOT_CODE_SURFACE_FORBIDDEN', file);
}

const forbiddenOneShotNames = tracked.filter((file) =>
  /(?:^|\/)(?:patch|hotfix|fix|temp|tmp|test)[_-].*\.(?:php|js|mjs|cjs|py|sh)$/i.test(file)
  && !file.startsWith('scripts/lint/')
  && !file.startsWith('scripts/ci/')
  && !file.startsWith('scripts/staging2/')
  && !file.startsWith('scripts/production/')
);
for (const file of forbiddenOneShotNames) {
  fail('UNOWNED_ONE_SHOT_SURFACE', file);
}

const executableRefPattern = /(?:^|[\s"'=(:])((?:scripts|tools|lib|wp-content)\/[A-Za-z0-9_./@-]+\.(?:mjs|js|cjs|php|sh|py|json|css))(?:$|[\s"'):,])/g;

function assertLiteralRefs(owner, source) {
  executableRefPattern.lastIndex = 0;
  for (const match of source.matchAll(executableRefPattern)) {
    const ref = match[1].replace(/^\.\//, '');
    if (!trackedSet.has(ref) && !fs.existsSync(path.join(repoRoot, ref))) {
      fail('BROKEN_LOCAL_REFERENCE', `${owner} -> ${ref}`);
    }
  }
}

const packageJson = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
for (const [name, command] of Object.entries(packageJson.scripts || {})) {
  assertLiteralRefs(`package.json#${name}`, String(command));
}

const workflowFiles = tracked.filter((file) => /^\.github\/workflows\/.*\.ya?ml$/i.test(file));
for (const workflow of workflowFiles) {
  assertLiteralRefs(workflow, fs.readFileSync(path.join(repoRoot, workflow), 'utf8'));
}

const bootstrapPath = 'wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php';
assert.ok(trackedSet.has(bootstrapPath), 'Canonical theme bootstrap must be tracked');
const bootstrapSource = fs.readFileSync(path.join(repoRoot, bootstrapPath), 'utf8');
for (const match of bootstrapSource.matchAll(/['"](inc\/[A-Za-z0-9_./-]+\.php)['"]/g)) {
  const modulePath = `wp-content/themes/nuvanx-medical/${match[1]}`;
  if (!trackedSet.has(modulePath)) {
    fail('BOOTSTRAP_MODULE_MISSING', modulePath);
  }
}

const phpFiles = tracked.filter((file) => file.endsWith('.php'));
for (const file of phpFiles) {
  const source = fs.readFileSync(path.join(repoRoot, file), 'utf8');
  const literalDirIncludes = source.matchAll(/\b(?:require|require_once|include|include_once)\s*(?:\(\s*)?__DIR__\s*\.\s*['"]([^'"]+\.php)['"]/g);
  for (const match of literalDirIncludes) {
    const resolved = path.posix.normalize(path.posix.join(path.posix.dirname(file), match[1]));
    if (!trackedSet.has(resolved)) {
      fail('BROKEN_LITERAL_PHP_INCLUDE', `${file} -> ${resolved}`);
    }
  }
}

const textExtensions = /\.(?:md|txt|json|ya?ml|php|mjs|js|cjs|sh|py|css)$/i;
const textFiles = tracked.filter((file) => textExtensions.test(file));
const textCorpus = new Map();
for (const file of textFiles) {
  try {
    textCorpus.set(file, fs.readFileSync(path.join(repoRoot, file), 'utf8'));
  } catch {
    // Binary or unreadable files are outside this textual-reference report.
  }
}

const migrations = tracked.filter((file) => /^tools\/migrations\/.*\.php$/i.test(file));
const orphanMigrations = [];
for (const migration of migrations) {
  const basename = path.posix.basename(migration);
  const referencedElsewhere = [...textCorpus].some(([owner, source]) => owner !== migration && source.includes(basename));
  if (!referencedElsewhere) orphanMigrations.push(migration);
}
if (orphanMigrations.length > 0) {
  report.push(`MIGRATION_ORPHAN_CANDIDATES=${orphanMigrations.join(',')}`);
}

const variantPattern = /^(.*?)-(safe|resilient|legacy|old|backup|copy|v\d+)\.(mjs|js|cjs|php|sh|py)$/i;
const variantCandidates = [];
for (const file of tracked) {
  const base = path.posix.basename(file);
  const match = base.match(variantPattern);
  if (!match) continue;
  const sibling = path.posix.join(path.posix.dirname(file), `${match[1]}.${match[3]}`);
  if (trackedSet.has(sibling)) variantCandidates.push(`${file}<=>${sibling}`);
}
if (variantCandidates.length > 0) {
  report.push(`VARIANT_DUPLICATION_CANDIDATES=${variantCandidates.join(',')}`);
}

for (const line of report) console.log(`${line}=REPORT`);

if (failures.length > 0) {
  for (const failure of failures) console.error(`REPOSITORY_HYGIENE=FAIL ${failure}`);
  console.error(`REPOSITORY_HYGIENE=FAIL count=${failures.length}`);
  process.exit(1);
}

console.log(
  `REPOSITORY_HYGIENE=PASS tracked=${tracked.length}`
  + ` workflows=${workflowFiles.length}`
  + ` php=${phpFiles.length}`
  + ` migrations=${migrations.length}`
  + ` zero_byte=0 root_code=0 residue=0 broken_refs=0`,
);
