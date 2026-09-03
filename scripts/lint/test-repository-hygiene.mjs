#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const repoRoot = process.cwd();
const themeRoot = 'wp-content/themes/nuvanx-medical/';
const hygieneOwner = 'scripts/lint/test-repository-hygiene.mjs';
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
  && !file.startsWith('scripts/production/'),
);
for (const file of forbiddenOneShotNames) {
  fail('UNOWNED_ONE_SHOT_SURFACE', file);
}

// Workflows contain both executable inputs and runtime outputs. Only executable
// source references are repository-owned inputs. JSON/CSS artifacts, credentials,
// evidence files and remote deployment stamps are intentionally outside this gate.
const executableRefPattern = /(?:^|[\s"'=(:])((?:scripts|tools|lib|wp-content)\/[A-Za-z0-9_./@-]+\.(?:mjs|js|cjs|php|sh|py))(?:$|[\s"'):,])/g;

function executableRefs(source) {
  executableRefPattern.lastIndex = 0;
  return [...source.matchAll(executableRefPattern)].map((match) => match[1].replace(/^\.\//, ''));
}

function assertExecutableRefs(owner, source) {
  for (const ref of executableRefs(source)) {
    if (!trackedSet.has(ref) && !fs.existsSync(path.join(repoRoot, ref))) {
      fail('BROKEN_EXECUTABLE_REFERENCE', `${owner} -> ${ref}`);
    }
  }
}

const packageJson = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
for (const [name, command] of Object.entries(packageJson.scripts || {})) {
  assertExecutableRefs(`package.json#${name}`, String(command));
}

const workflowFiles = tracked.filter((file) => /^\.github\/workflows\/.*\.ya?ml$/i.test(file));
for (const workflow of workflowFiles) {
  assertExecutableRefs(workflow, fs.readFileSync(path.join(repoRoot, workflow), 'utf8'));
}

const bootstrapPath = `${themeRoot}inc/nvx-theme-bootstrap.php`;
assert.ok(trackedSet.has(bootstrapPath), 'Canonical theme bootstrap must be tracked');
const bootstrapSource = fs.readFileSync(path.join(repoRoot, bootstrapPath), 'utf8');
const bootstrapModules = new Set();
for (const match of bootstrapSource.matchAll(/['"](inc\/[A-Za-z0-9_./-]+\.php)['"]/g)) {
  const modulePath = `${themeRoot}${match[1]}`;
  bootstrapModules.add(modulePath);
  if (!trackedSet.has(modulePath)) {
    fail('BOOTSTRAP_MODULE_MISSING', modulePath);
  }
}

const externalRuntimeIncludes = new Set(['wp-load.php']);
const externalRuntimeOwners = [];
const literalPhpEdges = [];
const unanchoredLiteralIncludes = [];

function assertPhpInclude(file, baseDirectory, target) {
  const relativeTarget = target.replace(/^\/+/, '');
  const resolved = path.posix.normalize(path.posix.join(baseDirectory, relativeTarget));
  if (trackedSet.has(resolved)) {
    literalPhpEdges.push({ owner: file, target: resolved });
    return;
  }

  if (externalRuntimeIncludes.has(resolved) && file.startsWith('tools/migrations/')) {
    externalRuntimeOwners.push(`${file}->${resolved}`);
    return;
  }

  fail('BROKEN_LITERAL_PHP_INCLUDE', `${file} -> ${resolved}`);
}

const phpFiles = tracked.filter((file) => file.endsWith('.php'));
const includeCheckedPhpFiles = phpFiles;

// token_get_all() is the syntax boundary for PHP includes. Starting only from
// T_REQUIRE/T_REQUIRE_ONCE/T_INCLUDE/T_INCLUDE_ONCE means quoted assertions,
// comments and fixture strings cannot masquerade as executable includes. Each
// token expression is collected until its semicolon, so multiline formatting
// and multiple includes on one physical line are both covered.
const phpTokenizer = String.raw`
$files = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = [];
$include_tokens = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];
foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException('Unable to read PHP source: ' . $file);
    }
    $tokens = token_get_all($source);
    $count = count($tokens);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array($token[0], $include_tokens, true)) {
            continue;
        }
        $expression = '';
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $next = $tokens[$cursor];
            if (!is_array($next) && $next === ';') {
                break;
            }
            if (is_array($next)) {
                if (in_array($next[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next[0] === T_WHITESPACE) {
                    $expression .= ' ';
                    continue;
                }
                $expression .= $next[1];
                continue;
            }
            $expression .= $next;
        }
        $out[$file][] = trim($expression);
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
`;

let phpIncludeExpressions = {};
try {
  const encoded = execFileSync('php', ['-r', phpTokenizer], {
    cwd: repoRoot,
    input: JSON.stringify(includeCheckedPhpFiles),
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
  });
  phpIncludeExpressions = JSON.parse(encoded || '{}');
} catch (error) {
  fail('PHP_TOKENIZER_FAILED', error instanceof Error ? error.message : String(error));
}

const dirnameIncludePattern = /dirname\s*\(\s*__DIR__(?:\s*,\s*(\d+))?\s*\)\s*\.\s*(['"])([^'"]+\.php)\2/;
const directIncludePattern = /__DIR__\s*\.\s*(['"])([^'"]+\.php)\1/;
const plainLiteralIncludePattern = /^\s*(['"])([^'"]+\.php)\1\s*$/;

for (const file of includeCheckedPhpFiles) {
  const fileDirectory = path.posix.dirname(file);
  for (const expression of phpIncludeExpressions[file] || []) {
    const dirnameMatch = expression.match(dirnameIncludePattern);
    if (dirnameMatch) {
      let baseDirectory = fileDirectory;
      const levels = dirnameMatch[1] ? Number.parseInt(dirnameMatch[1], 10) : 1;
      for (let level = 0; level < levels; level += 1) {
        baseDirectory = path.posix.dirname(baseDirectory);
      }
      assertPhpInclude(file, baseDirectory, dirnameMatch[3]);
      continue;
    }

    const directMatch = expression.match(directIncludePattern);
    if (directMatch) {
      assertPhpInclude(file, fileDirectory, directMatch[2]);
      continue;
    }

    // Bare literal includes do not resolve relative to the including file in
    // PHP. Their runtime lookup depends on include_path and the executing
    // script's working directory, neither of which is a repository invariant.
    // Keep them explicitly outside this anchored-path gate rather than model
    // them incorrectly. They remain visible in the report for later migration
    // toward __DIR__-anchored ownership.
    const plainLiteralMatch = expression.match(plainLiteralIncludePattern);
    if (plainLiteralMatch) {
      unanchoredLiteralIncludes.push(`${file}->${plainLiteralMatch[2]}`);
      continue;
    }

    // Dynamic includes are intentionally outside the literal-path contract.
    // If an expression contains both __DIR__ and a literal PHP suffix, however,
    // it is repository-owned syntax we do not understand and must fail closed.
    if (/__DIR__/.test(expression) && /['"][^'"]+\.php['"]/.test(expression)) {
      fail('UNSUPPORTED_LITERAL_PHP_INCLUDE', `${file} -> ${expression}`);
    }
  }
}

if (externalRuntimeOwners.length > 0) {
  report.push(`EXTERNAL_WORDPRESS_RUNTIME_DEPENDENCIES=${[...new Set(externalRuntimeOwners)].join(',')}`);
}
if (unanchoredLiteralIncludes.length > 0) {
  report.push(`UNANCHORED_LITERAL_PHP_INCLUDES=${[...new Set(unanchoredLiteralIncludes)].join(',')}`);
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

// Theme ownership is reachability, not mere textual adjacency. Established
// WordPress entry surfaces are roots; literal include edges plus the canonical
// bootstrap manifest are traversed transitively. An orphan cycle under inc/
// therefore cannot make itself look owned.
const themeRuntimePhpFiles = phpFiles.filter((file) =>
  file.startsWith(themeRoot)
  && !file.startsWith(`${themeRoot}tests/`)
  && !file.startsWith(`${themeRoot}tools/`),
);
const themeRuntimePhpSet = new Set(themeRuntimePhpFiles);
const immediateThemeModules = themeRuntimePhpFiles.filter((file) =>
  new RegExp(`^${themeRoot.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}inc/[^/]+\\.php$`).test(file),
);
const themeEntryRoots = new Set(
  themeRuntimePhpFiles.filter((file) => {
    const relative = file.slice(themeRoot.length);
    return !relative.includes('/')
      || relative.startsWith('templates/')
      || relative.startsWith('template-parts/');
  }),
);
const themeAdjacency = new Map();
for (const file of themeRuntimePhpFiles) themeAdjacency.set(file, new Set());
for (const edge of literalPhpEdges) {
  if (themeRuntimePhpSet.has(edge.owner) && themeRuntimePhpSet.has(edge.target)) {
    themeAdjacency.get(edge.owner)?.add(edge.target);
  }
}
if (themeRuntimePhpSet.has(bootstrapPath)) {
  const bootstrapEdges = themeAdjacency.get(bootstrapPath) || new Set();
  for (const modulePath of bootstrapModules) {
    if (themeRuntimePhpSet.has(modulePath)) bootstrapEdges.add(modulePath);
  }
  themeAdjacency.set(bootstrapPath, bootstrapEdges);
}

const reachableThemePhp = new Set();
const reachabilityQueue = [...themeEntryRoots];
while (reachabilityQueue.length > 0) {
  const current = reachabilityQueue.shift();
  if (!current || reachableThemePhp.has(current) || !themeRuntimePhpSet.has(current)) continue;
  reachableThemePhp.add(current);
  for (const target of themeAdjacency.get(current) || []) {
    if (!reachableThemePhp.has(target)) reachabilityQueue.push(target);
  }
}
for (const modulePath of immediateThemeModules) {
  if (!reachableThemePhp.has(modulePath)) {
    fail('UNREFERENCED_THEME_MODULE', modulePath);
  }
}

const retainedMigrationGuards = new Map([
  [
    'tools/migrations/migrate-contacto-template.php',
    {
      owner: `${themeRoot}inc/nvx-contacto-valoracion-page.php`,
      marker: 'templates/template-contact.php',
      reason: 'legacy_contact_template_runtime_compatibility',
    },
  ],
]);

const migrations = tracked.filter((file) => /^tools\/migrations\/.*\.php$/i.test(file));
const orphanMigrations = [];
const retainedLegacyMigrations = [];
const executableTextFiles = [...textCorpus].filter(([owner]) =>
  owner !== hygieneOwner && !/\.(?:md|txt)$/i.test(owner),
);
for (const migration of migrations) {
  // Retained one-time migrations must have an explicit live compatibility
  // owner. The hygiene registry itself never counts as ownership.
  const retained = retainedMigrationGuards.get(migration);
  if (retained) {
    const ownerSource = textCorpus.get(retained.owner) || '';
    if (!trackedSet.has(retained.owner) || !ownerSource.includes(retained.marker)) {
      fail('RETAINED_MIGRATION_GUARD_BROKEN', `${migration} -> ${retained.owner}#${retained.marker}`);
    } else {
      retainedLegacyMigrations.push(`${migration}->${retained.owner}:${retained.reason}`);
    }
    continue;
  }

  const basename = path.posix.basename(migration);
  const referencedByExecutableContract = executableTextFiles.some(
    ([owner, source]) => owner !== migration && source.includes(basename),
  );
  if (!referencedByExecutableContract) {
    orphanMigrations.push(migration);
  }
}
if (orphanMigrations.length > 0) {
  for (const migration of orphanMigrations) fail('ORPHAN_MIGRATION', migration);
}
if (retainedLegacyMigrations.length > 0) {
  report.push(`RETAINED_LEGACY_MIGRATIONS=${retainedLegacyMigrations.join(',')}`);
}

const variantPattern = /^(.*?)-(safe|resilient|legacy|old|backup|copy|v\d+)\.(mjs|js|cjs|php|sh|py)$/i;
const variantCandidates = [];
for (const file of tracked) {
  const base = path.posix.basename(file);
  const match = base.match(variantPattern);
  if (!match) continue;
  const sibling = path.posix.join(path.posix.dirname(file), `${match[1]}.${match[3]}`);
  if (!trackedSet.has(sibling)) continue;

  const fileSource = textCorpus.get(file) || '';
  const siblingSource = textCorpus.get(sibling) || '';
  const pairIsExplicitlyOwned = fileSource.includes(path.posix.basename(sibling))
    || siblingSource.includes(path.posix.basename(file));
  if (!pairIsExplicitlyOwned) variantCandidates.push(`${file}<=>${sibling}`);
}
if (variantCandidates.length > 0) {
  for (const pair of variantCandidates) fail('UNOWNED_VARIANT_DUPLICATION', pair);
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
  + ` php_include_edges=${literalPhpEdges.length}`
  + ` theme_roots=${themeEntryRoots.size}`
  + ` theme_reachable=${reachableThemePhp.size}`
  + ` theme_modules=${immediateThemeModules.length}`
  + ` migrations=${migrations.length}`
  + ` retained_legacy_migrations=${retainedLegacyMigrations.length}`
  + ` external_runtime_dependencies=${new Set(externalRuntimeOwners).size}`
  + ` unanchored_literal_includes=${new Set(unanchoredLiteralIncludes).size}`
  + ` zero_byte=0 root_code=0 residue=0 broken_exec_refs=0 orphan_theme_modules=0 orphan_migrations=0 unowned_variants=0`,
);
