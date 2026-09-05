import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const theme = path.join(root, 'wp-content/themes/nuvanx-medical');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const functions = read('wp-content/themes/nuvanx-medical/functions.php');
const bootstrap = read('wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php');
const environment = read('wp-content/themes/nuvanx-medical/inc/nvx-environment-flags.php');
const gtm = read('wp-content/themes/nuvanx-medical/inc/nvx-gtm-integration.php');
const header = read('wp-content/themes/nuvanx-medical/header.php');
const singlePost = read('wp-content/themes/nuvanx-medical/single-post.php');
const structured = read('wp-content/themes/nuvanx-medical/inc/nvx-structured-data.php');
const pageRegistry = read('wp-content/themes/nuvanx-medical/inc/nvx-page-registry.php');
const pageRenderHelpers = read('wp-content/themes/nuvanx-medical/inc/nvx-page-render-helpers.php');
const pageShell = read('wp-content/themes/nuvanx-medical/template-parts/content/nvx-page-shell.php');
const nativeStyle = read('wp-content/themes/nuvanx-medical/inc/nvx-native-style-governance.php');

assert.match(functions, /require_once __DIR__ \. '\/inc\/nvx-theme-bootstrap\.php';/,
  'functions.php must delegate module loading to the canonical bootstrap');
assert.doesNotMatch(functions, /require_once get_template_directory\(\) \. '\/inc\//,
  'functions.php must not retain the legacy flat module graph');

const requestRequire = bootstrap.indexOf("require_once __DIR__ . '/nvx-theme-request.php';");
const manifestStart = bootstrap.indexOf('function nvx_theme_bootstrap_manifest(): array');
assert.ok(requestRequire >= 0 && requestRequire < manifestStart,
  'Immutable request snapshot must be installed before the module manifest is registered');
assert.match(bootstrap, /add_action\( 'after_setup_theme', 'nvx_theme_bootstrap_modules', -1000 \);/,
  'Canonical modules must load before normal after_setup_theme consumers');
assert.match(bootstrap, /is_readable\( \$path \)/,
  'Bootstrap must preflight every module before requiring any of them');
assert.match(bootstrap, /array\( 'response' => 503 \)/,
  'Missing canonical module must fail closed with HTTP 503');

const manifestBody = bootstrap.slice(manifestStart, bootstrap.indexOf('/** Load the complete theme module graph', manifestStart));
const manifest = [...manifestBody.matchAll(/'((?:inc\/)[^']+\.php)'/g)].map((match) => match[1]);
assert.ok(manifest.length > 40, 'Canonical manifest must contain the complete runtime graph');
assert.equal(new Set(manifest).size, manifest.length, 'Canonical manifest must not contain duplicate modules');
for (const module of manifest) {
  assert.ok(fs.existsSync(path.join(theme, module.replace(/^inc\//, 'inc/'))), `Manifest module missing: ${module}`);
}

function requireOrder(before, after) {
  const first = manifest.indexOf(before);
  const second = manifest.indexOf(after);
  assert.ok(first >= 0, `Missing manifest owner: ${before}`);
  assert.ok(second > first, `${after} must load after ${before}`);
}

requireOrder('inc/nvx-page-registry.php', 'inc/nvx-page-render-helpers.php');
requireOrder('inc/nvx-marketing-consent.php', 'inc/nvx-ads-conversion-catalog.php');
requireOrder('inc/nvx-ads-conversion-catalog.php', 'inc/nvx-hubspot-secure-attribution.php');
requireOrder('inc/nvx-hubspot-secure-attribution.php', 'inc/nvx-gtm-integration.php');
requireOrder('inc/nvx-gtm-integration.php', 'inc/nvx-attribution-integration.php');
requireOrder('inc/nvx-attribution-integration.php', 'inc/nvx-supabase-relay-queue.php');
requireOrder('inc/nvx-supabase-relay-queue.php', 'inc/nvx-lead-captured-relay.php');
requireOrder('inc/nvx-lead-captured-relay.php', 'inc/nvx-google-attribution-relay-auth.php');
requireOrder('inc/nvx-blog-system.php', 'inc/nvx-governed-blog-runtime.php');
requireOrder('inc/nvx-cta-components.php', 'inc/nvx-content-presentation.php');
requireOrder('inc/nvx-signature-catalog.php', 'inc/nvx-signature-phase-pages.php');
requireOrder('inc/nvx-clinics-dom-helpers.php', 'inc/nvx-clinics-hub.php');

// Page ownership is fail-closed at the canonical registry. Legacy module filters
// can remain during retirement, but an unregistered route receives an explicit
// sentinel before nvx_get_page_owner() could consult them. Consumers that use
// owner truthiness must explicitly preserve unowned generic-page behavior.
assert.match(pageRegistry, /const NVX_CANONICAL_PAGE_UNOWNED = 'nvx_unowned';/,
  'Canonical page registry must define the unowned sentinel');
assert.match(pageRegistry, /return NVX_CANONICAL_PAGE_UNOWNED;/,
  'Unregistered routes must resolve to the canonical unowned sentinel');
assert.doesNotMatch(pageRegistry, /'\/sobre-nosotros\/'\s*=>/,
  'Legacy /sobre-nosotros/ must not be a canonical page owner');
assert.doesNotMatch(pageRegistry, /'\/profhilo-madrid\/'\s*=>/,
  'Unpublished Profhilo route must not be a canonical page owner');
assert.match(pageRenderHelpers, /if \( function_exists\( 'nvx_get_canonical_page_owner' \) \)/,
  'Page-render helper must consult canonical ownership before legacy filters');
assert.match(pageShell, /NVX_CANONICAL_PAGE_UNOWNED !== \$owner/,
  'Generic page shell must not classify the canonical unowned sentinel as managed editorial');
assert.match(nativeStyle, /NVX_CANONICAL_PAGE_UNOWNED === \$owner/,
  'Native-style governance must leave unowned CMS prose untouched');

assert.ok(manifest.includes('inc/nvx-structured-data.php'), 'Structured-data package owner must be in manifest');
for (const implementation of [
  'inc/nvx-schema-foundation.php',
  'inc/nvx-schema-faq.php',
  'inc/nvx-schema-treatments.php',
  'inc/nvx-schema-physicians.php',
  'inc/nvx-schema-graph.php',
]) {
  assert.equal(manifest.includes(implementation), false,
    `${implementation} must remain owned by nvx-structured-data.php, not by the root manifest`);
  const basename = path.basename(implementation);
  assert.match(structured, new RegExp(`require_once __DIR__ \\. '/${basename.replaceAll('.', '\\.')}'`),
    `Structured-data owner must load ${basename}`);
}

assert.doesNotMatch(environment, /require_once[^;]+nvx-meta-browser-governance\.php/,
  'Environment flags must not load Meta governance laterally');
assert.doesNotMatch(gtm, /require_once\s+__DIR__\s*\.\s*'\/nvx-/,
  'GTM context must not own server transport dependencies');
assert.doesNotMatch(header, /require_once[^;]+\/inc\/nvx-/,
  'header.php must render markup only, not own runtime modules');
assert.doesNotMatch(singlePost, /require_once[^;]+nvx-governed-blog-runtime\.php/,
  'single-post.php must not load the governed blog runtime laterally');

console.log(`THEME_BOOTSTRAP_SINGLE_OWNER=PASS modules=${manifest.length} request_snapshot=early schema_package=single_owner page_owner=canonical-fail-closed generic_shell=preserved native_style=preserved`);
