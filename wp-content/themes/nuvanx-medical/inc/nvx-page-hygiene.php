<?php
/**
 * NUVANX canonical page hygiene for staging/production indexing.
 *
 * - Redirect superseded cookie documents to the Complianz EU statement.
 * - Keep transactional / incomplete-evidence pages out of search results.
 * - Keep published routes addressable; editorial readiness governs indexing,
 *   not whether a published WordPress page returns HTTP 200.
 * - Does not print schema or CSS.
 *
 * @package NUVANX_Medical
 */

defined( 'ABSPATH' ) || exit;



/**
 * Redirect superseded cookie documents to the Complianz EU statement (page 577).
 */
function nvx_redirect_superseded_legal_pages(): void {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$page_id = is_page() ? (int) get_queried_object_id() : 0;
	$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path    = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );

	if ( in_array( $page_id, array( 18, 31 ), true ) || in_array( $path, array( 'politica-de-cookies', 'mas-informacion-sobre-las-cookies' ), true ) ) {
		$target = function_exists( 'get_permalink' ) ? get_permalink( 577 ) : '';
		if ( ! is_string( $target ) || '' === $target ) {
			$target = home_url( '/politica-de-cookies-ue/' );
		}

		if ( is_string( $target ) && '' !== $target ) {
			wp_safe_redirect( $target, 301, 'NUVANX' );
			exit;
		}
	}
}
add_action( 'template_redirect', 'nvx_redirect_superseded_legal_pages', 1 );

/**
 * Canonical valoración landing is /madrid/valoracion/.
 *
 * Bare /valoracion and accented /valoración (404 otherwise) redirect early
 * from the request path so accents never depend on page lookup.
 */
function nvx_redirect_valoracion_aliases(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$path = '/' . trim( rawurldecode( $path ), '/' ) . '/';
	$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );

	$aliases = array(
		'/valoracion/',
		'/valoración/',
		'/valoracion-medica/',
		'/consulta-medica/',
		'/consulta-médica/',
		'/consultamedica/',
	);

	if ( ! in_array( $path, $aliases, true ) ) {
		return;
	}

	// Already on the nested canonical path.
	if ( 0 === strpos( $path, '/madrid/valoracion/' ) ) {
		return;
	}

	// Preserve query strings (gclid, UTM, etc.) securely using the request context.
	$context    = function_exists( 'nvx_theme_request_context' ) ? nvx_theme_request_context() : null;
	$query_args = is_array( $context ) ? $context['query_args'] : array();
	$target     = home_url( '/madrid/valoracion/' );
	if ( ! empty( $query_args ) ) {
		$target = add_query_arg( urlencode_deep( $query_args ), $target );
	}

	wp_safe_redirect( $target, 301, 'NUVANX' );
	exit;
}
add_action( 'template_redirect', 'nvx_redirect_valoracion_aliases', 0 );

/**
 * Redirect the historic short Goya alias to the canonical clinic route owned by clinics.json.
 */
function nvx_redirect_goya_alias(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$path = '/' . trim( rawurldecode( $path ), '/' ) . '/';

	// Exact match for the short alias only.
	if ( '/medicina-estetica-goya/' !== $path ) {
		return;
	}

	$clinics        = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	$canonical_path = trim( (string) ( $clinics['goya']['landing_path'] ?? '' ) );
	if ( '' === $canonical_path ) {
		return;
	}

	// Preserve query strings (gclid, UTM, etc.) - parse and reconstruct.
	$parsed_uri   = wp_parse_url( $uri );
	$query_params = array();

	if ( isset( $parsed_uri['query'] ) && '' !== $parsed_uri['query'] ) {
		parse_str( $parsed_uri['query'], $query_params );
	}

	$target = home_url( $canonical_path );

	if ( ! empty( $query_params ) ) {
		$target = add_query_arg( $query_params, $target );
	}

	wp_safe_redirect( $target, 301, 'NUVANX' );
	exit;
}
add_action( 'template_redirect', 'nvx_redirect_goya_alias', 1 );

/**
 * Public slugs that must not stay as soft 404s while the CMS row is unpublished.
 *
 * The redirect is skipped the moment a published page or governed post exists,
 * so a later medical-review publish reclaims the canonical URL automatically.
 *
 * @return array<string,string> slug => target path
 */
function nvx_unpublished_public_route_redirects(): array {
	return array(
		'intrusismo-tratamientos-inyectables-riesgos' => '/blog/',
		'acido-hialuronico-relleno-madrid'            => '/medicina-estetica/',
	);
}

/**
 * Whether a published singular already owns this public slug.
 */
function nvx_published_singular_exists_for_slug( string $slug ): bool {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return false;
	}

	if ( function_exists( 'nvx_governed_blog_runtime_db_post_by_slug' ) ) {
		$post = nvx_governed_blog_runtime_db_post_by_slug( $slug );
		if ( $post instanceof WP_Post ) {
			return true;
		}
	}

	foreach ( array( 'page', 'post' ) as $type ) {
		$found = get_page_by_path( $slug, OBJECT, $type );
		if ( $found instanceof WP_Post && 'publish' === $found->post_status ) {
			return true;
		}
	}

	return false;
}

/**
 * 301 unpublished-but-known public slugs so Google does not keep a soft 404.
 */
function nvx_redirect_unpublished_public_routes(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$slug = sanitize_title( trim( rawurldecode( $path ), '/' ) );
	$map  = nvx_unpublished_public_route_redirects();

	if ( ! isset( $map[ $slug ] ) || nvx_published_singular_exists_for_slug( $slug ) ) {
		return;
	}

	$context    = function_exists( 'nvx_theme_request_context' ) ? nvx_theme_request_context() : null;
	$query_args = is_array( $context ) ? $context['query_args'] : array();
	$target     = home_url( $map[ $slug ] );
	if ( ! empty( $query_args ) ) {
		$target = add_query_arg( urlencode_deep( $query_args ), $target );
	}

	wp_safe_redirect( $target, 301, 'NUVANX' );
	exit;
}
add_action( 'template_redirect', 'nvx_redirect_unpublished_public_routes', 0 );

/**
 * Transactional pages that must not pass PageRank via links (noindex + nofollow).
 *
 * Resolved by slug so IDs may differ across environments.
 *
 * @return int[]
 */
function nvx_nofollow_page_ids() {
	$ids       = array();
	$thank_you = function_exists( 'nvx_page_id_by_slug' )
		? nvx_page_id_by_slug( 'gracias' )
		: 0;
	if ( $thank_you > 0 ) {
		$ids[] = $thank_you;
	}

	/**
	 * Filter page IDs that receive noindex, nofollow.
	 *
	 * @param int[] $ids Page IDs.
	 */
	return array_values( array_unique( array_map( 'intval', apply_filters( 'nvx_nofollow_page_ids', $ids ) ) ) );
}

/**
 * Comparison articles retained for internal medical/evidence review only.
 *
 * They are intentionally not deleted here: editorial and clinical teams need a
 * reversible review path. Until a reviewer approves substantiated, non-
 * denigrating copy, they cannot be surfaced in public archive, search or XML
 * sitemap listings.
 *
 * @return string[]
 */
function nvx_quarantined_comparison_post_slugs(): array {
	return array(
		'exion-face-vs-hifu-ultherapy-thermage-regeneracion-endogena',
		'exion-body-vs-coolsculpting-morpheus8-lipolisis-retraccion',
		'exion-fractional-vs-morpheus8-potenza-ia-vs-trauma',
		'emfusion-vs-hydrafacial-dermapen-microcanales-acusticos',
		'protocolos-combinados-ecosistema-nuvanx-exion-endolift-emfusion',
	);
}

/**
 * Resolve quarantined post IDs without assuming fixed database IDs.
 *
 * Uses a single WP_Query with post_name__in instead of N individual
 * get_page_by_path() calls — reduces DB round-trips from 5 to 1.
 *
 * @return int[]
 */
function nvx_quarantined_comparison_post_ids(): array {
	static $ids = null;
	if ( is_array( $ids ) ) {
		return $ids;
	}

	$slugs = nvx_quarantined_comparison_post_slugs();
	if ( empty( $slugs ) ) {
		return $ids = array();
	}

	$query = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'any',
			'post_name__in'          => $slugs,
			'posts_per_page'         => count( $slugs ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
		)
	);

	return $ids = array_values( array_map( 'intval', (array) $query->posts ) );
}



/**
 * Keep pending comparison content out of public post collections.
 */
function nvx_exclude_quarantined_comparison_posts( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( ! $query->is_home() && ! $query->is_archive() && ! $query->is_search() && ! $query->is_feed() ) {
		return;
	}

	$ids = nvx_quarantined_comparison_post_ids();
	if ( array() === $ids ) {
		return;
	}

	$existing = $query->get( 'post__not_in' );
	$existing = is_array( $existing ) ? $existing : array();
	$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $ids ) ) ) );
}
add_action( 'pre_get_posts', 'nvx_exclude_quarantined_comparison_posts', 30 );

/**
 * Resolve superseded legal-document page IDs by slug.
 *
 * These URLs permanently redirect to the canonical Complianz EU cookie policy
 * and therefore must not remain discoverable in XML sitemaps.
 *
 * @return int[]
 */
function nvx_superseded_legal_page_ids(): array {
	$ids = array();
	foreach ( array( 'politica-de-cookies', 'mas-informacion-sobre-las-cookies' ) as $slug ) {
		$page_id = function_exists( 'nvx_page_id_by_slug' ) ? nvx_page_id_by_slug( $slug ) : 0;
		if ( $page_id > 0 ) {
			$ids[] = $page_id;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Page IDs that are noindex but should remain navigable (e.g., cookie policy).
 *
 * These pages are excluded from sitemaps and search indexing but intentionally
 * kept in navigation menus for legal compliance and user access.
 *
 * @return int[]
 */
function nvx_noindex_but_navigable_page_ids(): array {
	$ids = array();

	// Complianz EU cookie policy - noindex but navigable
	$cookie_policy_id = function_exists( 'nvx_page_id_by_slug' )
		? nvx_page_id_by_slug( 'politica-de-cookies-ue' )
		: 0;

	if ( $cookie_policy_id > 0 ) {
		$ids[] = $cookie_policy_id;
	}

	/**
	 * Filter page IDs that are noindex but should remain navigable.
	 *
	 * @param int[] $ids Page IDs.
	 */
	return array_values( array_unique( array_map( 'intval', apply_filters( 'nvx_noindex_but_navigable_page_ids', $ids ) ) ) );
}

/**
 * Collects page and post IDs that should be excluded from public indexing.
 *
 * Patient cases are no longer part of this collection: the five repository-
 * governed clinical sequences have documented publication consent and explicit
 * editorial approval for public indexing as of the 2026-08-30 release.
 *
 * @return int[] Unique IDs excluded from sitemaps and other public index listings.
 */
function nvx_noindex_page_ids() {
	$ids = nvx_nofollow_page_ids();
	$ids = array_merge(
		$ids,
		nvx_quarantined_comparison_post_ids(),
		nvx_superseded_legal_page_ids(),
		nvx_noindex_but_navigable_page_ids()
	);

	// Retired strategy pages: exclude from sitemaps since they only redirect.
	if ( function_exists( 'nvx_retired_strategy_page_ids' ) ) {
		$ids = array_merge( $ids, nvx_retired_strategy_page_ids() );
	}

	/**
	 * Filter page IDs forced to noindex (sitemap exclusion + robots).
	 *
	 * @param int[] $ids Page IDs.
	 */
	return array_values( array_unique( array_map( 'intval', apply_filters( 'nvx_noindex_page_ids', $ids ) ) ) );
}



/**
 * Exclude sensitive pages from the Yoast XML sitemap by post ID list.
 *
 * @param int[] $excluded_ids Existing excluded IDs.
 * @return int[]
 */
function nvx_exclude_sensitive_pages_from_sitemap_ids( $excluded_ids ) {
	$excluded_ids = is_array( $excluded_ids ) ? $excluded_ids : array();

	return array_values( array_unique( array_merge( $excluded_ids, nvx_noindex_page_ids() ) ) );
}
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', 'nvx_exclude_sensitive_pages_from_sitemap_ids' );

/**
 * Keep deliberately noindex archive taxonomies out of Yoast XML sitemaps.
 *
 * @param bool   $excluded Existing Yoast exclusion state.
 * @param string $taxonomy Taxonomy name.
 * @return bool
 */
function nvx_exclude_noindex_taxonomies_from_yoast_sitemap( $excluded, $taxonomy ): bool {
	if ( in_array( (string) $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		return true;
	}

	return (bool) $excluded;
}
add_filter( 'wpseo_sitemap_exclude_taxonomy', 'nvx_exclude_noindex_taxonomies_from_yoast_sitemap', 10, 2 );

/**
 * Exclude sensitive pages from the WordPress Core XML sitemap.
 *
 * @param array  $args      Query arguments for the sitemap posts query.
 * @param string $post_type Post type name.
 * @return array
 */
function nvx_exclude_sensitive_pages_from_core_sitemap( $args, $post_type ) {
	unset( $post_type );
	$excluded = nvx_noindex_page_ids();
	if ( ! empty( $excluded ) ) {
		$args['post__not_in'] = isset( $args['post__not_in'] ) ? array_merge( (array) $args['post__not_in'], $excluded ) : $excluded;
	}
	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'nvx_exclude_sensitive_pages_from_core_sitemap', 10, 2 );

/**
 * Filters sitemap entries for content marked as noindex.
 *
 * @param array|false $url Sitemap URL data.
 * @param string      $type Sitemap object type.
 * @param WP_Post     $post Content associated with the sitemap entry.
 * @return array|false The sitemap URL data, or false when the content is marked as noindex.
 */
function nvx_filter_sitemap_entry_sensitive_pages( $url, $type, $post ) {
	unset( $type );
	if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
		return $url;
	}

	if ( in_array( (int) $post->ID, nvx_noindex_page_ids(), true ) ) {
		return false;
	}

	return $url;
}
add_filter( 'wpseo_sitemap_entry', 'nvx_filter_sitemap_entry_sensitive_pages', 20, 3 );

/**
 * Keep XML sitemaps out of SiteGround Dynamic and File-based cache.
 *
 * Sitemap membership is derived from the governed publication manifest and
 * Yoast indexables during each deploy. Serving a stale XML response after
 * that reconciliation creates a public contradiction even when page HTML is
 * intentionally cacheable. SiteGround Optimizer exposes this filter before it
 * emits its X-Cache-Enabled header; the route list is deliberately exact.
 *
 * @param mixed $urls Existing SiteGround Optimizer exclusion fragments.
 * @return string[]
 */
function nvx_exclude_publication_sitemaps_from_sg_cache( $urls ): array {
	$urls = is_array( $urls ) ? $urls : array();
	$required = array( 'sitemap_index.xml', 'page-sitemap.xml', 'post-sitemap.xml' );

	return array_values( array_unique( array_merge( $urls, $required ) ) );
}
add_filter( 'sgo_exclude_urls_from_cache', 'nvx_exclude_publication_sitemaps_from_sg_cache', 100 );

/**
 * Rewrites absolute production URLs to the current staging host on staging2.
 *
 * @param mixed $content Content whose production URLs should be rewritten.
 * @return mixed The content with production URLs rewritten, or the original value when rewriting is not applicable.
 */
function nvx_normalize_staging2_internal_links( $content ) {
	if ( ! is_string( $content ) || '' === $content || ! function_exists( 'nvx_environment_is_staging2' ) || ! nvx_environment_is_staging2() ) {
		return $content;
	}

	$staging_home = untrailingslashit( home_url( '/' ) );
	$hosts        = array( 'www.nuvanx.com', 'nuvanx.com' );
	$schemes      = array( 'https', 'http' );

	foreach ( $schemes as $scheme ) {
		foreach ( $hosts as $host ) {
			$content = str_ireplace( $scheme . '://' . $host, $staging_home, $content );
		}
	}

	return $content;
}
add_filter( 'the_content', 'nvx_normalize_staging2_internal_links', NVX_HOOK_PRIO_INTERNAL_LINKS );

/**
 * Remove sensitive pages from navigation menus automatically.
 *
 * Public legal pages may be noindex for search governance while still needing
 * direct navigation access, so they are explicitly excluded from this hide-list.
 * Public patient cases remain visible because they are no longer in the noindex set.
 *
 * @param array $items Array of menu items.
 * @return array
 */
function nvx_exclude_sensitive_pages_from_menus( $items ) {
	if ( ! is_array( $items ) ) {
		return $items;
	}

	$noindex_ids       = nvx_noindex_page_ids();
	$navigable_ids     = nvx_noindex_but_navigable_page_ids();
	$menu_excluded_ids = array_values( array_diff( $noindex_ids, $navigable_ids ) );
	$cases_id          = function_exists( 'nvx_page_id_by_slug' ) ? nvx_page_id_by_slug( 'casos-de-pacientes' ) : 0;
	$cases_public      = $cases_id > 0 && ! in_array( $cases_id, $noindex_ids, true );

	foreach ( $items as $key => $item ) {
		$is_blocked_post = isset( $item->type, $item->object_id )
			&& 'post_type' === $item->type
			&& in_array( (int) $item->object_id, $menu_excluded_ids, true );

		$is_blocked_cases_url = false;
		if ( ! $cases_public && isset( $item->url ) && is_string( $item->url ) ) {
			$path                 = (string) wp_parse_url( $item->url, PHP_URL_PATH );
			$path                 = '/' . trim( $path, '/' ) . '/';
			$is_blocked_cases_url = '/casos-de-pacientes/' === $path;
		}

		if ( $is_blocked_post || $is_blocked_cases_url ) {
			unset( $items[ $key ] );
		}
	}

	return $items;
}
add_filter( 'wp_get_nav_menu_items', 'nvx_exclude_sensitive_pages_from_menus', 20 );

/**
 * Approved legal-framework note for the privacy and legal-notice pages.
 */
function nvx_legal_framework_note_markup(): string {
	$message = __( 'El artículo 13 del RGPD exige facilitar la información correspondiente cuando se recogen datos personales, y el artículo 10 de la LSSI exige que determinada información del prestador sea accesible de manera permanente, fácil, directa y gratuita.', 'nuvanx-medical' );

	return '<aside class="nvx-legal-context" role="note" aria-label="' . esc_attr__( 'Marco normativo', 'nuvanx-medical' ) . '"><p><strong>'
		. esc_html__( 'Marco normativo.', 'nuvanx-medical' )
		. '</strong> ' . esc_html( $message ) . '</p></aside>';
}


/**
 * Resolve a published page ID by slug (environment-safe; no hard-coded IDs).
 */
function nvx_page_id_by_slug( string $slug ): int {
	static $cache = array();
	$slug         = trim( $slug, '/' );
	if ( '' === $slug ) {
		return 0;
	}
	if ( array_key_exists( $slug, $cache ) ) {
		return $cache[ $slug ];
	}
	$page           = get_page_by_path( $slug, OBJECT, 'page' );
	$cache[ $slug ] = $page instanceof WP_Post ? (int) $page->ID : 0;
	return $cache[ $slug ];
}

/**
 * Determines whether the current page has one of the specified slugs.
 *
 * @param string|string[] $slugs Page slug or list of page slugs to match.
 * @return bool True if the current page slug matches one of the specified slugs, false otherwise.
 */
function nvx_is_page_slug( $slugs ): bool {
	if ( ! is_page() ) {
		return false;
	}
	$current = (string) get_post_field( 'post_name', get_queried_object_id() );
	$slugs   = (array) $slugs;
	return in_array( $current, $slugs, true );
}

/**
 * Applies production safeguards to legal and EXION®-related page content.
 *
 * @param string $content HTML content to process.
 * @return string The content with legal placeholders removed, regulatory context added to applicable pages, and Morpheus comparisons or unapproved EXION® prices replaced.
 */
function nvx_apply_production_business_rules( $content ) {
	if ( ! is_string( $content ) || '' === trim( $content ) ) {
		return $content;
	}

	// Privacidad y Aviso Legal: regulatory context + single H1 (slug-based).
	if ( nvx_is_page_slug( array( 'politica-privacidad', 'aviso-legal' ) ) ) {
		$content = preg_replace( '/<div\b[^>]*\bnvx-legal-placeholder\b[^>]*>[\s\S]*?<\/div>/iu', '', $content ) ?? $content;
		if ( false === strpos( $content, 'El artículo 13 del RGPD' ) ) {
			$content .= nvx_legal_framework_note_markup();
		}
	}

	// EXION® pages: strip unapproved Morpheus8 comparatives and bare euro prices in copy.
	if ( false !== stripos( $content, 'EXION®' ) || false !== stripos( $content, 'Morpheus' ) ) {
		$content = preg_replace( '/<details[^>]*>.*?Morpheus.*?<\/details>/is', '', $content ) ?? $content;
		$content = preg_replace( '/(EXION®[^<]*?)\b\d{3,4}\s*€/i', '$1 (Presupuesto tras valoración)', $content ) ?? $content;
	}

	return $content;
}
add_filter( 'the_content', 'nvx_apply_production_business_rules', NVX_HOOK_PRIO_BUSINESS_RULES );

/**
 * Removes quantitative trust-badge sections from content.
 *
 * @param string $content Post content.
 * @return string Content without quantitative trust-badge sections.
 */
function nvx_remove_unverified_quantitative_trust_badges( string $content ): string {
	if ( false === strpos( $content, 'nvx-trust-badges' ) ) {
		return $content;
	}

	$filtered = preg_replace(
		'#<section\b[^>]*\bnvx-trust-badges\b[^>]*>.*?</section>#isu',
		'',
		$content
	);

	return is_string( $filtered ) ? $filtered : $content;
}
add_filter( 'the_content', 'nvx_remove_unverified_quantitative_trust_badges', NVX_HOOK_PRIO_TRUST_BADGES );

/**
 * Sanitize Complianz cookie banner HTML to prevent unreplaced placeholder tokens ({title}, {url})
 * from reaching the accessibility tree (WCAG 2.4.4, 4.1.2).
 *
 * @param string $html Complianz cookie banner HTML or template markup.
 * @return string Sanitized markup.
 */
function nvx_sanitize_complianz_banner_html( string $html ): string {
	if ( false === strpos( $html, '{title}' ) && false === strpos( $html, '{url}' ) ) {
		return $html;
	}

	// Substitute {title} with appropriate document titles based on destination URL or context.
	$html = (string) preg_replace_callback(
		'/<a\s+([^>]*?)href=([\'"])(.*?)\2([^>]*)>(.*?)<\/a>/is',
		static function ( array $matches ): string {
			$attr_before = $matches[1];
			$quote       = $matches[2];
			$href        = $matches[3];
			$attr_after  = $matches[4];
			$inner_text  = $matches[5];

			if ( '#' === $href && false !== strpos( $attr_before, 'data-relative_url' ) ) {
				if ( false !== strpos( $inner_text, 'Política de privacidad' ) ) {
					$href = function_exists( 'home_url' ) ? home_url( '/politica-privacidad/' ) : '#';
				} elseif ( false !== strpos( $inner_text, 'Política de cookies' ) ) {
					$href = function_exists( 'home_url' ) ? home_url( '/politica-de-cookies-ue/' ) : '#';
				} elseif ( false !== strpos( $inner_text, 'Aviso legal' ) ) {
					$href = function_exists( 'home_url' ) ? home_url( '/aviso-legal/' ) : '#';
				} elseif ( false !== strpos( $inner_text, 'Administrar opciones' ) || false !== strpos( $inner_text, 'Gestionar' ) ) {
					$href = '#'; // Keep as hash for JS-managed consent dialogs
				} else {
					$href = '#'; // Keep as hash for other JS-managed links
				}
			}

			if ( false !== strpos( $inner_text, '{title}' ) ) {
				$title = 'Política de cookies';
				if ( false !== strpos( $href, 'privacidad' ) || false !== strpos( $href, 'privacy' ) ) {
					$title = 'Política de privacidad';
				} elseif ( false !== strpos( $href, 'aviso-legal' ) || false !== strpos( $href, 'legal' ) ) {
					$title = 'Aviso legal';
				}
				$inner_text = str_replace( '{title}', $title, $inner_text );
			}

			// Replace href="#" with actual URLs to prevent same-origin network errors
			// Complianz uses data-relative_url attributes for JavaScript replacement,
			// but href="#" can cause network errors when links are interacted with
			if ( '#' === $href && false !== strpos( $attr_before, 'data-relative_url' ) ) {
				if ( false !== strpos( $inner_text, 'Política de cookies' ) || false !== strpos( $inner_text, 'Política de privacidad' ) ) {
					$href = function_exists( 'home_url' ) ? home_url( '/politica-de-cookies-ue/' ) : '#';
				} elseif ( false !== strpos( $inner_text, 'Aviso legal' ) ) {
					$href = function_exists( 'home_url' ) ? home_url( '/aviso-legal/' ) : '#';
				} elseif ( false !== strpos( $inner_text, 'Administrar opciones' ) || false !== strpos( $inner_text, 'Gestionar' ) ) {
					$href = '#'; // Keep as hash for JS-managed consent dialogs
				} else {
					$href = '#'; // Keep as hash for other JS-managed links
				}
			}

			return '<a ' . $attr_before . 'href=' . $quote . $href . $quote . $attr_after . '>' . $inner_text . '</a>';
		},
		$html
	);

	// Fallback replacement for any remaining bare {title}
	return str_replace( '{title}', 'Política de cookies', $html );
}
add_filter( 'cmplz_banner_html', 'nvx_sanitize_complianz_banner_html', 20 );
add_filter( 'cmplz_template', 'nvx_sanitize_complianz_banner_html', 20 );

/**
 * Checks if the current page has a standard wrapper to avoid duplicate regex matching.
 *
 * @return bool True if it has the standard wrapper.
 */
function nvx_page_has_standard_wrapper(): bool {
	static $has_wrapper = null;
	if ( null !== $has_wrapper ) {
		return $has_wrapper;
	}

	$has_wrapper = false;
	if ( is_page() ) {
		$post_id     = get_the_ID();
		$content     = get_post_field( 'post_content', $post_id );
		$has_wrapper = (bool) preg_match( '/class=["\'][^"\']*entry-content[^"\']*nvx-prose/iu', (string) $content );
	}
	return $has_wrapper;
}
