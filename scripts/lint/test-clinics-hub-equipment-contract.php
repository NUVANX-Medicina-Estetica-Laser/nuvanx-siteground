<?php
/**
 * Editorial contract for the clinic galleries and the scoped equipment section.
 *
 * This runs without WordPress. It deliberately validates source data and policy
 * boundaries, while browser acceptance validates the actual staging runtime.
 */

declare(strict_types=1);

$root          = dirname(__DIR__, 2);
$gbp_file      = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php';
$hub_file      = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-clinics-hub.php';
$sede_file     = $root . '/wp-content/themes/nuvanx-medical/templates/page-sede.php';
$registry_file = $root . '/wp-content/themes/nuvanx-medical/inc/data/clinic-asset-registry.json';
$runtime_file  = $root . '/scripts/staging2/clinic-media-runtime.mjs';

$fail = static function (string $reason): never {
    fwrite(STDERR, "CLINICS_HUB_EQUIPMENT_CONTRACT=FAIL reason={$reason}\n");
    exit(1);
};

foreach (array($gbp_file, $hub_file, $sede_file, $registry_file, $runtime_file) as $file) {
    if (!is_readable($file)) {
        $fail('required_file_unreadable:' . basename($file));
    }
}

$gbp      = (string) file_get_contents($gbp_file);
$hub      = (string) file_get_contents($hub_file);
$sede     = (string) file_get_contents($sede_file);
$runtime  = (string) file_get_contents($runtime_file);
$registry = json_decode((string) file_get_contents($registry_file), true);
if (!is_array($registry)) {
    $fail('registry_invalid_json');
}

$gallery_paths = array(
    'goya' => array(
        '2026/03/nuvanx-medicina-estetica1.webp',
        '2026/06/nvx-fachada-goya-900.webp',
    ),
    'chamberi' => array(
        '2026/03/nuvanx-medicina-estetica7.webp',
        '2026/06/nvx-fachada-chamberi-final-760.webp',
        '2026/06/Sala-Nuvanx.webp',
        '2025/04/despacho-nuvanx.webp',
    ),
);
$equipment_paths = array(
    '2026/08/endolift-lasemar-1500-eufoton.webp',
    '2026/08/BTL-Exion-Mobile-Version-1024x956-1.png',
    '2026/08/Endolift-ISO9001-Laser.webp',
    '2026/08/SmartLipo-for-Laserlipolysis-DEKA-1.png',
    '2026/08/ipl-exilite-luz-pulsada.webp',
    '2026/08/Emfusion-btl-lentigo-aranitas-vasculares-punto-de-rubi-marcas-de-acne.png',
    '2026/08/SMARTXIDE-DOT_EQUIPO-TOUCH-DEKA-LASER-CO2-FRACCIONAL.png',
);

$map_start = strpos($gbp, 'function nvx_clinic_editorial_photo_map');
$map_end   = strpos($gbp, 'function nvx_clinic_landing_photos');
if (false === $map_start || false === $map_end || $map_end <= $map_start) {
    $fail('gallery_map_missing');
}
$map = substr($gbp, $map_start, $map_end - $map_start);
foreach ($gallery_paths as $clinic => $paths) {
    foreach ($paths as $path) {
        if (1 !== substr_count($map, $path)) {
            $fail('gallery_path_missing_or_duplicated:' . $clinic . ':' . $path);
        }
    }
}
if (6 !== substr_count($map, "'uploads_path'")) {
    $fail('gallery_path_count_not_six');
}
foreach (array('2026/07/gosia-1.webp', '2026/07/WhatsApp-Image-2026-07-04-at-1.39.33-PM.webp') as $team_path) {
    if (false !== strpos($map, $team_path)) {
        $fail('goya_team_portrait_in_gallery:' . $team_path);
    }
}
foreach (array('1077', '1078', '1630', '1632') as $retired_id) {
    if (false !== strpos($map, "'id'           => {$retired_id}")) {
        $fail('retired_gallery_attachment_reintroduced:' . $retired_id);
    }
}
foreach (array(
    'wp_getimagesize( $source_path )',
    "'srcset'  => \$url . ' ' . (int) \$image_size[0] . 'w'",
    'function nvx_clinic_landing_gallery_expected_count',
    "return 'goya' === \$clinic_key ? 2 : 4;",
    'function nvx_clinic_landing_gallery_is_complete',
    'nvx_clinic_landing_gallery_expected_count( $clinic_key ) === count( $photos )',
) as $needle) {
    if (false === strpos($gbp, $needle)) {
        $fail('gallery_runtime_contract_missing:' . $needle);
    }
}
foreach (array(
    'data-nvx-gallery-contract="incomplete"',
    'Galería de la sede temporalmente no disponible',
    'if ( $clinic_gallery_complete )',
    'nvx_clinic_landing_gallery_is_complete( $clinic_photos, $clinic_key )',
) as $needle) {
    if (false === strpos($sede, $needle)) {
        $fail('gallery_visible_failure_state_missing:' . $needle);
    }
}
if (false !== strpos($sede, 'consulta-medica-personalizada-nuvanx-madrid')) {
    $fail('goya_generic_filler_reintroduced');
}

$catalog_start = strpos($hub, 'function nvx_clinics_hub_equipment_catalog');
$catalog_end   = strpos($hub, 'function nvx_clinics_hub_equipment_image_markup');
if (false === $catalog_start || false === $catalog_end || $catalog_end <= $catalog_start) {
    $fail('equipment_catalog_missing');
}
$catalog = substr($hub, $catalog_start, $catalog_end - $catalog_start);
if (7 !== substr_count($catalog, "'uploads_path'")) {
    $fail('equipment_path_count_not_seven');
}
if (7 !== substr_count($catalog, "'alt'") || 7 !== substr_count($catalog, "'description'")) {
    $fail('equipment_alt_or_description_count');
}
foreach ($equipment_paths as $path) {
    if (1 !== substr_count($catalog, $path)) {
        $fail('equipment_path_missing_or_duplicated:' . $path);
    }
}
if (false !== strpos($catalog, 'https://')) {
    $fail('equipment_cross_origin_source_forbidden');
}
foreach (array(
    'data-nvx-approved-equipment-section="clinic-hub-v1"',
    'NVX_APPROVED_EQUIPMENT_SECTION:clinic-hub-v1',
    'function nvx_clinics_hub_append_approved_equipment',
    "add_filter( 'the_content', 'nvx_clinics_hub_append_approved_equipment', 220 );",
    'return nvx_clinics_hub_equipment_unavailable_markup();',
    'function nvx_clinics_hub_equipment_unavailable_markup',
    'data-nvx-approved-equipment-section="incomplete"',
) as $needle) {
    if (false === strpos($hub, $needle)) {
        $fail('equipment_scope_hook_missing');
    }
}
foreach (array(
    'async function inspectEquipmentSection',
    'equipment_section_incomplete:',
    'equipment_card_count:',
    'equipment_selected_resource_invalid:',
    'equipment_current_src_cross_origin:',
    "{ key: 'chamberi', path: '/medicina-estetica-chamberi/', expectedGalleryCount: 4 }",
    "{ key: 'goya', path: '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/', expectedGalleryCount: 2 }",
    'initial.gallery.imageCount !== clinic.expectedGalleryCount',
    'images.length !== clinic.expectedGalleryCount',
) as $needle) {
    if (false === strpos($runtime, $needle)) {
        $fail('runtime_acceptance_contract_missing:' . $needle);
    }
}

$override = $registry['approved_editorial_overrides'] ?? null;
if (!is_array($override) || 'operator_explicit' !== ($override['source'] ?? null)) {
    $fail('registry_override_missing');
}
foreach ($gallery_paths as $clinic => $paths) {
    $entries = $override['clinic_landing_galleries'][$clinic] ?? null;
    if (!is_array($entries) || count($entries) !== count($paths)) {
        $fail('registry_gallery_count:' . $clinic);
    }
    $actual = array_map(static fn(array $entry): string => (string) ($entry['uploads_path'] ?? ''), $entries);
    if ($actual !== $paths) {
        $fail('registry_gallery_order_or_paths:' . $clinic);
    }
}
$equipment_override = $override['clinics_hub_equipment_section'] ?? null;
if (!is_array($equipment_override) || 'clinic-hub-v1' !== ($equipment_override['marker'] ?? null)) {
    $fail('registry_equipment_scope_missing');
}
if (($equipment_override['allowed_uploads_paths'] ?? null) !== $equipment_paths) {
    $fail('registry_equipment_paths_mismatch');
}
$prohibited = $equipment_override['prohibited_uses'] ?? array();
foreach (array('GBP', 'individual sede landing galleries', 'proof of physical availability at a specific sede', 'unverified clinical efficacy claims') as $scope) {
    if (!in_array($scope, $prohibited, true)) {
        $fail('registry_equipment_prohibition_missing');
    }
}

echo "CLINICS_HUB_EQUIPMENT_CONTRACT=PASS galleries=6 goya=2 chamberi=4 equipment=7 scope=clinic-hub-v1\n";
