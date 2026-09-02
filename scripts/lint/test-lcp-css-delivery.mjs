import assert from 'node:assert/strict';
import fs from 'node:fs';

const conversionEvents = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/js/nvx-conversion-events.js',
  'utf8',
);
const functionsPhp = fs.readFileSync('wp-content/themes/nuvanx-medical/functions.php', 'utf8');
const governance = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-native-style-governance.php',
  'utf8',
);
const integrations = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-integrations.php',
  'utf8',
);
const components = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/css/nvx-components.css',
  'utf8',
);
const homeCss = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/css/nvx-home-v3.css',
  'utf8',
);
const patternsCss = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/css/nvx-patterns-editorial.css',
  'utf8',
);
const headerCss = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/css/nvx-header.css',
  'utf8',
);

assert.match(
  functionsPhp,
  /display=swap/,
  'Google Fonts request must keep font-display=swap',
);
assert.match(
  functionsPhp,
  /Public pages inline the local stack/,
  'theme stylesheet registration must document the public inline delivery',
);
assert.match(
  governance,
  /function nvx_theme_critical_stylesheet_files/,
  'the critical bundle must have a route-aware source manifest',
);
assert.match(
  governance,
  /assets\/css\/nvx-site-layout\.css/,
  'layout CSS must be part of the inline bundle',
);
assert.match(
  governance,
  /assets\/css\/nvx-components\.css/,
  'component CSS must be part of the inline bundle',
);
assert.match(
  governance,
  /assets\/css\/nvx-patterns-editorial\.css/,
  'interior hero CSS must be reserved before deferred assets',
);
assert.match(
  governance,
  /assets\/css\/nvx-header\.css/,
  'header geometry CSS must be part of the inline bundle',
);
assert.match(
  governance,
  /assets\/css\/nvx-accessibility-governance\.css/,
  'accessibility CSS must be part of the inline bundle',
);
assert.match(
  governance,
  /assets\/css\/nvx-home-v3\.css/,
  'home CSS must be included only on the front page',
);
assert.match(
  governance,
  /assets\/css\/nvx-posts\.css/,
  'blog CSS must be included on editorial routes',
);
assert.match(
  governance,
  /assets\/css\/nvx-soluciones-medicas\.css/,
  'solutions CSS must be included on its route',
);
assert.match(
  governance,
  /assets\/css\/nvx-cases\.css/,
  'patient-cases CSS must be included on its route',
);
assert.match(
  governance,
  /function nvx_theme_public_delivers_inline_styles/,
  'public requests must skip the local CSS file chain',
);
assert.match(
  governance,
  /themes\/nuvanx-medical\/assets\/css/,
  'leftover theme CSS file links must be dropped even if a plugin re-enqueues them',
);
assert.match(
  governance,
  /function nvx_theme_defer_local_script_tags/,
  'theme JS must stay deferred on the public document',
);
assert.match(
  functionsPhp,
  /nvx_theme_public_delivers_inline_styles/,
  'the public stylesheet enqueue must skip file URLs when the inline bundle is active',
);
assert.match(
  governance,
  /function nvx_theme_inline_critical_style_foundation/,
  'the stylesheet bundle must be emitted inline',
);
assert.match(
  governance,
  /function nvx_theme_dequeue_late_local_styles/,
  'late template styles must not recreate local stylesheet links',
);
assert.match(
  governance,
  /function nvx_theme_nonblocking_google_fonts/,
  'Google Fonts stylesheet must not block first paint',
);
assert.match(
  integrations,
  /function nvx_theme_is_klaviyo_asset/,
  'Klaviyo assets must be identified on all public routes',
);
assert.match(
  integrations,
  /function nvx_dequeue_public_klaviyo_onsite/,
  'Klaviyo Onsite must be removed globally from the public frontend',
);
assert.match(
  integrations,
  /function nvx_theme_defer_auxiliary_script_tags/,
  'Complianz and Joinchat scripts must be deferred',
);
assert.match(
  integrations,
  /function nvx_theme_interaction_joinchat_script/,
  'Joinchat must wait for a user gesture so it does not measure layout on first paint',
);
assert.match(
  integrations,
  /function nvx_theme_disable_public_emoji/,
  'WordPress emoji detection must not walk the public DOM',
);
assert.doesNotMatch(
  fs.readFileSync(
    'wp-content/themes/nuvanx-medical/assets/js/nvx-main.js',
    'utf8',
  ),
  /offsetWidth|getBoundingClientRect|getComputedStyle/,
  'theme UI JS must not force layout on the critical path',
);
assert.match(
  integrations,
  /function nvx_theme_defer_auxiliary_style_tags/,
  'Complianz and Joinchat styles must be non-blocking',
);
assert.match(
  integrations,
  /function nvx_theme_demote_auxiliary_styles/,
  'Joinchat CSS must be forced to print media before it is printed',
);
assert.ok(
  integrations.includes("preg_replace( '/\\smedia="),
  'Joinchat tag rewrite must strip a leftover media=all attribute',
);
assert.match(
  integrations,
  /preg_replace\( '\/\^<script\\b\/i', '<script defer', \$tag, 1 \)/,
  'script deferral must only alter the opening tag, not inline JavaScript content',
);
assert.doesNotMatch(
  integrations,
  /return str_replace\( '<script', '<script defer', \$tag \)/,
  'script deferral must not rewrite comparisons such as <scripts.length inside inline code',
);
assert.match(
  components,
  /\.nvx-brand-microcopy--dark[\s\S]*color:\s*var\(--nvx-light\)/,
  'dark hero microcopy must use solid light text so Lighthouse AA cannot fail on alpha',
);
assert.doesNotMatch(
  components,
  /\.nvx-brand-microcopy--dark[^{]*\{[^}]*--nvx-border-on-dark/,
  'microcopy must not reuse the 0.45 border-on-dark token',
);
assert.match(
  components,
  /\.nvx-brand-hero \.nvx-brand-btn--primary[\s\S]*--button-bg:\s*var\(--nvx-light\)/,
  'dark hero primary CTA must invert to light so it is visible on ink',
);
assert.match(
  components,
  /\.nvx-brand-hero \.nvx-brand-btn--secondary[\s\S]*--button-color:\s*var\(--nvx-light\)/,
  'dark hero WhatsApp CTA must use light label and border',
);
assert.match(
  components,
  /\.nvx-reg-copy[\s\S]*font-size:\s*var\(--nvx-type-caption\)/,
  'sanitary registration must use copyright-scale type',
);
assert.match(
  homeCss,
  /\.nvx-home-hero\s*\{[\s\S]*?height:\s*var\(--nvx-home-hero-h\);[\s\S]*?min-height:\s*var\(--nvx-home-hero-h\);[\s\S]*?display:\s*flex;[\s\S]*?align-items:\s*flex-end;[\s\S]*?overflow:\s*hidden;/,
  'front-page canonical CSS must reserve home hero geometry',
);
assert.match(
  patternsCss,
  /\.nvx-brand-hero\s*\{[^}]*background:\s*var\(--nvx-ink\);[^}]*color:\s*var\(--nvx-light\);/,
  'canonical interior hero CSS must reserve the dark stage in the inline bundle',
);
assert.match(
  patternsCss,
  /\.nvx-brand-hero__copy,[\s\S]*?padding-block:\s*var\(--nvx-space-8\);/,
  'canonical interior hero CSS must reserve hero copy padding before first paint',
);
assert.match(
  headerCss,
  /\.nvx-header\s*\{[^}]*min-height:\s*var\(--nvx-header-height-mobile\);/,
  'canonical header CSS must reserve mobile header height before first paint',
);

const baseCss = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/css/nvx-base.css',
  'utf8',
);
const layoutCss = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/css/nvx-site-layout.css',
  'utf8',
);
const fontsCss = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/css/nvx-fonts.css',
  'utf8',
);
assert.match(
  layoutCss,
  /\.nvx-brand-page > \.nvx-brand-section,[\s\S]*?padding-block:\s*var\(--nvx-pad-section\);/,
  'canonical layout CSS must reserve brand-section padding on first paint',
);
assert.match(
  headerCss,
  /\.nvx-logo__img,\s*\.custom-logo\s*\{[^}]*height:\s*var\(--nvx-logo-height-mobile\);[^}]*max-width:\s*var\(--nvx-logo-width-mobile\);/,
  'canonical header CSS must reserve logo geometry on first paint',
);
assert.doesNotMatch(
  baseCss,
  /home-hero geometry reservation|interior-hero first paint/,
  'base CSS must not recreate retired PHP first-paint snippet ownership',
);
assert.doesNotMatch(
  layoutCss,
  /transition:\s*padding-block/,
  'section padding must not animate after first paint',
);
assert.doesNotMatch(
  layoutCss,
  /transition:\s*padding-inline/,
  'shell padding must not animate after first paint',
);
assert.match(
  fontsCss,
  /Playfair Display Fallback/,
  'Playfair must have a metric-matched fallback to limit font-swap CLS',
);
assert.match(
  fontsCss,
  /Manrope Fallback/,
  'Manrope must have a metric-matched fallback to limit font-swap CLS',
);
assert.match(
  integrations,
  /klaviyojs/,
  'the official plugin handle klaviyojs must be dequeued',
);

assert.match(
  conversionEvents,
  /config\.ads && config\.ads\.phone_whatsapp_send_to/,
  'phone/WhatsApp clicks must send the catalog-owned Ads click conversion',
);
assert.match(
  conversionEvents,
  /joinchat/,
  'Joinchat widget clicks must count as WhatsApp conversions',
);

const helpers = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-page-render-helpers.php',
  'utf8',
);
const presentation = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-content-presentation.php',
  'utf8',
);
const mainJs = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/assets/js/nvx-main.js',
  'utf8',
);
assert.match(
  helpers,
  /function nvx_content_enhance_img_tag_attrs/,
  'content images must receive srcset/sizes from theme or upload derivatives',
);
assert.match(
  helpers,
  /function nvx_image_dimensions_for_url/,
  'content images must resolve explicit width and height without a network fetch',
);
assert.match(
  helpers,
  /'Sala-Nuvanx'\s*=>\s*array\(\s*1086,\s*1448\s*\)/,
  'Chamberí waiting-room intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'nuvanx-medicina-2'\s*=>\s*array\(\s*1220,\s*960\s*\)/,
  'Chamberí façade intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'Endolift-ISO9001-Laser'\s*=>\s*array\(\s*850,\s*470\s*\)/,
  'Endolift device intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'SmartLipo-for-Laserlipolysis-DEKA-1'\s*=>\s*array\(\s*447,\s*800\s*\)/,
  'SmartLipo PNG intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'nvx-fachada-goya-900'\s*=>\s*array\(\s*900,\s*675\s*\)/,
  'Goya façade intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'BTL-Exion-Mobile-Version-1024x956-1'\s*=>\s*array\(\s*1024,\s*956\s*\)/,
  'Goya EXION PNG intrinsic size must be catalogued',
);
assert.ok(
  fs.existsSync(
    'wp-content/themes/nuvanx-medical/assets/images/responsive/BTL-Exion-Mobile-Version-1024x956-1-480.webp',
  ),
  'Goya EXION must ship as a 480w WebP instead of the 758 KiB PNG',
);
assert.match(
  helpers,
  /function nvx_theme_responsive_candidates/,
  'theme-hosted WebP derivatives must be discoverable by stem',
);
assert.match(
  helpers,
  /function nvx_lazy_map_embed_markup/,
  'Google Maps must not load until the user asks',
);
const signaturePhp = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-signature-phase-pages.php',
  'utf8',
);
assert.match(
  signaturePhp,
  /<ul class="nvx-brand-grid nvx-brand-grid--3">/,
  'Signature cards must be a real list',
);
assert.doesNotMatch(
  signaturePhp,
  /<article class="nvx-brand-card" role="listitem"/,
  'article must not take role=listitem (invalid ARIA for agents)',
);
assert.match(
  helpers,
  /function nvx_sanitize_invalid_list_roles/,
  'invalid article listitem roles must be stripped from rendered content',
);
assert.match(
  helpers,
  /function nvx_rewrite_eager_maps_iframes/,
  'CMS and leftover Maps iframes must be rewritten to click-to-load',
);
assert.doesNotMatch(
  fs.readFileSync('wp-content/themes/nuvanx-medical/templates/page-sede.php', 'utf8'),
  /<iframe[^>]+maps\.google/,
  'the sede template must not emit an eager Maps iframe',
);
assert.doesNotMatch(
  fs.readFileSync('wp-content/themes/nuvanx-medical/front-page.php', 'utf8'),
  /<iframe[^>]+maps\.google/,
  'the home template must not emit an eager Maps iframe',
);
assert.match(
  presentation,
  /nvx_content_enhance_img_tag_attrs/,
  'body image normalization must attach responsive attributes',
);
assert.match(
  mainJs,
  /data-nvx-map-src/,
  'nvx-main must bind click-to-load maps',
);
assert.ok(
  fs.existsSync(
    'wp-content/themes/nuvanx-medical/assets/images/responsive/SmartLipo-for-Laserlipolysis-DEKA-1-447.webp',
  ),
  'SmartLipo must ship as WebP instead of the 329 KiB PNG',
);
assert.ok(
  fs.existsSync(
    'wp-content/themes/nuvanx-medical/assets/images/responsive/Sala-Nuvanx-480.webp',
  ),
  'Chamberí waiting-room photo must have a 480w WebP',
);
assert.match(
  helpers,
  /'Box-Clinica-Novias'\s*=>\s*array\(\s*1024,\s*1536\s*\)/,
  'bridal clinic-box intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'Papada-novias'\s*=>\s*array\(\s*1536,\s*1024\s*\)/,
  'bridal papada intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'Brazos-novias'\s*=>\s*array\(\s*941,\s*1672\s*\)/,
  'bridal arms intrinsic size must be catalogued',
);
assert.match(
  helpers,
  /'Espalda-novias'\s*=>\s*array\(\s*941,\s*1672\s*\)/,
  'bridal back intrinsic size must be catalogued',
);
assert.doesNotMatch(
  helpers,
  /'Protocolo-Endolift-Thermage-Morpheus8-ultherapy'/,
  'retired bridal mood-collage must not be in intrinsic-size catalog',
);
for (const file of [
  'Box-Clinica-Novias-480.webp',
  'Box-Clinica-Novias-768.webp',
  'Box-Clinica-Novias-1024.webp',
  'Papada-novias-480.webp',
  'Papada-novias-768.webp',
  'Papada-novias-960.webp',
  'Papada-novias-1536.webp',
  'Brazos-novias-480.webp',
  'Brazos-novias-768.webp',
  'Brazos-novias-941.webp',
  'Espalda-novias-480.webp',
  'Espalda-novias-768.webp',
  'Espalda-novias-941.webp',
]) {
  assert.ok(
    fs.existsSync(`wp-content/themes/nuvanx-medical/assets/images/responsive/${file}`),
    `bridal protocol must ship ${file} as WebP`,
  );
}
for (const file of [
  'Protocolo-Endolift-Thermage-Morpheus8-ultherapy-280.webp',
  'Protocolo-Endolift-Thermage-Morpheus8-ultherapy-383.webp',
]) {
  assert.ok(
    !fs.existsSync(`wp-content/themes/nuvanx-medical/assets/images/responsive/${file}`),
    `retired bridal mood-collage must not ship ${file}`,
  );
}
const bridalPhp = fs.readFileSync(
  'wp-content/themes/nuvanx-medical/inc/nvx-bridal-page.php',
  'utf8',
);
assert.match(
  functionsPhp,
  /nvx-bridal-page\.php/,
  'bridal gallery module must be bootstrapped',
);
assert.match(
  bridalPhp,
  /function nvx_bridal_inject_media/,
  'bridal page must inject gallery markup via the_content',
);
assert.match(
  bridalPhp,
  /Box-Clinica-Novias\.png/,
  'bridal gallery must use the clinic-box upload stem',
);
assert.match(
  bridalPhp,
  /Papada-novias\.png/,
  'bridal gallery must use the papada upload stem',
);
assert.match(
  bridalPhp,
  /Brazos-novias\.png/,
  'bridal gallery must use the arms upload stem',
);
assert.match(
  bridalPhp,
  /Espalda-novias\.png/,
  'bridal gallery must use the back upload stem',
);
assert.doesNotMatch(
  bridalPhp,
  /Protocolo-Endolift-Thermage-Morpheus8-ultherapy/,
  'bridal studio must not reintroduce the unapproved mood-collage',
);
assert.doesNotMatch(
  bridalPhp,
  /\.png(?!["'])/,
  'bridal markup must not hard-code PNG as the delivered src',
);
assert.match(
  components,
  /\.nvx-bridal-studio__spread/,
  'bridal studio must use equal two-column spreads, not a full-bleed box',
);
assert.match(
  components,
  /\.nvx-bridal-studio__pair/,
  'bridal portraits must sit in a matched pair',
);
assert.match(
  components,
  /aspect-ratio: 4 \/ 5/,
  'portrait plates must share one crop so the box cannot dwarf the rest',
);

console.log('LCP_CSS_DELIVERY=PASS');