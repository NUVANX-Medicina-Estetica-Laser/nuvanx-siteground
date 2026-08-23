#!/usr/bin/env node
/**
 * Lint script to detect hardcoded color values in CSS files.
 *
 * This script scans CSS files for:
 * - Hex color codes (# followed by 3 or 6 hex digits)
 * - rgb() / rgba() function calls (except in shadows)
 * - Named colors that should use tokens instead
 *
 * Violations are reported but the script exits with 0 unless --strict flag is used.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import '../../tools/forensics/deep-forensic-scan.mjs';
import { scanDirectory } from './file-scan-utils.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const THEME_DIR = path.join(__dirname, '../../wp-content/themes/nuvanx-medical');

const COLOR_PATTERNS = [
  // Hex colors: #fff, #ffffff, #000, #000000, #f7f7f5, etc.
  /#[0-9a-fA-F]{3}(?![0-9a-fA-F])/g,  // # followed by 3 hex, not 4
  /#[0-9a-fA-F]{6}(?![0-9a-fA-F])/g,  // # followed by 6 hex, not 7
  // rgb() / rgba() functions (but allow them in shadow definitions)
  /rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+/g,
  // Common named colors that should use tokens instead
  /\b(white|black|red|blue|green|yellow|orange|purple|pink|gray|grey)\b/gi,
];

/**
 * Scans a CSS file for hardcoded color violations.
 * @param {string} filePath - The path to the CSS file to scan.
 * @return {Promise<Array<{line: number, file: string, match: string, context: string}>>} The detected color violations with their line number, relative file path, matched text, and line context.
 */
async function scanFile(filePath) {
  const content = await fs.readFile(filePath, 'utf-8');
  const lines = content.split('\n');
  const violations = [];

  // Skip nvx-tokens.css entirely (that's where tokens are defined)
  if (filePath.includes('nvx-tokens.css')) {
    return violations;
  }

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNum = i + 1;

    // Skip comments
    if (line.trim().startsWith('//') || line.trim().startsWith('/*')) continue;

    // Skip entire line if it contains white-space property
    if (/white-space:/i.test(line)) continue;

    // Skip if line contains shadow (allow rgba in shadows)
    if (/shadow/i.test(line)) continue;

    // Strip var() expressions from line context before checking for hardcoded colors
    const checkLine = line.replace(/var\(--[\w-]+\)/g, '');

    // Skip if inside comment block
    if (line.includes('/*') && line.includes('*/')) continue;

    for (const pattern of COLOR_PATTERNS) {
      const matches = checkLine.matchAll(pattern);
      for (const match of matches) {
        const matchedText = match[0];

        // Skip "white" in "white-space" property
        if (matchedText === 'white' && /white-space/i.test(line)) continue;

        violations.push({
          line: lineNum,
          file: path.relative(THEME_DIR, filePath),
          match: matchedText,
          context: line.trim()
        });
      }
    }
  }

  return violations;
}

/**
 * Scans theme CSS files for hardcoded color values and reports any violations.
 *
 * In strict mode, exits with status code 1 when violations are found; otherwise,
 * violations are reported with a successful exit status.
 */
async function main() {
  const args = process.argv.slice(2);
  const strict = args.includes('--strict');
  const cssDir = path.join(THEME_DIR, 'assets/css');

  console.log('🎨 Scanning CSS files for hardcoded color values...');
  console.log(`📁 Directory: ${cssDir}`);

  const violations = await scanDirectory(cssDir, ['.css'], scanFile);

  if (violations.length === 0) {
    console.log('✅ No hardcoded color values found');
    process.exit(0);
  }

  console.log(`\n⚠️  Found ${violations.length} potential hardcoded color violations:\n`);

  // Group by file
  const byFile = {};
  violations.forEach(v => {
    if (!byFile[v.file]) byFile[v.file] = [];
    byFile[v.file].push(v);
  });

  for (const [file, fileViolations] of Object.entries(byFile)) {
    console.log(`📄 ${file}:`);
    fileViolations.forEach(v => {
      console.log(`   Line ${v.line}: ${v.match}`);
      console.log(`   Context: ${v.context.substring(0, 60)}...`);
    });
    console.log('');
  }

  console.log('💡 REMEDY: Replace hardcoded colors with CSS tokens from nvx-tokens.css');
  console.log('   Example: #f7f7f5 → var(--nvx-color-paper)');
  console.log('   Example: #1a1a1a → var(--nvx-ink)');

  if (strict) {
    console.log('\n❌ Strict mode: exiting with error code');
    process.exit(1);
  } else {
    console.log('\n⚠️  Strict mode not enabled: exiting with success (warnings shown)');
    process.exit(0);
  }
}

main().catch(err => {
  console.error('❌ Error:', err);
  process.exit(1);
});
