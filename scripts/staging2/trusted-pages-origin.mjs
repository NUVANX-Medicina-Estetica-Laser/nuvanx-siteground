import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';

const CANONICAL_STAGING_ROOT = '/home/customer/www/staging2.nuvanx.com/public_html';
const ALLOWED_STAGING_ALIASES = new Set(['nvx-staging2', 'nvx-staging2-pr']);

const WP_PAGES_SCRIPT = `set -Eeuo pipefail
cd "$STAGING_ROOT"
wp eval '$pages=get_posts(array("post_type"=>array("page","post"),"post_status"=>"publish","posts_per_page"=>-1,"orderby"=>"ID","order"=>"ASC")); $payload=array_map(static function($p){return array("id"=>(int)$p->ID,"slug"=>(string)$p->post_name,"link"=>(string)get_permalink($p->ID),"title"=>(string)get_the_title($p->ID),"post_type"=>(string)$p->post_type,"status"=>(string)$p->post_status,"template"=>"page" === $p->post_type ? (string)get_page_template_slug($p->ID) : "");},$pages); echo wp_json_encode($payload);'
`;

function runTrustedWpCliInventory(alias, stagingRoot) {
  return new Promise((resolve, reject) => {
    const child = spawn(
      '/usr/bin/ssh',
      ['-o', 'BatchMode=yes', '--', alias, `STAGING_ROOT=${stagingRoot} bash -se`],
      { stdio: ['pipe', 'pipe', 'pipe'] }
    );

    let stdout = '';
    let stderr = '';
    child.stdout.setEncoding('utf8');
    child.stderr.setEncoding('utf8');
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.once('error', reject);
    child.once('close', (code, signal) => {
      if (signal) {
        reject(new Error(`trusted WP-CLI inventory SSH terminated by signal ${signal}`));
        return;
      }
      if (code !== 0) {
        reject(new Error(`trusted WP-CLI inventory SSH failed rc=${code}: ${stderr.trim() || '(no stderr)'}`));
        return;
      }
      resolve(stdout.trim());
    });

    child.stdin.end(WP_PAGES_SCRIPT);
  });
}

/**
 * Ensure Block C has a trusted WordPress published-content inventory.
 *
 * Canonical Staging already supplies WORDPRESS_PAGES_FILE explicitly. PR
 * previews run the same trusted tooling but previously fell back to the public
 * REST API, which SiteGround may challenge before any browser assertion runs.
 * When an allowed Staging SSH alias is available, materialize the exact same
 * WP-CLI inventory into RUNNER_TEMP instead of depending on public REST.
 *
 * @returns {Promise<string|null>} Existing/generated inventory path, or null
 * when no trusted-origin alias is configured and the caller may use REST.
 */
export async function ensureTrustedPagesFile() {
  const existing = (process.env.WORDPRESS_PAGES_FILE || '').trim();
  if (existing) {
    return existing;
  }

  const alias = (process.env.ORIGIN_SSH_ALIAS || '').trim();
  if (!alias) {
    return null;
  }
  if (!ALLOWED_STAGING_ALIASES.has(alias)) {
    // An explicitly configured but untrusted alias is a control-plane error,
    // not a signal to fall back to public REST. Fail closed so a typo or
    // unexpected target cannot silently weaken the trusted inventory contract.
    throw new Error(`Refusing unsupported Staging origin alias for published-content inventory: ${alias}`);
  }

  const stagingRoot = (process.env.STAGING_ROOT || CANONICAL_STAGING_ROOT).trim();
  if (stagingRoot !== CANONICAL_STAGING_ROOT) {
    throw new Error(`Refusing unexpected Staging root for published-content inventory: ${stagingRoot}`);
  }

  const jsonText = await runTrustedWpCliInventory(alias, stagingRoot);
  let pages;
  try {
    pages = JSON.parse(jsonText);
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    throw new Error(`Trusted WP-CLI published-content inventory returned invalid JSON: ${reason}`);
  }
  if (!Array.isArray(pages) || pages.length === 0) {
    throw new Error('Trusted WP-CLI published-content inventory must be a non-empty array');
  }

  const runnerTemp = process.env.RUNNER_TEMP || '/tmp';
  const outputPath = path.join(runnerTemp, `staging2-published-pages-${process.pid}.json`);
  await fs.writeFile(outputPath, `${JSON.stringify(pages)}\n`, 'utf8');
  process.env.WORDPRESS_PAGES_FILE = outputPath;
  console.log(`BLOCK_C_INVENTORY_BOOTSTRAP=trusted-wp-cli alias=${alias} published_content=${pages.length}`);
  return outputPath;
}
