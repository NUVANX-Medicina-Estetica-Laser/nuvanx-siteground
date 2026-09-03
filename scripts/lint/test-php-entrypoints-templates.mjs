import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
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

// Explicit reviewed root entrypoint inventory contract
assert.deepEqual(root, [
  '404.php',
  'archive.php',
  'footer.php',
  'front-page.php',
  'functions.php',
  'header.php',
  'home.php',
  'index.php',
  'page-casos-de-pacientes.php',
  'page-equipo-medico.php',
  'page-gracias.php',
  'page.php',
  'search.php',
  'single-post.php',
  'single.php',
], 'Root PHP entrypoint inventory changed; review new/removed root entrypoint explicitly');

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

// --- PHP Token Analysis Helper ---
function tokenizePhp(source) {
  const json = execFileSync(
    'php',
    [
      '-r',
      `
      $tokens = token_get_all($argv[1]);
      $res = [];
      foreach ($tokens as $t) {
        $res[] = is_array($t) ? [token_name($t[0]), $t[1]] : $t;
      }
      echo json_encode($res);
      `,
      source,
    ],
    { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 }
  );
  return JSON.parse(json);
}

const LOAD_TOKENS = new Set(['T_REQUIRE', 'T_REQUIRE_ONCE', 'T_INCLUDE', 'T_INCLUDE_ONCE']);
const FORBIDDEN_GLOBALS = new Set(['_SERVER', '_POST', '_GET', '_REQUEST', '_COOKIE', '_FILES']);

function extractViolations(tokens, isFunctionsPhp = false) {
  const nonTrivia = tokens.filter((t) => {
    if (typeof t === 'string') return true;
    const name = t[0];
    return name !== 'T_WHITESPACE' && name !== 'T_COMMENT' && name !== 'T_DOC_COMMENT';
  });

  const superglobals = [];
  const lateralLoads = [];

  for (let i = 0; i < nonTrivia.length; i++) {
    const t = nonTrivia[i];
    if (typeof t === 'string') continue;
    const name = t[0];
    const text = t[1];

    if (name === 'T_VARIABLE') {
      const varName = text.replace('$', '');
      if (FORBIDDEN_GLOBALS.has(varName)) {
        superglobals.push(text);
      } else if (text === '$GLOBALS') {
        const next = nonTrivia[i + 1];
        const nextNext = nonTrivia[i + 2];
        if (next === '[' && nextNext && typeof nextNext !== 'string') {
          const rawKey = nextNext[1].replace(/^['"]|['"]$/g, '');
          if (FORBIDDEN_GLOBALS.has(rawKey)) {
            superglobals.push(`$GLOBALS['${rawKey}']`);
          }
        }
      }
    }

    if (LOAD_TOKENS.has(name)) {
      let stmt = '';
      let j = i + 1;
      while (j < nonTrivia.length && nonTrivia[j] !== ';' && nonTrivia[j] !== '?>') {
        const tok = nonTrivia[j];
        stmt += (typeof tok === 'string' ? tok : tok[1]) + ' ';
        j++;
      }
      if (stmt.includes('/inc/nvx-') || stmt.includes('inc/nvx-')) {
        if (isFunctionsPhp) {
          const allowedFunctionsImports = [
            'inc/nvx-constants.php',
            'inc/nvx-config-helpers.php',
            'inc/nvx-theme-bootstrap.php',
          ];
          const isAllowed = allowedFunctionsImports.some((allowed) => stmt.includes(allowed));
          if (!isAllowed) {
            lateralLoads.push(stmt.trim());
          }
        } else {
          lateralLoads.push(stmt.trim());
        }
      }
    }
  }

  return { superglobals, lateralLoads };
}

// Self-test tokenizer with fixtures covering variants, comments, and strings
{
  const fixtureSource = `<?php
    // require_once '/inc/nvx-fake1.php';
    /* include '/inc/nvx-fake2.php'; */
    $dummy = 'require_once /inc/nvx-fake3.php';
    require_once /* comment */ __DIR__ . '/inc/nvx-real1.php';
    include( /* note */ __DIR__ . '/inc/nvx-real2.php' );
    require __DIR__ . '/inc/nvx-real3.php';
    include_once( __DIR__ . '/inc/nvx-real4.php' );
    $val = $_GET['test'];
    $val2 = $GLOBALS['_POST']['test'];
    // $val3 = $_COOKIE['test'];
    // $val4 = $GLOBALS['_REQUEST']['test'];
  `;
  const fixtureTokens = tokenizePhp(fixtureSource);
  const fixtureResult = extractViolations(fixtureTokens, false);
  assert.equal(fixtureResult.lateralLoads.length, 4, 'Tokenizer fixture must detect all 4 require/include variants with comments/parentheses');
  assert.deepEqual(fixtureResult.superglobals, ['$_GET', "$GLOBALS['_POST']"], 'Tokenizer fixture must detect direct and $GLOBALS access but ignore comments');
}

// Semantic verification of non-inc theme PHP files
for (const relative of nonInc) {
  const filePath = path.join(theme, relative);
  const source = fs.readFileSync(filePath, 'utf8');
  const tokens = tokenizePhp(source);
  const isFunctions = relative === 'functions.php';
  const { superglobals, lateralLoads } = extractViolations(tokens, isFunctions);

  assert.equal(
    superglobals.length,
    0,
    `${relative} must not create a browser-request authority (found: ${superglobals.join(', ')})`
  );
  assert.equal(
    lateralLoads.length,
    0,
    `${relative} must not load nvx runtime modules laterally (found: ${lateralLoads.join(', ')})`
  );
}

const functions = fs.readFileSync(path.join(theme, 'functions.php'), 'utf8');
assert.match(
  functions,
  /require_once __DIR__ \. '\/inc\/nvx-theme-bootstrap\.php';/,
  'functions.php must delegate runtime loading to the canonical bootstrap'
);

for (const file of ['home.php', 'archive.php', 'search.php']) {
  const source = fs.readFileSync(path.join(theme, file), 'utf8');
  assert.match(
    source,
    /require(?:_once)?\s*(?:\(?\s*get_template_directory\(\)\s*\.\s*)?['"]\/template-parts\/content\/nvx-blog-archive-route\.php['"]\s*\)?;/,
    `${file} must delegate to the shared Journal archive route via executable require`
  );
}

const singlePost = fs.readFileSync(path.join(theme, 'single-post.php'), 'utf8');
const contextOffset = singlePost.indexOf('nvx_theme_request_context()');
const delegationOffset = singlePost.indexOf("require_once __DIR__ . '/single.php';");
assert.ok(contextOffset >= 0, 'single-post must use canonical request context for governed route recovery');
assert.ok(delegationOffset >= 0, 'single-post template delegation to single.php must remain explicit');
assert.ok(
  contextOffset < delegationOffset,
  'Request context recovery must strictly precede delegation in single-post.php'
);

const sede = fs.readFileSync(path.join(theme, 'templates/page-sede.php'), 'utf8');
assert.match(
  sede,
  /\$clinic_key\s*=\s*'chamberi';/,
  'Known page-sede default-clinic routing debt must remain visible until clinic-route consolidation'
);
assert.match(
  sede,
  /strpos\( \$current_slug, 'goya' \)/,
  'Known page-sede substring routing debt must remain visible until clinic-route consolidation'
);

console.log(
  `PHP_ENTRYPOINTS_TEMPLATES=PASS all_php=${all.length} inc=${inc.length} root=${root.length} templates=${templates.length} parts=${parts.length} non_inc=${nonInc.length}`
);
