<?php
/**
 * Approval-gated medical review provenance for clinical pages.
 *
 * Visible review attribution and reviewedBy schema are emitted only when the
 * current page has a complete approval record in post meta:
 *
 * - `_nvx_medical_review_status` = `approved`
 * - `_nvx_medical_reviewer`      = registered reviewer key
 * - `_nvx_medical_review_date`   = YYYY-MM-DD
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string,array{name:string,license:string,url:string,id:string,title:string}> */
function nvx_medical_reviewers(): array {
	$license = defined( 'NVX_DIRECTOR_COLEGIADO' ) ? (string) NVX_DIRECTOR_COLEGIADO : '282864786';
	$url     = home_url( '/equipo-medico/#physician-rivera-tejeda' );

	return array(
		'rivera' => array(
			'name'    => 'Dr. José Javier Rivera Tejeda',
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

/** Restrict review provenance to registered treatment pages. */
function nvx_medical_review_supported_page( int $post_id ): bool {
	if ( $post_id <= 0 || ! function_exists( 'nvx_schema_resolve_treatment_key' ) ) {
		return false;
	}

	return null !== nvx_schema_resolve_treatment_key( $post_id );
}

/**
 * Return one complete approval record or null.
 *
 * @return array{reviewer_key:string,name:string,license:string,url:string,id:string,title:string,date:string,date_label:string}|null
 */
function nvx_medical_review_record( int $post_id = 0 ): ?array {
	$post_id = $post_id > 0 ? $post_id : (int) get_queried_object_id();
	if ( ! nvx_medical_review_supported_page( $post_id ) ) {
		return null;
	}

	$status       = strtolower( trim( (string) get_post_meta( $post_id, '_nvx_medical_review_status', true ) ) );
	$reviewer_key = strtolower( trim( (string) get_post_meta( $post_id, '_nvx_medical_reviewer', true ) ) );
	$date         = trim( (string) get_post_meta( $post_id, '_nvx_medical_review_date', true ) );
	$reviewers    = nvx_medical_reviewers();

	if ( 'approved' !== $status || ! isset( $reviewers[ $reviewer_key ] ) || ! nvx_medical_review_valid_date( $date ) ) {
		return null;
	}

	$reviewer = $reviewers[ $reviewer_key ];
	$time     = strtotime( $date . ' 12:00:00' );
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

/** Remove unconditional bylines so a single provenance block can be re-injected. */
function nvx_medical_review_strip_unconditional_bylines( string $content ): string {
	$pattern = '#<div\b[^>]*\bclass=["\'][^"\']*\bnvx-medical-byline\b[^"\']*["\'][^>]*>[\s\S]*?</div>\s*</div>#iu';
	$clean   = preg_replace( $pattern, '', $content );

	return is_string( $clean ) ? $clean : $content;
}

/**
 * Enforce fail-closed visible provenance after all page builders have run.
 */
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

/** Test whether a schema type contains WebPage. */
function nvx_medical_review_schema_has_type( $types, string $type ): bool {
	return in_array( $type, is_array( $types ) ? $types : array( $types ), true );
}

/** Add reviewedBy only when the same approved reviewer disclosure is visible. */
function nvx_medical_review_schema_graph( $graph ) {
	$record = nvx_medical_review_record();
	if ( null === $record || ! is_array( $graph ) ) {
		return $graph;
	}

	foreach ( $graph as $index => $piece ) {
		if (
			is_array( $piece )
			&& isset( $piece['@type'] )
			&& nvx_medical_review_schema_has_type( $piece['@type'], 'WebPage' )
		) {
			$graph[ $index ]['reviewedBy'] = array( '@id' => $record['id'] );
		}
	}

	return $graph;
}
nvx_add_filter_with_priority( 'wpseo_schema_graph', 'nvx_medical_review_schema_graph' );
