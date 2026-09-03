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
 * Build redirect URL with preserved governed query arguments.
 *
 * @param string              $target     Target path.
 * @param array<string,mixed> $query_args Sanitized query arguments from the immutable request context.
 */
function nvx_build_redirect_url( string $target, array $query_args ): string {
	$redirect_url = home_url( $target );

	// Never carry post-resolution args: they would re-select the retired record
	// on the target URL and loop this redirect indefinitely.
	unset( $query_args['p'], $query_args['page_id'], $query_args['name'], $query_args['pagename'], $query_args['attachment_id'], $query_args['preview'], $query_args['preview_id'], $query_args['post_type'] );
	if ( ! empty( $query_args ) ) {
		$redirect_url = add_query_arg( $query_args, $redirect_url );
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

	$context = function_exists( 'nvx_theme_request_context' ) ? nvx_theme_request_context() : array();
	$path    = isset( $context['path'] ) && is_string( $context['path'] )
		? strtolower( trim( rawurldecode( $context['path'] ), '/' ) )
		: '';
	$query_args = isset( $context['query_args'] ) && is_array( $context['query_args'] )
		? $context['query_args']
		: array();

	$targets = array(
		'liposculpt-air'       => '/remodelacion-corporal-laser-madrid/',
		'tratamiento-retirado' => '/tratamientos/',
		'v-lift-awake'         => '/papada-definicion-mandibular-madrid/',
	);

	// Check path-based redirect for top-level pages.
	if ( isset( $targets[ $path ] ) ) {
		$redirect_url = nvx_build_redirect_url( $targets[ $path ], $query_args );
		wp_safe_redirect( $redirect_url, 301, 'NUVANX' );
		exit;
	}

	// Check post_name-based redirect for nested pages and other post types.
	if ( is_singular() ) {
		$queried_object = get_queried_object();
		if ( $queried_object && isset( $queried_object->post_name ) && isset( $targets[ $queried_object->post_name ] ) ) {
			$redirect_url = nvx_build_redirect_url( $targets[ $queried_object->post_name ], $query_args );
			wp_safe_redirect( $redirect_url, 301, 'NUVANX' );
			exit;
		}
	}
}
add_action( 'template_redirect', 'nvx_redirect_retired_strategy_slugs', 0 );
