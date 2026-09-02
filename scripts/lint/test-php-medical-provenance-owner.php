<?php
/**
 * Root-cause regression for medical review provenance ownership.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'NVX_HOOK_PRIO_MEDICAL_REVIEW' ) ) {
	define( 'NVX_HOOK_PRIO_MEDICAL_REVIEW', 60 );
}

$approved_managed_pages = array(
	'/madrid/valoracion/' => array(
		'status'   => 'approved',
		'reviewer' => 'rivera',
		'date'     => '2026-08-01',
	),
	'/papada-definicion-mandibular-madrid/' => array(
		'status'   => 'approved',
		'reviewer' => 'rivera',
		'date'     => '2026-08-01',
	),
);

$GLOBALS['nvx_test_path']      = '/madrid/valoracion/';
$GLOBALS['nvx_test_treatment'] = false;
$GLOBALS['nvx_test_post_meta'] = array();
$GLOBALS['nvx_test_approvals'] = array( 'managed_pages' => $approved_managed_pages );

function add_filter( ...$args ): bool { unset( $args ); return true; }
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function is_feed(): bool { return false; }
function is_singular( string $type = '' ): bool { return 'page' === $type; }
function is_page(): bool { return true; }
function get_queried_object_id(): int { return 42; }
function get_permalink( int $post_id = 0 ): string { unset( $post_id ); return 'https://nuvanx.test' . $GLOBALS['nvx_test_path']; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function home_url( string $path = '' ): string { return 'https://nuvanx.test' . $path; }
function nvx_theme_request_path(): string { return $GLOBALS['nvx_test_path']; }
function nvx_schema_resolve_treatment_key( int $post_id ) { unset( $post_id ); return $GLOBALS['nvx_test_treatment'] ? 'double_chin' : null; }
function get_post_meta( int $post_id, string $key, bool $single = false ) { unset( $post_id, $single ); return $GLOBALS['nvx_test_post_meta'][ $key ] ?? ''; }
function nvx_catalog_json_load( string $filename ): array { return 'medical-review-approvals.json' === $filename ? array_merge( array( '_error' => false ), $GLOBALS['nvx_test_approvals'] ) : array( '_error' => 'unexpected_catalog' ); }
function nvx_medical_colegiado( string $key ): string { unset( $key ); return '282873964'; }
function nvx_medical_staff_name( string $key ): string { unset( $key ); return 'Dr. José Javier Rivera Tejeda'; }
function wp_date( string $format, int $timestamp ): string { unset( $format, $timestamp ); return '1 de agosto de 2026'; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( string $text ): string { return $text; }
function esc_attr( string $text ): string { return $text; }
function esc_url( string $text ): string { return $text; }

function nvx_provenance_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'PHP_MEDICAL_PROVENANCE_OWNER=FAIL invariant=' . $name . PHP_EOL );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/wp-content/themes/nuvanx-medical/inc/nvx-medical-review.php';

// Exact canonical registry keys are required; near-equivalent malformed keys
// must never be normalized into approval authority.
$GLOBALS['nvx_test_approvals'] = array(
	'managed_pages' => array(
		'/madrid/valoracion' => $approved_managed_pages['/madrid/valoracion/'],
	),
);
nvx_provenance_assert( array() === nvx_medical_review_managed_registry(), 'REGISTRY_REJECTS_MISSING_TRAILING_SLASH' );
$GLOBALS['nvx_test_approvals'] = array(
	'managed_pages' => array(
		'//madrid/valoracion//' => $approved_managed_pages['/madrid/valoracion/'],
	),
);
nvx_provenance_assert( array() === nvx_medical_review_managed_registry(), 'REGISTRY_REJECTS_DUPLICATE_SLASH_FORM' );
$GLOBALS['nvx_test_approvals'] = array( 'managed_pages' => $approved_managed_pages );

$managed = nvx_medical_review_record();
nvx_provenance_assert( is_array( $managed ), 'MANAGED_APPROVAL_RESOLVES' );
nvx_provenance_assert( 'managed_registry' === ( $managed['source'] ?? '' ), 'MANAGED_APPROVAL_SOURCE' );
nvx_provenance_assert( '2026-08-01' === ( $managed['date'] ?? '' ), 'MANAGED_APPROVAL_DATE' );
nvx_provenance_assert( 'Dr. José Javier Rivera Tejeda' === ( $managed['name'] ?? '' ), 'MANAGED_APPROVAL_REVIEWER' );
nvx_provenance_assert( nvx_medical_review_governed_page(), 'MANAGED_PAGE_IS_GOVERNED' );

$rogue_graph = array(
	array(
		'@type'        => array( 'WebPage', 'MedicalWebPage' ),
		'@id'          => 'https://nuvanx.test/madrid/valoracion/#webpage',
		'reviewedBy'   => array( '@id' => 'https://rogue.example/#doctor' ),
		'lastReviewed' => '1999-01-01',
	),
);
$governed = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert(
	'https://nuvanx.test/equipo-medico/#physician-rivera-tejeda' === ( $governed[0]['reviewedBy']['@id'] ?? '' ),
	'ROGUE_REVIEWER_REPLACED_BY_CANONICAL_OWNER'
);
nvx_provenance_assert( '2026-08-01' === ( $governed[0]['lastReviewed'] ?? '' ), 'ROGUE_DATE_REPLACED_BY_APPROVED_DATE' );

$legacy_visible = '<h1>Valoración médica</h1><p class="nvx-medical-review">Revisión médica: Dr. Rivera · ICOMEM · agosto 2026</p><p>Contenido clínico.</p>';
$approved_visible = nvx_medical_review_enforce_visible_provenance( $legacy_visible );
nvx_provenance_assert( false === strpos( $approved_visible, 'class="nvx-medical-review"' ), 'APPROVED_LEGACY_PARAGRAPH_REMOVED' );
nvx_provenance_assert( 1 === substr_count( $approved_visible, 'data-nvx-medical-review="approved"' ), 'APPROVED_EXACTLY_ONE_CANONICAL_ATTRIBUTION' );

// A registered managed page remains inside the governance perimeter even when
// its approval becomes pending: schema and visible legacy provenance are both removed.
$GLOBALS['nvx_test_approvals']['managed_pages']['/madrid/valoracion/']['status'] = 'pending';
$pending = nvx_medical_review_record();
nvx_provenance_assert( null === $pending, 'REGISTERED_PENDING_PAGE_FAILS_CLOSED' );
nvx_provenance_assert( nvx_medical_review_governed_page(), 'REGISTERED_PENDING_PAGE_STAYS_GOVERNED' );
$clean = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( ! isset( $clean[0]['reviewedBy'] ), 'PENDING_ROGUE_REVIEWER_REMOVED' );
nvx_provenance_assert( ! isset( $clean[0]['lastReviewed'] ), 'PENDING_ROGUE_DATE_REMOVED' );
$pending_visible = nvx_medical_review_enforce_visible_provenance( $legacy_visible );
nvx_provenance_assert( false === strpos( $pending_visible, 'class="nvx-medical-review"' ), 'PENDING_LEGACY_PARAGRAPH_REMOVED' );
nvx_provenance_assert( false === strpos( $pending_visible, 'data-nvx-medical-review="approved"' ), 'PENDING_NO_CANONICAL_ATTRIBUTION' );

// Restore approved registry for precedence checks.
$GLOBALS['nvx_test_approvals'] = array( 'managed_pages' => $approved_managed_pages );

// Papada is also classified as a treatment route. Explicit managed registration
// must win even when generic treatment resolution is enabled and post meta is absent.
$GLOBALS['nvx_test_path']      = '/papada-definicion-mandibular-madrid/';
$GLOBALS['nvx_test_treatment'] = true;
$GLOBALS['nvx_test_post_meta'] = array();
$papada = nvx_medical_review_record( 42 );
nvx_provenance_assert( is_array( $papada ), 'PAPADA_MANAGED_APPROVAL_RESOLVES_WITH_TREATMENT_CLASSIFICATION' );
nvx_provenance_assert( 'managed_registry' === ( $papada['source'] ?? '' ), 'PAPADA_MANAGED_APPROVAL_PRECEDENCE' );
nvx_provenance_assert( '2026-08-01' === ( $papada['date'] ?? '' ), 'PAPADA_MANAGED_APPROVAL_DATE' );
$papada_visible = nvx_medical_review_enforce_visible_provenance( $legacy_visible );
nvx_provenance_assert( 1 === substr_count( $papada_visible, 'data-nvx-medical-review="approved"' ), 'PAPADA_VISIBLE_ATTRIBUTION_FROM_MANAGED_RECORD' );
$papada_graph = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( '2026-08-01' === ( $papada_graph[0]['lastReviewed'] ?? '' ), 'PAPADA_SCHEMA_FROM_MANAGED_RECORD' );

// An unrelated page outside this owner's perimeter must remain untouched.
$GLOBALS['nvx_test_path']      = '/unrelated-page/';
$GLOBALS['nvx_test_treatment'] = false;
$unrelated = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( 'https://rogue.example/#doctor' === ( $unrelated[0]['reviewedBy']['@id'] ?? '' ), 'UNRELATED_PAGE_GRAPH_LEFT_UNTOUCHED' );
nvx_provenance_assert( '1999-01-01' === ( $unrelated[0]['lastReviewed'] ?? '' ), 'UNRELATED_PAGE_DATE_LEFT_UNTOUCHED' );

// Generic treatment routes without an explicit managed registration preserve the
// existing post-meta approval contract.
$GLOBALS['nvx_test_treatment'] = true;
$GLOBALS['nvx_test_post_meta'] = array(
	'_nvx_medical_review_status' => 'approved',
	'_nvx_medical_reviewer'      => 'rivera',
	'_nvx_medical_review_date'   => '2026-08-15',
);
$treatment = nvx_medical_review_record( 42 );
nvx_provenance_assert( is_array( $treatment ), 'TREATMENT_POST_META_STILL_RESOLVES' );
nvx_provenance_assert( 'post_meta' === ( $treatment['source'] ?? '' ), 'TREATMENT_POST_META_SOURCE' );
nvx_provenance_assert( '2026-08-15' === ( $treatment['date'] ?? '' ), 'TREATMENT_POST_META_DATE' );

$GLOBALS['nvx_test_post_meta']['_nvx_medical_review_status'] = 'pending';
nvx_provenance_assert( null === nvx_medical_review_record( 42 ), 'TREATMENT_PENDING_FAILS_CLOSED' );

$registry_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/medical-review-approvals.json' );
nvx_provenance_assert( false !== strpos( $registry_source, '"version": 1' ), 'MANAGED_APPROVAL_REGISTRY_VERSIONED' );
nvx_provenance_assert( false !== strpos( $registry_source, '"/madrid/valoracion/"' ), 'VALORACION_APPROVAL_VERSIONED' );
nvx_provenance_assert( false !== strpos( $registry_source, '"/papada-definicion-mandibular-madrid/"' ), 'PAPADA_APPROVAL_VERSIONED' );

$medical_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-medical-review.php' );
nvx_provenance_assert( false !== strpos( $medical_source, 'nvx_medical_review_registry_path_is_canonical' ), 'REGISTRY_EXACT_PATH_GUARD_PRESENT' );
nvx_provenance_assert( false !== strpos( $medical_source, "unset( \$graph[ \$index ]['reviewedBy'], \$graph[ \$index ]['lastReviewed'] )" ), 'CANONICAL_OWNER_SANITIZES_EARLIER_PROVENANCE' );
nvx_provenance_assert( false !== strpos( $medical_source, "\$graph[ \$index ]['lastReviewed'] = \$record['date']" ), 'CANONICAL_OWNER_EMITS_APPROVED_DATE' );

echo 'PHP_MEDICAL_PROVENANCE_OWNER=PASS managed_registry=exact precedence=managed legacy_byline=removed treatment_meta=preserved rogue_provenance=fail_closed scope=governed_only' . PHP_EOL;
