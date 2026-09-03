<?php
/**
 * Exact-path clinic identity and NAP schema governance.
 *
 * Template selection is owned exclusively by nvx-business-config.php. This
 * module is a final structured-data fence only: it prevents persisted template
 * metadata, slug fragments and nested route prefixes from leaking the wrong
 * clinic branch into the public Schema graph.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolve the immutable public path through the canonical route normalizer. */
function nvx_clinic_identity_current_path(): string {
	if ( ! function_exists( 'nvx_theme_request_path' ) || ! function_exists( 'nvx_clinic_normalize_landing_path' ) ) {
		return '/';
	}

	return nvx_clinic_normalize_landing_path( (string) nvx_theme_request_path() );
}

/**
 * Branch keys allowed to survive in the final public Schema graph.
 *
 * Individual clinic landings expose exactly one branch. The corporate home,
 * canonical Contacto page, journal posts, medical-team hub and clinics hub may
 * expose both branches. All other routes fail closed to no MedicalClinic branch
 * nodes. Contacto is matched by immutable exact path here rather than by the
 * broader legacy contact helper, so nested/substr routes cannot inherit clinic
 * identity from this final trust boundary.
 *
 * @return string[]
 */
function nvx_clinic_identity_allowed_schema_keys(): array {
	$path = nvx_clinic_identity_current_path();

	if ( function_exists( 'is_front_page' ) && is_front_page() ) {
		return array( 'chamberi', 'goya' );
	}

	if ( function_exists( 'is_singular' ) && is_singular( 'post' ) ) {
		return array( 'chamberi', 'goya' );
	}

	if (
		'/contacto/' === $path
		|| '/equipo-medico/' === $path
		|| ( function_exists( 'nvx_clinic_is_exact_hub_path' ) && nvx_clinic_is_exact_hub_path( $path ) )
	) {
		return array( 'chamberi', 'goya' );
	}

	if ( ! function_exists( 'nvx_clinic_key_from_landing_path' ) ) {
		return array();
	}

	$key = nvx_clinic_key_from_landing_path( $path );
	return is_string( $key ) && '' !== $key ? array( $key ) : array();
}

/** Whether a Schema graph node represents a MedicalClinic. */
function nvx_clinic_identity_is_clinic_node( array $node ): bool {
	$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
	return in_array( 'MedicalClinic', $types, true );
}

/**
 * Remove only references to clinic branches that are not allowed on this route.
 *
 * Both a single associative reference and a list of references are supported
 * because Schema emitters may legitimately use either shape. References to
 * non-clinic organizations remain untouched; this module is not their owner.
 *
 * @param mixed              $refs         Incoming reference value.
 * @param array<string,bool> $allowed_ids  Allowed clinic node IDs.
 * @param array<string,bool> $all_clinic_ids All clinic node IDs in the graph.
 * @return mixed
 */
function nvx_clinic_identity_filter_refs( $refs, array $allowed_ids, array $all_clinic_ids ) {
	if ( ! is_array( $refs ) || empty( $refs ) ) {
		return $refs;
	}

	$is_assoc = function_exists( 'array_is_list' )
		? ! array_is_list( $refs )
		: array_keys( $refs ) !== range( 0, count( $refs ) - 1 );

	if ( $is_assoc ) {
		$id = (string) ( $refs['@id'] ?? '' );
		return '' !== $id && isset( $all_clinic_ids[ $id ] ) && ! isset( $allowed_ids[ $id ] )
			? array()
			: $refs;
	}

	$filtered = array();
	foreach ( $refs as $ref ) {
		if ( ! is_array( $ref ) ) {
			$filtered[] = $ref;
			continue;
		}

		$id = (string) ( $ref['@id'] ?? '' );
		if ( '' !== $id && isset( $all_clinic_ids[ $id ] ) && ! isset( $allowed_ids[ $id ] ) ) {
			continue;
		}

		$filtered[] = $ref;
	}

	return $filtered;
}

/**
 * Final Schema fence: exact route authority wins over legacy inference.
 *
 * @param mixed $graph Yoast Schema graph.
 * @return mixed
 */
function nvx_clinic_identity_schema_graph( $graph ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}

	$allowed_keys = array_fill_keys( nvx_clinic_identity_allowed_schema_keys(), true );
	$allowed_ids  = array();
	$clinic_ids   = array();

	// Resolve both the complete clinic set and the route-allowed subset before
	// filtering references. Unknown organization IDs must not be treated as clinics.
	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) || ! nvx_clinic_identity_is_clinic_node( $node ) ) {
			continue;
		}

		$key = sanitize_key( (string) ( $node['branchCode'] ?? '' ) );
		$id  = (string) ( $node['@id'] ?? '' );
		if ( '' !== $id ) {
			$clinic_ids[ $id ] = true;
		}
		if ( '' !== $id && '' !== $key && isset( $allowed_keys[ $key ] ) ) {
			$allowed_ids[ $id ] = true;
		}
	}

	$result = array();
	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) ) {
			$result[] = $node;
			continue;
		}

		if ( nvx_clinic_identity_is_clinic_node( $node ) ) {
			$key = sanitize_key( (string) ( $node['branchCode'] ?? '' ) );
			$id  = (string) ( $node['@id'] ?? '' );
			if ( '' === $key || '' === $id || ! isset( $allowed_keys[ $key ], $allowed_ids[ $id ] ) ) {
				continue;
			}
		}

		foreach ( array( 'subOrganization', 'department' ) as $property ) {
			if ( ! array_key_exists( $property, $node ) ) {
				continue;
			}

			$refs = nvx_clinic_identity_filter_refs( $node[ $property ], $allowed_ids, $clinic_ids );
			if ( is_array( $refs ) && empty( $refs ) ) {
				unset( $node[ $property ] );
			} else {
				$node[ $property ] = $refs;
			}
		}

		$result[] = $node;
	}

	return array_values( $result );
}
add_filter( 'wpseo_schema_graph', 'nvx_clinic_identity_schema_graph', PHP_INT_MAX - 1 );
