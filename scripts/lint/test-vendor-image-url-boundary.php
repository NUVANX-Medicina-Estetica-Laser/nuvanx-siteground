<?php
/**
 * Regression contract for vendor-image URL detection.
 *
 * Vendor packshots must be rejected by their filename stems. Legitimate NUVANX
 * treatment assets may live below routes/directories containing brand or
 * treatment names and must not be classified as vendor media solely from the
 * directory path.
 */

declare(strict_types=1);

$root   = dirname( __DIR__, 2 );
$source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-gbp-local.php' );
$fail   = static function ( string $reason ): never {
	fwrite( STDERR, "VENDOR_IMAGE_URL_BOUNDARY=FAIL reason={$reason}\n" );
	exit( 1 );
};

if ( ! preg_match(
	"/function\\s+nvx_public_vendor_image_url_regex\\(\\):\\s*string\\s*\\{\\s*return\\s+'((?:\\\\.|[^'])+)';\\s*\\}/s",
	$source,
	$match
) ) {
	$fail( 'regex_function_not_found' );
}

$regex = $match[1];

$blocked = array(
	'https://nuvanx.com/wp-content/uploads/2026/02/deka.webp',
	'https://nuvanx.com/wp-content/uploads/2026/07/btl-exilite-ipl-madrid.webp',
	'https://nuvanx.com/wp-content/uploads/2026/07/BTL-Exion-Mobile-Version.webp',
	'https://nuvanx.com/wp-content/uploads/2026/07/exion.webp',
	'https://nuvanx.com/wp-content/uploads/2026/07/exion-machine.webp',
	'https://nuvanx.com/wp-content/uploads/2026/07/Endolift-ISO9001-Laser.jpg',
	'https://nuvanx.com/wp-content/uploads/2026/07/endolift-lasemar-1500-eufoton.webp',
	'https://nuvanx.com/wp-content/uploads/2026/07/SmartLipo-for-Laserlipolysis-DEKA.png',
);

$allowed = array(
	'https://nuvanx.com/wp-content/uploads/2026/08/exion-face/hero.webp',
	'https://nuvanx.com/wp-content/uploads/2026/08/exion-body/resultado-clinico.webp',
	'https://nuvanx.com/wp-content/uploads/2026/08/endolift-facial-papada-mandibula/hero.webp',
	'https://nuvanx.com/wp-content/uploads/2026/08/endolift-facial/consulta.webp',
	'https://nuvanx.com/wp-content/uploads/2026/08/equipo-medico/consulta-nuvanx.webp',
);

foreach ( $blocked as $url ) {
	if ( 1 !== preg_match( $regex, $url ) ) {
		$fail( 'packshot_not_blocked:' . $url );
	}
}

foreach ( $allowed as $url ) {
	if ( 1 === preg_match( $regex, $url ) ) {
		$fail( 'legitimate_url_false_positive:' . $url );
	}
}

$srcset = 'https://nuvanx.com/wp-content/uploads/2026/08/exion-face/hero-480.webp 480w, https://nuvanx.com/wp-content/uploads/2026/08/exion-face/hero-960.webp 960w';
if ( 1 === preg_match( $regex, $srcset ) ) {
	$fail( 'legitimate_srcset_false_positive' );
}

echo "VENDOR_IMAGE_URL_BOUNDARY=PASS blocked=" . count( $blocked ) . ' allowed=' . count( $allowed ) . PHP_EOL;

require __DIR__ . '/test-public-media-delivery.php';
