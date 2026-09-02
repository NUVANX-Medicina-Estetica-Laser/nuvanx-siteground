<?php
/**
 * Canonical single-post entrypoint.
 *
 * Rebind version-governed journal routes to the exact published post before the
 * shared single template renders the document head. This is a final runtime
 * guard for hosts where a stale singular query/indexable can survive earlier
 * request parsing and leak a neighbouring article's canonical metadata.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

// Load the DB-authoritative guard at the final single-post entrypoint. This is
// intentionally before get_header()/Yoast so both the body loop and document
// head are rebuilt from the actual governed public path.
require_once __DIR__ . '/inc/nvx-governed-blog-runtime.php';

global $wp_query;

$nvx_slug       = '';
$nvx_exact_post = null;

// The actual public request path is authoritative for version-governed journal
// routes. Never prefer a stale WP_Query name here: that was able to rebind the
// requested matrix article to a neighbouring post before get_header()/Yoast ran.
if ( function_exists( 'nvx_seo_blog_post_metadata_catalog' ) ) {
	$nvx_request_slug = function_exists( 'nvx_governed_blog_runtime_request_slug' )
		? nvx_governed_blog_runtime_request_slug()
		: '';
	if ( '' === $nvx_request_slug ) {
		$nvx_uri          = function_exists( 'nvx_theme_request_context' ) ? nvx_theme_request_context()['uri'] : '';
		$nvx_path         = wp_parse_url( $nvx_uri, PHP_URL_PATH );
		$nvx_path         = is_string( $nvx_path ) ? '/' . trim( $nvx_path, '/' ) . '/' : '';
		$nvx_request_slug = trim( $nvx_path, '/' );
	}

	if ( '' !== $nvx_request_slug && false === strpos( $nvx_request_slug, '/' ) ) {
		$nvx_catalog = nvx_seo_blog_post_metadata_catalog();
		if ( isset( $nvx_catalog[ $nvx_request_slug ] ) && is_array( $nvx_catalog[ $nvx_request_slug ] ) ) {
			$candidate = function_exists( 'nvx_governed_blog_runtime_db_post_by_slug' )
				? nvx_governed_blog_runtime_db_post_by_slug( $nvx_request_slug )
				: ( function_exists( 'nvx_document_governance_get_published_post_by_slug' )
					? nvx_document_governance_get_published_post_by_slug( $nvx_request_slug )
					: get_page_by_path( $nvx_request_slug, OBJECT, 'post' ) );

			if (
				$candidate instanceof WP_Post
				&& 'publish' === $candidate->post_status
				&& $nvx_request_slug === $candidate->post_name
			) {
				$nvx_slug       = $nvx_request_slug;
				$nvx_exact_post = $candidate;
			}
		}
	}
}

// Fall back to WordPress' parsed query only when the actual request path did not
// resolve to an exact governed published post.
if ( '' === $nvx_slug && $wp_query instanceof WP_Query && is_string( $wp_query->get( 'name' ) ) && '' !== $wp_query->get( 'name' ) ) {
	$nvx_slug = (string) $wp_query->get( 'name' );
}

if (
	'' !== $nvx_slug
	&& false === strpos( $nvx_slug, '/' )
	&& function_exists( 'nvx_seo_blog_post_metadata_catalog' )
	&& function_exists( 'nvx_single_post_rebind_query' )
) {
	$nvx_catalog = nvx_seo_blog_post_metadata_catalog();
	if ( isset( $nvx_catalog[ $nvx_slug ] ) && is_array( $nvx_catalog[ $nvx_slug ] ) ) {
		if ( ! ( $nvx_exact_post instanceof WP_Post ) ) {
			$nvx_exact_post = function_exists( 'nvx_governed_blog_runtime_db_post_by_slug' )
				? nvx_governed_blog_runtime_db_post_by_slug( $nvx_slug )
				: ( function_exists( 'nvx_document_governance_get_published_post_by_slug' )
					? nvx_document_governance_get_published_post_by_slug( $nvx_slug )
					: get_page_by_path( $nvx_slug, OBJECT, 'post' ) );
		}

		if (
			$nvx_exact_post instanceof WP_Post
			&& 'publish' === $nvx_exact_post->post_status
			&& $nvx_slug === $nvx_exact_post->post_name
		) {
			global $post, $wp_the_query;

			if ( $wp_query instanceof WP_Query ) {
				nvx_single_post_rebind_query( $wp_query, $nvx_exact_post, $nvx_slug );
			}
			if ( $wp_the_query instanceof WP_Query && $wp_the_query !== $wp_query ) {
				nvx_single_post_rebind_query( $wp_the_query, $nvx_exact_post, $nvx_slug );
			}

			$post = $nvx_exact_post;
			setup_postdata( $post );
		}
	}
}

require_once __DIR__ . '/single.php';
