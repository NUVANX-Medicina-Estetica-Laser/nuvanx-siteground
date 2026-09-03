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
	define( 'NVX_HOOK_PRIO_MEDICAL_REVIEW', 147 );
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
$GLOBALS['nvx_test_approvals'] = array( 'version' => 1, 'managed_pages' => $approved_managed_pages );

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

$rogue_graph = array(
	array(
		'@type'        => array( 'WebPage', 'MedicalWebPage' ),
		'@id'          => 'https://nuvanx.test/madrid/valoracion/#webpage',
		'reviewedBy'   => array( '@id' => 'https://rogue.example/#doctor' ),
		'lastReviewed' => '1999-01-01',
	),
);
$legacy_visible = '<h1>Valoración médica</h1><p class="nvx-medical-review">Revisión médica: Dr. Rivera · ICOMEM · agosto 2026</p><p>Contenido clínico.</p>';

$GLOBALS['nvx_test_approvals'] = array( 'managed_pages' => $approved_managed_pages );
nvx_provenance_assert( array() === nvx_medical_review_managed_registry(), 'REGISTRY_REJECTS_MISSING_VERSION' );
nvx_provenance_assert( nvx_medical_review_governed_page(), 'MISSING_VERSION_PAGE_STAYS_GOVERNED' );
nvx_provenance_assert( null === nvx_medical_review_record(), 'MISSING_VERSION_HAS_NO_APPROVAL' );
$missing_version_graph = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( ! isset( $missing_version_graph[0]['reviewedBy'], $missing_version_graph[0]['lastReviewed'] ), 'MISSING_VERSION_SCHEMA_FAILS_CLOSED' );
$missing_version_visible = nvx_medical_review_enforce_visible_provenance( $legacy_visible );
nvx_provenance_assert( false === strpos( $missing_version_visible, 'nvx-medical-review' ), 'MISSING_VERSION_VISIBLE_FAILS_CLOSED' );

$GLOBALS['nvx_test_approvals'] = array( 'version' => 2, 'managed_pages' => $approved_managed_pages );
nvx_provenance_assert( array() === nvx_medical_review_managed_registry(), 'REGISTRY_REJECTS_UNSUPPORTED_VERSION' );
nvx_provenance_assert( nvx_medical_review_governed_page(), 'UNSUPPORTED_VERSION_PAGE_STAYS_GOVERNED' );
nvx_provenance_assert( null === nvx_medical_review_record(), 'UNSUPPORTED_VERSION_HAS_NO_APPROVAL' );

$GLOBALS['nvx_test_approvals'] = array(
	'version'       => 1,
	'managed_pages' => array(
		'/madrid/valoracion' => $approved_managed_pages['/madrid/valoracion/'],
	),
);
nvx_provenance_assert( array() === nvx_medical_review_managed_registry(), 'REGISTRY_REJECTS_MISSING_TRAILING_SLASH' );
nvx_provenance_assert( nvx_medical_review_governed_page(), 'MALFORMED_KEY_PAGE_STAYS_GOVERNED' );
$GLOBALS['nvx_test_approvals'] = array(
	'version'       => 1,
	'managed_pages' => array(
		'//madrid/valoracion//' => $approved_managed_pages['/madrid/valoracion/'],
	),
);
nvx_provenance_assert( array() === nvx_medical_review_managed_registry(), 'REGISTRY_REJECTS_DUPLICATE_SLASH_FORM' );
$GLOBALS['nvx_test_approvals'] = array( 'version' => 1, 'managed_pages' => $approved_managed_pages );

$managed = nvx_medical_review_record();
nvx_provenance_assert( is_array( $managed ), 'MANAGED_APPROVAL_RESOLVES' );
nvx_provenance_assert( 'managed_registry' === ( $managed['source'] ?? '' ), 'MANAGED_APPROVAL_SOURCE' );
nvx_provenance_assert( '2026-08-01' === ( $managed['date'] ?? '' ), 'MANAGED_APPROVAL_DATE' );
nvx_provenance_assert( 'Dr. José Javier Rivera Tejeda' === ( $managed['name'] ?? '' ), 'MANAGED_APPROVAL_REVIEWER' );
nvx_provenance_assert( nvx_medical_review_governed_page(), 'MANAGED_PAGE_IS_GOVERNED' );

$governed = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( 'https://nuvanx.test/equipo-medico/#physician-rivera-tejeda' === ( $governed[0]['reviewedBy']['@id'] ?? '' ), 'ROGUE_REVIEWER_REPLACED_BY_CANONICAL_OWNER' );
nvx_provenance_assert( '2026-08-01' === ( $governed[0]['lastReviewed'] ?? '' ), 'ROGUE_DATE_REPLACED_BY_APPROVED_DATE' );

$approved_visible = nvx_medical_review_enforce_visible_provenance( $legacy_visible );
nvx_provenance_assert( false === strpos( $approved_visible, 'class="nvx-medical-review"' ), 'APPROVED_LEGACY_PARAGRAPH_REMOVED' );
nvx_provenance_assert( 1 === substr_count( $approved_visible, 'data-nvx-medical-review="approved"' ), 'APPROVED_EXACTLY_ONE_CANONICAL_ATTRIBUTION' );

$flat_div = '<h1>Valoración médica</h1><div class="nvx-medical-byline">Flat legacy review</div><p>Contenido.</p>';
$flat_clean = nvx_medical_review_enforce_visible_provenance( $flat_div );
nvx_provenance_assert( false === strpos( $flat_clean, 'Flat legacy review' ), 'FLAT_LEGACY_DIV_REMOVED' );
nvx_provenance_assert( 1 === substr_count( $flat_clean, 'data-nvx-medical-review="approved"' ), 'FLAT_DIV_REPLACED_ONCE' );

$nested_div = '<h1>Valoración médica</h1><div class="nvx-medical-byline"><div class="nvx-medical-byline__text">Nested legacy review</div></div><p>Contenido.</p>';
$nested_clean = nvx_medical_review_enforce_visible_provenance( $nested_div );
nvx_provenance_assert( false === strpos( $nested_clean, 'Nested legacy review' ), 'NESTED_LEGACY_DIV_REMOVED' );
nvx_provenance_assert( 1 === substr_count( $nested_clean, 'data-nvx-medical-review="approved"' ), 'NESTED_DIV_REPLACED_ONCE' );

$deep_nested = '<h1>Valoración médica</h1><div class="nvx-medical-byline"><div class="level-1"><div class="level-2"><div class="level-3">Deep legacy review</div><div>Secondary legacy block</div></div></div></div><section data-adjacent="before">Antes intacto</section><p>Contenido clínico.</p><section data-adjacent="after">Después intacto</section>';
$deep_clean = nvx_medical_review_enforce_visible_provenance( $deep_nested );
nvx_provenance_assert( false === strpos( $deep_clean, 'Deep legacy review' ), 'DEEP_NESTED_LEGACY_CONTENT_REMOVED' );
nvx_provenance_assert( false === strpos( $deep_clean, 'Secondary legacy block' ), 'DEEP_NESTED_SECONDARY_BLOCK_REMOVED' );
nvx_provenance_assert( false !== strpos( $deep_clean, '<section data-adjacent="before">Antes intacto</section><p>Contenido clínico.</p><section data-adjacent="after">Después intacto</section>' ), 'DEEP_NESTED_ADJACENT_CONTENT_PRESERVED' );
nvx_provenance_assert( 1 === substr_count( $deep_clean, 'data-nvx-medical-review="approved"' ), 'DEEP_NESTED_REPLACED_ONCE' );

$GLOBALS['nvx_test_approvals']['managed_pages']['/madrid/valoracion/']['status'] = 'pending';
nvx_provenance_assert( null === nvx_medical_review_record(), 'REGISTERED_PENDING_PAGE_FAILS_CLOSED' );
nvx_provenance_assert( nvx_medical_review_governed_page(), 'REGISTERED_PENDING_PAGE_STAYS_GOVERNED' );
$clean = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( ! isset( $clean[0]['reviewedBy'] ), 'PENDING_ROGUE_REVIEWER_REMOVED' );
nvx_provenance_assert( ! isset( $clean[0]['lastReviewed'] ), 'PENDING_ROGUE_DATE_REMOVED' );
$pending_visible = nvx_medical_review_enforce_visible_provenance( $legacy_visible );
nvx_provenance_assert( false === strpos( $pending_visible, 'class="nvx-medical-review"' ), 'PENDING_LEGACY_PARAGRAPH_REMOVED' );
nvx_provenance_assert( false === strpos( $pending_visible, 'data-nvx-medical-review="approved"' ), 'PENDING_NO_CANONICAL_ATTRIBUTION' );

$malformed_target = '<h1>Valoración médica</h1><div class="shell"><p class="nvx-medical-review">Rogue malformed attribution</div><section data-adjacent="malformed">Contenido adyacente intacto</section>';
$malformed_clean = nvx_medical_review_enforce_visible_provenance( $malformed_target );
nvx_provenance_assert( false === strpos( $malformed_clean, 'Rogue malformed attribution' ), 'MALFORMED_ORPHAN_TARGET_REMOVED' );
nvx_provenance_assert( false !== strpos( $malformed_clean, '<div class="shell"></div><section data-adjacent="malformed">Contenido adyacente intacto</section>' ), 'MALFORMED_PARENT_AND_ADJACENT_CONTENT_PRESERVED' );
nvx_provenance_assert( false === strpos( $malformed_clean, 'data-nvx-medical-review="approved"' ), 'MALFORMED_PENDING_NO_CANONICAL_ATTRIBUTION' );

$GLOBALS['nvx_test_approvals'] = array( 'version' => 1, 'managed_pages' => $approved_managed_pages );
$GLOBALS['nvx_test_path']      = '/papada-definicion-mandibular-madrid/';
$GLOBALS['nvx_test_treatment'] = true;
$GLOBALS['nvx_test_post_meta'] = array();
$papada = nvx_medical_review_record( 42 );
nvx_provenance_assert( is_array( $papada ), 'PAPADA_MANAGED_APPROVAL_RESOLVES_WITH_TREATMENT_CLASSIFICATION' );
nvx_provenance_assert( 'managed_registry' === ( $papada['source'] ?? '' ), 'PAPADA_MANAGED_APPROVAL_PRECEDENCE' );
nvx_provenance_assert( '2026-08-01' === ( $papada['date'] ?? '' ), 'PAPADA_MANAGED_APPROVAL_DATE' );

$GLOBALS['nvx_test_approvals'] = array( 'version' => 2, 'managed_pages' => $approved_managed_pages );
nvx_provenance_assert( nvx_medical_review_governed_page( 42 ), 'PAPADA_WRONG_VERSION_STAYS_GOVERNED' );
nvx_provenance_assert( null === nvx_medical_review_record( 42 ), 'PAPADA_WRONG_VERSION_DOES_NOT_FALL_THROUGH_TO_TREATMENT' );
$papada_fail_closed = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( ! isset( $papada_fail_closed[0]['reviewedBy'], $papada_fail_closed[0]['lastReviewed'] ), 'PAPADA_WRONG_VERSION_SCHEMA_STRIPPED' );
$GLOBALS['nvx_test_approvals'] = array( 'version' => 1, 'managed_pages' => $approved_managed_pages );

$GLOBALS['nvx_test_path']      = '/unrelated-page/';
$GLOBALS['nvx_test_treatment'] = false;
$unrelated = nvx_medical_review_schema_graph( $rogue_graph );
nvx_provenance_assert( 'https://rogue.example/#doctor' === ( $unrelated[0]['reviewedBy']['@id'] ?? '' ), 'UNRELATED_PAGE_GRAPH_LEFT_UNTOUCHED' );
nvx_provenance_assert( '1999-01-01' === ( $unrelated[0]['lastReviewed'] ?? '' ), 'UNRELATED_PAGE_DATE_LEFT_UNTOUCHED' );

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
$late_legacy = '<h1>Endoláser Corporal</h1><p class="nvx-medical-review">Late unconditional ICOMEM claim</p><p>Contenido.</p>';
$after_canonical = nvx_medical_review_enforce_visible_provenance( $late_legacy );
nvx_provenance_assert( false === strpos( $after_canonical, 'Late unconditional ICOMEM claim' ), 'PENDING_LATE_LEGACY_PRODUCER_STRIPPED' );
nvx_provenance_assert( false === strpos( $after_canonical, 'data-nvx-medical-review="approved"' ), 'PENDING_LATE_PRODUCER_NO_REINJECTION' );

$registry_source  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/medical-review-approvals.json' );
$medical_source   = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-medical-review.php' );
$constants_source = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-constants.php' );
nvx_provenance_assert( false !== strpos( $registry_source, '"version": 1' ), 'MANAGED_APPROVAL_REGISTRY_VERSIONED' );
nvx_provenance_assert( false !== strpos( $registry_source, '"/madrid/valoracion/"' ), 'VALORACION_APPROVAL_VERSIONED' );
nvx_provenance_assert( false !== strpos( $registry_source, '"/papada-definicion-mandibular-madrid/"' ), 'PAPADA_APPROVAL_VERSIONED' );
nvx_provenance_assert( false !== strpos( $medical_source, 'nvx_medical_review_managed_paths' ), 'MANAGED_PERIMETER_INDEPENDENT_OF_REGISTRY' );
nvx_provenance_assert( false !== strpos( $medical_source, "1 !== (int) ( \$catalog['version'] ?? 0 )" ), 'REGISTRY_VERSION_GUARD_PRESENT' );
nvx_provenance_assert( false !== strpos( $medical_source, 'nvx_medical_review_registry_path_is_canonical' ), 'REGISTRY_EXACT_PATH_GUARD_PRESENT' );
nvx_provenance_assert( false !== strpos( $medical_source, 'nvx_medical_review_legacy_wrapper_ranges' ), 'BALANCED_BYLINE_PARSER_PRESENT' );
nvx_provenance_assert( false !== strpos( $medical_source, "unset( \$graph[ \$index ]['reviewedBy'], \$graph[ \$index ]['lastReviewed'] )" ), 'CANONICAL_OWNER_SANITIZES_EARLIER_PROVENANCE' );
nvx_provenance_assert( false !== strpos( $medical_source, "\$graph[ \$index ]['lastReviewed'] = \$record['date']" ), 'CANONICAL_OWNER_EMITS_APPROVED_DATE' );
nvx_provenance_assert( false !== strpos( $constants_source, 'NVX_HOOK_PRIO_CLINICAL_AUTHORITY_BYLINE  = 145' ), 'LEGACY_BYLINE_PRIORITY_DOCUMENTED' );
nvx_provenance_assert( false !== strpos( $constants_source, 'NVX_HOOK_PRIO_MEDICAL_REVIEW             = 147' ), 'CANONICAL_VISIBLE_OWNER_RUNS_AFTER_LEGACY_BYLINE' );

echo 'PHP_MEDICAL_PROVENANCE_OWNER=PASS managed_registry=versioned_exact perimeter=independent precedence=managed legacy_byline=balanced malformed=fail_closed treatment_meta=preserved rogue_provenance=fail_closed priority=147' . PHP_EOL;
