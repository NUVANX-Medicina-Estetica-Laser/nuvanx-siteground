<?php
/**
 * Permanent redirects for retired working protocol names.
 *
 * LipoSculpt-Air™ and V-Lift Awake™ were internal working names that never
 * completed medical/legal publication review. They must not remain addressable
 * as treatment offers even if legacy WordPress records are still published.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get WordPress page IDs for retired strategy slugs.
 *
 * @return int[]
 */
function nvx_retired_strategy_page_ids(): array {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}

	$slugs = array( 'liposculpt-air', 'tratamiento-retirado', 'v-lift-awake' );

	// Search every singular post type for matching top-level or nested slugs.
	$retired_query = new WP_Query(
		array(
			'post_type'      => 'any',
			'post_name__in'  => $slugs,
			'fields'         => 'ids',
			'posts_per_page' => -1,
		)
	);

	$ids = array_map( 'intval', $retired_query->posts );

	return $ids;
}

/**
 * Build redirect URL with preserved query string.
 *
 * @param string $target The target path.
 * @param string $query  The query string.
 * @return string The full redirect URL.
 */
function nvx_build_redirect_url( string $target, string $query ): string {
	$redirect_url = home_url( $target );
	if ( '' !== $query ) {
		$query_args = array();
		wp_parse_str( $query, $query_args );
		// Never carry post-resolution args: they would re-select the retired
		// record on the target URL and loop this redirect indefinitely.
		unset( $query_args['p'], $query_args['page_id'], $query_args['name'], $query_args['pagename'], $query_args['attachment_id'], $query_args['preview'], $query_args['preview_id'], $query_args['post_type'] );
		if ( ! empty( $query_args ) ) {
			$redirect_url = add_query_arg( $query_args, $redirect_url );
		}
	}
	return $redirect_url;
}

/**
 * Redirect retired strategy slugs to their approved public clinical hubs.
 */
function nvx_redirect_retired_strategy_slugs(): void {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path  = strtolower( trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' ) );
	$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	$targets = array(
		'liposculpt-air'       => '/remodelacion-corporal-laser-madrid/',
		'tratamiento-retirado' => '/tratamientos/',
		'v-lift-awake'         => '/papada-definicion-mandibular-madrid/',
	);

	// Check path-based redirect for top-level pages.
	if ( isset( $targets[ $path ] ) ) {
		$redirect_url = nvx_build_redirect_url( $targets[ $path ], $query );
		wp_safe_redirect( $redirect_url, 301, 'NUVANX' );
		exit;
	}

	// Check post_name-based redirect for nested pages and other post types.
	if ( is_singular() ) {
		$queried_object = get_queried_object();
		if ( $queried_object && isset( $queried_object->post_name ) && isset( $targets[ $queried_object->post_name ] ) ) {
			$redirect_url = nvx_build_redirect_url( $targets[ $queried_object->post_name ], $query );
			wp_safe_redirect( $redirect_url, 301, 'NUVANX' );
			exit;
		}
	}
}
add_action( 'template_redirect', 'nvx_redirect_retired_strategy_slugs', 0 );
