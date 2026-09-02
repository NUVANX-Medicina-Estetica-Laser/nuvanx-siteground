<?php
/**
 * DB-authoritative runtime hardening for governed journal routes.
 *
 * This module is loaded during theme bootstrap. Governed one-segment requests
 * are pinned to the authoritative wp_posts row before the main query executes,
 * then rebound again on `wp` as a final defence before template/SEO consumers.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NVX_GOVERNED_BLOG_RUNTIME_CONTRACT' ) ) {
	define( 'NVX_GOVERNED_BLOG_RUNTIME_CONTRACT', '20260815-immutable-request-final-query-lock-v3' );
}

/** Immutable public request URI captured before the main query lifecycle. */
function nvx_governed_blog_runtime_original_request_uri(): string {
	return function_exists( 'nvx_theme_request_context' ) ? nvx_theme_request_context()['uri'] : '';
}

/** Actual one-segment public slug, independent of WP_Query/global post state. */
function nvx_governed_blog_runtime_request_slug(): string {
	$uri  = nvx_governed_blog_runtime_original_request_uri();
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	$path = is_string( $path ) ? trim( $path, '/' ) : '';

	if ( '' === $path || false !== strpos( $path, '/' ) ) {
		return '';
	}

	return sanitize_title( $path );
}

/**
 * Resolve a governed published post directly from wp_posts.
 *
 * Persistent/object caches are repaired only after the authoritative row has
 * been validated. The result is memoized for the remainder of this request.
 */
function nvx_governed_blog_runtime_db_post_by_slug( string $slug ): ?WP_Post {
	static $memo = array();

	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}
	if ( array_key_exists( $slug, $memo ) ) {
		return $memo[ $slug ];
	}

	global $wpdb;
	if ( ! isset( $wpdb ) || ! ( $wpdb instanceof wpdb ) ) {
		$memo[ $slug ] = null;
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'post' AND post_status = 'publish' ORDER BY ID ASC LIMIT 1",
			$slug
		)
	);

	if (
		! is_object( $row )
		|| ! isset( $row->ID, $row->post_name, $row->post_status, $row->post_type )
		|| (int) $row->ID <= 0
		|| 'publish' !== (string) $row->post_status
		|| 'post' !== (string) $row->post_type
		|| $slug !== (string) $row->post_name
	) {
		$memo[ $slug ] = null;
		return null;
	}

	$post = new WP_Post( $row );
	clean_post_cache( (int) $row->ID );
	wp_cache_set( (int) $row->ID, $post, 'posts' );
	$memo[ $slug ] = $post;

	return $post;
}

/**
 * Resolve the exact governed request from path + versioned SEO catalog + DB.
 *
 * @return array{slug:string,path:string,post:WP_Post,metadata:array<string,mixed>}|null
 */
function nvx_governed_blog_runtime_context(): ?array {
	if ( ! function_exists( 'nvx_seo_blog_post_metadata_catalog' ) ) {
		return null;
	}

	$slug = nvx_governed_blog_runtime_request_slug();
	if ( '' === $slug ) {
		return null;
	}

	$catalog = nvx_seo_blog_post_metadata_catalog();
	if ( ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
		return null;
	}

	$post = nvx_governed_blog_runtime_db_post_by_slug( $slug );
	if ( ! ( $post instanceof WP_Post ) ) {
		return null;
	}

	return array(
		'slug'     => $slug,
		'path'     => '/' . $slug . '/',
		'post'     => $post,
		'metadata' => $catalog[ $slug ],
	);
}

/**
 * HTML canonical for a governed Journal article.
 *
 * Defaults to the article path. A catalog `canonical_path` consolidates ranking
 * onto a transactional treatment page when both URLs compete for the same query.
 *
 * @param array{slug?:string,path?:string,metadata?:array<string,mixed>} $context
 */
function nvx_governed_blog_html_canonical_url( array $context ): string {
	$override = trim( (string) ( $context['metadata']['canonical_path'] ?? '' ) );
	if ( '' !== $override && '/' === $override[0] && false === strpos( $override, '//' ) ) {
		return home_url( '/' . trim( $override, '/' ) . '/' );
	}

	$path = (string) ( $context['path'] ?? '' );
	if ( '' === $path ) {
		$slug = trim( (string) ( $context['slug'] ?? '' ), '/' );
		$path = '' !== $slug ? '/' . $slug . '/' : '/';
	}

	return home_url( $path );
}

/**
 * Pin the main query to the authoritative governed post before SQL executes.
 *
 * Production proved that repairing only on `wp` can be too late: the initial
 * rewrite/query may already expose a neighbouring singular object to WordPress
 * and SEO integrations. The public request path + catalog + direct DB row are
 * therefore authoritative at pre_get_posts time.
 */
function nvx_governed_blog_runtime_pre_get_posts( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return;
	}

	$resolved = $context['post'];
	$slug     = $context['slug'];

	$query->set( 'p', (int) $resolved->ID );
	$query->set( 'name', $slug );
	$query->set( 'post_type', 'post' );
	$query->set( 'post_status', 'publish' );
	$query->set( 'pagename', '' );
	$query->set( 'page_id', 0 );
}

/**
 * Last-word post-array lock after SQL/cache filters have run.
 *
 * Production may have plugins/snippets that mutate the main query after our
 * pre_get_posts callback. For an exact governed public path, the versioned
 * catalog + immutable request URI + authoritative DB row are the only valid
 * identity, so replace a neighbouring result before the loop can observe it.
 *
 * @param WP_Post[] $posts Query result posts.
 * @return WP_Post[]
 */
function nvx_governed_blog_runtime_force_the_posts( array $posts, WP_Query $query ): array {
	if ( is_admin() || ! $query->is_main_query() ) {
		return $posts;
	}

	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return $posts;
	}

	$resolved = $context['post'];
	$slug     = $context['slug'];
	$query->set( 'p', (int) $resolved->ID );
	$query->set( 'name', $slug );
	$query->set( 'post_type', 'post' );
	$query->set( 'post_status', 'publish' );
	$query->set( 'pagename', '' );
	$query->set( 'page_id', 0 );

	return array( $resolved );
}

/**
 * Rebind both public query globals and the loop post before downstream SEO and
 * template consumers observe stale singular state.
 */
function nvx_governed_blog_runtime_rebind_queries(): ?WP_Post {
	$context = nvx_governed_blog_runtime_context();
	if ( null === $context || ! function_exists( 'nvx_single_post_rebind_query' ) ) {
		return null;
	}

	global $post, $wp, $wp_query, $wp_the_query;
	$resolved = $context['post'];
	$slug     = $context['slug'];

	if ( $wp_query instanceof WP_Query ) {
		nvx_single_post_rebind_query( $wp_query, $resolved, $slug );
		$wp_query->current_post = -1;
		$wp_query->in_the_loop  = false;
		$wp_query->before_loop  = true;
	}
	if ( $wp_the_query instanceof WP_Query && $wp_the_query !== $wp_query ) {
		nvx_single_post_rebind_query( $wp_the_query, $resolved, $slug );
		$wp_the_query->current_post = -1;
		$wp_the_query->in_the_loop  = false;
		$wp_the_query->before_loop  = true;
	}

	// Keep WP::query_vars coherent for consumers that inspect the request object
	// rather than the global WP_Query instance.
	if ( isset( $wp ) && is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) {
		$wp->query_vars['p']         = (int) $resolved->ID;
		$wp->query_vars['name']      = $slug;
		$wp->query_vars['post_type'] = 'post';
		$wp->query_vars['pagename']  = '';
		$wp->query_vars['page_id']   = 0;
	}

	$post = $resolved;
	setup_postdata( $post );

	// The governed canonical contract below is the single owner. Remove core's
	// singular canonical emitter for this request to prevent duplicate tags.
	remove_action( 'wp_head', 'rel_canonical' );

	return $resolved;
}

/** WordPress action adapter: actions must not return a value. */
function nvx_governed_blog_runtime_rebind_queries_action(): void {
	nvx_governed_blog_runtime_rebind_queries();
}

/** Force the canonical post entrypoint for an exact governed request. */
function nvx_governed_blog_runtime_template_include( $template ) {
	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return $template;
	}

	$single_post = get_template_directory() . '/single-post.php';
	return is_readable( $single_post ) ? $single_post : $template;
}

/** Final title derived only from the governed request path/catalog. */
function nvx_governed_blog_runtime_title( $title ) {
	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return $title;
	}
	$value = trim( (string) ( $context['metadata']['title'] ?? '' ) );
	return '' !== $value ? $value : $title;
}

/** Final description derived only from the governed request path/catalog. */
function nvx_governed_blog_runtime_description( $description ) {
	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return $description;
	}
	$value = trim( (string) ( $context['metadata']['description'] ?? '' ) );
	return '' !== $value ? $value : $description;
}

/** Path-authoritative canonical value, also used by tests and diagnostics. */
function nvx_governed_blog_runtime_canonical( $canonical ) {
	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return $canonical;
	}
	return nvx_governed_blog_html_canonical_url( $context );
}

/**
 * Prevent Yoast from emitting a second canonical on governed routes.
 *
 * Staging intentionally has blog_public=0 and Yoast can omit canonical there,
 * while Production emits one. The explicit contract below avoids that drift.
 *
 * @param mixed $canonical Existing Yoast canonical after path normalization.
 * @return mixed
 */
function nvx_governed_blog_runtime_suppress_yoast_canonical( $canonical ) {
	return null !== nvx_governed_blog_runtime_context() ? false : $canonical;
}

/** Final Open Graph URL; staging retains the production-host social policy. */
function nvx_governed_blog_runtime_opengraph_url( $url ) {
	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return $url;
	}
	if ( function_exists( 'nvx_seo_is_nonproduction_environment' ) && nvx_seo_is_nonproduction_environment() ) {
		return home_url( $context['path'] );
	}
	return home_url( $context['path'] );
}

/** Normalize Yoast's presentation after every earlier object/indexable mapper. */
function nvx_governed_blog_runtime_yoast_presentation( $presentation, $context ) {
	unset( $context );
	$runtime = nvx_governed_blog_runtime_context();
	if ( null === $runtime || ! is_object( $presentation ) ) {
		return $presentation;
	}

	$title       = trim( (string) ( $runtime['metadata']['title'] ?? '' ) );
	$description = trim( (string) ( $runtime['metadata']['description'] ?? '' ) );
	$canonical   = nvx_governed_blog_html_canonical_url( $runtime );
	$og_url      = nvx_governed_blog_runtime_opengraph_url( home_url( $runtime['path'] ) );

	if ( '' !== $title ) {
		$presentation->title            = $title;
		$presentation->open_graph_title = $title;
		$presentation->twitter_title    = $title;
	}
	if ( '' !== $description ) {
		$presentation->meta_description       = $description;
		$presentation->open_graph_description = $description;
		$presentation->twitter_description    = $description;
	}
	$presentation->canonical      = $canonical;
	$presentation->open_graph_url = $og_url;

	return $presentation;
}

/** Emit the governed canonical as the sole stable owner. */
function nvx_governed_blog_runtime_print_canonical(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return;
	}

	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		return;
	}

	echo '<link rel="canonical" href="' . esc_url( nvx_governed_blog_html_canonical_url( $context ) ) . '" />' . "\n";
}

/** Keep document/runtime sentinels and legacy fallback for non-governed routes. */
function nvx_governed_blog_runtime_print_head_contract(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return;
	}

	$context = nvx_governed_blog_runtime_context();
	if ( null === $context ) {
		$canonical = function_exists( 'nvx_document_governance_canonical_url' )
			? nvx_document_governance_canonical_url()
			: '';
		if ( '' !== $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
	}

	echo '<meta name="nvx-document-contract" content="1" />' . "\n";
	if ( null !== $context ) {
		echo '<meta name="nvx-governed-blog-runtime-contract" content="' . esc_attr( NVX_GOVERNED_BLOG_RUNTIME_CONTRACT ) . '" />' . "\n";
	}
}

// Loaded from nvx-blog-system.php during functions.php bootstrap. Every phase
// below is a final-word lock for exact governed paths. Plugins load before the
// theme, so PHP_INT_MAX also places this callback after peer-priority plugin
// callbacks registered earlier.
add_action( 'pre_get_posts', 'nvx_governed_blog_runtime_pre_get_posts', PHP_INT_MAX );
add_filter( 'the_posts', 'nvx_governed_blog_runtime_force_the_posts', PHP_INT_MAX, 2 );
add_action( 'wp', 'nvx_governed_blog_runtime_rebind_queries_action', PHP_INT_MAX );
add_action( 'template_redirect', 'nvx_governed_blog_runtime_rebind_queries_action', PHP_INT_MAX );
add_filter( 'template_include', 'nvx_governed_blog_runtime_template_include', PHP_INT_MAX );

remove_action( 'wp_head', 'nvx_document_governance_print_head_contract', 2 );
add_action( 'wp_head', 'nvx_governed_blog_runtime_print_canonical', 1 );
add_action( 'wp_head', 'nvx_governed_blog_runtime_print_head_contract', 2 );

add_filter( 'wpseo_title', 'nvx_governed_blog_runtime_title', PHP_INT_MAX );
add_filter( 'pre_get_document_title', 'nvx_governed_blog_runtime_title', PHP_INT_MAX );
add_filter( 'wpseo_opengraph_title', 'nvx_governed_blog_runtime_title', PHP_INT_MAX );
add_filter( 'wpseo_twitter_title', 'nvx_governed_blog_runtime_title', PHP_INT_MAX );
add_filter( 'wpseo_metadesc', 'nvx_governed_blog_runtime_description', PHP_INT_MAX );
add_filter( 'wpseo_opengraph_desc', 'nvx_governed_blog_runtime_description', PHP_INT_MAX );
add_filter( 'wpseo_twitter_description', 'nvx_governed_blog_runtime_description', PHP_INT_MAX );
add_filter( 'wpseo_canonical', 'nvx_governed_blog_runtime_canonical', PHP_INT_MAX );
add_filter( 'wpseo_canonical', 'nvx_governed_blog_runtime_suppress_yoast_canonical', PHP_INT_MAX );
add_filter( 'wpseo_opengraph_url', 'nvx_governed_blog_runtime_opengraph_url', PHP_INT_MAX );
add_filter( 'wpseo_frontend_presentation', 'nvx_governed_blog_runtime_yoast_presentation', PHP_INT_MAX, 2 );
