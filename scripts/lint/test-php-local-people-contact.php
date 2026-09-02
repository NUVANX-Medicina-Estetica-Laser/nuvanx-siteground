<?php
/**
 * Block 8 regression: people + clinics + contact + medical hubs.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

function nvx_block8_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_LOCAL_PEOPLE_CONTACT=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
$dr   = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-dr-rivera-page.php' );
$guide = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-que-exigir-page.php' );
$valoracion = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php' );
$contact    = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-contacto-valoracion-page.php' );
$clinics    = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-clinics-hub.php' );

nvx_block8_assert(
	false !== strpos( $dr, "nvx_schema_path_matches( \$path, '/dr-javier-rivera-tejeda/' )" ),
	'DR_RIVERA_EXACT_PATH_MATCH'
);
nvx_block8_assert(
	false === strpos( $dr, "false !== strpos( \$path, '/dr-javier-rivera-tejeda/' )" ),
	'DR_RIVERA_NO_SUBSTRING_HIJACK'
);
nvx_block8_assert(
	false !== strpos( $guide, "nvx_schema_path_matches( \$path, '/que-exigir-antes-de-operarte/' )" ),
	'GUIDE_EXACT_PATH_MATCH'
);
nvx_block8_assert(
	false === strpos( $guide, "false !== strpos( \$path, '/que-exigir-antes-de-operarte/' )" ),
	'GUIDE_NO_SUBSTRING_HIJACK'
);

// Consolidation guards: keep confirmed cross-cutting debt visible until the
// single-owner follow-up removes it deliberately.
nvx_block8_assert(
	false !== strpos( $valoracion, "\$graph[ \$index ]['lastReviewed']    = '2026-08-01'" ),
	'VALORACION_UNGOVERNED_LAST_REVIEWED_RECORDED'
);
nvx_block8_assert(
	false !== strpos( $valoracion, "\$graph[ \$index ]['reviewedBy']      = \$reviewer" ),
	'VALORACION_UNGOVERNED_REVIEWER_RECORDED'
);
nvx_block8_assert(
	false !== strpos( $valoracion, 'https://wa.me/34689317399' ),
	'VALORACION_HARDCODED_WHATSAPP_FALLBACK_RECORDED'
);
nvx_block8_assert(
	false !== strpos( $contact, "false !== strpos( \$path, '/contacto/' )" ),
	'CONTACT_SUBSTRING_FALLBACK_RECORDED'
);
nvx_block8_assert(
	false !== strpos( $clinics, "require_once __DIR__ . '/nvx-clinics-dom-helpers.php'" ),
	'CLINICS_LATERAL_OWNER_RECORDED'
);

$bootstrap = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );
$catalog   = strpos( $bootstrap, "'inc/nvx-catalog-json.php'" );
$helpers   = strpos( $bootstrap, "'inc/nvx-page-render-helpers.php'" );
$targets   = array(
	'nvx-equipo-page.php',
	'nvx-nosotros-page.php',
	'nvx-contacto-valoracion-page.php',
	'nvx-valoracion-managed-page.php',
	'nvx-laser-medicine-page.php',
	'nvx-aesthetic-medicine-page.php',
	'nvx-clinics-dom-helpers.php',
	'nvx-clinics-hub.php',
	'nvx-dr-rivera-page.php',
	'nvx-que-exigir-page.php',
);
foreach ( $targets as $target ) {
	$offset = strpos( $bootstrap, "'inc/{$target}'" );
	nvx_block8_assert( false !== $offset, 'MANIFEST_TARGET_' . $target );
	nvx_block8_assert( false !== $catalog && $catalog < $offset, 'CATALOG_PRECEDES_' . $target );
	nvx_block8_assert( false !== $helpers && $helpers < $offset, 'HELPERS_PRECEDE_' . $target );
}

echo 'PHP_LOCAL_PEOPLE_CONTACT=PASS route_hijacks=exact manifest=covered consolidation_debt=guarded' . PHP_EOL;
