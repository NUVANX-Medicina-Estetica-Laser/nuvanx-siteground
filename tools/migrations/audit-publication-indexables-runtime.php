<?php
/**
 * Read-only diagnostic of Yoast indexables under the current runtime policy.
 *
 * This script intentionally runs before the controlled WP-CLI staging bypass.
 * It never writes database state and distinguishes a normal Staging2 runtime
 * selection from the later, temporary production-mode reconciliation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL reason=wordpress_not_bootstrapped\n" );
	exit( 1 );
}

$theme_dir     = get_template_directory();
$manifest_path = $theme_dir . '/inc/data/publication-manifest.json';
if ( ! is_file( $manifest_path ) || ! is_readable( $manifest_path ) ) {
	fwrite( STDERR, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL reason=manifest_unreadable\n" );
	exit( 1 );
}

$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) || 'nuvanx-publication-manifest' !== (string) ( $manifest['schema'] ?? '' ) || ! is_array( $manifest['routes'] ?? null ) ) {
	fwrite( STDERR, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL reason=manifest_invalid\n" );
	exit( 1 );
}

$repository_class = 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository';
if ( ! class_exists( $repository_class ) || ! function_exists( 'YoastSEO' ) ) {
	fwrite( STDERR, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL reason=yoast_repository_unavailable\n" );
	exit( 1 );
}

$container = YoastSEO();
if ( ! is_object( $container ) || ! isset( $container->classes ) || ! is_object( $container->classes ) || ! method_exists( $container->classes, 'get' ) ) {
	fwrite( STDERR, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL reason=yoast_container_unavailable\n" );
	exit( 1 );
}

try {
	$repository = $container->classes->get( $repository_class );
} catch ( Throwable $error ) {
	fwrite( STDERR, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL reason=yoast_service_resolution_failed detail=" . $error->getMessage() . "\n" );
	exit( 1 );
}

if ( ! is_object( $repository ) || ! method_exists( $repository, 'find_by_id_and_type' ) ) {
	fwrite( STDERR, "PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL reason=yoast_service_contract_invalid\n" );
	exit( 1 );
}

$normalize_path = static function ( string $value ): string {
	$path = (string) wp_parse_url( $value, PHP_URL_PATH );
	return '' === trim( $path, '/' ) ? '/' : '/' . trim( $path, '/' ) . '/';
};

$indexable = 0;
$matching  = 0;
$drift     = array();

foreach ( $manifest['routes'] as $route => $config ) {
	if ( ! is_array( $config ) || true !== ( $config['robots']['index'] ?? null ) ) {
		continue;
	}

	$route   = $normalize_path( (string) $route );
	$post_id = (int) ( $config['post_id'] ?? 0 );
	$post    = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! ( $post instanceof WP_Post ) ) {
		$drift[] = "{$route}:missing_post";
		continue;
	}

	++$indexable;
	try {
		$actual = $repository->find_by_id_and_type( $post_id, 'post', false );
	} catch ( Throwable $error ) {
		$drift[] = "{$route}:repository_lookup_failed";
		continue;
	}
	if ( ! is_object( $actual ) ) {
		$drift[] = "{$route}:missing_indexable";
		continue;
	}

	$expected_permalink = get_permalink( $post_id );
	$permalink          = isset( $actual->permalink ) ? (string) $actual->permalink : '';
	$canonical          = isset( $actual->canonical ) ? (string) $actual->canonical : '';
	$noindex            = isset( $actual->is_robots_noindex ) ? $actual->is_robots_noindex : null;
	$public             = isset( $actual->is_public ) ? $actual->is_public : null;
	$reasons            = array();

	if ( $route !== $normalize_path( $permalink ) ) {
		$reasons[] = 'permalink_mismatch';
	}
	if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $expected_permalink ) ) {
		$reasons[] = 'canonical_mismatch';
	}
	if ( in_array( $noindex, array( 1, '1', true ), true ) ) {
		$reasons[] = 'noindex';
	}
	if ( in_array( $public, array( 0, '0', false ), true ) ) {
		$reasons[] = 'not_public';
	}

	if ( empty( $reasons ) ) {
		++$matching;
		continue;
	}

	$drift[] = "{$route}:" . implode( ',', $reasons );
}

$environment = function_exists( 'nvx_seo_is_nonproduction_environment' ) && nvx_seo_is_nonproduction_environment() ? 'nonproduction' : 'production';

if ( ! empty( $drift ) ) {
	printf(
		"PUBLICATION_INDEXABLE_RUNTIME_AUDIT=FAIL routes=%d indexable=%d matching=%d drift=%d environment=%s\n",
		count( $manifest['routes'] ),
		$indexable,
		$matching,
		count( $drift ),
		$environment
	);
	printf( "PUBLICATION_INDEXABLE_RUNTIME_DRIFT=%s\n", implode( '|', $drift ) );
	exit( 1 );
}

printf(
	"PUBLICATION_INDEXABLE_RUNTIME_AUDIT=PASS routes=%d indexable=%d matching=%d drift=0 environment=%s\n",
	count( $manifest['routes'] ),
	$indexable,
	$matching,
	$environment
);
