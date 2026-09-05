<?php
/**
 * Block 8 regression: people + clinics + contact + medical hubs.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function nvx_block8_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_LOCAL_PEOPLE_CONTACT=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root       = dirname( __DIR__, 2 );
$theme_inc  = $root . '/wp-content/themes/nuvanx-medical/inc/';
require_once $theme_inc . 'nvx-constants.php';
$dr         = (string) file_get_contents( $theme_inc . 'nvx-dr-rivera-page.php' );
$guide      = (string) file_get_contents( $theme_inc . 'nvx-que-exigir-page.php' );
$valoracion = (string) file_get_contents( $theme_inc . 'nvx-valoracion-managed-page.php' );
$medical    = (string) file_get_contents( $theme_inc . 'nvx-medical-review.php' );
$contact    = (string) file_get_contents( $theme_inc . 'nvx-contacto-valoracion-page.php' );
$clinics    = (string) file_get_contents( $theme_inc . 'nvx-clinics-hub.php' );

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool { return false; }
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool { return false; }
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ): bool { unset( $post_types ); return true; }
}
if ( ! function_exists( 'is_page' ) ) {
	function is_page( $page = '' ): bool { unset( $page ); return true; }
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int { return 1; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): bool { unset( $args ); return true; }
}
if ( ! function_exists( 'nvx_schema_current_path' ) ) {
	function nvx_schema_current_path( $id = 0 ): string {
		unset( $id );
		return $GLOBALS['nvx_test_path'] ?? '';
	}
}
if ( ! function_exists( 'nvx_schema_normalize_path' ) ) {
	function nvx_schema_normalize_path( $path ): string {
		$p = '/' . trim( (string) $path, '/' );
		return '/' === $p ? '/' : $p . '/';
	}
}

require_once $theme_inc . 'nvx-dr-rivera-page.php';
require_once $theme_inc . 'nvx-que-exigir-page.php';

// Behavioral assertions covering exact, nested, prefixed, and unrelated paths.
$GLOBALS['nvx_test_path'] = '/dr-javier-rivera-tejeda/';
nvx_block8_assert( nvx_content_is_dr_rivera_page( '' ), 'DR_RIVERA_EXACT_MATCH_TRUE' );

$GLOBALS['nvx_test_path'] = '/dr-javier-rivera-tejeda/child/';
nvx_block8_assert( ! nvx_content_is_dr_rivera_page( '' ), 'DR_RIVERA_CHILD_REJECTED' );

$GLOBALS['nvx_test_path'] = '/dr-javier-rivera-tejeda-consulta/';
nvx_block8_assert( ! nvx_content_is_dr_rivera_page( '' ), 'DR_RIVERA_PREFIX_SIBLING_REJECTED' );

$GLOBALS['nvx_test_path'] = '/tratamientos/';
nvx_block8_assert( ! nvx_content_is_dr_rivera_page( '' ), 'DR_RIVERA_UNRELATED_REJECTED' );

$GLOBALS['nvx_test_path'] = '/que-exigir-antes-de-operarte/';
nvx_block8_assert( nvx_content_is_que_exigir_page( '' ), 'GUIDE_EXACT_MATCH_TRUE' );

$GLOBALS['nvx_test_path'] = '/que-exigir-antes-de-operarte/child/';
nvx_block8_assert( ! nvx_content_is_que_exigir_page( '' ), 'GUIDE_CHILD_REJECTED' );

$GLOBALS['nvx_test_path'] = '/que-exigir-antes-de-operarte-previo/';
nvx_block8_assert( ! nvx_content_is_que_exigir_page( '' ), 'GUIDE_PREFIX_SIBLING_REJECTED' );

$GLOBALS['nvx_test_path'] = '/contacto/';
nvx_block8_assert( ! nvx_content_is_que_exigir_page( '' ), 'GUIDE_UNRELATED_REJECTED' );

nvx_block8_assert(
	false !== strpos( $dr, "nvx_schema_normalize_path( \$path ) === '/dr-javier-rivera-tejeda/'" ),
	'DR_RIVERA_NORMALIZED_EXACT_EQUALITY'
);
nvx_block8_assert(
	false === strpos( $dr, 'nvx_schema_path_matches(' ),
	'DR_RIVERA_NO_PREFIX_MATCH'
);
nvx_block8_assert(
	false !== strpos( $guide, "nvx_schema_normalize_path( \$path ) === '/que-exigir-antes-de-operarte/'" ),
	'GUIDE_NORMALIZED_EXACT_EQUALITY'
);
nvx_block8_assert(
	false === strpos( $guide, 'nvx_schema_path_matches(' ),
	'GUIDE_NO_PREFIX_MATCH'
);

// Medical review provenance is single-owner. Feature renderers may type and
// enrich WebPage nodes, but must not stamp reviewedBy/lastReviewed themselves.
nvx_block8_assert(
	0 === preg_match( '/\$graph\s*\[\s*\$index\s*\]\s*\[\s*[\'\"]lastReviewed[\'\"]\s*\]\s*=/', $valoracion ),
	'VALORACION_NO_DIRECT_LAST_REVIEWED_WRITER'
);
nvx_block8_assert(
	0 === preg_match( '/\$graph\s*\[\s*\$index\s*\]\s*\[\s*[\'\"]reviewedBy[\'\"]\s*\]\s*=/', $valoracion ),
	'VALORACION_NO_DIRECT_REVIEWER_WRITER'
);
nvx_block8_assert(
	false !== strpos( $medical, "['lastReviewed']" ) && false !== strpos( $medical, "['reviewedBy']" ),
	'MEDICAL_REVIEW_CANONICAL_PROVENANCE_OWNER_PRESENT'
);

// Other Block 8 residual guards remain explicit until their dedicated owner
// migrations are completed.
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

$bootstrap = (string) file_get_contents( $theme_inc . 'nvx-theme-bootstrap.php' );
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

echo 'PHP_LOCAL_PEOPLE_CONTACT=PASS route_hijacks=exact manifest=covered provenance=single-owner consolidation_debt=guarded' . PHP_EOL;
