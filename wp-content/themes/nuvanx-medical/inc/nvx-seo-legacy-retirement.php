<?php
/**
 * Retire page-local SEO title/description owners after all theme modules load.
 *
 * Canonical text metadata is owned by nvx-seo-metadata.php + the versioned
 * seo-metadata.json catalog. Contact-specific image and schema filters remain
 * active because they own different concerns.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retire the legacy direct-header owner.
 *
 * nvx-seo-production-readiness.php is the canonical X-Robots-Tag owner through
 * the wp_headers filter. Keeping a second send_headers/header() writer makes
 * the final policy order-dependent and can weaken the stronger noarchive /
 * nosnippet staging contract.
 */
remove_action( 'send_headers', 'nvx_seo_enforce_http_robots_header', 1 );

/**
 * Identifies indexable publication-manifest routes whose Yoast canonical must
 * remain derived from the permalink. The whitelist is configuration-led: a
 * manual canonical cannot be restored for an indexable route by a plugin,
 * snippet, cron callback, or page-save hook outside this repository.
 */
function nvx_seo_retirement_is_indexable_manifest_post( int $post_id ): bool {
	static $indexable_ids = null;

	if ( null === $indexable_ids ) {
		$indexable_ids = array();
		$manifest_path = __DIR__ . '/data/publication-manifest.json';
		$decoded       = is_readable( $manifest_path )
			? json_decode( (string) file_get_contents( $manifest_path ), true )
			: null;

		if ( is_array( $decoded ) && is_array( $decoded['routes'] ?? null ) ) {
			foreach ( $decoded['routes'] as $config ) {
				if ( ! is_array( $config ) || 'publish' !== (string) ( $config['status'] ?? '' ) || true !== ( $config['robots']['index'] ?? null ) ) {
					continue;
				}
				$id = (int) ( $config['post_id'] ?? 0 );
				if ( $id > 0 ) {
					$indexable_ids[ $id ] = true;
				}
			}
		}
	}

	return isset( $indexable_ids[ $post_id ] );
}

/**
 * Stops a late runtime writer from recreating a manual Yoast canonical that
 * differs from the current permalink of an indexable manifest route.
 *
 * Returning a non-null value from WordPress's metadata short-circuit filters
 * marks the rejected write as handled, while blank and derived values remain
 * valid for the controlled reconciliation pipeline.
 *
 * @param mixed  $check      Short-circuit value from WordPress.
 * @param int    $post_id    Post receiving the metadata write.
 * @param string $meta_key   Metadata key.
 * @param mixed  $meta_value Proposed metadata value.
 * @return mixed
 */
function nvx_seo_retirement_block_divergent_canonical_persistence( $check, $post_id, $meta_key, $meta_value ) {
	if ( '_yoast_wpseo_canonical' !== (string) $meta_key || ! nvx_seo_retirement_is_indexable_manifest_post( (int) $post_id ) ) {
		return $check;
	}

	$canonical = trim( (string) $meta_value );
	if ( '' === $canonical ) {
		return $check;
	}

	$permalink = get_permalink( (int) $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink || untrailingslashit( $canonical ) === untrailingslashit( $permalink ) ) {
		return $check;
	}

	nvx_observability_log(
		'seo_retirement',
		'divergent_canonical_rejected',
		array( 'post_id' => (int) $post_id )
	);
	return true;
}
add_filter( 'add_post_metadata', 'nvx_seo_retirement_block_divergent_canonical_persistence', PHP_INT_MAX, 4 );
add_filter( 'update_post_metadata', 'nvx_seo_retirement_block_divergent_canonical_persistence', PHP_INT_MAX, 4 );

/** Remove legacy text-metadata filters once every theme module is registered. */
add_action(
	'wp_loaded',
	static function (): void {
		$legacy = array(
			array( 'wpseo_title', 'nvx_filter_valoracion_document_title', 21 ),
			array( 'wpseo_metadesc', 'nvx_filter_valoracion_metadesc', 21 ),
			array( 'wpseo_title', 'nvx_filter_contacto_document_title', 21 ),
			array( 'wpseo_metadesc', 'nvx_filter_contacto_metadesc', 21 ),
			array( 'wpseo_title', 'nvx_contacto_seo_title', 10 ),
			array( 'wpseo_metadesc', 'nvx_contacto_seo_metadesc', 10 ),
			array( 'wpseo_opengraph_title', 'nvx_filter_contacto_social_title', 110 ),
			array( 'wpseo_twitter_title', 'nvx_filter_contacto_social_title', 110 ),
			array( 'wpseo_opengraph_desc', 'nvx_filter_contacto_social_description', 110 ),
			array( 'wpseo_twitter_description', 'nvx_filter_contacto_social_description', 110 ),
		);

		foreach ( $legacy as $registration ) {
			remove_filter( $registration[0], $registration[1], $registration[2] );
		}
	},
	1
);
