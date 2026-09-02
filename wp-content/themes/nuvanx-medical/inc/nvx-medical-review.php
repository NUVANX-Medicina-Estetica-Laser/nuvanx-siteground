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

/** Normalize a public path for exact managed-page approval lookup. */
function nvx_medical_review_normalize_path( string $path ): string {
	$path = '/' . trim( $path, '/' );
	return '/' === $path ? '/' : $path . '/';
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
	if ( $post_id <= 0 || ! function_exists( 'get_permalink' ) ) {
		return '';
	}

	$permalink = get_permalink( $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return '';
	}

	$path = function_exists( 'wp_parse_url' )
		? wp_parse_url( $permalink, PHP_URL_PATH )
		: parse_url( $permalink, PHP_URL_PATH );

	return is_string( $path ) && '' !== $path ? nvx_medical_review_normalize_path( $path ) : '';
}

/**
 * Load approved managed-page provenance from the versioned registry.
 *
 * Invalid, incomplete or non-approved records are discarded. A missing or
 * malformed registry therefore fails closed.
 *
 * @return array<string,array{status:string,reviewer:string,date:string}>
 */
function nvx_medical_review_managed_approvals(): array {
	if ( ! function_exists( 'nvx_catalog_json_load' ) ) {
		return array();
	}

	$catalog = nvx_catalog_json_load( 'medical-review-approvals.json' );
	if ( ! empty( $catalog['_error'] ) || ! isset( $catalog['managed_pages'] ) || ! is_array( $catalog['managed_pages'] ) ) {
		return array();
	}

	$approvals = array();
	foreach ( $catalog['managed_pages'] as $raw_path => $record ) {
		if ( ! is_string( $raw_path ) || ! is_array( $record ) ) {
			continue;
		}

		$path         = nvx_medical_review_normalize_path( $raw_path );
		$status       = strtolower( trim( (string) ( $record['status'] ?? '' ) ) );
		$reviewer_key = strtolower( trim( (string) ( $record['reviewer'] ?? '' ) ) );
		$date         = trim( (string) ( $record['date'] ?? '' ) );

		if ( 'approved' !== $status || '' === $reviewer_key || ! nvx_medical_review_valid_date( $date ) ) {
			continue;
		}

		$approvals[ $path ] = array(
			'status'   => $status,
			'reviewer' => $reviewer_key,
			'date'     => $date,
		);
	}

	return $approvals;
}

/**
 * Resolve one approval source without allowing page modules to author provenance.
 *
 * @return array{status:string,reviewer:string,date:string,source:string}|null
 */
function nvx_medical_review_approval( int $post_id = 0 ): ?array {
	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();

	if ( nvx_medical_review_supported_page( $post_id ) ) {
		return array(
			'status'   => strtolower( trim( (string) get_post_meta( $post_id, '_nvx_medical_review_status', true ) ) ),
			'reviewer' => strtolower( trim( (string) get_post_meta( $post_id, '_nvx_medical_reviewer', true ) ) ),
			'date'     => trim( (string) get_post_meta( $post_id, '_nvx_medical_review_date', true ) ),
			'source'   => 'post_meta',
		);
	}

	$path      = nvx_medical_review_current_path( $post_id );
	$approvals = nvx_medical_review_managed_approvals();
	if ( '' === $path || ! isset( $approvals[ $path ] ) ) {
		return null;
	}

	return array(
		'status'   => $approvals[ $path ]['status'],
		'reviewer' => $approvals[ $path ]['reviewer'],
		'date'     => $approvals[ $path ]['date'],
		'source'   => 'managed_registry',
	);
}

/** Whether a registered reviewer has every public provenance field required. */
function nvx_medical_review_reviewer_complete( array $reviewer ): bool {
	foreach ( array( 'name', 'license', 'url', 'id', 'title' ) as $field ) {
		if ( '' === trim( (string) ( $reviewer[ $field ] ?? '' ) ) ) {
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
 * Remove unconditional bylines so a single provenance block can be re-injected.
 *
 * Covers both the `<div>`-wrapped bylines emitted by page modules and the
 * `<address>`-wrapped hero byline emitted by BTL detail pages; the closing tag
 * is matched by backreference so the alternation never over-captures.
 */
function nvx_medical_review_strip_unconditional_bylines( string $content ): string {
	$pattern = '#<(div|address)\b[^>]*\bclass=["\'][^"\']*\bnvx-medical-byline\b[^"\']*["\'][^>]*>[\s\S]*?</div>\s*</\1>#iu';
	$clean   = preg_replace( $pattern, '', $content );

	return is_string( $clean ) ? $clean : $content;
}

/** Enforce fail-closed visible provenance after all page builders have run. */
function nvx_medical_review_enforce_visible_provenance( string $content ): string {
	if (
		is_admin()
		|| wp_doing_ajax()
		|| is_feed()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( ! is_singular( 'page' ) && ! is_page() )
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
 * Enforce the canonical provenance owner on WebPage nodes.
 *
 * Earlier page-specific filters are not trusted as provenance authorities:
 * reviewedBy / lastReviewed are first removed from every governed page node,
 * then restored only from the validated approval record. This makes the graph
 * fail closed even if a future lower-priority emitter attempts to bypass the
 * canonical owner.
 */
function nvx_medical_review_schema_graph( $graph ) {
	if ( ! is_array( $graph ) ) {
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
