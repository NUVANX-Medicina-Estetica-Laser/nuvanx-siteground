<?php
/**
 * Final Schema.org semantic governance for the Yoast graph.
 *
 * Runs after all ordinary graph producers and before the FAQ gate / @id dedupe.
 * Its purpose is deliberately narrow: remove or normalize properties whose
 * rendered domain/range is invalid, including legacy values that may originate
 * from WordPress/Yoast metadata rather than versioned theme source.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return string[] */
function nvx_schema_semantic_allowed_procedure_types(): array {
	return array(
		'https://schema.org/PercutaneousProcedure',
		'https://schema.org/NoninvasiveProcedure',
	);
}

/** @return string[] */
function nvx_schema_semantic_allowed_medical_specialties(): array {
	$members = array(
		'Anesthesia',
		'Cardiovascular',
		'CommunityHealth',
		'Dentistry',
		'Dermatology',
		'DietNutrition',
		'Emergency',
		'Endocrine',
		'Gastroenterologic',
		'Genetic',
		'Geriatric',
		'Gynecologic',
		'Hematologic',
		'Infectious',
		'LaboratoryScience',
		'Midwifery',
		'Musculoskeletal',
		'Neurologic',
		'Nursing',
		'Obstetric',
		'Oncologic',
		'Optometric',
		'Otolaryngologic',
		'Pathology',
		'Pediatric',
		'PharmacySpecialty',
		'Physiotherapy',
		'Podiatric',
		'PrimaryCare',
		'Psychiatric',
		'PublicHealth',
		'Pulmonary',
		'Radiography',
		'Renal',
		'RespiratoryTherapy',
		'Rheumatologic',
		'SpeechPathology',
		'Surgical',
		'Toxicologic',
		'Urologic',
	);

	return array_map(
		static function ( string $member ): string {
			return 'https://schema.org/' . $member;
		},
		$members
	);
}

/** @param mixed $value @type value. @return string[] */
function nvx_schema_semantic_types( $value ): array {
	$types = is_array( $value ) ? $value : array( $value );
	return array_values(
		array_filter(
			array_map( 'strval', $types ),
			static function ( string $type ): bool {
				return '' !== $type;
			}
		)
	);
}

/** Whether this explicit type collection represents a WebPage or subtype. */
function nvx_schema_semantic_is_webpage( array $types ): bool {
	foreach ( $types as $type ) {
		if ( 'WebPage' === $type || str_ends_with( $type, 'Page' ) ) {
			return true;
		}
	}
	return false;
}

/** Whether this explicit type collection represents an Event or subtype. */
function nvx_schema_semantic_is_event( array $types ): bool {
	foreach ( $types as $type ) {
		if ( 'Event' === $type || str_ends_with( $type, 'Event' ) ) {
			return true;
		}
	}
	return false;
}

/** Whether this explicit type collection is a medical procedure or subtype. */
function nvx_schema_semantic_is_medical_procedure( array $types ): bool {
	$procedure_types = array(
		'MedicalProcedure',
		'DiagnosticProcedure',
		'PalliativeProcedure',
		'PhysicalExam',
		'PhysicalTherapy',
		'PsychologicalTreatment',
		'RadiationTherapy',
		'SurgicalProcedure',
		'TherapeuticProcedure',
	);

	return (bool) array_intersect( $types, $procedure_types );
}

/** Whether a node can carry descriptive expertise through knowsAbout. */
function nvx_schema_semantic_supports_knows_about( array $types ): bool {
	return (bool) array_intersect(
		$types,
		array( 'Organization', 'MedicalOrganization', 'MedicalClinic', 'LocalBusiness', 'Person', 'Physician' )
	);
}

/** @param mixed $value Schema value. */
function nvx_schema_semantic_value_id( $value ): string {
	if ( is_string( $value ) ) {
		return $value;
	}
	if ( is_array( $value ) && isset( $value['@id'] ) && is_string( $value['@id'] ) ) {
		return $value['@id'];
	}
	return '';
}

/** @param mixed $value Schema scalar-or-list value. @return array<int,mixed> */
function nvx_schema_semantic_value_list( $value ): array {
	if ( ! is_array( $value ) ) {
		return array( $value );
	}

	$keys = array_keys( $value );
	$is_list = array() === $value || $keys === range( 0, count( $value ) - 1 );
	return $is_list ? $value : array( $value );
}

/** Append a descriptive value to knowsAbout without duplicating it. */
function nvx_schema_semantic_append_knows_about( array &$node, string $value ): void {
	$value = trim( $value );
	if ( '' === $value ) {
		return;
	}

	$existing = isset( $node['knowsAbout'] ) ? nvx_schema_semantic_value_list( $node['knowsAbout'] ) : array();
	foreach ( $existing as $item ) {
		if ( is_string( $item ) && $item === $value ) {
			return;
		}
	}
	$existing[]         = $value;
	$node['knowsAbout'] = array_values( $existing );
}

/** Normalize one associative Schema.org object recursively. */
function nvx_schema_semantic_sanitize_object( array $node ): array {
	$types = nvx_schema_semantic_types( $node['@type'] ?? array() );

	if ( array_key_exists( 'reviewedBy', $node ) && ! nvx_schema_semantic_is_webpage( $types ) ) {
		unset( $node['reviewedBy'] );
	}

	if ( array_key_exists( 'performer', $node ) && ! nvx_schema_semantic_is_event( $types ) ) {
		unset( $node['performer'] );
	}

	if ( array_key_exists( 'priceRange', $node ) && ! array_intersect( $types, array( 'LocalBusiness', 'MedicalClinic' ) ) ) {
		unset( $node['priceRange'] );
	}

	// 3.2 Audit: wordCount cannot be accurately determined for block themes at this phase.
	if ( array_key_exists( 'wordCount', $node ) ) {
		unset( $node['wordCount'] );
	}

	if ( array_key_exists( 'procedureType', $node ) ) {
		if ( ! nvx_schema_semantic_is_medical_procedure( $types ) ) {
			unset( $node['procedureType'] );
		} else {
			$allowed = nvx_schema_semantic_allowed_procedure_types();
			$valid   = array();
			foreach ( nvx_schema_semantic_value_list( $node['procedureType'] ) as $candidate ) {
				$id = nvx_schema_semantic_value_id( $candidate );
				if ( in_array( $id, $allowed, true ) ) {
					$valid[] = $candidate;
				}
			}
			if ( empty( $valid ) ) {
				unset( $node['procedureType'] );
			} else {
				$node['procedureType'] = 1 === count( $valid ) ? $valid[0] : array_values( $valid );
			}
		}
	}

	if ( array_key_exists( 'medicalSpecialty', $node ) ) {
		$allowed = nvx_schema_semantic_allowed_medical_specialties();
		$valid   = array();
		foreach ( nvx_schema_semantic_value_list( $node['medicalSpecialty'] ) as $candidate ) {
			$id = nvx_schema_semantic_value_id( $candidate );
			if ( in_array( $id, $allowed, true ) ) {
				$valid[] = $candidate;
				continue;
			}
			if ( is_string( $candidate ) && nvx_schema_semantic_supports_knows_about( $types ) ) {
				nvx_schema_semantic_append_knows_about( $node, $candidate );
			}
		}
		if ( empty( $valid ) ) {
			unset( $node['medicalSpecialty'] );
		} else {
			$node['medicalSpecialty'] = 1 === count( $valid ) ? $valid[0] : array_values( $valid );
		}
	}

	// Internal NUVANX policy: MedicalProcedure and Service emitters must never
	// assert a recognizingAuthority. The restriction is intentionally based on
	// the property, not on a growing blacklist of authority names or values.
	if ( array_key_exists( 'recognizingAuthority', $node ) && array_intersect( $types, array( 'MedicalProcedure', 'Service' ) ) ) {
		unset( $node['recognizingAuthority'] );
	}

	foreach ( $node as $key => $value ) {
		if ( is_array( $value ) ) {
			$node[ $key ] = nvx_schema_semantic_sanitize_value( $value );
		}
	}

	return $node;
}

/** Recursively sanitize associative objects and list values. */
function nvx_schema_semantic_sanitize_value( array $value ): array {
	$keys    = array_keys( $value );
	$is_list = array() === $value || $keys === range( 0, count( $value ) - 1 );

	if ( ! $is_list ) {
		return nvx_schema_semantic_sanitize_object( $value );
	}

	$result = array();
	foreach ( $value as $item ) {
		$result[] = is_array( $item ) ? nvx_schema_semantic_sanitize_value( $item ) : $item;
	}
	return $result;
}

/**
 * Final semantic pass over the rendered Yoast graph.
 *
 * @param array $graph Yoast Schema graph.
 * @return array
 */
function nvx_schema_semantic_normalize_graph( $graph ) {
	if ( ! is_array( $graph ) || is_admin() || is_feed() ) {
		return $graph;
	}

	$result             = array();
	$valid_breadcrumbs = array();

	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$types = nvx_schema_semantic_types( $node['@type'] ?? array() );
		if ( in_array( 'BreadcrumbList', $types, true ) ) {
			$id       = isset( $node['@id'] ) ? (string) $node['@id'] : '';
			$elements = isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) ? $node['itemListElement'] : array();
			if ( '' !== $id && ! empty( $elements ) ) {
				$valid_breadcrumbs[ $id ] = true;
			}
		}
	}

	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) ) {
			$result[] = $node;
			continue;
		}

		$types = nvx_schema_semantic_types( $node['@type'] ?? array() );
		if ( in_array( 'BreadcrumbList', $types, true ) ) {
			$elements = isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) ? $node['itemListElement'] : array();
			if ( empty( $elements ) ) {
				if ( 1 === count( $types ) ) {
					continue;
				}
				$node['@type'] = array_values( array_diff( $types, array( 'BreadcrumbList' ) ) );
				$types = nvx_schema_semantic_types( $node['@type'] );
			}
		}

		if ( nvx_schema_semantic_is_webpage( $types ) ) {
			if ( isset( $node['breadcrumb'] ) ) {
				$ref = is_array( $node['breadcrumb'] ) && isset( $node['breadcrumb']['@id'] )
					? (string) $node['breadcrumb']['@id']
					: ( is_string( $node['breadcrumb'] ) ? $node['breadcrumb'] : '' );

				// Prune orphan or empty breadcrumb pointer so Google never flags missing itemListElement.
				if ( '' === $ref || empty( $valid_breadcrumbs[ $ref ] ) ) {
					unset( $node['breadcrumb'] );
				}
			}
		}

		$result[] = nvx_schema_semantic_sanitize_value( $node );
	}

	return array_values( $result );
}
add_filter( 'wpseo_schema_graph', 'nvx_schema_semantic_normalize_graph', PHP_INT_MAX - 2, 1 );

/** Resolve a callback's source file without executing it. */
function nvx_schema_runtime_callback_file( $callback ): string {
	try {
		if ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			return (string) $reflection->getFileName();
		}
		if ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflection = new ReflectionFunction( $callback );
			return (string) $reflection->getFileName();
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$reflection = new ReflectionMethod( $class, (string) $callback[1] );
			return (string) $reflection->getFileName();
		}
	} catch ( Throwable $error ) {
		return '';
	}
	return '';
}

/**
 * Retire legacy standalone Schema emitters after plugins/MU plugins register.
 *
 * The canonical structured-data owner is wpseo_schema_graph plus the governed
 * theme filters above. This deliberately does NOT disable Code Snippets or any
 * other plugin mechanisms unless they explicitly emit standalone Schema.org blocks.
 */

/**
 * Determine if a callback is a legacy emitter that should be retired.
 *
 * Handles string function names, closures, object methods, and Code Snippets wrappers.
 * This is necessary because the legacy emitters might be registered in various ways
 * (plain string, closure, object method, Code Snippets wrapper) and we need to
 * detect all variants to ensure they are removed.
 *
 * @param mixed $callback Callback to check
 * @return bool True if callback should be retired
 */
function nvx_schema_is_legacy_emitter( $callback ): bool {
	// Check for plain string function names
	if ( is_string( $callback ) ) {
		return in_array( $callback, array( 'nvx_seo_geo_output_jsonld', 'nvx_seo_geo_output_breadcrumb' ), true );
	}

	// Check for object methods (array with object and method name)
	if ( is_array( $callback ) && isset( $callback[1] ) && is_string( $callback[1] ) ) {
		$method_name = $callback[1];
		return in_array( $method_name, array( 'nvx_seo_geo_output_jsonld', 'nvx_seo_geo_output_breadcrumb' ), true );
	}

	// Check for closures or other callable types
	// Use reflection to get the closure's filename and check if it contains the legacy function name
	if ( is_callable( $callback ) && ! is_string( $callback ) && ! is_array( $callback ) ) {
		try {
			$reflection = new ReflectionFunction( $callback );
			$filename = $reflection->getFileName();
			// Check if the closure is defined in a file that contains the legacy function names
			$content = file_get_contents( $filename );
			return false !== strpos( $content, 'nvx_seo_geo_output_jsonld' ) ||
			       false !== strpos( $content, 'nvx_seo_geo_output_breadcrumb' );
		} catch ( Exception $e ) {
			// If reflection fails, conservatively assume it's not a legacy emitter
			return false;
		}
	}

	return false;
}

/**
 * Retire legacy JSON-LD emitters from wp_head at runtime.
 *
 * NOTE: nvx_seo_geo_output_jsonld and nvx_seo_geo_output_breadcrumb are retired
 * legacy emitters defined outside this repo. Their full output is unverifiable here.
 * This removal is safe only under the staging-inventory claim that these callbacks
 * emit only their proven legacy JSON-LD blocks (Organization, MedicalOrganization,
 * BreadcrumbList). If the live callbacks emit additional head output (e.g. meta tags,
 * GEO hints), unhooking them drops that too. Confirm against actual plugin source
 * before deploying to production.
 *
 * The removal handles multiple registration patterns:
 * - Plain string function names
 * - Object methods (array syntax)
 * - Closures (via reflection to check filename)
 * - Code Snippets wrappers (detected via filename inspection)
 */
function nvx_schema_runtime_retire_legacy_emitters(): void {
	global $wp_filter;

	// Guard access by checking that wp_head is a WP_Hook instance
	// This avoids brittle coupling to internal structure if the global is modified or filtered elsewhere
	if ( ! isset( $wp_filter['wp_head'] ) || ! ( $wp_filter['wp_head'] instanceof WP_Hook ) ) {
		return;
	}

	if ( ! isset( $wp_filter['wp_head']->callbacks ) || ! is_array( $wp_filter['wp_head']->callbacks ) ) {
		return;
	}

	foreach ( $wp_filter['wp_head']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			// Add robust guards for unexpected hook structures
			if ( ! is_array( $callback ) || ! isset( $callback['function'] ) ) {
				continue;
			}
			$function = $callback['function'];

			if ( nvx_schema_is_legacy_emitter( $function ) ) {
				remove_action( 'wp_head', $function, (int) $priority );
				continue;
			}

			$file = nvx_schema_runtime_callback_file( $function );
			// Use full path matching instead of basename to avoid removing unrelated callbacks
			// Only remove if the file is exactly in the theme's inc directory with the specific name
			if ( '' !== $file && false !== strpos( $file, 'nuvanx-home-unified-faq-schema.php' ) ) {
				// Additional check: ensure it's in the theme directory
				$theme_dir = get_template_directory();
				if ( 0 === strpos( $file, $theme_dir ) ) {
					remove_action( 'wp_head', $function, (int) $priority );
				}
			}
		}
	}
}
add_action( 'wp_loaded', 'nvx_schema_runtime_retire_legacy_emitters', PHP_INT_MAX );
