<?php
/**
 * Block 7 regression: treatment catalog + laser / BTL landing PHP.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

function nvx_block7_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_TREATMENT_LANDINGS_CATALOG=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
$profhilo = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-profhilo-page.php' );
$medical  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-medical-review.php' );

// Profhilo is not a canonical published treatment route. Its renderer therefore
// must never author review provenance from local post-meta state. The canonical
// medical-review module remains the only validator/renderer on governed pages.
nvx_block7_assert( false === strpos( $profhilo, 'nvx-profhilo-reviewed' ), 'NO_PROFHILO_SECONDARY_REVIEW_BLOCK' );
nvx_block7_assert( false === strpos( $profhilo, "\$data['byline_author']" ), 'NO_PROFHILO_EDITORIAL_BYLINE_OWNER' );
nvx_block7_assert( false === strpos( $profhilo, "\$data['byline_title']" ), 'NO_PROFHILO_EDITORIAL_REVIEW_DATE_OWNER' );
nvx_block7_assert( false === strpos( $profhilo, "'_nvx_medical_review_status'" ), 'NO_PROFHILO_LOCAL_REVIEW_STATUS_AUTHORITY' );
nvx_block7_assert( false === strpos( $profhilo, 'nvx-medical-byline' ), 'NO_PROFHILO_LOCAL_REVIEW_RENDERER' );
nvx_block7_assert( false !== strpos( $medical, 'data-nvx-medical-review="approved"' ), 'CANONICAL_APPROVAL_GATED_REVIEW_OWNER_PRESENT' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
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
if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool { return false; }
}
if ( ! function_exists( 'is_home' ) ) {
	function is_home(): bool { return false; }
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int { return 1; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string { return 'https://nuvanx.test' . $path; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { return $text; }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $text ): string { return $text; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string { return $text; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
}
if ( ! function_exists( 'nvx_medical_colegiado' ) ) {
	function nvx_medical_colegiado( string $key = '' ): string { unset( $key ); return '282873964'; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): bool { unset( $args ); return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): bool { unset( $args ); return true; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		unset( $post_id, $single );
		return $GLOBALS['nvx_test_post_meta'][ $key ] ?? '';
	}
}

require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-constants.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-catalog-json.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-page-render-helpers.php';
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-profhilo-page.php';

// The renderer must never emit review provenance, whether review metadata is
// absent or contains only the legacy status flag.
$GLOBALS['nvx_test_post_meta'] = array();
$rendered_hero = nvx_profhilo_hero_copy_markup();
nvx_block7_assert( false === strpos( $rendered_hero, 'nvx-profhilo-reviewed' ), 'RENDERED_HERO_NO_SECONDARY_REVIEW_BLOCK' );
nvx_block7_assert( false === strpos( $rendered_hero, 'nvx-medical-byline' ), 'RENDERED_HERO_NO_LOCAL_REVIEW_BYLINE' );

$rendered_body = nvx_profhilo_editorial_body_markup();
nvx_block7_assert( false === strpos( $rendered_body, 'nvx-profhilo-reviewed' ), 'RENDERED_BODY_NO_SECONDARY_REVIEW_BLOCK' );
nvx_block7_assert( false === strpos( $rendered_body, 'nvx-medical-byline' ), 'RENDERED_BODY_NO_LOCAL_REVIEW_BYLINE' );

$GLOBALS['nvx_test_post_meta'] = array( '_nvx_medical_review_status' => 'approved' );
$approved_status_only_hero = nvx_profhilo_hero_copy_markup();
nvx_block7_assert( false === strpos( $approved_status_only_hero, 'nvx-medical-byline' ), 'APPROVED_STATUS_ALONE_DOES_NOT_AUTHOR_PROFHILO_BYLINE' );
nvx_block7_assert( false === strpos( $approved_status_only_hero, 'data-nvx-medical-review="approved"' ), 'PROFHILO_RENDERER_NEVER_OWNS_REVIEW_PROVENANCE' );

// Inventory landing/editorial consumers and ensure the canonical helper owner
// precedes them in bootstrap. All ordinary consumers must not load the helper
// laterally. Endolaser remains an explicit protected residual: any emitter byte
// change requires a complete bound clinical approval record and is not bypassed
// merely to satisfy this ownership cleanup.
$bootstrap = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-theme-bootstrap.php' );
$helpers   = strpos( $bootstrap, "'inc/nvx-page-render-helpers.php'" );
$landings  = array(
	'nvx-bridal-page.php',
	'nvx-solutions-page.php',
	'nvx-endolift-page.php',
	'nvx-exion-page.php',
	'nvx-profhilo-page.php',
	'nvx-endolaser-page.php',
	'nvx-co2-page.php',
	'nvx-btl-detail-pages.php',
	'nvx-aesthetic-medicine-page.php',
);
nvx_block7_assert( false !== $helpers, 'PAGE_HELPER_OWNER_PRESENT' );
foreach ( $landings as $file ) {
	$offset  = strpos( $bootstrap, "'inc/{$file}'" );
	$landing = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/' . $file );
	nvx_block7_assert( false !== $offset && $helpers < $offset, 'PAGE_HELPERS_PRECEDE_' . $file );
	if ( 'nvx-endolaser-page.php' !== $file ) {
		nvx_block7_assert( false === strpos( $landing, "require_once __DIR__ . '/nvx-page-render-helpers.php'" ), 'NO_DIRECT_PAGE_HELPER_REQUIRE_' . $file );
	}
}

$endolaser = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-endolaser-page.php' );
nvx_block7_assert(
	false !== strpos( $endolaser, "require_once __DIR__ . '/nvx-page-render-helpers.php'" ),
	'ENDOLASER_PAGE_HELPER_RESIDUAL_REMAINS_APPROVAL_GATED'
);

// The current tariff contract is already identity-safe: canonical lips hydration
// exists, the retired composite key does not, and Bioestimulation is card n=05.
$tariffs = json_decode(
	(string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/tariff-catalog.json' ),
	true
);
nvx_block7_assert( is_array( $tariffs ), 'TARIFF_JSON_VALID' );
nvx_block7_assert( isset( $tariffs['labios_ha']['hidratacion'] ), 'LIPS_HYDRATION_CANONICAL_KEY_PRESENT' );
nvx_block7_assert( ! isset( $tariffs['labios_ha']['perfilado_hidratacion'] ), 'LEGACY_LIPS_COMPOSITE_KEY_ABSENT' );

$aesthetic_catalog = json_decode(
	(string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/aesthetic-medicine-page.json' ),
	true
);
nvx_block7_assert( is_array( $aesthetic_catalog ) && isset( $aesthetic_catalog['treatments'][4] ), 'AESTHETIC_TREATMENT_INDEX_4_EXISTS' );
nvx_block7_assert( false !== strpos( (string) $aesthetic_catalog['treatments'][4]['title'], 'Bioestimulación' ), 'BIOSTIMULATION_IS_INDEX_4' );

echo 'PHP_TREATMENT_LANDINGS_CATALOG=PASS profhilo_review=renderer_has_no_owner landing_inventory=expanded endolaser_helper_residual=approval_gated tariff_contract=identity_safe' . PHP_EOL;