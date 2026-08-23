<?php
/**
 * Audit the actual Yoast XML sitemap selection predicates against NUVANX's
 * versioned publication manifest. This script is read-only and runs via WP-CLI
 * after WordPress is loaded. It intentionally loads the page-hygiene module,
 * because WP-CLI does not guarantee loading the active theme.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "PUBLICATION_SITEMAP_SELECTION=FAIL reason=wp_cli_required\n" );
	exit( 1 );
}

$theme_dir     = get_template_directory();
$manifest_path = $theme_dir . '/inc/data/publication-manifest.json';
$hygiene_path  = $theme_dir . '/inc/nvx-page-hygiene.php';

if ( ! is_readable( $manifest_path ) || ! is_readable( $hygiene_path ) || ! class_exists( 'WPSEO_Meta' ) ) {
	fwrite( STDERR, "PUBLICATION_SITEMAP_SELECTION=FAIL reason=dependencies_unavailable\n" );
	exit( 1 );
}

require_once $hygiene_path;

$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) || ! is_array( $manifest['routes'] ?? null ) ) {
	fwrite( STDERR, "PUBLICATION_SITEMAP_SELECTION=FAIL reason=manifest_invalid\n" );
	exit( 1 );
}

$normalize_path = static function ( string $value ): string {
	$path = (string) wp_parse_url( $value, PHP_URL_PATH );
	if ( '' === $path || '/' === $path ) {
		return '/';
	}
	return '/' . trim( $path, '/' ) . '/';
};

$excluded_ids = array();
$front_page   = (int) get_option( 'page_on_front' );
if ( $front_page > 0 ) {
	$excluded_ids[] = $front_page;
}
$excluded_ids = apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', $excluded_ids );
$excluded_ids = is_array( $excluded_ids ) ? array_map( 'intval', $excluded_ids ) : array();
$posts_page   = (int) get_option( 'page_for_posts' );
if ( $posts_page > 0 ) {
	$excluded_ids[] = $posts_page;
}
$excluded_ids = array_values( array_unique( $excluded_ids ) );

$eligible = 0;
$failures = array();

foreach ( $manifest['routes'] as $route => $config ) {
	if ( ! is_array( $config ) || 'publish' !== (string) ( $config['status'] ?? '' ) || true !== ( $config['robots']['index'] ?? null ) ) {
		continue;
	}

	$route   = $normalize_path( (string) $route );
	$post_id = (int) ( $config['post_id'] ?? 0 );
	$post    = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! ( $post instanceof WP_Post ) ) {
		$failures[] = "missing_post:{$route}";
		continue;
	}

	// Yoast places the static front page and posts page through
	// get_first_links(), outside the generic post selector audited below.
	if ( in_array( $post_id, array_filter( array( $front_page, $posts_page ) ), true ) ) {
		++$eligible;
		continue;
	}

	$permalink        = get_permalink( $post );
	$robots_noindex   = (string) WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id );
	$canonical        = (string) WPSEO_Meta::get_value( 'canonical', $post_id );
	$filtered_url     = apply_filters( 'wpseo_xml_sitemap_post_url', $permalink, $post );
	$is_external      = ! is_string( $filtered_url ) || '' === $filtered_url || wp_parse_url( $filtered_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST );
	$entry            = ! $is_external ? apply_filters( 'wpseo_sitemap_entry', array( 'loc' => $filtered_url ), 'post', $post ) : false;
	$failure_reasons  = array();

	if ( in_array( $post_id, $excluded_ids, true ) ) {
		$failure_reasons[] = 'excluded_id';
	}
	if ( '1' === $robots_noindex ) {
		$failure_reasons[] = 'legacy_noindex';
	}
	if ( '' !== $canonical && $canonical !== $permalink ) {
		$failure_reasons[] = 'canonical_mismatch';
	}
	if ( $is_external ) {
		$failure_reasons[] = 'external_or_empty_permalink';
	}
	if ( empty( $entry ) || ! is_array( $entry ) || empty( $entry['loc'] ) ) {
		$failure_reasons[] = 'sitemap_entry_filter';
	}

	if ( ! empty( $failure_reasons ) ) {
		$failures[] = sprintf( '%s:id=%d:reasons=%s', $route, $post_id, implode( ',', $failure_reasons ) );
		continue;
	}

	++$eligible;
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "PUBLICATION_SITEMAP_SELECTION=FAIL eligible={$eligible} failures=" . implode( '|', $failures ) . "\n" );
	exit( 1 );
}

printf(
	"PUBLICATION_SITEMAP_SELECTION=PASS routes=%d indexable=%d excluded_ids=%d\n",
	count( $manifest['routes'] ),
	$eligible,
	count( $excluded_ids )
);
