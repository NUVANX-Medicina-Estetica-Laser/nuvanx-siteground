<?php
/**
 * Global front-end runtime governance and head contract.
 *
 * Full-document rewrite buffers were retired: SiteGround Optimizer + Complianz +
 * core already own the front-end buffer stack, and nesting another rewrite layer
 * produced HTTP 200 + empty body on /soluciones-medicas/. Head contract pieces
 * (canonical, document marker) and runtime assets are enforced via wp_head /
 * Yoast filters and enqueues instead of full-document preg rewrites.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast may skip or duplicate canonical under staging noindex. Theme emits the
 * single public canonical from wp_head; suppress Yoast's copy as the final word
 * on wpseo_canonical (priority PHP_INT_MAX so no peer filter can re-enable it).
 *
 * Non-production Open Graph host policy is owned below
 * (nvx_document_governance_nonproduction_opengraph_url) — not on this filter.
 *
 * @param string|false $canonical Existing canonical.
 * @return false
 */
function nvx_document_governance_suppress_yoast_canonical( $canonical ) {
	unset( $canonical );
	return false;
}
add_filter( 'wpseo_canonical', 'nvx_document_governance_suppress_yoast_canonical', PHP_INT_MAX );

/**
 * Normalized path from the actual HTTP request, independent of the global post.
 */
function nvx_document_governance_request_path(): string {
	$uri = function_exists( 'nvx_governed_blog_runtime_original_request_uri' )
		? nvx_governed_blog_runtime_original_request_uri()
		: ( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/' );
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	$path = is_string( $path ) && '' !== $path ? $path : '/';
	$path = '/' . trim( $path, '/' );

	return '/' === $path ? '/' : $path . '/';
}

/**
 * Resolve a published post by its exact post_name slug across caches and fallback queries.
 *
 * Memoized per-request to avoid repeated DB queries when called from multiple filters.
 */
function nvx_document_governance_get_published_post_by_slug( string $slug ): ?WP_Post {
	static $cache = array();

	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}

	// Return cached result if available
	if ( isset( $cache[ $slug ] ) ) {
		return $cache[ $slug ];
	}

	$result = null;

	// Try get_page_by_path first (cache-aware).
	$post = get_page_by_path( $slug, OBJECT, 'post' );
	if ( $post instanceof WP_Post && 'publish' === $post->post_status && $slug === $post->post_name ) {
		$result = $post;
	}

	// Fallback to get_posts if first attempt failed.
	if ( null === $result ) {
		$posts = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		if ( ! empty( $posts ) && $posts[0] instanceof WP_Post && 'publish' === $posts[0]->post_status && $slug === $posts[0]->post_name ) {
			$result = $posts[0];
		}
	}

	// Final fallback is DB-authoritative. Do not re-enter get_post() here:
	// a stale persistent object cache is precisely the condition this fallback
	// must survive when a valid governed slug resolves to a neighbouring post.
	if ( null === $result ) {
		global $wpdb;
		if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'post' AND post_status = 'publish' LIMIT 1",
					$slug
				)
			);
			if (
				is_object( $row )
				&& isset( $row->ID, $row->post_name, $row->post_status, $row->post_type )
				&& (int) $row->ID > 0
				&& 'publish' === (string) $row->post_status
				&& 'post' === (string) $row->post_type
				&& $slug === (string) $row->post_name
			) {
				$found = new WP_Post( $row );
				// Repair the poisoned post cache so downstream consumers also see the corrected row
				clean_post_cache( (int) $row->ID );
				wp_cache_set( (int) $row->ID, $found, 'posts' );
				$result = $found;
			}
		}
	}

	// Cache the result for this request
	$cache[ $slug ] = $result;

	return $result;
}

/**
 * Bind an exact governed journal path to its published post before WP_Query runs.
 *
 * With a postname permalink structure WordPress may first parse a one-segment
 * route through a verbose page rule (`pagename`). If that intermediate context
 * survives into canonical/SEO resolution, a neighbouring singular can leak into
 * the rendered head even though a distinct published post exists for the path.
 * Resolve only version-governed journal slugs that have an exact published post;
 * every other request keeps WordPress core query behaviour unchanged.
 *
 * @param array<string,mixed> $query_vars Parsed request query variables.
 * @return array<string,mixed>
 */
function nvx_document_governance_bind_governed_blog_query( array $query_vars ): array {
	if ( is_admin() || wp_doing_ajax() || ! function_exists( 'nvx_seo_blog_post_metadata_catalog' ) ) {
		return $query_vars;
	}

	$path = nvx_document_governance_request_path();
	$slug = trim( $path, '/' );
	if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
		return $query_vars;
	}

	$catalog = nvx_seo_blog_post_metadata_catalog();
	if ( ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
		return $query_vars;
	}

	$post = nvx_document_governance_get_published_post_by_slug( $slug );
	if ( ! ( $post instanceof WP_Post ) ) {
		return $query_vars;
	}

	unset(
		$query_vars['pagename'],
		$query_vars['page_id'],
		$query_vars['attachment'],
		$query_vars['attachment_id']
	);
	$query_vars['name']      = $slug;
	$query_vars['post_type'] = 'post';
	$query_vars['p']         = (int) $post->ID;

	return $query_vars;
}
add_filter( 'request', 'nvx_document_governance_bind_governed_blog_query', PHP_INT_MAX );

/**
 * Guarantee the main query binds to the exact governed post before execution.
 */
function nvx_document_governance_bind_blog_pre_get_posts( WP_Query $query ): void {
	// Early exit if not applicable.
	if ( is_admin() || ! $query->is_main_query() || ! function_exists( 'nvx_seo_blog_post_metadata_catalog' ) ) {
		return;
	}

	$path = nvx_document_governance_request_path();
	$slug = trim( $path, '/' );

	// Validate slug format.
	if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
		return;
	}

	// Check if slug is in governed catalog.
	$catalog = nvx_seo_blog_post_metadata_catalog();
	if ( ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
		return;
	}

	// Resolve the exact published post.
	$post = nvx_document_governance_get_published_post_by_slug( $slug );
	if ( ! ( $post instanceof WP_Post ) ) {
		return;
	}

	// Bind query to exact post.
	$query->set( 'name', $slug );
	$query->set( 'post_type', 'post' );
	$query->set( 'p', (int) $post->ID );
	$query->set( 'pagename', '' );
	$query->set( 'page_id', 0 );
	$query->is_page     = false;
	$query->is_single   = true;
	$query->is_singular = true;
	$query->is_404      = false;
	$query->is_archive  = false;
	$query->is_home     = false;
}
add_action( 'pre_get_posts', 'nvx_document_governance_bind_blog_pre_get_posts', 1 );

/**
 * Resolve governed journal metadata by the requested slug rather than a mutable
 * global query object. This is the final head-contract guard against a stale
 * Yoast/indexable/global-post context leaking metadata from a neighbouring post.
 *
 * @return array{slug:string,path:string,metadata:array<string,mixed>}|null
 */
function nvx_document_governance_governed_blog_request(): ?array {
	if ( is_admin() || wp_doing_ajax() || is_search() || is_feed() || is_preview() ) {
		return null;
	}

	if ( ! function_exists( 'nvx_seo_blog_post_metadata_catalog' ) ) {
		return null;
	}

	$path = nvx_document_governance_request_path();
	$slug = trim( $path, '/' );
	if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
		return null;
	}

	$catalog = nvx_seo_blog_post_metadata_catalog();
	if ( ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
		return null;
	}

	// Request-path authority must survive a stale WordPress 404/query context.
	// Only an exact published post may claim that authority, so unknown catalog
	// entries still retain normal 404 behavior.
	$post = nvx_document_governance_get_published_post_by_slug( $slug );
	if ( ! ( $post instanceof WP_Post ) ) {
		return null;
	}

	return array(
		'slug'     => $slug,
		'path'     => '/' . $slug . '/',
		'metadata' => $catalog[ $slug ],
	);
}

/**
 * Keep an already-canonical governed journal route bound to its own published post.
 *
 * WordPress redirect_canonical can occasionally infer a neighbouring singular as
 * the destination when runtime/indexable state is stale. For a route that is
 * already the exact canonical path of a separately published governed post, a
 * cross-post redirect is never valid. Non-canonical forms (for example a missing
 * trailing slash), non-governed routes and unpublished posts retain core behavior.
 *
 * @param string|false $redirect_url  Canonical redirect proposed by WordPress.
 * @param string       $requested_url Requested absolute URL.
 * @return string|false
 */
function nvx_document_governance_preserve_exact_governed_blog_route( $redirect_url, $requested_url ) {
	if ( is_admin() || wp_doing_ajax() || ! function_exists( 'nvx_seo_blog_post_metadata_catalog' ) ) {
		return $redirect_url;
	}

	$raw_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$raw_path = wp_parse_url( $raw_uri, PHP_URL_PATH );
	if ( ! is_string( $raw_path ) || '' === $raw_path ) {
		return $redirect_url;
	}

	$normalized_path = nvx_document_governance_request_path();
	if ( $raw_path !== $normalized_path ) {
		return $redirect_url;
	}

	$slug = trim( $normalized_path, '/' );
	if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
		return $redirect_url;
	}

	$catalog = nvx_seo_blog_post_metadata_catalog();
	if ( ! isset( $catalog[ $slug ] ) || ! is_array( $catalog[ $slug ] ) ) {
		return $redirect_url;
	}

	$post = nvx_document_governance_get_published_post_by_slug( $slug );
	if ( ! ( $post instanceof WP_Post ) ) {
		return $redirect_url;
	}

	unset( $requested_url );
	return false;
}
add_filter( 'redirect_canonical', 'nvx_document_governance_preserve_exact_governed_blog_route', PHP_INT_MAX, 2 );

/** Final governed journal title from the requested public route. */
function nvx_document_governance_governed_blog_title( $title ) {
	$request = nvx_document_governance_governed_blog_request();
	if ( null === $request ) {
		return $title;
	}

	$value = trim( (string) ( $request['metadata']['title'] ?? '' ) );
	return '' !== $value ? $value : $title;
}
add_filter( 'wpseo_title', 'nvx_document_governance_governed_blog_title', PHP_INT_MAX );
add_filter( 'pre_get_document_title', 'nvx_document_governance_governed_blog_title', PHP_INT_MAX );
add_filter( 'wpseo_opengraph_title', 'nvx_document_governance_governed_blog_title', PHP_INT_MAX );
add_filter( 'wpseo_twitter_title', 'nvx_document_governance_governed_blog_title', PHP_INT_MAX );

/** Final governed journal description from the requested public route. */
function nvx_document_governance_governed_blog_description( $description ) {
	$request = nvx_document_governance_governed_blog_request();
	if ( null === $request ) {
		return $description;
	}

	$value = trim( (string) ( $request['metadata']['description'] ?? '' ) );
	return '' !== $value ? $value : $description;
}
add_filter( 'wpseo_metadesc', 'nvx_document_governance_governed_blog_description', PHP_INT_MAX );
add_filter( 'wpseo_opengraph_desc', 'nvx_document_governance_governed_blog_description', PHP_INT_MAX );
add_filter( 'wpseo_twitter_description', 'nvx_document_governance_governed_blog_description', PHP_INT_MAX );

/**
 * Final Open Graph URL for governed journal routes.
 *
 * Staging remains noindex and intentionally advertises the production URL for
 * social previews; production emits its own self URL. Both are derived from the
 * requested route, never from a mutable queried-object/indexable context.
 */
function nvx_document_governance_governed_blog_opengraph_url( $url ) {
	$request = nvx_document_governance_governed_blog_request();
	if ( null === $request ) {
		return $url;
	}

	return home_url( $request['path'] );
}
add_filter( 'wpseo_opengraph_url', 'nvx_document_governance_governed_blog_opengraph_url', PHP_INT_MAX );

/**
 * Whether the current strategy route is approved for publication.
 */
function nvx_document_governance_is_approved_strategy_route(): bool {
	if ( ! function_exists( 'nvx_strategy_current_page_key' ) || ! function_exists( 'nvx_strategy_page_catalog' ) ) {
		return false;
	}

	$key     = nvx_strategy_current_page_key();
	$catalog = nvx_strategy_page_catalog();

	return null !== $key
		&& 'approved_for_publication' === ( $catalog[ $key ]['review_status'] ?? null );
}

/**
 * Production-host URL for social previews on non-production environments.
 *
 * Staging stays noindex; approved public routes may still expose the production
 * URL for Open Graph. Protected working-name routes return empty.
 */
function nvx_document_governance_production_public_url(): string {
	$is_strategy_page = function_exists( 'nvx_strategy_current_page_key' ) && null !== nvx_strategy_current_page_key();
	$is_protected     = $is_strategy_page && ! nvx_document_governance_is_approved_strategy_route();
	if ( $is_protected || is_404() || is_search() || is_preview() ) {
		return '';
	}

	if ( ! is_front_page() && ! is_home() && ! is_singular() ) {
		return '';
	}

	$path = function_exists( 'nvx_seo_current_path' )
		? nvx_seo_current_path()
		: nvx_document_governance_request_path();

	return home_url( $path );
}

/**
 * Open Graph URL on non-production: point social previews at the production host
 * for approved routes. HTML link[rel=canonical] remains owned by this module's
 * wp_head emission — never re-attach production host policy to wpseo_canonical.
 *
 * @param mixed $url Yoast Open Graph URL.
 * @return mixed
 */
function nvx_document_governance_nonproduction_opengraph_url( $url ) {
	if ( ! function_exists( 'nvx_seo_is_nonproduction_environment' ) || ! nvx_seo_is_nonproduction_environment() ) {
		return $url;
	}

	$production = nvx_document_governance_production_public_url();
	return '' !== $production ? $production : $url;
}
add_filter( 'wpseo_opengraph_url', 'nvx_document_governance_nonproduction_opengraph_url', 1000 );

/**
 * Resolve the canonical URL without changing the staging robots policy.
 *
 * Always returns a non-empty absolute URL for public HTML so the rendered
 * document never ships without a canonical link.
 */
function nvx_document_governance_canonical_url(): string {
	$request = nvx_document_governance_governed_blog_request();
	if ( null !== $request ) {
		if ( function_exists( 'nvx_governed_blog_runtime_context' ) && function_exists( 'nvx_governed_blog_html_canonical_url' ) ) {
			$context = nvx_governed_blog_runtime_context();
			if ( is_array( $context ) ) {
				return nvx_governed_blog_html_canonical_url( $context );
			}
		}
		return home_url( $request['path'] );
	}

	$url = '';

	if ( ! is_404() && function_exists( 'nvx_seo_current_canonical_url' ) ) {
		$url = trim( (string) nvx_seo_current_canonical_url() );
	}

	if ( '' === $url && ! is_404() && ! is_front_page() && ! is_search() ) {
		$page_id   = (int) get_queried_object_id();
		$permalink = $page_id > 0 ? get_permalink( $page_id ) : '';
		$url       = is_string( $permalink ) ? trim( $permalink ) : '';
	}

	if ( '' === $url && ! is_404() && ! is_search() ) {
		$url = home_url( '/' );
	}

	return $url;
}

/**
 * Emit document contract pieces: contract marker + exactly one canonical.
 */
function nvx_document_governance_print_head_contract(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
		return;
	}

	$canonical = nvx_document_governance_canonical_url();
	if ( '' !== $canonical ) {
		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
	}
	echo '<meta name="nvx-document-contract" content="1" />' . "\n";
}
add_action( 'wp_head', 'nvx_document_governance_print_head_contract', 2 );

/**
 * Enqueue the platform accessibility/runtime layer.
 */
function nvx_document_governance_enqueue_assets(): void {
	$uri = get_template_directory_uri();

	if ( ! function_exists( 'nvx_theme_public_delivers_inline_styles' ) || ! nvx_theme_public_delivers_inline_styles() ) {
		wp_enqueue_style(
			'nvx-accessibility-governance',
			$uri . '/assets/css/nvx-accessibility-governance.css',
			array( 'nvx-header', 'nvx-footer' ),
			function_exists( 'nvx_asset_version' )
				? nvx_asset_version( 'assets/css/nvx-accessibility-governance.css' )
				: NVX_THEME_VERSION
		);
	}

	wp_enqueue_script(
		'nvx-runtime-governance',
		$uri . '/assets/js/nvx-runtime-governance.js',
		array( 'nvx-main' ),
		function_exists( 'nvx_asset_version' )
			? nvx_asset_version( 'assets/js/nvx-runtime-governance.js' )
				: NVX_THEME_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	$config = array(
		'mobileNavId' => 'nvx-mobile-nav',
	);

	$encoded = wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
	if ( ! is_string( $encoded ) ) {
		$encoded = '{}';
	}

	wp_add_inline_script(
		'nvx-runtime-governance',
		'window.nvxRuntimeGovernance=' . $encoded . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'nvx_document_governance_enqueue_assets', 100 );