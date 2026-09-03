<?php
/**
 * Block 3 runtime/ownership regression: Data + SEO + Structured Data.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'NVX_HOOK_PRIO_JSONLD_STRIP', 5 );
define( 'WPMU_PLUGIN_DIR', '/tmp/nvx-mu-plugins' );
define( 'NVX_SD_ID_MEDICAL_PROCEDURE', '#medical-procedure' );
define( 'NVX_SD_ENDOLIFT_FACIAL', 'Endolift® facial' );
define( 'NVX_SD_ID_SERVICE', '#service' );

if ( ! class_exists( 'WP_Hook' ) ) {
	class WP_Hook {
		public array $callbacks = array();
	}
}

$GLOBALS['nvx_test_filters'] = array();
$GLOBALS['nvx_test_actions'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ): bool {
	$GLOBALS['nvx_test_filters'][] = array( (string) $hook, $callback, (int) $priority, (int) $accepted_args );
	return true;
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ): bool {
	$GLOBALS['nvx_test_actions'][] = array( (string) $hook, $callback, (int) $priority, (int) $accepted_args );
	return true;
}
function remove_action( ...$args ): bool { unset( $args ); return true; }
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_is_json_request(): bool { return false; }
function is_front_page(): bool { return true; }
function is_singular( $type = '' ): bool { return 'page' === $type; }
function get_template_directory(): string { return '/tmp/nuvanx-theme'; }
function get_stylesheet_directory(): string { return '/tmp/nuvanx-theme'; }
function nvx_format_price_eur( $amount, $decimals = 2 ): string {
	return number_format( (float) $amount, (int) $decimals, ',', '.' );
}
function nvx_endolift_price_from_eur(): float { return 798.60; }
function nvx_endolift_price_papada_eur(): float { return 1064.80; }
function nvx_co2_price_facial_eur(): float { return 330.00; }
function nvx_co2_price_body_eur(): float { return 450.00; }

function nvx_block3_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_DATA_SEO_SCHEMA_RUNTIME=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/wp-content/themes/nuvanx-medical/inc/nvx-jsonld-content.php';
require_once $repo_root . '/wp-content/themes/nuvanx-medical/inc/nvx-schema-treatments.php';

$content_registration = false;
foreach ( $GLOBALS['nvx_test_filters'] as $registration ) {
	if (
		'the_content' === $registration[0]
		&& 'nvx_filter_strip_embedded_jsonld' === $registration[1]
		&& NVX_HOOK_PRIO_JSONLD_STRIP === $registration[2]
	) {
		$content_registration = true;
	}

	// wp_head is an action and provides no mutable HTML string to filter.
	nvx_block3_assert( 'wp_head' !== $registration[0], 'NO_WP_HEAD_CONTENT_FILTER' );
}
nvx_block3_assert( $content_registration, 'CONTENT_JSONLD_FILTER_REGISTERED' );

$retirement_registration = false;
foreach ( $GLOBALS['nvx_test_actions'] as $registration ) {
	if (
		'wp_loaded' === $registration[0]
		&& 'nvx_jsonld_retire_legacy_standalone_schema_callbacks' === $registration[1]
		&& PHP_INT_MAX === $registration[2]
	) {
		$retirement_registration = true;
		break;
	}
}
nvx_block3_assert( $retirement_registration, 'LEGACY_SCHEMA_CALLBACK_RETIREMENT_REGISTERED' );

$schema_script = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"MedicalClinic"}</script>';
$app_script    = '<script type="application/ld+json">{"configuration":{"feature":"keep"}}</script>';
$cleaned       = nvx_strip_embedded_jsonld_html( '<p>A</p>' . $schema_script . $app_script . '<p>B</p>' );
nvx_block3_assert( false === strpos( $cleaned, 'MedicalClinic' ), 'EMBEDDED_SCHEMA_REMOVED' );
nvx_block3_assert( false !== strpos( $cleaned, '"feature":"keep"' ), 'NON_SCHEMA_JSONLD_PRESERVED' );

$legacy_source = (string) file_get_contents( $repo_root . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-legacy-retirement.php' );
$readiness     = (string) file_get_contents( $repo_root . '/wp-content/themes/nuvanx-medical/inc/nvx-seo-production-readiness.php' );

nvx_block3_assert(
	false === strpos( $legacy_source, "require_once __DIR__ . '/nvx-gracias-robots-governance.php'" ),
	'NO_LATERAL_GRACIAS_LOAD'
);
nvx_block3_assert(
	false !== strpos( $legacy_source, "remove_action( 'send_headers', 'nvx_seo_enforce_http_robots_header', 1 )" ),
	'DUPLICATE_DIRECT_X_ROBOTS_OWNER_RETIRED'
);
nvx_block3_assert(
	false !== strpos( $readiness, "\$headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive, nosnippet'" ),
	'CANONICAL_X_ROBOTS_OWNER_PRESENT'
);

$endolift = nvx_schema_treatment_node_laser(
	'endolift_facial',
	'https://example.test/endolift/',
	'https://example.test/#organization'
);
nvx_block3_assert( is_array( $endolift ), 'ENDOLIFT_SCHEMA_NODE_CREATED' );
$description = (string) ( $endolift['description'] ?? '' );
nvx_block3_assert( false !== strpos( $description, '798,60 €' ), 'ENDOLIFT_FROM_PRICE_FORMATTED_ES' );
nvx_block3_assert( false !== strpos( $description, '1.064,80 €' ), 'ENDOLIFT_PAPADA_PRICE_FORMATTED_ES' );
nvx_block3_assert( false === strpos( $description, '798.6 €' ), 'ENDOLIFT_RAW_FLOAT_NOT_EMITTED' );

echo 'PHP_DATA_SEO_SCHEMA_RUNTIME=PASS jsonld_hook=action_safe jsonld_ssot=1 xrobots_owner=single endolift_price=localized' . PHP_EOL;
