<?php
/**
 * Signature Phase catalog data: label helpers, spec definitions, token resolver,
 * entry hydrator, catalog builder, and current-page key resolver.
 *
 * Extracted from nvx-signature-phase-pages.php.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NVX_CONTOUR_ARCHITECTURE' ) ) {
	define( 'NVX_CONTOUR_ARCHITECTURE', 'NUVANX Contour Architecture™' );
}
if ( ! defined( 'NVX_CONTOUR_ARCHITECTURE_SHORT' ) ) {
	define( 'NVX_CONTOUR_ARCHITECTURE_SHORT', 'Contour Architecture™' );
}
if ( ! defined( 'NVX_VALORACION_PATH' ) ) {
	define( 'NVX_VALORACION_PATH', '/madrid/valoracion/' );
}

/**
 * Public Contour Architecture™ display name (full brand form).
 */
function nvx_signature_contour_label(): string {
	return NVX_CONTOUR_ARCHITECTURE;
}

/**
 * Public Contour Architecture™ short label (without NUVANX prefix).
 */
function nvx_signature_contour_label_short(): string {
	return NVX_CONTOUR_ARCHITECTURE_SHORT;
}

/**
 * Absolute URL for the private medical valuation route.
 */
function nvx_signature_valoracion_url(): string {
	return home_url( NVX_VALORACION_PATH );
}

/**
 * Load and validate raw Signature phase specs from the versioned JSON catalogue.
 *
 * Incomplete records fail closed before renderers can index missing clinical or
 * SEO fields. The canonical catalog-json owner is loaded by the root bootstrap.
 *
 * @return array<string, array<string, mixed>>
 */
function nvx_signature_phase_catalog_specs(): array {
	$catalog = nvx_catalog_json_load( 'nvx-signature-phase-catalog.json' );

	if ( ! function_exists( 'nvx_catalog_filter_records' ) ) {
		return array();
	}

	return nvx_catalog_filter_records(
		$catalog,
		array(
			'slug',
			'title',
			'kicker',
			'lead',
			'intro',
			'assessment',
			'technology',
			'limits',
			'seo_title',
			'seo_desc',
			'protocol',
		),
		'nvx-signature-phase-catalog.json'
	);
}

/**
 * Resolve catalogue tokens for Contour Architecture™ naming variants.
 *
 * @param mixed $value
 * @return mixed
 */
function nvx_signature_phase_resolve_token( $value ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}
	if ( 'contour_upper' === $value ) {
		return 'CONTOUR ARCHITECTURE™';
	}
	if ( 'contour_lower' === $value ) {
		return 'CONTOUR ARCHITECTURE™';
	}
	if ( 'contour_mixed' === $value ) {
		return nvx_signature_contour_label();
	}
	if ( 'Contour Architecture™' === $value ) {
		return 'CONTOUR ARCHITECTURE™';
	}
	return $value;
}

/**
 * Hydrate one raw JSON entry into a runtime catalogue page.
 *
 * @param array<string, mixed> $spec
 * @return array<string, mixed>
 */
function nvx_signature_phase_hydrate_entry( array $spec ): array {
	$entry = array();
	foreach ( $spec as $key => $value ) {
		if ( in_array( $key, array( 'faq', 'ficha_links', 'related_fichas' ), true ) && is_array( $value ) ) {
			// Nested objects must keep their keys; token replace is string-only.
			$entry[ $key ] = $value;
			continue;
		}
		if ( is_array( $value ) ) {
			$entry[ $key ] = array_map( 'nvx_signature_phase_resolve_token', $value );
			continue;
		}
		$entry[ $key ] = nvx_signature_phase_resolve_token( $value );
	}
	return $entry;
}

/**
 * Provides the approved landing-page content and metadata for Signature phases 1 and 2.
 *
 * @return array The catalogue keyed by internal page identifier.
 */
function nvx_signature_phase_catalog(): array {
	static $catalog = null;
	if ( null !== $catalog ) {
		return $catalog;
	}

	$catalog = array();
	foreach ( nvx_signature_phase_catalog_specs() as $key => $spec ) {
		if ( ! is_array( $spec ) ) {
			continue;
		}
		$catalog[ $key ] = nvx_signature_phase_hydrate_entry( $spec );
	}
	return $catalog;
}

/**
 * Identifies the governed landing page for the current request.
 *
 * @return string|null The matching catalog key, or null when the request does not
 *     target a governed landing page.
 */
function nvx_signature_phase_current_key(): ?string {
	if ( ! is_page() || is_404() ) {
		return null;
	}
	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	if ( '' === $slug ) {
		return null;
	}
	foreach ( nvx_signature_phase_catalog() as $key => $page ) {
		if ( isset( $page['slug'] ) && $page['slug'] === $slug ) {
			return $key;
		}
	}
	return null;
}
