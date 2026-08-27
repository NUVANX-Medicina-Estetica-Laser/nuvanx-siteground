<?php
/**
 * Contract: clinic galleries expose only verified site photography.
 * Chamberí owns four approved site photos; Goya owns exactly Box + Fachada.
 * Gosia/Eva remain team portraits and the old generic Goya filler is forbidden.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

$root          = dirname(__DIR__, 2);
$template_path = $root . '/wp-content/themes/nuvanx-medical/templates/page-sede.php';
$helper_path   = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php';
$template      = (string) file_get_contents($template_path);
$helper        = (string) file_get_contents($helper_path);

$fail = static function (string $message): void {
    fwrite(STDERR, 'GOYA_GALLERY_FALLBACK_CONTRACT=FAIL ' . $message . PHP_EOL);
    exit(1);
};

foreach (array(
    "'2026/03/nuvanx-medicina-estetica1.webp'",
    "'2026/06/nvx-fachada-goya-900.webp'",
) as $required) {
    if (!str_contains($helper, $required)) {
        $fail('Goya site-photo map missing ' . $required);
    }
}

foreach (array(
    "'2026/07/gosia-1.webp'",
    "'2026/07/WhatsApp-Image-2026-07-04-at-1.39.33-PM.webp'",
) as $forbidden) {
    if (str_contains($helper, $forbidden)) {
        $fail('team portrait leaked into Goya site-photo map: ' . $forbidden);
    }
}

foreach (array(
    "return 'goya' === \$clinic_key ? 2 : 4;",
    'nvx_clinic_landing_gallery_expected_count( $clinic_key )',
    "function nvx_clinic_landing_gallery_is_complete( array \$photos, string \$clinic_key = 'chamberi' ): bool",
    "'id'   => 3101",
    "'id'   => 3100",
    "'name' => __( 'Gosia'",
    "'name' => __( 'Eva'",
) as $required) {
    if (!str_contains($helper, $required)) {
        $fail('helper contract missing ' . $required);
    }
}

if (str_contains($template, 'consulta-medica-personalizada-nuvanx-madrid')) {
    $fail('generic consultation fallback must not fill the Goya gallery');
}
if (str_contains($template, "'goya' === \$clinic_key && 3 === count( \$clinic_photos )")) {
    $fail('legacy Goya three-card fallback condition remains');
}
if (!str_contains($template, 'nvx_clinic_landing_gallery_is_complete( $clinic_photos, $clinic_key )')) {
    $fail('renderer must validate completeness against the current clinic key');
}
if (!str_contains($template, "( 'goya' === \$clinic_key ? 2 : 4 )")) {
    $fail('renderer fallback contract must remain 2 for Goya and 4 for Chamberí');
}
if (str_contains($template, 'las cuatro fotografías editoriales aprobadas')) {
    $fail('incomplete-gallery copy must not hardcode four photos for every sede');
}

echo 'GOYA_GALLERY_FALLBACK_CONTRACT=PASS mode=site_only goya=2 chamberi=4' . PHP_EOL;
