<?php
/**
 * Final clinic identity/NAP schema governance.
 *
 * Exact route resolution and template routing are owned by nvx-business-config.php.
 * This module deliberately does not infer routes or register template ownership;
 * it only fences the final public schema graph using that canonical resolver.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return the immutable normalized public request path. */
function nvx_clinic_identity_current_path(): string {
	if ( ! function_exists( 'nvx_theme_request_path' ) || ! function_exists( 'nvx_clinic_normalize_landing_path' ) ) {
		return '/';
	}

	return nvx_clinic_normalize_landing_path( (string) nvx_theme_request_path() );
}

/** Return all canonical clinic keys for aggregate organization surfaces. */
function nvx_clinic_identity_all_keys(): array {
	if ( ! function_exists( 'nvx_get_clinics_config' ) ) {
		return array();
	}

	$keys = array();
	foreach ( nvx_get_clinics_config() as $key => $clinic ) {
		if ( is_string( $key ) && '' !== $key && is_array( $clinic ) ) {
			$keys[] = $key;
		}
	}

	return $keys;
}

/**
 * Resolve which clinic branches may appear in the final schema graph.
 *
 * Aggregate brand/team/editorial surfaces may reference every canonical branch.
 * Individual clinic landings may reference exactly the branch resolved by the
 * business-config exact-path owner. Every other route fails closed to none.
 *
 * @return string[]
 */
function nvx_clinic_identity_allowed_schema_keys(): array {
	$path = nvx_clinic_identity_current_path();

	if ( function_exists( 'is_front_page' ) && is_front_page() ) {
		return nvx_clinic_identity_all_keys();
	}
	if ( function_exists( 'is_singular' ) && is_singular( 'post' ) ) {
		return nvx_clinic_identity_all_keys();
	}
	if ( '/equipo-medico/' === $path ) {
		return nvx_clinic_identity_all_keys();
	}
	if ( function_exists( 'nvxIsClinicsHub' ) && nvxIsClinicsHub() ) {
		return nvx_clinic_identity_all_keys();
	}

	if ( ! function_exists( 'nvx_current_clinic_landing_key' ) ) {
		return array();
	}

	$key = nvx_current_clinic_landing_key();
	return is_string( $key ) && '' !== $key ? array( $key ) : array();
}

/** Whether one schema graph node represents a MedicalClinic. */
function nvx_clinic_identity_is_clinic_node( array $node ): bool {
	$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
	return in_array( 'MedicalClinic', $types, true );
}

/**
 * Keep only organization references to clinic nodes that survived the fence.
 *
 * Yoast/schema producers may expose one reference as an associative object or
 * many references as a list. Preserve the original shape for a valid single
 * reference and fail closed when its @id does not belong to a surviving clinic.
 *
 * @param mixed              $refs        Schema reference value.
 * @param array<string,bool> $allowed_ids Surviving clinic IDs.
 * @return mixed|null
 */
function nvx_clinic_identity_filter_refs( $refs, array $allowed_ids ) {
	if ( ! is_array( $refs ) ) {
		return null;
	}

	if ( array_key_exists( '@id', $refs ) ) {
		$id = (string) $refs['@id'];
		return '' !== $id && isset( $allowed_ids[ $id ] ) ? $refs : null;
	}

	$filtered = array();
	foreach ( $refs as $ref ) {
		$id = is_array( $ref ) ? (string) ( $ref['@id'] ?? '' ) : '';
		if ( '' !== $id && isset( $allowed_ids[ $id ] ) ) {
			$filtered[] = $ref;
		}
	}

	return empty( $filtered ) ? null : $filtered;
}

/**
 * Final schema fence: exact route authority wins over legacy template/prefix inference.
 *
 * @param mixed $graph Yoast schema graph.
 * @return mixed
 */
function nvx_clinic_identity_schema_graph( $graph ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}

	$allowed_keys = array_fill_keys( nvx_clinic_identity_allowed_schema_keys(), true );
	$allowed_ids  = array();
	$result       = array();

	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) || ! nvx_clinic_identity_is_clinic_node( $node ) ) {
			$result[] = $node;
			continue;
		}

		$key = sanitize_key( (string) ( $node['branchCode'] ?? '' ) );
		if ( '' === $key || ! isset( $allowed_keys[ $key ] ) ) {
			continue;
		}

		$id = (string) ( $node['@id'] ?? '' );
		if ( '' !== $id ) {
			$allowed_ids[ $id ] = true;
		}
		$result[] = $node;
	}

	foreach ( $result as $index => $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		foreach ( array( 'subOrganization', 'department' ) as $property ) {
			if ( ! array_key_exists( $property, $node ) ) {
				continue;
			}

			$refs = nvx_clinic_identity_filter_refs( $node[ $property ], $allowed_ids );
			if ( null === $refs ) {
				unset( $result[ $index ][ $property ] );
			} else {
				$result[ $index ][ $property ] = $refs;
			}
		}
	}

	return array_values( $result );
}
add_filter( 'wpseo_schema_graph', 'nvx_clinic_identity_schema_graph', PHP_INT_MAX - 1 );
