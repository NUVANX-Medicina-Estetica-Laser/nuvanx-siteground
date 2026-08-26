<?php
/**
 * Contract: Goya emits a fourth static gallery card only when the governed
 * photo map yields three renderable cards, retaining a complete responsive
 * image contract without relying on a stale uploads derivative.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

$root          = dirname(__DIR__, 2);
$template_path = $root . '/wp-content/themes/nuvanx-medical/templates/page-sede.php';
$theme_root    = $root . '/wp-content/themes/nuvanx-medical/';
$template      = (string) file_get_contents($template_path);

$fail = static function (string $message): void {
    fwrite(STDERR, 'GOYA_GALLERY_FALLBACK_CONTRACT=FAIL ' . $message . PHP_EOL);
    exit(1);
};

if (!str_contains($template, "'goya' === \$clinic_key && 3 === count( \$clinic_photos )")) {
    $fail('renderer must add a fallback only when Goya has exactly three cards');
}

$sources = array(
    480 => 'assets/images/responsive/consulta-medica-personalizada-nuvanx-madrid-480.webp',
    768 => 'assets/images/responsive/consulta-medica-personalizada-nuvanx-madrid-768.webp',
    960 => 'assets/images/responsive/consulta-medica-personalizada-nuvanx-madrid-960.webp',
);

foreach ($sources as $width => $source) {
    $path = $theme_root . $source;
    if (!is_file($path) || 0 === filesize($path)) {
        $fail('missing versioned source ' . $source);
    }
    if (!str_contains($template, $source . ' ' . $width . 'w')) {
        $fail('srcset must contain ' . $width . 'w source');
    }
}

foreach (array("'id'      => 0", "'width'   => 960", "'height'  => 540", "'srcset'  => \$fallback_srcset", 'loading="lazy"', 'decoding="async"') as $required) {
    if (!str_contains($template, $required)) {
        $fail('renderer media contract missing ' . $required);
    }
}

echo 'GOYA_GALLERY_FALLBACK_CONTRACT=PASS' . PHP_EOL;
