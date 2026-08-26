#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');

const hubPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/nvx-clinics-hub.php');
const migrationPath = path.join(root, 'tools/migrations/content-hygiene-staging-only.php');

const [hubSource, migrationSource] = await Promise.all([
  fs.readFile(hubPath, 'utf8'),
  fs.readFile(migrationPath, 'utf8'),
]);

const failures = [];
const requireSource = (source, needle, reason) => {
  if (!source.includes(needle)) failures.push(reason);
};

function segment(source, startNeedle, endNeedle, label) {
  const start = source.indexOf(startNeedle);
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  if (start < 0 || end < 0 || end <= start) {
    failures.push(`${label}_segment_missing`);
    return '';
  }
  return source.slice(start, end);
}

const catalog = segment(
  hubSource,
  'function nvx_clinics_hub_equipment_catalog(): array',
  'function nvx_clinics_hub_equipment_image_markup',
  'equipment_catalog'
);
const imageMarkup = segment(
  hubSource,
  'function nvx_clinics_hub_equipment_image_markup',
  'function nvx_clinics_hub_equipment_section_markup',
  'equipment_image_markup'
);
const sectionMarkup = segment(
  hubSource,
  'function nvx_clinics_hub_equipment_section_markup',
  'function nvx_clinics_hub_equipment_unavailable_markup',
  'equipment_section_markup'
);

const equipmentPaths = [...catalog.matchAll(/'uploads_path'\s*=>\s*'([^']+)'/g)].map((match) => match[1]);
const uniqueEquipmentPaths = new Set(equipmentPaths);

if (equipmentPaths.length !== 7) failures.push(`equipment_catalog_count_${equipmentPaths.length}`);
if (uniqueEquipmentPaths.size !== 7) failures.push(`equipment_catalog_unique_count_${uniqueEquipmentPaths.size}`);
for (const equipmentPath of equipmentPaths) {
  if (!/^20\d{2}\/\d{2}\/.+\.(?:webp|png|jpe?g|avif)$/i.test(equipmentPath)) {
    failures.push(`equipment_catalog_invalid_uploads_path_${equipmentPath}`);
  }
}

requireSource(imageMarkup, 'is_readable( $source_path ) ? wp_getimagesize( $source_path ) : false', 'equipment_requires_readable_local_image');
requireSource(imageMarkup, "return '';", 'equipment_missing_media_fails_closed');
requireSource(sectionMarkup, "if ( '' === $image )", 'equipment_section_missing_image_guard');
requireSource(sectionMarkup, 'return nvx_clinics_hub_equipment_unavailable_markup();', 'equipment_section_unavailable_fallback');
requireSource(sectionMarkup, '7 !== count( $catalog ) || 7 !== count( $cards )', 'equipment_section_exact_card_count_guard');
requireSource(sectionMarkup, 'data-nvx-approved-equipment-section="clinic-hub-v1"', 'equipment_section_approval_marker');

requireSource(migrationSource, "function_exists( 'nvx_clinics_hub_equipment_catalog' )", 'staging_media_catalog_function_guard');
requireSource(migrationSource, 'foreach ( nvx_clinics_hub_equipment_catalog() as $eq )', 'staging_media_catalog_iteration');
requireSource(migrationSource, "$eq_path = $normalize_media_path( (string) ( $eq['uploads_path'] ?? '' ) );", 'staging_media_catalog_path_normalization');
requireSource(migrationSource, '$media_paths[ $eq_path ]        = true;', 'staging_media_catalog_sync_registration');
requireSource(migrationSource, '$required_originals[ $eq_path ] = true;', 'staging_media_catalog_required_original');
requireSource(migrationSource, 'if ( ! is_file( $source ) )', 'staging_media_missing_source_guard');
requireSource(migrationSource, 'if ( isset( $required_originals[ $relative ] ) )', 'staging_media_required_source_branch');
requireSource(migrationSource, '$media_copy_failures++;', 'staging_media_copy_failure_accounting');
requireSource(migrationSource, 'if ( $media_copy_failures > 0 )', 'staging_media_parity_fail_closed');
requireSource(migrationSource, 'Status: MIGRATION_FAIL', 'staging_media_migration_failure_exit');

if (failures.length > 0) {
  console.error(`CLINIC_EQUIPMENT_STAGING_MEDIA_CONTRACT=FAIL reasons=${failures.join(',')}`);
  process.exit(1);
}

console.log(`CLINIC_EQUIPMENT_STAGING_MEDIA_CONTRACT=PASS equipment=${equipmentPaths.length} sync=dynamic required_originals=fail_closed renderer=local_readable_only`);
