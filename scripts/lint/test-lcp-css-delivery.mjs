#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (file) => fs.readFileSync(file, 'utf8');
const functionsPhp = read('wp-content/themes/nuvanx-medical/functions.php');
const governance = read('wp-content/themes/nuvanx-medical/inc/nvx-native-style-governance.php');
const integrations = read('wp-content/themes/nuvanx-medical/inc/nvx-integrations.php');
const components = read('wp-content/themes/nuvanx-medical/assets/css/nvx-components.css');
const homeCss = read('wp-content/themes/nuvanx-medical/assets/css/nvx-home-v3.css');
const patternsCss = read('wp-content/themes/nuvanx-medical/assets/css/nvx-patterns-editorial.css');
const headerCss = read('wp-content/themes/nuvanx-medical/assets/css/nvx-header.css');
const layoutCss = read('wp-content/themes/nuvanx-medical/assets/css/nvx-site-layout.css');
const baseCss = read('wp-content/themes/nuvanx-medical/assets/css/nvx-base.css');
const mainJs = read('wp-content/themes/nuvanx-medical/assets/js/nvx-main.js');

assert.match(functionsPhp, /display=swap/, 'Google Fonts must keep font-display=swap');

for (const handle of [
  'nvx-fonts',
  'nvx-tokens',
  'nvx-base',
  'nvx-layout',
  'nvx-components',
  'nvx-patterns',
  'nvx-treatment-authority',
  'nvx-header',
  'nvx-footer',
]) {
  assert.match(
    functionsPhp,
    new RegExp(`wp_enqueue_style\\(\\s*['\"]${handle}['\"]`),
    `${handle} must be delivered as a linked WordPress stylesheet`,
  );
}

assert.match(
  functionsPhp,
  /wp_enqueue_style\(\s*'nvx-home-v3'/,
  'home CSS must remain a linked route-owned stylesheet',
);
assert.match(
  functionsPhp,
  /wp_enqueue_style\(\s*'nvx-portfolio-hub'/,
  'portfolio hub CSS must remain a linked route-owned stylesheet',
);

for (const retired of [
  'nvx_theme_public_delivers_inline_styles',
  'nvx_theme_get_css_manifest',
  'nvx_theme_get_compiled_critical_css_bundle',
  'nvx_theme_inline_critical_style_foundation',
  'nvx_theme_drop_inlined_file_links',
  'nvx-critical-inline',
]) {
  assert.doesNotMatch(
    `${functionsPhp}\n${governance}`,
    new RegExp(retired),
    `retired static-inline owner must stay absent: ${retired}`,
  );
}

assert.doesNotMatch(
  governance,
  /wp_add_inline_style\s*\(/,
  'native style governance must not re-embed static CSS',
);
assert.doesNotMatch(
  governance,
  /dist\/manifest\.json|file_get_contents\s*\([^)]*\.css/,
  'runtime PHP must not read compiled/source CSS into memory',
);
assert.match(
  governance,
  /function nvx_theme_defer_local_script_tags/,
  'theme JS must stay deferred on the public document',
);
assert.match(
  governance,
  /function nvx_theme_nonblocking_google_fonts/,
  'Google Fonts stylesheet must remain non-blocking',
);

assert.match(integrations, /function nvx_theme_is_klaviyo_asset/);
assert.match(integrations, /function nvx_dequeue_public_klaviyo_onsite/);
assert.match(integrations, /function nvx_theme_defer_auxiliary_script_tags/);
assert.match(integrations, /function nvx_theme_interaction_joinchat_script/);
assert.match(integrations, /function nvx_theme_disable_public_emoji/);
assert.match(integrations, /function nvx_theme_defer_auxiliary_style_tags/);
assert.match(integrations, /function nvx_theme_demote_auxiliary_styles/);
assert.doesNotMatch(
  mainJs,
  /offsetWidth|getBoundingClientRect|getComputedStyle/,
  'theme UI JS must not force layout on the critical path',
);

assert.match(
  components,
  /\.nvx-brand-microcopy--dark[\s\S]*color:\s*var\(--nvx-light\)/,
  'dark hero microcopy must use solid light text',
);
assert.match(
  components,
  /\.nvx-brand-hero \.nvx-brand-btn--primary[\s\S]*--button-bg:\s*var\(--nvx-light\)/,
  'dark hero primary CTA must remain visible',
);
assert.match(
  homeCss,
  /\.nvx-home-hero\s*\{[\s\S]*?height:\s*var\(--nvx-home-hero-h\);[\s\S]*?min-height:\s*var\(--nvx-home-hero-h\);/,
  'home CSS must reserve hero geometry',
);
assert.match(
  patternsCss,
  /\.nvx-brand-hero\s*\{[^}]*background:\s*var\(--nvx-ink\);[^}]*color:\s*var\(--nvx-light\);/,
  'interior hero surface must stay in canonical source CSS',
);
assert.match(
  patternsCss,
  /\.nvx-brand-hero__copy,[\s\S]*?padding-block:\s*var\(--nvx-space-8\);/,
  'interior hero copy spacing must stay source-owned',
);
assert.match(
  headerCss,
  /\.nvx-header\s*\{[^}]*min-height:\s*var\(--nvx-header-height-mobile\);/,
  'header CSS must reserve mobile header height',
);
assert.match(
  layoutCss,
  /\.nvx-brand-page > \.nvx-brand-section,[\s\S]*?padding-block:\s*var\(--nvx-pad-section\);/,
  'layout CSS must reserve section padding',
);
assert.match(
  headerCss,
  /\.nvx-logo__img,\s*\.custom-logo\s*\{[^}]*height:\s*var\(--nvx-logo-height-mobile\);/,
  'header CSS must reserve logo geometry',
);
assert.doesNotMatch(
  baseCss,
  /home-hero geometry reservation|interior-hero first paint/,
  'base CSS must not recreate page-specific first-paint ownership',
);

console.log('LCP_CSS_DELIVERY=PASS static_delivery=linked runtime_css_reads=0 static_inline_bundle=0');
