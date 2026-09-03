import assert from 'node:assert/strict';
import fs from 'node:fs';

const functionsPhp = fs.readFileSync('wp-content/themes/nuvanx-medical/functions.php', 'utf8');
const governance = fs.readFileSync('wp-content/themes/nuvanx-medical/inc/nvx-native-style-governance.php', 'utf8');
const baseCss = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/css/nvx-base.css', 'utf8');
const layoutCss = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/css/nvx-site-layout.css', 'utf8');
const componentsCss = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/css/nvx-components.css', 'utf8');
const patternsCss = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/css/nvx-patterns-editorial.css', 'utf8');
const homeCss = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/css/nvx-home-v3.css', 'utf8');
const headerCss = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/css/nvx-header.css', 'utf8');
const fontsCss = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/css/nvx-fonts.css', 'utf8');
const mainJs = fs.readFileSync('wp-content/themes/nuvanx-medical/assets/js/nvx-main.js', 'utf8');

assert.match(functionsPhp, /display=swap/, 'Google Fonts request must keep font-display=swap');
assert.match(
  governance,
  /function\s+nvx_theme_public_delivers_inline_styles\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+false\s*;\s*\}/s,
  'static theme CSS must use linked delivery',
);
assert.doesNotMatch(governance, /nvx-critical-inline|dist\/manifest\.json|nvx_theme_inline_critical_style_foundation/, 'runtime static CSS inlining must remain retired');
assert.doesNotMatch(governance, /nvx_theme_drop_inlined_file_links|nvx_theme_dequeue_late_local_styles/, 'runtime must not suppress canonical local stylesheet links');
assert.match(governance, /function nvx_theme_critical_stylesheet_files/, 'release validation must retain a complete source CSS inventory');
assert.match(governance, /function nvx_theme_nonblocking_google_fonts/, 'Google Fonts stylesheet must remain non-blocking');
assert.match(governance, /function nvx_theme_defer_local_script_tags/, 'theme JS must remain deferred');

for (const asset of [
  'nvx-fonts.css',
  'nvx-tokens.css',
  'nvx-base.css',
  'nvx-site-layout.css',
  'nvx-components.css',
  'nvx-patterns-editorial.css',
  'nvx-treatment-authority.css',
  'nvx-header.css',
  'nvx-footer.css',
  'nvx-home-v3.css',
  'nvx-portfolio-hub.css',
]) {
  assert.match(functionsPhp, new RegExp(asset.replace('.', '\\.')), `${asset} must be emitted through the canonical enqueue graph`);
}
assert.match(functionsPhp, /nvx_asset_version\(/, 'linked CSS must be versioned from the exact deployed asset');
assert.match(functionsPhp, /wp_enqueue_style\(\s*'nvx-tokens'/s, 'core token stylesheet must be linked');
assert.match(functionsPhp, /wp_enqueue_style\(\s*'nvx-base'/s, 'core base stylesheet must be linked');
assert.match(functionsPhp, /wp_enqueue_style\(\s*'nvx-layout'/s, 'core layout stylesheet must be linked');
assert.match(functionsPhp, /wp_enqueue_style\(\s*'nvx-components'/s, 'core component stylesheet must be linked');
assert.match(functionsPhp, /wp_enqueue_style\(\s*'nvx-patterns'/s, 'editorial pattern stylesheet must be linked');
assert.match(functionsPhp, /wp_enqueue_style\(\s*'nvx-header'/s, 'header stylesheet must be linked');
assert.match(functionsPhp, /wp_enqueue_style\(\s*'nvx-footer'/s, 'footer stylesheet must be linked');

assert.doesNotMatch(mainJs, /offsetWidth|getBoundingClientRect|getComputedStyle/, 'theme UI JS must not force layout on the critical path');
assert.match(homeCss, /\.nvx-home-hero\s*\{[\s\S]*?height:\s*var\(--nvx-home-hero-h\);[\s\S]*?min-height:\s*var\(--nvx-home-hero-h\);/, 'home hero geometry must be reserved in static CSS');
assert.match(patternsCss, /\.nvx-brand-hero\s*\{[^}]*background:\s*var\(--nvx-ink\);[^}]*color:\s*var\(--nvx-light\);/, 'interior hero stage must be owned by static CSS');
assert.match(patternsCss, /\.nvx-brand-hero__copy,[\s\S]*?padding-block:\s*var\(--nvx-space-8\);/, 'interior hero copy spacing must be reserved in static CSS');
assert.match(headerCss, /\.nvx-header\s*\{[^}]*min-height:\s*var\(--nvx-header-height-mobile\);/, 'header geometry must be reserved before first paint');
assert.match(headerCss, /\.nvx-logo__img,\s*\.custom-logo\s*\{[^}]*height:\s*var\(--nvx-logo-height-mobile\);[^}]*max-width:\s*var\(--nvx-logo-width-mobile\);/, 'logo geometry must be reserved before first paint');
assert.match(layoutCss, /\.nvx-brand-page > \.nvx-brand-section,[\s\S]*?padding-block:\s*var\(--nvx-pad-section\);/, 'section spacing must be static before first paint');
assert.doesNotMatch(layoutCss, /transition:\s*padding-(?:block|inline)/, 'critical shell spacing must not animate after first paint');
assert.doesNotMatch(baseCss, /home-hero geometry reservation|interior-hero first paint/, 'base CSS must not recreate retired PHP first-paint snippets');
assert.match(fontsCss, /Playfair Display Fallback/, 'Playfair must retain a metric-matched fallback');
assert.match(fontsCss, /Manrope Fallback/, 'Manrope must retain a metric-matched fallback');
assert.match(componentsCss, /\.nvx-brand-hero \.nvx-brand-btn--primary[\s\S]*--button-bg:\s*var\(--nvx-light\)/, 'dark hero primary CTA must retain visible contrast');

console.log('LCP_CSS_DELIVERY=PASS model=linked_static cacheable=1 runtime_inline=0');
