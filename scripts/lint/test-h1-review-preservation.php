<?php
/** Behavioral contract for H1 medical-review preservation and durable planning. */

declare(strict_types=1);

define( 'OBJECT', 'OBJECT' );

$GLOBALS['nvx_h1_test_durable'] = array();
$GLOBALS['nvx_h1_test_cached']  = array();

class WP_Post {
	public int $ID = 42;
	public string $post_content = '<div class="nvx-aesthetic-treatment-source" data-nvx-treatment="fixture"></div>';
	public string $post_status = 'publish';
	public string $post_type = 'page';
	public string $post_name = 'fixture';
}

final class NvxH1PlanningWpdb {
	public string $postmeta = 'wp_postmeta';

	public function prepare( string $query, ...$args ): array {
		return array( 'query' => $query, 'args' => $args );
	}

	public function get_col( array $prepared ): array {
		$post_id  = (int) ( $prepared['args'][0] ?? 0 );
		$meta_key = (string) ( $prepared['args'][1] ?? '' );
		$values   = $GLOBALS['nvx_h1_test_durable'][ $post_id ][ $meta_key ] ?? array();
		return is_array( $values ) ? $values : array( (string) $values );
	}
}

$GLOBALS['wpdb'] = new NvxH1PlanningWpdb();

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
}
function sanitize_title( $value ): string { return sanitize_key( $value ); }
function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
function sanitize_textarea_field( $value ): string { return (string) $value; }
function maybe_serialize( $value ) { return $value; }
function maybe_unserialize( $value ) { return $value; }
function get_post_meta( $post_id, $meta_key, $single = false ) {
	return (string) ( $GLOBALS['nvx_h1_test_cached'][ (int) $post_id ][ (string) $meta_key ] ?? '' );
}
function get_page_by_path( $slug, $output = null, $post_type = 'page' ) {
	if ( 'fixture' === $slug && 'page' === $post_type ) {
		return new WP_Post();
	}
	return null;
}
function nvx_strategy_page_catalog(): array { return array(); }
function nvx_aesthetic_treatment_catalog(): array {
	return array(
		'fixture' => array(
			'slug'        => 'fixture',
			'h1'          => 'Fixture',
			'description' => 'Fixture',
		),
	);
}
function nvx_journal_tech_article_map(): array { return array(); }
function nvx_journal_tech_article_catalog( string $slug ): array { return array(); }
function nvx_medical_reviewers(): array {
	return array(
		'rivera' => array(
			'name'    => 'Doctor',
			'license' => '1',
			'url'     => 'https://example.test/doctor',
			'id'      => 'doctor',
			'title'   => 'Director',
		),
	);
}
function nvx_medical_review_valid_date( string $date ): bool { return '2026-08-01' === $date; }
function nvx_medical_review_reviewer_complete( array $reviewer ): bool {
	foreach ( array( 'name', 'license', 'url', 'id', 'title' ) as $field ) {
		if ( '' === trim( (string) ( $reviewer[ $field ] ?? '' ) ) ) {
			return false;
		}
	}
	return true;
}
function nvx_medical_review_record( int $post_id = 0 ): ?array {
	$status   = (string) get_post_meta( $post_id, '_nvx_medical_review_status', true );
	$reviewer = (string) get_post_meta( $post_id, '_nvx_medical_reviewer', true );
	$date     = (string) get_post_meta( $post_id, '_nvx_medical_review_date', true );
	return 'approved' === $status && 'rivera' === $reviewer && '2026-08-01' === $date ? array( 'source' => 'cached-test' ) : null;
}

require_once dirname( __DIR__, 2 ) . '/tools/migrations/h1-content-seed-reconciliation.php';

$cases = array(
	true  => 'approved',
	false => 'pending',
);
foreach ( $cases as $approved => $expected ) {
	$actual = nvx_h1_target_review_status( (bool) $approved );
	if ( $expected !== $actual ) {
		fwrite( STDERR, "H1_REVIEW_PRESERVATION=FAIL reason=target_status\n" );
		exit( 1 );
	}
}

$post_id = 42;
$GLOBALS['nvx_h1_test_cached'][ $post_id ] = array(
	'_nvx_aesthetic_treatment_key' => 'fixture',
	'_nvx_medical_review_status'    => 'approved',
	'_nvx_medical_reviewer'         => 'rivera',
	'_nvx_medical_review_date'      => '2026-08-01',
);
$GLOBALS['nvx_h1_test_durable'][ $post_id ] = array(
	'_nvx_aesthetic_treatment_key' => array( 'drifted-key' ),
	'_nvx_medical_review_status'    => array( 'approved' ),
	'_nvx_medical_reviewer'         => array( 'rivera' ),
	'_nvx_medical_review_date'      => array( '2026-08-01' ),
);

$plan = nvx_h1_build_plan();
$repairs = array_values(
	array_filter(
		$plan['ops'] ?? array(),
		static fn ( array $op ): bool => 'aesthetic' === ( $op['scope'] ?? '' ) && 'repair_seed_meta' === ( $op['action'] ?? '' )
	)
);
if ( 1 !== count( $repairs ) || 'fixture' !== ( $repairs[0]['payload']['key'] ?? '' ) ) {
	fwrite( STDERR, "H1_REVIEW_PRESERVATION=FAIL reason=stale_cache_authorized_noop\n" );
	exit( 1 );
}

// Canonically valid durable provenance remains valid after bounded normalization.
$GLOBALS['nvx_h1_test_durable'][ $post_id ]['_nvx_medical_review_status'] = array( ' APPROVED ' );
$GLOBALS['nvx_h1_test_durable'][ $post_id ]['_nvx_medical_reviewer']      = array( ' RIVERA ' );
$GLOBALS['nvx_h1_test_durable'][ $post_id ]['_nvx_medical_review_date']   = array( ' 2026-08-01 ' );
$normalized_review = nvx_h1_durable_aesthetic_review_state( $post_id );
if (
	! (bool) $normalized_review['approved']
	|| 'approved' !== $normalized_review['status']
	|| 'rivera' !== $normalized_review['reviewer']
	|| '2026-08-01' !== $normalized_review['date']
) {
	fwrite( STDERR, "H1_REVIEW_PRESERVATION=FAIL reason=durable_approval_normalization\n" );
	exit( 1 );
}

// Conflicting durable duplicates are not a valid source of truth and must fail closed.
$GLOBALS['nvx_h1_test_durable'][ $post_id ]['_nvx_aesthetic_treatment_key'] = array( 'fixture', 'conflict' );
$conflict_detected = false;
try {
	nvx_h1_build_plan();
} catch ( RuntimeException $error ) {
	$conflict_detected = str_starts_with( $error->getMessage(), 'post_meta_durable_conflict_' );
}
if ( ! $conflict_detected ) {
	fwrite( STDERR, "H1_REVIEW_PRESERVATION=FAIL reason=durable_duplicate_conflict_not_blocked\n" );
	exit( 1 );
}

echo "H1_REVIEW_PRESERVATION=PASS approved=preserved unapproved=pending preplan_authority=durable stale_cache_bypass=blocked approval_normalization=bounded duplicate_conflict=fail_closed\n";
