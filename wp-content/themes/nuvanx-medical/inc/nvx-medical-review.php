<?php
/**
 * Approval-gated medical review provenance for clinical pages.
 *
 * Visible review attribution and reviewedBy schema are emitted only when the
 * current page has a complete approval record from one canonical owner:
 *
 * - treatment pages: approved post-meta record;
 * - managed pages: approved versioned registry record.
 *
 * The managed governance perimeter is code-owned and independent from approval
 * catalog availability. A missing, malformed or unsupported approval catalog
 * therefore fails closed instead of restoring legacy page-level provenance.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string,array{name:string,license:string,url:string,id:string,title:string}> */
function nvx_medical_reviewers(): array {
	$license = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$name    = function_exists( 'nvx_medical_staff_name' ) ? nvx_medical_staff_name( 'director' ) : '';
	$url     = home_url( '/equipo-medico/#physician-rivera-tejeda' );

	return array(
		'rivera' => array(
			'name'    => $name,
			'license' => $license,
			'url'     => $url,
			'id'      => $url,
			'title'   => 'Director médico NUVANX',
		),
	);
}

/** Validate an ISO calendar date without silently correcting it. */
function nvx_medical_review_valid_date( string $date ): bool {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match ) ) {
		return false;
	}

	return checkdate( (int) $match[2], (int) $match[3], (int) $match[1] );
}

/** Restrict post-meta review provenance to registered treatment pages. */
function nvx_medical_review_supported_page( int $post_id ): bool {
	if ( $post_id <= 0 || ! function_exists( 'nvx_schema_resolve_treatment_key' ) ) {
		return false;
	}

	return null !== nvx_schema_resolve_treatment_key( $post_id );
}

/** Normalize a request/permalink path for exact managed-page lookup. */
function nvx_medical_review_normalize_path( string $path ): string {
	$path = '/' . trim( $path, '/' );
	return '/' === $path ? '/' : $path . '/';
}

/** Whether a registry key is already in the exact canonical public-path form. */
function nvx_medical_review_registry_path_is_canonical( string $raw_path ): bool {
	return '' !== $raw_path
		&& trim( $raw_path ) === $raw_path
		&& nvx_medical_review_normalize_path( $raw_path ) === $raw_path;
}

/**
 * Code-owned managed-page governance perimeter.
 *
 * This list deliberately does not depend on the approval JSON. If approval
 * loading fails these routes remain governed and legacy provenance is stripped.
 *
 * @return array<int,string>
 */
function nvx_medical_review_managed_paths(): array {
	return array(
		'/madrid/valoracion/',
		'/papada-definicion-mandibular-madrid/',
	);
}

/** Resolve the current canonical request path without trusting a second raw URI read. */
function nvx_medical_review_current_path( int $post_id = 0 ): string {
	if ( function_exists( 'nvx_theme_request_path' ) ) {
		$path = (string) nvx_theme_request_path();
		if ( '' !== $path ) {
			return nvx_medical_review_normalize_path( $path );
		}
	}

	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();
	if ( $post_id <= 0 || ! function_exists( 'get_permalink' ) || ! function_exists( 'wp_parse_url' ) ) {
		return '';
	}

	$permalink = get_permalink( $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return '';
	}

	$path = wp_parse_url( $permalink, PHP_URL_PATH );
	return is_string( $path ) && '' !== $path ? nvx_medical_review_normalize_path( $path ) : '';
}

/** Whether one canonical path belongs to the managed provenance perimeter. */
function nvx_medical_review_is_managed_path( string $path ): bool {
	return '' !== $path && in_array( $path, nvx_medical_review_managed_paths(), true );
}

/**
 * Load validated managed-page provenance records.
 *
 * Only registry schema version 1 is supported. Registry keys must already be
 * exact canonical paths and must belong to the code-owned governance perimeter.
 * Missing, malformed, unsupported or unexpected records never become authority.
 *
 * @return array<string,array{status:string,reviewer:string,date:string}>
 */
function nvx_medical_review_managed_registry(): array {
	if ( ! function_exists( 'nvx_catalog_json_load' ) ) {
		return array();
	}

	$catalog = nvx_catalog_json_load( 'medical-review-approvals.json' );
	if (
		! empty( $catalog['_error'] )
		|| 1 !== (int) ( $catalog['version'] ?? 0 )
		|| ! isset( $catalog['managed_pages'] )
		|| ! is_array( $catalog['managed_pages'] )
	) {
		return array();
	}

	$records = array();
	foreach ( $catalog['managed_pages'] as $raw_path => $record ) {
		if (
			! is_string( $raw_path )
			|| ! is_array( $record )
			|| ! nvx_medical_review_registry_path_is_canonical( $raw_path )
			|| ! nvx_medical_review_is_managed_path( $raw_path )
		) {
			continue;
		}

		$records[ $raw_path ] = array(
			'status'   => strtolower( trim( (string) ( $record['status'] ?? '' ) ) ),
			'reviewer' => strtolower( trim( (string) ( $record['reviewer'] ?? '' ) ) ),
			'date'     => trim( (string) ( $record['date'] ?? '' ) ),
		);
	}

	return $records;
}

/** Return the exact managed registry record for the current canonical path. */
function nvx_medical_review_managed_record( int $post_id = 0 ): ?array {
	$path = nvx_medical_review_current_path( $post_id );
	if ( ! nvx_medical_review_is_managed_path( $path ) ) {
		return null;
	}

	$registry = nvx_medical_review_managed_registry();
	return isset( $registry[ $path ] ) ? $registry[ $path ] : null;
}

/** Whether the current page belongs to the canonical medical provenance perimeter. */
function nvx_medical_review_governed_page( int $post_id = 0 ): bool {
	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();
	$path    = nvx_medical_review_current_path( $post_id );

	// Governance is independent from whether approval data loaded successfully.
	if ( nvx_medical_review_is_managed_path( $path ) ) {
		return true;
	}

	return nvx_medical_review_supported_page( $post_id );
}

/**
 * Resolve one approval source without allowing page modules to author provenance.
 *
 * Exact managed-page registration has deterministic precedence over the generic
 * treatment classifier. A managed route with missing/invalid registry data
 * returns no approval and must not fall through to treatment post meta.
 *
 * @return array{status:string,reviewer:string,date:string,source:string}|null
 */
function nvx_medical_review_approval( int $post_id = 0 ): ?array {
	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();
	$path    = nvx_medical_review_current_path( $post_id );

	if ( nvx_medical_review_is_managed_path( $path ) ) {
		$managed = nvx_medical_review_managed_record( $post_id );
		if ( null === $managed ) {
			return null;
		}

		return array(
			'status'   => $managed['status'],
			'reviewer' => $managed['reviewer'],
			'date'     => $managed['date'],
			'source'   => 'managed_registry',
		);
	}

	if ( ! nvx_medical_review_supported_page( $post_id ) ) {
		return null;
	}

	return array(
		'status'   => strtolower( trim( (string) get_post_meta( $post_id, '_nvx_medical_review_status', true ) ) ),
		'reviewer' => strtolower( trim( (string) get_post_meta( $post_id, '_nvx_medical_reviewer', true ) ) ),
		'date'     => trim( (string) get_post_meta( $post_id, '_nvx_medical_review_date', true ) ),
		'source'   => 'post_meta',
	);
}

/** Whether a registered reviewer has every public provenance field required. */
function nvx_medical_review_reviewer_complete( array $reviewer ): bool {
	foreach ( array( 'name', 'license', 'url', 'id', 'title' ) as $field ) {
		if ( '' === trim( (string) ( $reviewer[ $field ] ?? '' ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Return one complete approval record or null.
 *
 * @return array{reviewer_key:string,name:string,license:string,url:string,id:string,title:string,date:string,date_label:string,source:string}|null
 */
function nvx_medical_review_record( int $post_id = 0 ): ?array {
	$approval = nvx_medical_review_approval( $post_id );
	if ( null === $approval ) {
		return null;
	}

	$status       = $approval['status'];
	$reviewer_key = $approval['reviewer'];
	$date         = $approval['date'];
	$reviewers    = nvx_medical_reviewers();

	if ( 'approved' !== $status || ! isset( $reviewers[ $reviewer_key ] ) || ! nvx_medical_review_valid_date( $date ) ) {
		return null;
	}

	$reviewer = $reviewers[ $reviewer_key ];
	if ( ! nvx_medical_review_reviewer_complete( $reviewer ) ) {
		return null;
	}

	$time = strtotime( $date . ' 12:00:00' );
	if ( false === $time ) {
		return null;
	}

	return array(
		'reviewer_key' => $reviewer_key,
		'name'         => $reviewer['name'],
		'license'      => $reviewer['license'],
		'url'          => $reviewer['url'],
		'id'           => $reviewer['id'],
		'title'        => $reviewer['title'],
		'date'         => $date,
		'date_label'   => wp_date( 'j \d\e F \d\e Y', $time ),
		'source'       => $approval['source'],
	);
}

/** Build the compact hero byline from an approved record. */
function nvx_medical_review_byline_markup( array $record ): string {
	$html  = '<div class="nvx-medical-byline" data-nvx-medical-review="approved">';
	$html .= '<div class="nvx-medical-byline__text">';
	$html .= '<strong>' . esc_html__( 'Contenido revisado médicamente por ', 'nuvanx-medical' );
	$html .= '<a href="' . esc_url( $record['url'] ) . '">' . esc_html( $record['name'] ) . '</a></strong><br>';
	$html .= '<span class="nvx-medical-byline__title">' . esc_html( $record['title'] );
	$html .= ' · ' . esc_html__( 'Colegiado ICOMEM Nº', 'nuvanx-medical' ) . ' ' . esc_html( $record['license'] );
	$html .= ' · ' . esc_html__( 'Última revisión clínica:', 'nuvanx-medical' ) . ' ';
	$html .= '<time datetime="' . esc_attr( $record['date'] ) . '">' . esc_html( $record['date_label'] ) . '</time></span>';
	$html .= '</div></div>';

	return $html;
}

/**
 * Remove every legacy visible provenance wrapper used by governed renderers.
 *
 * Nested wrappers are removed before flat wrappers so a complete canonical
 * block is never left partially behind. Address and paragraph legacy variants
 * are handled separately to avoid cross-wrapper over-capture.
 */
function nvx_medical_review_strip_unconditional_bylines( string $content ): string {
	$patterns = array(
		'#<div\b[^>]*\bclass=["\'][^"\']*\bnvx-medical-byline\b[^"\']*["\'][^>]*>[\s\S]*?</div>\s*</div>#iu',
		'#<address\b[^>]*\bclass=["\'][^"\']*\bnvx-medical-byline\b[^"\']*["\'][^>]*>[\s\S]*?</div>\s*</address>#iu',
		'#<div\b[^>]*\bclass=["\'][^"\']*\bnvx-medical-byline\b[^"\']*["\'][^>]*>[\s\S]*?</div>#iu',
		'#<address\b[^>]*\bclass=["\'][^"\']*\bnvx-medical-byline\b[^"\']*["\'][^>]*>[\s\S]*?</address>#iu',
		'#<p\b[^>]*\bclass=["\'][^"\']*\bnvx-medical-review\b[^"\']*["\'][^>]*>[\s\S]*?</p>#iu',
	);

	$clean = $content;
	foreach ( $patterns as $pattern ) {
		$updated = preg_replace( $pattern, '', $clean );
		if ( is_string( $updated ) ) {
			$clean = $updated;
		}
	}

	return $clean;
}

/** Enforce fail-closed visible provenance after every known attribution producer. */
function nvx_medical_review_enforce_visible_provenance( string $content ): string {
	if (
		is_admin()
		|| wp_doing_ajax()
		|| is_feed()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( ! is_singular( 'page' ) && ! is_page() )
		|| ! nvx_medical_review_governed_page()
	) {
		return $content;
	}

	$content = nvx_medical_review_strip_unconditional_bylines( $content );
	$record  = nvx_medical_review_record();
	if ( null === $record ) {
		return $content;
	}

	$byline  = nvx_medical_review_byline_markup( $record );
	$updated = preg_replace( '/(<h1\b[^>]*>[\s\S]*?<\/h1>)/iu', '$1' . $byline, $content, 1 );

	return is_string( $updated ) ? $updated : $content;
}
add_filter( 'the_content', 'nvx_medical_review_enforce_visible_provenance', NVX_HOOK_PRIO_MEDICAL_REVIEW );

/** Test whether a schema type contains one requested type. */
function nvx_medical_review_schema_has_type( $types, string $type ): bool {
	return in_array( $type, is_array( $types ) ? $types : array( $types ), true );
}

/** Whether a schema node is governed as a page provenance surface. */
function nvx_medical_review_schema_is_page_node( array $piece ): bool {
	if ( ! isset( $piece['@type'] ) ) {
		return false;
	}

	return nvx_medical_review_schema_has_type( $piece['@type'], 'WebPage' )
		|| nvx_medical_review_schema_has_type( $piece['@type'], 'MedicalWebPage' );
}

/**
 * Enforce the canonical provenance owner on governed WebPage nodes.
 *
 * Earlier page-specific filters are not trusted as provenance authorities:
 * reviewedBy / lastReviewed are first removed from every governed page node,
 * then restored only from the validated approval record. Requests outside the
 * treatment/managed-page governance perimeter are left untouched.
 */
function nvx_medical_review_schema_graph( $graph ) {
	if ( ! is_array( $graph ) || ! nvx_medical_review_governed_page() ) {
		return $graph;
	}

	$record = nvx_medical_review_record();
	foreach ( $graph as $index => $piece ) {
		if ( ! is_array( $piece ) || ! nvx_medical_review_schema_is_page_node( $piece ) ) {
			continue;
		}

		unset( $graph[ $index ]['reviewedBy'], $graph[ $index ]['lastReviewed'] );
		if ( null === $record ) {
			continue;
		}

		$graph[ $index ]['reviewedBy']   = array( '@id' => $record['id'] );
		$graph[ $index ]['lastReviewed'] = $record['date'];
	}

	return $graph;
}
add_filter( 'wpseo_schema_graph', 'nvx_medical_review_schema_graph', 57 );
