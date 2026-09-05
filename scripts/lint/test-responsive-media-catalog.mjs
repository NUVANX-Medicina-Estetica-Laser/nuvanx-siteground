#!/usr/bin/env node
/**
 * Behavioral and delivery contract for responsive image intrinsics,
 * theme WebP derivatives, and click-to-load Maps embeds.
 *
 * Tracks #1081 / #1066 / #1067
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');
const exists = (rel) => fs.existsSync(path.join(root, rel));

const helpers = read('wp-content/themes/nuvanx-medical/inc/nvx-page-render-helpers.php');
const presentation = read('wp-content/themes/nuvanx-medical/inc/nvx-content-presentation.php');
const signaturePhp = read('wp-content/themes/nuvanx-medical/inc/nvx-signature-phase-pages.php');
const bridalPhp = read('wp-content/themes/nuvanx-medical/inc/nvx-bridal-page.php');
const mainJs = read('wp-content/themes/nuvanx-medical/assets/js/nvx-main.js');
const homePhp = read('wp-content/themes/nuvanx-medical/front-page.php');
const sedePhp = read('wp-content/themes/nuvanx-medical/templates/page-sede.php');

// 1. Content image enhancements and dimension resolution
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
  presentation,
  /nvx_content_enhance_img_tag_attrs/,
  'body image normalization must attach responsive attributes',
);
assert.match(
  helpers,
  /function nvx_theme_responsive_candidates/,
  'theme-hosted WebP derivatives must be discoverable by stem',
);

// 2. Intrinsic dimension catalog
const requiredIntrinsics = [
  ["'Sala-Nuvanx'", /'Sala-Nuvanx'\s*=>\s*array\(\s*1086,\s*1448\s*\)/],
  ["'nuvanx-medicina-2'", /'nuvanx-medicina-2'\s*=>\s*array\(\s*1220,\s*960\s*\)/],
  ["'Endolift-ISO9001-Laser'", /'Endolift-ISO9001-Laser'\s*=>\s*array\(\s*850,\s*470\s*\)/],
  ["'SmartLipo-for-Laserlipolysis-DEKA-1'", /'SmartLipo-for-Laserlipolysis-DEKA-1'\s*=>\s*array\(\s*447,\s*800\s*\)/],
  ["'nvx-fachada-goya-900'", /'nvx-fachada-goya-900'\s*=>\s*array\(\s*900,\s*675\s*\)/],
  ["'BTL-Exion-Mobile-Version-1024x956-1'", /'BTL-Exion-Mobile-Version-1024x956-1'\s*=>\s*array\(\s*1024,\s*956\s*\)/],
  ["'Box-Clinica-Novias'", /'Box-Clinica-Novias'\s*=>\s*array\(\s*1024,\s*1536\s*\)/],
  ["'Papada-novias'", /'Papada-novias'\s*=>\s*array\(\s*1536,\s*1024\s*\)/],
  ["'Brazos-novias'", /'Brazos-novias'\s*=>\s*array\(\s*941,\s*1672\s*\)/],
  ["'Espalda-novias'", /'Espalda-novias'\s*=>\s*array\(\s*941,\s*1672\s*\)/],
];

for (const [name, pattern] of requiredIntrinsics) {
  assert.match(helpers, pattern, `${name} intrinsic size must be catalogued`);
}

assert.doesNotMatch(
  helpers,
  /'Protocolo-Endolift-Thermage-Morpheus8-ultherapy'/,
  'retired bridal mood-collage must not be in intrinsic-size catalog',
);

// 3. Theme-hosted responsive WebP files existence
const requiredWebPFiles = [
  'BTL-Exion-Mobile-Version-1024x956-1-480.webp',
  'SmartLipo-for-Laserlipolysis-DEKA-1-447.webp',
  'Sala-Nuvanx-480.webp',
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
];

for (const file of requiredWebPFiles) {
  assert.ok(
    exists(`wp-content/themes/nuvanx-medical/assets/images/responsive/${file}`),
    `responsive asset must exist: ${file}`,
  );
}

const forbiddenWebPFiles = [
  'Protocolo-Endolift-Thermage-Morpheus8-ultherapy-280.webp',
  'Protocolo-Endolift-Thermage-Morpheus8-ultherapy-383.webp',
];

for (const file of forbiddenWebPFiles) {
  assert.ok(
    !exists(`wp-content/themes/nuvanx-medical/assets/images/responsive/${file}`),
    `retired asset must not exist: ${file}`,
  );
}

// 4. Bridal gallery markup and assets
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

// 5. Signature cards and list roles
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

// 6. Click-to-load Google Maps embeds
assert.match(
  helpers,
  /function nvx_lazy_map_embed_markup/,
  'Google Maps must not load until the user asks',
);
assert.match(
  helpers,
  /function nvx_rewrite_eager_maps_iframes/,
  'CMS and leftover Maps iframes must be rewritten to click-to-load',
);
assert.doesNotMatch(
  sedePhp,
  /<iframe[^>]+maps\.google/,
  'the sede template must not emit an eager Maps iframe',
);
assert.doesNotMatch(
  homePhp,
  /<iframe[^>]+maps\.google/,
  'the home template must not emit an eager Maps iframe',
);
assert.match(
  mainJs,
  /data-nvx-map-src/,
  'nvx-main must bind click-to-load maps',
);

console.log(
  `RESPONSIVE_MEDIA_CATALOG=PASS intrinsics=${requiredIntrinsics.length} ` +
  `webp_derivatives=${requiredWebPFiles.length} click_to_load_maps=verified`
);
