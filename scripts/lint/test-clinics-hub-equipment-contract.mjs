import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const gbpPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php');
const hubPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/nvx-clinics-hub.php');
const registryPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/data/clinic-asset-registry.json');
const runtimePath = path.join(root, 'scripts/staging2/clinic-media-runtime.mjs');

const gbp = fs.readFileSync(gbpPath, 'utf8');
const hub = fs.readFileSync(hubPath, 'utf8');
const runtime = fs.readFileSync(runtimePath, 'utf8');
const registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));

const fail = (reason) => {
  throw new Error(`CLINICS_HUB_EQUIPMENT_CONTRACT=FAIL reason=${reason}`);
};
const requireExact = (source, token, count, reason) => {
  const actual = source.split(token).length - 1;
  if (actual !== count) fail(`${reason}:actual=${actual}:expected=${count}`);
};

const galleryPaths = {
  goya: [
    '2026/03/nuvanx-medicina-estetica1.webp',
    '2026/06/nvx-fachada-goya-900.webp',
  ],
  chamberi: [
    '2026/03/nuvanx-medicina-estetica7.webp',
    '2026/06/nvx-fachada-chamberi-final-760.webp',
    '2026/06/Sala-Nuvanx.webp',
    '2025/04/despacho-nuvanx.webp',
  ],
};
const galleryRoles = {
  goya: ['box', 'facade'],
  chamberi: ['box', 'facade', 'waiting_room', 'consultation_office'],
};
const equipmentPaths = [
  '2026/08/endolift-lasemar-1500-eufoton.webp',
  '2026/08/BTL-Exion-Mobile-Version-1024x956-1.png',
  '2026/08/Endolift-ISO9001-Laser.webp',
  '2026/08/SmartLipo-for-Laserlipolysis-DEKA-1.png',
  '2026/08/ipl-exilite-luz-pulsada.webp',
  '2026/08/Emfusion-btl-lentigo-aranitas-vasculares-punto-de-rubi-marcas-de-acne.png',
  '2026/08/SMARTXIDE-DOT_EQUIPO-TOUCH-DEKA-LASER-CO2-FRACCIONAL.png',
];

const galleryStart = gbp.indexOf('function nvx_clinic_editorial_photo_map');
const galleryEnd = gbp.indexOf('function nvx_clinic_landing_gallery_expected_count');
if (galleryStart < 0 || galleryEnd <= galleryStart) fail('gallery_map_missing');
const galleryMap = gbp.slice(galleryStart, galleryEnd);
if (!galleryMap.includes('nvx_clinic_landing_gallery_registry( $clinic_key )')) fail('gallery_registry_consumer_missing');
for (const [clinic, paths] of Object.entries(galleryPaths)) {
  for (const assetPath of paths) {
    if (galleryMap.includes(assetPath)) fail(`gallery_path_redeclared_in_php:${clinic}:${assetPath}`);
  }
  for (const role of galleryRoles[clinic]) {
    if (!galleryMap.includes(`'${role}' => array(`)) fail(`gallery_role_copy_missing:${clinic}:${role}`);
  }
}
for (const forbiddenGoyaTeamPath of [
  '2026/07/gosia-1.webp',
  '2026/07/WhatsApp-Image-2026-07-04-at-1.39.33-PM.webp',
]) {
  if (galleryMap.includes(forbiddenGoyaTeamPath)) fail(`goya_team_portrait_in_gallery:${forbiddenGoyaTeamPath}`);
}
for (const retiredId of ['1077', '1078', '1630', '1632']) {
  if (galleryMap.includes(`'id'           => ${retiredId}`)) fail(`retired_gallery_attachment:${retiredId}`);
}
for (const token of [
  'wp_getimagesize( $source_path )',
  "'srcset'  => $url . ' ' . (int) $image_size[0] . 'w'",
  'function nvx_clinic_landing_gallery_expected_count',
  "return 'goya' === $clinic_key ? 2 : 4;",
  'function nvx_clinic_landing_gallery_is_complete',
  'nvx_clinic_landing_gallery_expected_count( $clinic_key ) === count( $photos )',
]) {
  if (!gbp.includes(token)) fail(`gallery_runtime_contract_missing:${token}`);
}
const sedeTemplate = fs.readFileSync(path.join(root, 'wp-content/themes/nuvanx-medical/templates/page-sede.php'), 'utf8');
for (const token of [
  'data-nvx-gallery-contract="incomplete"',
  'Galería de la sede temporalmente no disponible',
  'if ( $clinic_gallery_complete )',
  'nvx_clinic_landing_gallery_is_complete( $clinic_photos, $clinic_key )',
]) {
  if (!sedeTemplate.includes(token)) fail(`gallery_visible_failure_state_missing:${token}`);
}
if (sedeTemplate.includes('consulta-medica-personalizada-nuvanx-madrid')) fail('goya_generic_filler_reintroduced');

const catalogStart = hub.indexOf('function nvx_clinics_hub_equipment_catalog');
const catalogEnd = hub.indexOf('function nvx_clinics_hub_equipment_image_markup');
if (catalogStart < 0 || catalogEnd <= catalogStart) fail('equipment_catalog_missing');
const catalog = hub.slice(catalogStart, catalogEnd);
requireExact(catalog, "'uploads_path'", 7, 'equipment_path_count');
requireExact(catalog, "'alt'", 7, 'equipment_alt_count');
requireExact(catalog, "'description'", 7, 'equipment_description_count');
for (const assetPath of equipmentPaths) requireExact(catalog, assetPath, 1, `equipment_path:${assetPath}`);
if (catalog.includes('https://')) fail('equipment_cross_origin_source_forbidden');
for (const token of [
  'data-nvx-approved-equipment-section="clinic-hub-v1"',
  'NVX_APPROVED_EQUIPMENT_SECTION:clinic-hub-v1',
  'function nvx_clinics_hub_append_approved_equipment',
  "add_filter( 'the_content', 'nvx_clinics_hub_append_approved_equipment', NVX_HOOK_PRIO_CLINICS_APPROVED_EQUIPMENT );",
  'return nvx_clinics_hub_equipment_unavailable_markup();',
  'function nvx_clinics_hub_equipment_unavailable_markup',
  'data-nvx-approved-equipment-section="incomplete"',
]) {
  if (!hub.includes(token)) fail(`equipment_scope_hook_missing:${token}`);
}
for (const token of [
  'async function inspectEquipmentSection',
  'equipment_section_incomplete:',
  'equipment_card_count:',
  'equipment_selected_resource_invalid:',
  'equipment_current_src_cross_origin:',
  "{ key: 'chamberi', path: '/medicina-estetica-chamberi/', expectedGalleryCount: 4 }",
  "{ key: 'goya', path: '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/', expectedGalleryCount: 2 }",
  'initial.gallery.imageCount !== clinic.expectedGalleryCount',
  'images.length !== clinic.expectedGalleryCount',
]) {
  if (!runtime.includes(token)) fail(`runtime_acceptance_contract_missing:${token}`);
}

const override = registry.approved_editorial_overrides;
assert.equal(override?.source, 'operator_explicit', 'registry override source must remain explicit');
for (const [clinic, paths] of Object.entries(galleryPaths)) {
  const entries = override?.clinic_landing_galleries?.[clinic];
  const actualPaths = entries?.map(({ uploads_path: assetPath }) => assetPath);
  const actualRoles = entries?.map(({ role }) => role);
  assert.deepEqual(actualPaths, paths, `registry ${clinic} gallery order and paths must match the approved map`);
  assert.deepEqual(actualRoles, galleryRoles[clinic], `registry ${clinic} gallery roles must match renderer copy roles`);
}
assert.equal(override?.clinics_hub_equipment_section?.marker, 'clinic-hub-v1', 'registry equipment marker must remain scoped');
assert.deepEqual(override?.clinics_hub_equipment_section?.allowed_uploads_paths, equipmentPaths, 'registry equipment paths must match the renderer');
for (const prohibitedUse of ['GBP', 'individual sede landing galleries', 'proof of physical availability at a specific sede', 'unverified clinical efficacy claims']) {
  if (!override?.clinics_hub_equipment_section?.prohibited_uses?.includes(prohibitedUse)) fail(`registry_equipment_prohibition_missing:${prohibitedUse}`);
}

console.log('CLINICS_HUB_EQUIPMENT_CONTRACT=PASS galleries=6 goya=2 chamberi=4 equipment=7 scope=clinic-hub-v1 ownership=registry');
