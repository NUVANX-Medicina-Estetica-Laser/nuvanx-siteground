<?php
/**
 * P0 regression: medical review provenance has one final, approval-gated owner.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'NVX_HOOK_PRIO_MEDICAL_REVIEW' ) ) {
	define( 'NVX_HOOK_PRIO_MEDICAL_REVIEW', 70 );
}

$GLOBALS['nvx_test_filters'] = array();
$GLOBALS['nvx_test_meta']    = array();
$GLOBALS['nvx_test_type']    = 'page';
$GLOBALS['nvx_test_status']  = 'publish';

function add_filter( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['nvx_test_filters'][] = compact( 'tag', 'callback', 'priority', 'accepted_args' );
	return true;
}
function home_url( string $path = '' ): string { return 'https://nuvanx.test' . $path; }
function nvx_medical_colegiado( string $role ): string { unset( $role ); return '12345'; }
function nvx_medical_staff_name( string $role ): string { unset( $role ); return 'Dr. Test'; }
function get_queried_object_id(): int { return 42; }
function get_post_type( int $post_id ): string { unset( $post_id ); return (string) $GLOBALS['nvx_test_type']; }
function get_post_status( int $post_id ): string { unset( $post_id ); return (string) $GLOBALS['nvx_test_status']; }
function get_post_meta( int $post_id, string $key, bool $single ) { unset( $post_id, $single ); return $GLOBALS['nvx_test_meta'][ $key ] ?? ''; }
function wp_date( string $format, int $timestamp ): string { unset( $format, $timestamp ); return '2 de septiembre de 2026'; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function esc_url( string $text ): string { return $text; }
function esc_attr( string $text ): string { return $text; }
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function is_feed(): bool { return false; }
function is_singular( string $type = '' ): bool { return '' === $type || 'page' === $type; }
function is_page(): bool { return true; }

function nvx_p0_provenance_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'MEDICAL_PROVENANCE_FINAL_OWNER=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-medical-review.php';

$schema_registration = null;
foreach ( $GLOBALS['nvx_test_filters'] as $filter ) {
	if ( 'wpseo_schema_graph' === $filter['tag'] && 'nvx_medical_review_schema_graph' === $filter['callback'] ) {
		$schema_registration = $filter;
		break;
	}
}
nvx_p0_provenance_assert( is_array( $schema_registration ), 'SCHEMA_FINALIZER_REGISTERED' );
nvx_p0_provenance_assert( PHP_INT_MAX - 1 === $schema_registration['priority'], 'SCHEMA_FINALIZER_RUNS_LAST' );

$base_graph = array(
	array(
		'@type'        => array( 'WebPage', 'MedicalWebPage' ),
		'@id'          => 'https://nuvanx.test/madrid/valoracion/#webpage',
		'reviewedBy'   => array( '@id' => 'https://bad.example/doctor' ),
		'lastReviewed' => '2026-08-01',
	),
	array(
		'@type'        => 'MedicalProcedure',
		'@id'          => 'https://nuvanx.test/procedure',
		'lastReviewed' => '2020-01-01',
	),
);

// No explicit approval: earlier hardcoded WebPage provenance must be removed.
$GLOBALS['nvx_test_meta'] = array();
$unapproved               = nvx_medical_review_schema_graph( $base_graph );
nvx_p0_provenance_assert( ! isset( $unapproved[0]['reviewedBy'] ), 'UNAPPROVED_REVIEWER_STRIPPED' );
nvx_p0_provenance_assert( ! isset( $unapproved[0]['lastReviewed'] ), 'UNAPPROVED_DATE_STRIPPED' );
nvx_p0_provenance_assert( '2020-01-01' === $unapproved[1]['lastReviewed'], 'NON_PAGE_NODE_UNTOUCHED' );

// Valid explicit approval: both fields must come from the same canonical record.
$GLOBALS['nvx_test_meta'] = array(
	'_nvx_medical_review_status' => 'approved',
	'_nvx_medical_reviewer'      => 'rivera',
	'_nvx_medical_review_date'   => '2026-09-02',
);
$approved = nvx_medical_review_schema_graph( $base_graph );
nvx_p0_provenance_assert( 'https://nuvanx.test/equipo-medico/#physician-rivera-tejeda' === ( $approved[0]['reviewedBy']['@id'] ?? '' ), 'APPROVED_REVIEWER_CANONICAL' );
nvx_p0_provenance_assert( '2026-09-02' === ( $approved[0]['lastReviewed'] ?? '' ), 'APPROVED_DATE_CANONICAL' );

// Invalid review date must fail closed and remove stale earlier provenance.
$GLOBALS['nvx_test_meta']['_nvx_medical_review_date'] = '2026-02-31';
$invalid_date = nvx_medical_review_schema_graph( $base_graph );
nvx_p0_provenance_assert( ! isset( $invalid_date[0]['reviewedBy'], $invalid_date[0]['lastReviewed'] ), 'INVALID_DATE_FAILS_CLOSED' );

// Non-page content cannot claim the page review provenance contract.
$GLOBALS['nvx_test_meta']['_nvx_medical_review_date'] = '2026-09-02';
$GLOBALS['nvx_test_type'] = 'post';
$non_page = nvx_medical_review_schema_graph( $base_graph );
nvx_p0_provenance_assert( ! isset( $non_page[0]['reviewedBy'], $non_page[0]['lastReviewed'] ), 'NON_PAGE_FAILS_CLOSED' );

// MedicalWebPage subtype is still governed even if WebPage is not explicitly listed.
$GLOBALS['nvx_test_type'] = 'page';
$medical_page = nvx_medical_review_schema_graph(
	array(
		array(
			'@type'        => 'MedicalWebPage',
			'reviewedBy'   => array( '@id' => 'https://bad.example' ),
			'lastReviewed' => '2026-01-01',
		),
	)
);
nvx_p0_provenance_assert( '2026-09-02' === ( $medical_page[0]['lastReviewed'] ?? '' ), 'MEDICAL_WEBPAGE_GOVERNED' );

echo 'MEDICAL_PROVENANCE_FINAL_OWNER=PASS owner=medical-review approval=post-meta fail_closed=1 visible_schema_parity=1' . PHP_EOL;
