import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const theme = path.resolve('wp-content/themes/nuvanx-medical');

function phpFiles(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'vendor') continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...phpFiles(full));
    else if (entry.isFile() && entry.name.endsWith('.php')) out.push(full);
  }
  return out;
}

const all = phpFiles(theme).map((file) => path.relative(theme, file).replaceAll(path.sep, '/')).sort();
const inc = all.filter((file) => file.startsWith('inc/'));
const nonInc = all.filter((file) => !file.startsWith('inc/'));
const templates = all.filter((file) => file.startsWith('templates/'));
const parts = all.filter((file) => file.startsWith('template-parts/'));
const root = all.filter((file) => !file.includes('/'));

assert.ok(all.length >= 100, `Expected complete theme PHP surface, found only ${all.length}`);
assert.ok(inc.length >= 70, `Expected canonical inc surface, found only ${inc.length}`);
assert.deepEqual(templates, [
  'templates/page-contacto.php',
  'templates/page-landing-valoracion.php',
  'templates/page-sede.php',
  'templates/page-soluciones-medicas.php',
], 'Custom template inventory changed; review new/removed PHP template explicitly');
assert.deepEqual(parts, [
  'template-parts/content/nvx-blog-archive-route.php',
  'template-parts/content/nvx-blog-archive.php',
  'template-parts/content/nvx-blog-single.php',
  'template-parts/content/nvx-page-shell.php',
  'template-parts/content/nvx-soluciones-medicas.php',
], 'Template-part PHP inventory changed; review new/removed PHP template part explicitly');

for (const relative of nonInc) {
  const source = fs.readFileSync(path.join(theme, relative), 'utf8');
  assert.doesNotMatch(source, /\$_(?:SERVER|POST|GET|REQUEST|COOKIE|FILES)\b/,
    `${relative} must not create a browser-request authority outside canonical runtime modules`);

  if (relative === 'functions.php') continue;
  assert.doesNotMatch(source, /(?:require|include)(?:_once)?\s*\(?\s*[^;]*\/inc\/nvx-/,
    `${relative} must render/delegate only, not load nvx runtime modules laterally`);
}

const functions = fs.readFileSync(path.join(theme, 'functions.php'), 'utf8');
assert.match(functions, /require_once __DIR__ \. '\/inc\/nvx-theme-bootstrap\.php';/,
  'functions.php must delegate runtime loading to the canonical bootstrap');

for (const file of ['home.php', 'archive.php', 'search.php']) {
  const source = fs.readFileSync(path.join(theme, file), 'utf8');
  assert.match(source, /nvx-blog-archive-route\.php/,
    `${file} must delegate to the shared Journal archive route`);
}

const singlePost = fs.readFileSync(path.join(theme, 'single-post.php'), 'utf8');
assert.match(singlePost, /nvx_theme_request_context\(\)/,
  'single-post must use canonical request context for governed route recovery');
assert.match(singlePost, /require_once __DIR__ \. '\/single\.php';/,
  'single-post template delegation to single.php must remain explicit');

const sede = fs.readFileSync(path.join(theme, 'templates/page-sede.php'), 'utf8');
assert.match(sede, /\$clinic_key\s*=\s*'chamberi';/,
  'Known page-sede default-clinic routing debt must remain visible until clinic-route consolidation');
assert.match(sede, /strpos\( \$current_slug, 'goya' \)/,
  'Known page-sede substring routing debt must remain visible until clinic-route consolidation');

console.log(`PHP_ENTRYPOINTS_TEMPLATES=PASS all_php=${all.length} inc=${inc.length} root=${root.length} templates=${templates.length} parts=${parts.length} non_inc=${nonInc.length}`);
