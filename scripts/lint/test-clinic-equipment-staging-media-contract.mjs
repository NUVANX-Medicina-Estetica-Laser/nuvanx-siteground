#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');

const hubPath = path.join(root, 'wp-content/themes/nuvanx-medical/inc/nvx-clinics-hub.php');
const coreMigrationPath = path.join(root, 'tools/migrations/content-hygiene-staging-core.php');
const legacyMigrationPath = path.join(root, 'tools/migrations/content-hygiene-staging-only.php');
const migrationPath = (await fs.stat(coreMigrationPath).then(() => true, () => false))
  ? coreMigrationPath
  : legacyMigrationPath;
const clinicMediaRuntimePath = path.join(root, 'scripts/staging2/clinic-media-runtime.mjs');

const [hubSource, migrationSource, clinicMediaRuntimeSource] = await Promise.all([
  fs.readFile(hubPath, 'utf8'),
  fs.readFile(migrationPath, 'utf8'),
  fs.readFile(clinicMediaRuntimePath, 'utf8'),
]);

const failures = [];
const requireSource = (source, needle, reason) => {
  if (!source.includes(needle)) failures.push(reason);
};
const forbidSource = (source, needle, reason) => {
  if (source.includes(needle)) failures.push(reason);
};
const requireCount = (source, needle, expected, reason) => {
  const count = source.split(needle).length - 1;
  if (count !== expected) failures.push(`${reason}_count_${count}_expected_${expected}`);
};
const requireOrder = (source, firstNeedle, secondNeedle, reason) => {
  const first = source.indexOf(firstNeedle);
  const second = source.indexOf(secondNeedle);
  if (first < 0 || second < 0 || first >= second) failures.push(reason);
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

requireSource(migrationSource, "if ( ! function_exists( 'nvx_clinics_hub_equipment_catalog' ) )", 'staging_media_catalog_required_function_guard');
requireSource(migrationSource, '[FATAL] nvx_clinics_hub_equipment_catalog() not available.', 'staging_media_catalog_missing_function_fatal');
requireSource(migrationSource, '$equipment_catalog = nvx_clinics_hub_equipment_catalog();', 'staging_media_catalog_eager_load');
requireSource(migrationSource, '7 !== count( $equipment_catalog )', 'staging_media_catalog_exact_count_guard');
requireSource(migrationSource, 'foreach ( $equipment_catalog as $eq )', 'staging_media_catalog_iteration');
forbidSource(migrationSource, "if ( function_exists( 'nvx_clinics_hub_equipment_catalog' ) )", 'staging_media_catalog_optional_guard_present');
requireSource(migrationSource, "$eq_path = $normalize_media_path( (string) ( $eq['uploads_path'] ?? '' ) );", 'staging_media_catalog_path_normalization');
requireSource(migrationSource, '[FATAL] Clinic equipment catalog contains an invalid uploads_path.', 'staging_media_catalog_invalid_path_fatal');
requireSource(migrationSource, '$media_paths[ $eq_path ]         = true;', 'staging_media_catalog_sync_registration');
requireSource(migrationSource, '$required_originals[ $eq_path ]  = true;', 'staging_media_catalog_required_original');
requireSource(migrationSource, '$equipment_originals[ $eq_path ] = true;', 'staging_media_catalog_equipment_original');

const requiredSourceGuard = 'if ( $is_required && ( ! is_file( $source ) || filesize( $source ) <= 0 ) )';
const destinationGuard = 'if ( file_exists( $destination ) )';
requireSource(migrationSource, '$is_required  = isset( $required_originals[ $relative ] );', 'staging_media_required_flag');
requireSource(migrationSource, '$is_equipment = isset( $equipment_originals[ $relative ] );', 'staging_media_equipment_flag');
requireSource(migrationSource, requiredSourceGuard, 'staging_media_required_source_guard');
requireSource(migrationSource, '[MEDIA-ERROR] required Production original missing or empty:', 'staging_media_required_source_fatal');
requireOrder(migrationSource, requiredSourceGuard, destinationGuard, 'staging_media_required_source_must_precede_destination_acceptance');
requireSource(migrationSource, 'false === @getimagesize( $source )', 'staging_media_equipment_source_image_guard');
requireSource(migrationSource, '$destination_matches_source = filesize( $destination ) === filesize( $source )', 'staging_media_required_destination_size_guard');
requireSource(migrationSource, 'false !== @getimagesize( $destination )', 'staging_media_equipment_existing_destination_image_guard');
requireSource(migrationSource, '[MEDIA-REPAIR] required Staging media stale or unreadable:', 'staging_media_required_repair_path');
requireSource(migrationSource, 'if ( ! is_file( $source ) || filesize( $source ) <= 0 )', 'staging_media_optional_missing_source_guard');
requireSource(migrationSource, 'copied media failed size verification:', 'staging_media_copy_size_verification');
requireSource(migrationSource, 'copied equipment media failed image verification:', 'staging_media_copy_image_verification');
requireSource(migrationSource, '$media_copy_failures++;', 'staging_media_copy_failure_accounting');
requireSource(migrationSource, 'if ( $media_copy_failures > 0 )', 'staging_media_parity_fail_closed');
requireSource(migrationSource, 'Status: MIGRATION_FAIL', 'staging_media_migration_failure_exit');
requireSource(migrationSource, 'is_string( $source_hash ) && is_string( $dest_hash )', 'staging_media_required_destination_hash_guard');
const postCopyVerification = segment(
  migrationSource,
  'if ( ! wp_mkdir_p( $destination_dir ) || ! copy( $source, $destination ) )',
  '[MEDIA-ERROR] copied media failed size verification:',
  'staging_media_post_copy_verification'
);
requireSource(postCopyVerification, '$copied_source_hash !== $copied_dest_hash', 'staging_media_post_copy_hash_guard');

forbidSource(clinicMediaRuntimeSource, '!/^image\\\\//i', 'clinic_media_runtime_invalid_image_regex_double_backslash');
requireSource(clinicMediaRuntimeSource, '!/^image\\//i', 'clinic_media_runtime_valid_image_regex_present');

// Lazy-load acceptance must activate every image and distinguish candidate
// failures from recognized infrastructure evidence for both timeout and error.
requireSource(clinicMediaRuntimeSource, 'async function primeLazyImages(page, images)', 'clinic_media_runtime_lazy_activation_owner_missing');
requireSource(clinicMediaRuntimeSource, 'await images.nth(index).scrollIntoViewIfNeeded();', 'clinic_media_runtime_per_image_scroll_missing');
requireSource(clinicMediaRuntimeSource, 'async function readLoadedImage(image, includeResourceMetrics = false)', 'clinic_media_runtime_bounded_image_reader_missing');
requireSource(clinicMediaRuntimeSource, "timer = setTimeout(() => settle('timeout'), options.timeoutMs);", 'clinic_media_runtime_explicit_timeout_outcome_missing');
requireSource(clinicMediaRuntimeSource, "const onLoad = () => settle('load');", 'clinic_media_runtime_explicit_load_outcome_missing');
requireSource(clinicMediaRuntimeSource, "const onError = () => settle('error');", 'clinic_media_runtime_explicit_error_outcome_missing');
requireSource(clinicMediaRuntimeSource, 'await primeLazyImages(page, equipmentImages);', 'clinic_media_runtime_equipment_per_image_activation_missing');
requireSource(clinicMediaRuntimeSource, 'await primeLazyImages(page, galleryImages);', 'clinic_media_runtime_gallery_per_image_activation_missing');
requireSource(clinicMediaRuntimeSource, 'async function imageLoadFailureHasTransientEvidence', 'clinic_media_runtime_failure_evidence_owner_missing');
forbidSource(clinicMediaRuntimeSource, 'async function imageTimeoutHasTransientEvidence', 'clinic_media_runtime_timeout_only_evidence_owner_present');
requireSource(clinicMediaRuntimeSource, "const probeUrl = image.currentSrc || image.src || '';", 'clinic_media_runtime_failure_probe_url_missing');
requireSource(clinicMediaRuntimeSource, 'if (parsed.hostname !== expectedHost) return false;', 'clinic_media_runtime_failure_probe_host_guard_missing');
requireSource(clinicMediaRuntimeSource, 'syntheticError: \'\'', 'clinic_media_runtime_success_probe_error_marker_missing');
requireSource(clinicMediaRuntimeSource, 'status: 0,', 'clinic_media_runtime_probe_exception_not_separated');
requireSource(clinicMediaRuntimeSource, "if (body.syntheticError !== 'AbortError') return false;", 'clinic_media_runtime_non_timeout_exception_not_fail_real');
requireSource(clinicMediaRuntimeSource, 'reason=image_probe_timeout trigger=${outcome}', 'clinic_media_runtime_abort_timeout_not_evidence_bound');
requireSource(clinicMediaRuntimeSource, 'reason=image_${outcome}_transport_evidence', 'clinic_media_runtime_http_transport_evidence_missing');
requireCount(clinicMediaRuntimeSource, "if (image.outcome === 'timeout' || image.outcome === 'error')", 2, 'clinic_media_runtime_timeout_error_evidence_branches');
requireSource(
  clinicMediaRuntimeSource,
  "imageLoadFailureHasTransientEvidence(page, image, route.path, viewport.key, 'equipment', index, image.outcome)",
  'clinic_media_runtime_equipment_error_timeout_probe_missing'
);
requireSource(
  clinicMediaRuntimeSource,
  "imageLoadFailureHasTransientEvidence(page, image, clinic.path, viewport.key, 'gallery', index, image.outcome)",
  'clinic_media_runtime_gallery_error_timeout_probe_missing'
);
requireSource(clinicMediaRuntimeSource, 'equipment_image_load_timeout:', 'clinic_media_runtime_unproven_equipment_timeout_fails_real');
requireSource(clinicMediaRuntimeSource, 'gallery_image_load_timeout:', 'clinic_media_runtime_unproven_gallery_timeout_fails_real');
requireSource(clinicMediaRuntimeSource, 'equipment_image_not_loaded:', 'clinic_media_runtime_unproven_equipment_error_fails_real');
requireSource(clinicMediaRuntimeSource, 'gallery_image_not_loaded:', 'clinic_media_runtime_unproven_gallery_error_fails_real');
forbidSource(clinicMediaRuntimeSource, "status: 503,\n        url: selectedUrl", 'clinic_media_runtime_synthetic_503_for_fetch_exception_present');
forbidSource(
  clinicMediaRuntimeSource,
  "await section.scrollIntoViewIfNeeded();\n  await page.waitForTimeout(1200);",
  'clinic_media_runtime_equipment_parent_sleep_present'
);
forbidSource(
  clinicMediaRuntimeSource,
  "const gallery = page.locator('.nvx-clinic-gallery');\n    await gallery.scrollIntoViewIfNeeded();\n    await page.waitForTimeout(1200);",
  'clinic_media_runtime_gallery_parent_sleep_present'
);

if (failures.length > 0) {
  console.error(`CLINIC_EQUIPMENT_STAGING_MEDIA_CONTRACT=FAIL reasons=${failures.join(',')}`);
  process.exit(1);
}

console.log(`CLINIC_EQUIPMENT_STAGING_MEDIA_CONTRACT=PASS equipment=${equipmentPaths.length} sync=required_dynamic source=production_first destination=hash_and_size_checked image=verified fail_closed=1 renderer=local_readable_only regression_gate=clinic_media_regex+per_image_lazy_activation+evidence_classification`);
