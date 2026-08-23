#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const ROOT = resolve(process.cwd());
const SELF = 'tools/forensics/deep-forensic-scan.mjs';
const findings = [];
const coverage = [];
const syntaxFailures = [];
const parserFailures = [];

function run(command, args, options = {}) {
  return spawnSync(command, args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, ...options });
}
function add(severity, rule, file, line, excerpt, note = '') {
  findings.push({ severity, rule, file, line, excerpt: String(excerpt || '').trim().slice(0, 360), note });
}
function binary(buffer) {
  for (const byte of buffer.subarray(0, Math.min(buffer.length, 8192))) if (byte === 0) return true;
  return false;
}
function syntaxCheck(file) {
  const path = resolve(ROOT, file);
  const ext = extname(file).toLowerCase();
  let result;
  let tool;
  if (ext === '.php') { tool = 'php -l'; result = run('php', ['-l', path]); }
  else if (['.js', '.mjs', '.cjs'].includes(ext)) { tool = 'node --check'; result = run(process.execPath, ['--check', path]); }
  else if (ext === '.sh') { tool = 'bash -n'; result = run('bash', ['-n', path]); }
  else if (ext === '.json' || file.endsWith('.webmanifest')) {
    try { JSON.parse(readFileSync(path, 'utf8')); } catch (error) { parserFailures.push({ file, tool: 'JSON.parse', error: error.message }); }
    return;
  } else return;
  if (result.error || result.status !== 0) syntaxFailures.push({ file, tool, status: result.status, error: `${result.stdout || ''} ${result.stderr || ''}`.trim() });
}

const rules = [
  ['CRITICAL','merge-conflict',/^(?:<{7}|>{7})(?:\s|$)/],
  ['CRITICAL','dynamic-eval',/\beval\s*\(|\bnew\s+Function\s*\(/],
  ['CRITICAL','download-pipe-shell',/\b(?:curl|wget)\b[^|]*\|\s*(?:bash|sh)\b/],
  ['HIGH','php-command-exec',/\b(?:shell_exec|system|passthru|proc_open|popen)\s*\(|(?<!->)(?<!::)\bexec\s*\(/],
  ['HIGH','php-unserialize',/\bunserialize\s*\(/],
  ['HIGH','php-dynamic-include',/\b(?:include|include_once|require|require_once)\s*\(?\s*\$/],
  ['HIGH','php-user-input',/\$_(?:GET|POST|REQUEST|FILES|COOKIE)\b/],
  ['MEDIUM','php-server-input',/\$_SERVER\b/],
  ['HIGH','php-file-io',/\b(?:file_put_contents|fwrite|fopen|rename|copy|unlink|file_get_contents|readfile)\s*\(/],
  ['HIGH','wp-unsafe-redirect',/\bwp_redirect\s*\(/],
  ['MEDIUM','wp-safe-redirect',/\bwp_safe_redirect\s*\(/],
  ['HIGH','wp-remote-request',/\bwp_remote_(?:get|post|request)\s*\(/],
  ['HIGH','wpdb-query',/\$wpdb->(?:query|get_results|get_row|get_var|get_col)\s*\(/],
  ['MEDIUM','wpdb-prepare',/\$wpdb->prepare\s*\(/],
  ['HIGH','rest-route',/\bregister_rest_route\s*\(/],
  ['HIGH','ajax-nopriv',/wp_ajax_nopriv_/],
  ['HIGH','raw-variable-echo',/\becho\s+\$[A-Za-z_][A-Za-z0-9_]*(?:\s*[;,)]|\s*$)/],
  ['HIGH','dom-html-sink',/\.(?:innerHTML|outerHTML)\s*=|\binsertAdjacentHTML\s*\(|\bdocument\.write\s*\(/],
  ['HIGH','node-child-process',/\b(?:exec|execSync|spawn|spawnSync|execFile|execFileSync)\s*\(/],
  ['HIGH','node-fs-mutation',/\b(?:writeFileSync|writeFile|appendFileSync|appendFile|renameSync|rmSync|unlinkSync|copyFileSync)\s*\(/],
  ['MEDIUM','node-path-resolution',/\b(?:join|resolve)\s*\(/],
  ['MEDIUM','node-cli-input',/\bprocess\.argv\b/],
  ['HIGH','direct-browser-conversion',/\bgtag\s*\(|\bfbq\s*\(|\bsend_to\b/],
  ['HIGH','meta-browser-pixel',/connect\.facebook\.net|fbevents\.js|fbq\s*\(\s*['"]init['"]/],
  ['MEDIUM','error-suppression',/(^|[^\w])@[A-Za-z_\\][A-Za-z0-9_\\]*\s*\(/],
  ['LOW','todo-marker',/\b(?:TODO|FIXME|HACK|XXX)\b/i],
  ['MEDIUM','debug-call',/\b(?:var_dump|print_r|console\.debug)\s*\(/],
  ['MEDIUM','css-important',/!important\b/],
  ['MEDIUM','css-transition-all',/\btransition\s*:\s*all\b/i],
  ['HIGH','css-outline-disabled',/\boutline\s*:\s*(?:none|0)\b/i],
  ['MEDIUM','css-100vh',/\b100vh\b/],
  ['HIGH','target-blank',/target\s*=\s*['"]_blank['"]/i],
  ['HIGH','inline-handler',/\son(?:click|load|error|submit|change|input|mouseover|focus|blur)\s*=/i],
  ['MEDIUM','inline-style',/\sstyle\s*=\s*['"]/i],
  ['HIGH','noindex',/\bnoindex\b/i],
  ['HIGH','pull-request-target',/^\s*pull_request_target\s*:/],
  ['HIGH','permissions-write-all',/^\s*permissions\s*:\s*write-all\b/],
  ['HIGH','ssh-host-key-disabled',/StrictHostKeyChecking\s*=\s*no/],
  ['HIGH','rm-rf-variable',/\brm\s+-rf\s+["']?\$\{/]
];

const listed = run('git', ['ls-files', '-z']);
if (listed.status !== 0) throw new Error(listed.stderr || 'git ls-files failed');
const files = listed.stdout.split('\0').filter(Boolean).sort();
let totalLines = 0;
let totalBytes = 0;
let textFiles = 0;
let binaryFiles = 0;

for (const file of files) {
  const bytes = readFileSync(resolve(ROOT, file));
  const sha256 = createHash('sha256').update(bytes).digest('hex');
  totalBytes += bytes.length;
  if (binary(bytes)) {
    binaryFiles++;
    coverage.push({ file, kind: 'binary', bytes: bytes.length, sha256 });
    continue;
  }
  textFiles++;
  const text = bytes.toString('utf8');
  const lines = text.split(/\r?\n/);
  totalLines += lines.length;
  coverage.push({ file, kind: 'text', lines: lines.length, bytes: bytes.length, sha256 });
  syntaxCheck(file);
  if (file === SELF) continue;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const n = i + 1;
    if (file.startsWith('.github/workflows/')) {
      const use = line.match(/^\s*uses:\s*([^\s#]+)/);
      if (use && !use[1].startsWith('./') && !use[1].startsWith('docker://')) {
        const version = use[1].slice(use[1].lastIndexOf('@') + 1);
        if (!/^[0-9a-f]{40}$/i.test(version)) add('HIGH','action-unpinned',file,n,line);
      }
      if (/\$\{\{\s*github\.event\.(?:issue|pull_request|comment|review|head_commit)[^}]*\}\}/.test(line)) add('HIGH','untrusted-github-expression',file,n,line);
    }
    for (const [severity, rule, regex] of rules) {
      regex.lastIndex = 0;
      if (regex.test(line)) add(severity, rule, file, n, line);
    }

    if (file.endsWith('.php') && /\$_(?:GET|POST|REQUEST|FILES|COOKIE)/.test(line)) {
      const context = lines.slice(i, Math.min(lines.length, i + 5)).join(' ');
      if (!/(?:sanitize_|esc_|absint|intval|floatval|wp_unslash|filter_input|check_admin_referer|wp_verify_nonce)/.test(context)) add('HIGH','input-no-nearby-sanitizer',file,n,line);
    }
    if (file.endsWith('.php') && /register_rest_route\s*\(/.test(line)) {
      const context = lines.slice(i, Math.min(lines.length, i + 20)).join(' ');
      if (!/permission_callback/.test(context)) add('CRITICAL','rest-no-permission-callback',file,n,line);
    }
    if (/\.(?:php|html|htm)$/.test(file) && /target\s*=\s*['"]_blank['"]/i.test(line) && !/rel\s*=\s*['"][^'"]*(?:noopener|noreferrer)/i.test(line)) add('HIGH','target-blank-no-rel',file,n,line);
    if (/\.(?:php|html|htm)$/.test(file) && /<img\b/i.test(line) && !/\balt\s*=/.test(line)) add('MEDIUM','img-no-alt-same-line',file,n,line);
    if (/\.(?:php|html|htm)$/.test(file) && /<iframe\b/i.test(line) && !/\btitle\s*=/.test(line)) add('MEDIUM','iframe-no-title-same-line',file,n,line);
  }
  if (file.endsWith('.css')) {
    const open = (text.match(/\{/g) || []).length;
    const close = (text.match(/\}/g) || []).length;
    if (open !== close) add('CRITICAL','css-unbalanced-braces',file,0,`${open} open / ${close} close`);
  }
}

const severityOrder = { CRITICAL:0, HIGH:1, MEDIUM:2, LOW:3 };
findings.sort((a,b) => severityOrder[a.severity]-severityOrder[b.severity] || a.file.localeCompare(b.file) || a.line-b.line || a.rule.localeCompare(b.rule));
const bySeverity = {};
const byRule = {};
for (const f of findings) { bySeverity[f.severity]=(bySeverity[f.severity]||0)+1; byRule[f.rule]=(byRule[f.rule]||0)+1; }

console.log('=== DEEP FORENSIC COVERAGE ===');
console.log(`AUDIT_HEAD=${run('git',['rev-parse','HEAD']).stdout.trim()}`);
console.log(`AUDIT_TRACKED_FILES=${files.length}`);
console.log(`AUDIT_TEXT_FILES=${textFiles}`);
console.log(`AUDIT_BINARY_FILES=${binaryFiles}`);
console.log(`AUDIT_TEXT_LINES=${totalLines}`);
console.log(`AUDIT_TOTAL_BYTES=${totalBytes}`);
console.log(`AUDIT_SYNTAX_FAILURES=${syntaxFailures.length}`);
console.log(`AUDIT_PARSER_FAILURES=${parserFailures.length}`);
console.log(`AUDIT_FINDING_COUNTS=${JSON.stringify(bySeverity)}`);
console.log(`AUDIT_RULE_COUNTS=${JSON.stringify(byRule)}`);
console.log('=== FILE-BY-FILE COVERAGE ===');
for (const c of coverage) console.log(`COVERAGE\t${c.kind}\t${c.file}\tlines=${c.lines ?? '-'}\tbytes=${c.bytes}\tsha256=${c.sha256}`);
console.log('=== SYNTAX/PARSER FAILURES ===');
for (const f of [...syntaxFailures,...parserFailures]) console.log(`SYNTAX\t${f.file}\t${f.tool}\t${String(f.error).replace(/\s+/g,' ').slice(0,1000)}`);
console.log('=== ALL FINDINGS ===');
for (const f of findings) console.log(`FINDING\t${f.severity}\t${f.rule}\t${f.file}:${f.line}\t${f.excerpt}\t${f.note}`);
console.log('DEEP_FORENSIC_AUDIT=COMPLETE');
