#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, extname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const ROOT = resolve(process.cwd());
const OUT = resolve(ROOT, 'audit-output');
mkdirSync(OUT, { recursive: true });

const severityRank = { CRITICAL: 0, HIGH: 1, MEDIUM: 2, LOW: 3, INFO: 4 };
const findings = [];
const coverage = [];
const syntaxFailures = [];
const parserFailures = [];
const trackedText = new Map();

function run(command, args, options = {}) {
  return spawnSync(command, args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, ...options });
}

function add(severity, rule, file, line, excerpt, note = '') {
  findings.push({ severity, rule, file, line, excerpt: String(excerpt ?? '').trim().slice(0, 360), note });
}

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function isBinary(buffer) {
  const sample = buffer.subarray(0, Math.min(buffer.length, 8192));
  for (const byte of sample) if (byte === 0) return true;
  return false;
}

const gitFiles = run('git', ['ls-files', '-z']);
if (gitFiles.status !== 0) {
  console.error(gitFiles.stderr || 'git ls-files failed');
  process.exit(2);
}
const files = gitFiles.stdout.split('\0').filter(Boolean).sort();

const RULES = [
  ['CRITICAL', 'merge-conflict-marker', /^(?:<{7}|>{7})(?:\s|$)/],
  ['CRITICAL', 'php-eval', /\beval\s*\(/],
  ['CRITICAL', 'js-eval', /\beval\s*\(/],
  ['CRITICAL', 'js-new-function', /\bnew\s+Function\s*\(/],
  ['CRITICAL', 'shell-download-pipe-exec', /\b(?:curl|wget)\b[^\n|]*\|\s*(?:bash|sh)\b/],
  ['HIGH', 'php-command-exec', /\b(?:shell_exec|system|passthru|proc_open|popen)\s*\(/],
  ['HIGH', 'php-exec', /(?<!->)(?<!::)\bexec\s*\(/],
  ['HIGH', 'php-unserialize', /\bunserialize\s*\(/],
  ['MEDIUM', 'php-maybe-unserialize', /\bmaybe_unserialize\s*\(/],
  ['HIGH', 'php-dynamic-include', /\b(?:include|include_once|require|require_once)\s*\(?\s*\$/],
  ['HIGH', 'php-user-input', /\$_(?:GET|POST|REQUEST|FILES|COOKIE)\b/],
  ['MEDIUM', 'php-server-input', /\$_SERVER\b/],
  ['HIGH', 'php-file-write', /\b(?:file_put_contents|fwrite|fopen|rename|copy|unlink)\s*\(/],
  ['HIGH', 'php-file-read', /\b(?:file_get_contents|readfile)\s*\(/],
  ['HIGH', 'wp-unsafe-redirect', /\bwp_redirect\s*\(/],
  ['MEDIUM', 'wp-safe-redirect', /\bwp_safe_redirect\s*\(/],
  ['HIGH', 'wp-remote-request', /\bwp_remote_(?:get|post|request)\s*\(/],
  ['HIGH', 'wpdb-query', /\$wpdb->(?:query|get_results|get_row|get_var|get_col)\s*\(/],
  ['MEDIUM', 'wpdb-prepare', /\$wpdb->prepare\s*\(/],
  ['HIGH', 'wp-rest-route', /\bregister_rest_route\s*\(/],
  ['HIGH', 'wp-ajax-nopriv', /wp_ajax_nopriv_/],
  ['HIGH', 'php-raw-variable-echo', /\becho\s+\$[A-Za-z_][A-Za-z0-9_]*(?:\s*[;,)]|\s*$)/],
  ['HIGH', 'js-dom-html-sink', /\.(?:innerHTML|outerHTML)\s*=|\binsertAdjacentHTML\s*\(|\bdocument\.write\s*\(/],
  ['HIGH', 'js-dynamic-script', /createElement\s*\(\s*['"]script['"]\s*\)|\.src\s*=\s*(?:location|window|document|[^;]*(?:searchParams|dataset))/],
  ['HIGH', 'node-child-process', /\b(?:exec|execSync|spawn|spawnSync|execFile|execFileSync)\s*\(/],
  ['HIGH', 'node-fs-write', /\b(?:writeFileSync|writeFile|appendFileSync|appendFile|renameSync|rmSync|unlinkSync|copyFileSync)\s*\(/],
  ['HIGH', 'node-fs-read', /\b(?:readFileSync|readFile|readdirSync|readdir|statSync|lstatSync|realpathSync)\s*\(/],
  ['MEDIUM', 'node-path-resolution', /\b(?:join|resolve)\s*\(/],
  ['MEDIUM', 'node-cli-input', /\bprocess\.argv\b/],
  ['MEDIUM', 'browser-storage', /\b(?:localStorage|sessionStorage)\b/],
  ['HIGH', 'tracking-direct-conversion', /\bgtag\s*\(|\bfbq\s*\(|\bsend_to\b/],
  ['HIGH', 'tracking-meta-browser', /connect\.facebook\.net|fbevents\.js|fbq\s*\(\s*['"]init['"]/],
  ['MEDIUM', 'error-suppression', /(^|[^\w])@[A-Za-z_\\][A-Za-z0-9_\\]*\s*\(/],
  ['LOW', 'todo-marker', /\b(?:TODO|FIXME|HACK|XXX)\b/i],
  ['MEDIUM', 'debug-call', /\b(?:var_dump|print_r|console\.debug)\s*\(/],
  ['MEDIUM', 'css-important', /!important\b/],
  ['MEDIUM', 'css-transition-all', /\btransition\s*:\s*all\b/i],
  ['HIGH', 'css-outline-disabled', /\boutline\s*:\s*(?:none|0)\b/i],
  ['MEDIUM', 'css-100vh', /\b100vh\b/],
  ['HIGH', 'html-target-blank', /target\s*=\s*['"]_blank['"]/i],
  ['HIGH', 'html-inline-handler', /\son(?:click|load|error|submit|change|input|mouseover|focus|blur)\s*=/i],
  ['MEDIUM', 'html-inline-style', /\sstyle\s*=\s*['"]/i],
  ['HIGH', 'seo-noindex', /\bnoindex\b/i],
  ['HIGH', 'action-pull-request-target', /^\s*pull_request_target\s*:/],
  ['HIGH', 'action-write-all', /^\s*permissions\s*:\s*write-all\b/],
  ['HIGH', 'shell-strict-host-key-disabled', /StrictHostKeyChecking\s*=\s*no/],
  ['HIGH', 'shell-rm-rf-variable', /\brm\s+-rf\s+["']?\$\{/],
];

function checkActionPin(file, lineNo, line) {
  if (!file.startsWith('.github/workflows/')) return;
  const match = line.match(/^\s*uses:\s*([^\s#]+)(?:\s*#.*)?$/);
  if (!match) return;
  const ref = match[1];
  if (ref.startsWith('./') || ref.startsWith('docker://')) return;
  const at = ref.lastIndexOf('@');
  const version = at >= 0 ? ref.slice(at + 1) : '';
  if (!/^[0-9a-f]{40}$/i.test(version)) add('HIGH', 'github-action-unpinned', file, lineNo, line, 'Pin third-party actions to an immutable 40-character commit SHA.');
}

function checkWorkflowInterpolation(file, lineNo, line) {
  if (!file.startsWith('.github/workflows/')) return;
  if (/\$\{\{\s*github\.event\.(?:issue|pull_request|comment|review|head_commit)[^}]*\}\}/.test(line)) {
    add('HIGH', 'github-expression-in-shell-candidate', file, lineNo, line, 'Review for untrusted event-data interpolation into shell. Prefer env indirection and validation.');
  }
}

function syntaxCheck(file) {
  const ext = extname(file).toLowerCase();
  const path = resolve(ROOT, file);
  let result;
  let tool;
  if (ext === '.php') {
    tool = 'php -l';
    result = run('php', ['-l', path]);
  } else if (['.js', '.mjs', '.cjs'].includes(ext)) {
    tool = 'node --check';
    result = run(process.execPath, ['--check', path]);
  } else if (ext === '.sh') {
    tool = 'bash -n';
    result = run('bash', ['-n', path]);
  } else if (ext === '.json' || file.endsWith('.webmanifest')) {
    try { JSON.parse(readFileSync(path, 'utf8')); }
    catch (error) { parserFailures.push({ file, tool: 'JSON.parse', error: String(error.message) }); }
    return;
  } else {
    return;
  }
  if (result.error || result.status !== 0) {
    syntaxFailures.push({ file, tool, status: result.status, error: `${result.error ?? ''}\n${result.stdout ?? ''}\n${result.stderr ?? ''}`.trim() });
  }
}

function scanContextual(file, lines) {
  const text = lines.join('\n');
  if (file.endsWith('.php')) {
    for (let i = 0; i < lines.length; i += 1) {
      const line = lines[i];
      if (/\$_(?:GET|POST|REQUEST|FILES|COOKIE)/.test(line)) {
        const context = lines.slice(i, Math.min(lines.length, i + 4)).join(' ');
        if (!/(?:sanitize_|esc_|absint|intval|floatval|wp_unslash|filter_input|check_admin_referer|wp_verify_nonce)/.test(context)) {
          add('HIGH', 'php-input-without-nearby-sanitizer', file, i + 1, line, 'Manual review: no sanitizer/normalizer visible in the next four lines.');
        }
      }
      if (/\$wpdb->(?:query|get_results|get_row|get_var|get_col)\s*\(/.test(line)) {
        const context = lines.slice(Math.max(0, i - 4), Math.min(lines.length, i + 3)).join(' ');
        if (!/\$wpdb->prepare\s*\(/.test(context) && /\$[A-Za-z_]/.test(context)) {
          add('HIGH', 'wpdb-dynamic-query-without-nearby-prepare', file, i + 1, line, 'Manual review: dynamic query candidate without nearby $wpdb->prepare().');
        }
      }
      if (/register_rest_route\s*\(/.test(line)) {
        const context = lines.slice(i, Math.min(lines.length, i + 18)).join(' ');
        if (!/permission_callback/.test(context)) add('CRITICAL', 'rest-route-without-permission-callback-nearby', file, i + 1, line);
      }
    }
  }

  if (/\.(?:html|htm|php)$/.test(file)) {
    for (let i = 0; i < lines.length; i += 1) {
      const line = lines[i];
      if (/<img\b/i.test(line) && !/\balt\s*=/.test(line)) add('MEDIUM', 'img-without-alt-on-line', file, i + 1, line);
      if (/<iframe\b/i.test(line) && !/\btitle\s*=/.test(line)) add('MEDIUM', 'iframe-without-title-on-line', file, i + 1, line);
      if (/target\s*=\s*['"]_blank['"]/i.test(line) && !/rel\s*=\s*['"][^'"]*(?:noopener|noreferrer)/i.test(line)) add('HIGH', 'target-blank-without-rel', file, i + 1, line);
    }
  }

  if (/\.css$/.test(file)) {
    const openBraces = (text.match(/\{/g) || []).length;
    const closeBraces = (text.match(/\}/g) || []).length;
    if (openBraces !== closeBraces) add('CRITICAL', 'css-unbalanced-braces', file, 0, `${openBraces} open vs ${closeBraces} close`);
  }
}

let textFiles = 0;
let binaryFiles = 0;
let totalTextLines = 0;
let totalBytes = 0;

for (const file of files) {
  const path = resolve(ROOT, file);
  const buffer = readFileSync(path);
  const binary = isBinary(buffer);
  const hash = sha256(buffer);
  totalBytes += buffer.length;
  if (binary) {
    binaryFiles += 1;
    coverage.push({ file, kind: 'binary', bytes: buffer.length, sha256: hash });
    continue;
  }

  textFiles += 1;
  const text = buffer.toString('utf8');
  const lines = text.split(/\r?\n/);
  const lineCount = lines.length;
  totalTextLines += lineCount;
  trackedText.set(file, text);
  coverage.push({ file, kind: 'text', lines: lineCount, bytes: buffer.length, sha256: hash });

  // The auditor is intentionally excluded from rule matching to avoid its rule literals self-triggering.
  if (file !== 'tools/audit/deep-forensic-audit.mjs') {
    for (let index = 0; index < lines.length; index += 1) {
      const lineNo = index + 1;
      const line = lines[index];
      checkActionPin(file, lineNo, line);
      checkWorkflowInterpolation(file, lineNo, line);
      for (const [severity, rule, regex] of RULES) {
        regex.lastIndex = 0;
        if (regex.test(line)) add(severity, rule, file, lineNo, line);
      }
    }
    scanContextual(file, lines);
  }
  syntaxCheck(file);
}

// Cross-file integrity and ownership checks.
const workflowFiles = coverage.filter((x) => x.file.startsWith('.github/workflows/') && /\.ya?ml$/.test(x.file)).map((x) => x.file);
const canonicalWorkflows = ['.github/workflows/gemini-pr-reviewer.yml', '.github/workflows/production.yml', '.github/workflows/staging.yml'];
for (const canonical of canonicalWorkflows) if (!workflowFiles.includes(canonical)) add('CRITICAL', 'missing-canonical-workflow', canonical, 0, canonical);
for (const workflow of workflowFiles) if (!canonicalWorkflows.includes(workflow)) add('MEDIUM', 'noncanonical-workflow-present', workflow, 0, workflow);

const directGoogle = findings.filter((x) => x.rule === 'tracking-direct-conversion');
const metaBrowser = findings.filter((x) => x.rule === 'tracking-meta-browser');
if (directGoogle.length > 0) add('HIGH', 'tracking-owner-drift-candidate', 'repository', 0, `${directGoogle.length} direct browser conversion references found`, 'Site Kit/GTM should remain the sole browser-side Google Ads conversion owner if that is the repository contract.');
if (metaBrowser.length > 0) add('HIGH', 'meta-browser-owner-drift-candidate', 'repository', 0, `${metaBrowser.length} Meta browser references found`, 'Browser Meta Pixel is expected to remain retired if CAPI is the canonical owner.');

// Detect duplicate exact HubSpot embed declarations/form ids across tracked text.
const hubspotIds = new Map();
for (const [file, text] of trackedText) {
  const re = /(?:formId|form_id|data-form-id)["'\s:=]+([0-9a-f-]{20,})/gi;
  for (const match of text.matchAll(re)) {
    const id = match[1].toLowerCase();
    if (!hubspotIds.has(id)) hubspotIds.set(id, []);
    hubspotIds.get(id).push(file);
  }
}
for (const [id, refs] of hubspotIds) {
  const unique = [...new Set(refs)];
  if (unique.length > 3) add('MEDIUM', 'hubspot-form-id-wide-duplication', unique[0], 0, `${id} in ${unique.join(', ')}`, 'Verify intentional centralized reuse vs duplicate mounts.');
}

// Repository-level tool/config validation available on the hosted runner.
const composerJson = 'wp-content/themes/nuvanx-medical/composer.json';
if (trackedText.has(composerJson)) {
  const composer = run('composer', ['validate', '--strict', '--no-check-publish'], { cwd: resolve(ROOT, 'wp-content/themes/nuvanx-medical') });
  if (composer.error || composer.status !== 0) add('HIGH', 'composer-validate-failed', composerJson, 0, `${composer.stdout}\n${composer.stderr}`);
}
const diffCheck = run('git', ['diff', '--check', 'HEAD^', 'HEAD']);
if (diffCheck.status !== 0) add('HIGH', 'git-diff-check-failed', 'repository', 0, diffCheck.stdout || diffCheck.stderr);

findings.sort((a, b) => severityRank[a.severity] - severityRank[b.severity] || a.file.localeCompare(b.file) || a.line - b.line || a.rule.localeCompare(b.rule));
coverage.sort((a, b) => a.file.localeCompare(b.file));

const bySeverity = {};
const byRule = {};
for (const finding of findings) {
  bySeverity[finding.severity] = (bySeverity[finding.severity] || 0) + 1;
  byRule[finding.rule] = (byRule[finding.rule] || 0) + 1;
}

const report = {
  generatedAt: new Date().toISOString(),
  head: run('git', ['rev-parse', 'HEAD']).stdout.trim(),
  coverageSummary: { trackedFiles: files.length, textFiles, binaryFiles, totalTextLines, totalBytes, syntaxFailures: syntaxFailures.length, parserFailures: parserFailures.length },
  bySeverity,
  byRule,
  syntaxFailures,
  parserFailures,
  findings,
  coverage,
};
writeFileSync(resolve(OUT, 'deep-forensic-audit.json'), `${JSON.stringify(report, null, 2)}\n`);

console.log('=== DEEP FORENSIC AUDIT COVERAGE ===');
console.log(`AUDIT_HEAD=${report.head}`);
console.log(`AUDIT_TRACKED_FILES=${files.length}`);
console.log(`AUDIT_TEXT_FILES=${textFiles}`);
console.log(`AUDIT_BINARY_FILES=${binaryFiles}`);
console.log(`AUDIT_TEXT_LINES=${totalTextLines}`);
console.log(`AUDIT_TOTAL_BYTES=${totalBytes}`);
console.log(`AUDIT_SYNTAX_FAILURES=${syntaxFailures.length}`);
console.log(`AUDIT_PARSER_FAILURES=${parserFailures.length}`);
console.log(`AUDIT_FINDING_COUNTS=${JSON.stringify(bySeverity)}`);
console.log(`AUDIT_RULE_COUNTS=${JSON.stringify(byRule)}`);
console.log('=== FILE-BY-FILE COVERAGE (ALL TRACKED FILES) ===');
for (const item of coverage) console.log(`COVERAGE\t${item.kind}\t${item.file}\tlines=${item.lines ?? '-'}\tbytes=${item.bytes}\tsha256=${item.sha256}`);
console.log('=== SYNTAX/PARSER FAILURES ===');
for (const failure of [...syntaxFailures, ...parserFailures]) console.log(`SYNTAX\t${failure.file}\t${failure.tool}\t${String(failure.error).replace(/\s+/g, ' ').slice(0, 1000)}`);
console.log('=== ALL FINDINGS ===');
for (const finding of findings) console.log(`FINDING\t${finding.severity}\t${finding.rule}\t${finding.file}:${finding.line}\t${finding.excerpt}\t${finding.note}`);
console.log('DEEP_FORENSIC_AUDIT=COMPLETE');
