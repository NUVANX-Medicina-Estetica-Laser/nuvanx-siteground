<?php
/**
 * Schema foundation: config, tariffs, route registry, path/type helpers,
 * clinic resolution, MedicalClinic nodes, and Organization identity helpers.
 *
 * Extracted from nvx-structured-data.php without changing runtime behavior.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NVX_CONTACT_EMAIL' ) ) {
	define( 'NVX_CONTACT_EMAIL', function_exists( 'nvx_business_contact_email' ) ? nvx_business_contact_email() : '' );
}
/**
 * Editorial review month label for Endolift® byline (update with clinical review).
 */
if ( ! defined( 'NVX_ENDOLIFT_REVIEW_LABEL' ) ) {
	define( 'NVX_ENDOLIFT_REVIEW_LABEL', 'agosto 2026' );
}

if ( ! defined( 'NVX_SD_ID_MEDICAL_PROCEDURE' ) ) {
	define( 'NVX_SD_ID_MEDICAL_PROCEDURE', '#medical-procedure' );
	define( 'NVX_SD_ENDOLIFT_FACIAL', 'Endolift® facial' );
	define( 'NVX_SD_ID_SERVICE', '#service' );
	define( 'NVX_SD_PATH_EQUIPO_MEDICO', '/equipo-medico/' );
	define( 'NVX_SD_LABEL_NUM_COLEGIADO', 'Número de colegiado ICOMEM' );
	define( 'NVX_SD_LABEL_COLEGIADO_PREFIX', 'Colegiado ICOMEM ' );
	define( 'NVX_SD_ENDOLASER_CORPORAL', 'Endoláser corporal' );
	define( 'NVX_SD_LASER_CO2_FRACCIONADO', 'Láser CO₂ fraccionado' );
	define( 'NVX_SD_MEDICINA_REGENERATIVA', 'Medicina regenerativa' );
	define( 'NVX_SD_SOCIEDAD_SEMEG', 'Sociedad Española de Medicina Geriátrica (SEMEG)' );
}

/** Build one immutable public tariff row. */
function nvxTariffItem( string $label, float $pvp, string $group ): array {
	return compact( 'label', 'pvp', 'group' );
}

/**
 * Load tariff catalog from JSON
 */
function nvx_get_tariff_catalog() {
	$catalog_file = get_template_directory() . '/inc/data/tariff-catalog.json';
	if ( file_exists( $catalog_file ) ) {
		$catalog = json_decode( file_get_contents( $catalog_file ), true );
		return is_array( $catalog ) ? $catalog : array();
	}
	return array();
}

/**
 * Official public PVP catalogue (EUR, IVA 21% included).
 * Source: clinic tariff sheet. Never publish commission / internal cost notes.
 * Now loaded from JSON tariff-catalog.json for single source of truth.
 *
 * @return array{
 *   Endolift®: array<string, array{label:string,pvp:float,group:string}>,
 *   endolift_combo: array<string, array{label:string,pvp:float,group:string}>,
 *   laser_co2: array<string, array{label:string,pvp:float,group:string}>
 * }
 */
function nvx_tariff_catalog() {
	$catalog = nvx_get_tariff_catalog();

	// Convert JSON format to legacy PHP format for backward compatibility
	$result = array();
	foreach ( $catalog as $category => $items ) {
		$result[ $category ] = array();
		foreach ( $items as $key => $item ) {
			$result[ $category ][ $key ] = nvxTariffItem(
				$item['label'],
				(float) $item['pvp'],
				$item['group']
			);
		}
	}

	return $result;
}

/**
 * Lowest public Endolift® PVP (facial ojeras) — used for “desde” GEO copy/schema.
 *
 * @return float
 */
function nvx_endolift_price_from_eur() {
	$pvp = function_exists( 'nvx_tariff_pvp' ) ? nvx_tariff_pvp( 'Endolift®', 'ojeras' ) : null;
	return null !== $pvp ? $pvp : 798.60;
}

/**
 * Reference PVP for papada / marcación mandibular (page core indication).
 *
 * @return float
 */
function nvx_endolift_price_papada_eur() {
	$pvp = function_exists( 'nvx_tariff_pvp' ) ? nvx_tariff_pvp( 'Endolift®', 'papada' ) : null;
	return null !== $pvp ? $pvp : 1064.80;
}

/**
 * Reference PVP for Láser CO₂ facial session — used for "desde" schema/copy.
 * Source of truth: nvx_tariff_catalog()['laser_co2']['facial']['pvp'].
 *
 * Restored Jul-2026: function was never defined; two call sites were silently
 * skipped via function_exists() guards in nvx_schema_treatment_node_laser()
 * and nvx_schema_offer_catalog(), leaving CO2 schema prices permanently empty.
 *
 * @return float
 */
function nvx_co2_price_facial_eur(): float {
	$catalog = nvx_tariff_catalog();
	return (float) ( $catalog['laser_co2']['facial']['pvp'] ?? 330.00 );
}

/**
 * Reference PVP for Láser CO₂ corporal session.
 * Source of truth: nvx_tariff_catalog()['laser_co2']['corporal']['pvp'].
 *
 * @return float
 */
function nvx_co2_price_body_eur(): float {
	$catalog = nvx_tariff_catalog();
	return (float) ( $catalog['laser_co2']['corporal']['pvp'] ?? 450.00 );
}


/**
 * Format a EUR amount for Spanish locale display (2 decimals: 1.064,80).
 *
 * @param int|float|string $amount   Amount in euros.
 * @param int              $decimals Decimal places.
 * @return string
 */
function nvx_format_price_eur( $amount, $decimals = 2 ) {
	return number_format_i18n( (float) $amount, (int) $decimals );
}

/**
 * Schema.org price string (dot decimal, two places).
 *
 * @param int|float|string $amount Amount in euros.
 * @return string
 */
function nvx_schema_price_string( $amount ) {
	return number_format( (float) $amount, 2, '.', '' );
}

/**
 * Canonical page map for schema entities.
 *
 * IDs are staging/production fallbacks only. Runtime resolution prefers
 * permalink path, page URI, page template (Sede Local) and optional post meta
 * `_nvx_clinic_branch` so content moves do not require scattered ID edits.
 *
 * Build one canonical route registry entry.
 *
 * @param int $id The page identifier.
 * @param string $path The page path.
 * @return array{id:int, path:string}
 */
function nvxSchemaRouteEntry( int $id, string $path ): array {
	return compact( 'id', 'path' );
}

/** Build one canonical treatment registry entry.
 *
 * @param int    $id The page identifier.
 * @param string $path The treatment page path.
 * @param string $schema The treatment schema key.
 *
 * @return array The treatment registry entry.
 */
function nvxSchemaTreatmentRegistryEntry( int $id, string $path, string $schema ): array {
	return compact( 'id', 'path', 'schema' );
}

/**
 * Builds the Schema.org route registry for clinics, the clinic hub, and treatments.
 *
 * @return array The registry containing resolved clinic, clinic hub, and treatment route entries.
 */
function nvx_schema_page_registry() {
	$registry = array(
		'clinics'    => array(),
		'clinic_hub' => array(),
		'treatments' => array(),
	);

	$routes = function_exists( 'nvx_catalog_json_resolved' )
		? nvx_catalog_json_resolved( 'routes.json' )
		: array();

	foreach ( $routes as $path => $config ) {
		// Skip the loader's synthetic keys (e.g. '_error') whose value is not
		// a route config array; accessing offsets on them warns under PHP 8.
		if ( ! is_array( $config ) || ( is_string( $path ) && 0 === strpos( $path, '_' ) ) ) {
			continue;
		}
		$group = $config['schema_group'] ?? '';
		if ( ! $group ) {
			continue;
		}

		if ( isset( $config['route_alias'] ) ) {
			continue;
		}

		$id          = isset( $config['post_id'] ) ? (int) $config['post_id'] : 0;
		$schema_id   = $config['schema_id'] ?? '';
		$schema_type = $config['schema_type'] ?? 'MedicalProcedure';

		if ( 'clinics' === $group && $schema_id ) {
			$registry['clinics'][ $schema_id ] = nvxSchemaRouteEntry( $id, $path );
		} elseif ( 'clinic_hub' === $group && empty( $registry['clinic_hub'] ) ) {
			$registry['clinic_hub'] = nvxSchemaRouteEntry( $id, $path );
		} elseif ( 'treatments' === $group && $schema_id ) {
			$registry['treatments'][ $schema_id ] = nvxSchemaTreatmentRegistryEntry( $id, $path, $schema_type );
		}
	}

	// Canonical fallbacks: if routes.json is missing/malformed, downstream
	// consumers (nvx_schema_clinics, nvx_schema_resolve_clinic_keys_from_hub)
	// read fixed keys without isset() guards. Guarantee the structure exists so
	// the schema graph never emits empty url/@id or triggers undefined-key warnings.
	// Validate the path (not just the entry) so a malformed route with an empty
	// path still falls back to the canonical value; nvx_schema_clinics() feeds
	// these paths directly into home_url().
	$clinics = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	foreach ( array( 'chamberi' => 1543, 'goya' => 1537 ) as $clinic_key => $page_id ) {
		if ( empty( $registry['clinics'][ $clinic_key ]['path'] ) && ! empty( $clinics[ $clinic_key ]['landing_path'] ) ) {
			$registry['clinics'][ $clinic_key ] = nvxSchemaRouteEntry( $page_id, (string) $clinics[ $clinic_key ]['landing_path'] );
		}
	}
	if ( empty( $registry['clinic_hub']['path'] ) ) {
		$registry['clinic_hub'] = nvxSchemaRouteEntry( 1399, '/clinicas-de-medicina-estetica-nuvanx/' );
	}

	return $registry;
}

/**
 * Normalize a path for registry comparisons.
 *
 * @param string $path URL path or page URI.
 * @return string Leading/trailing-slash form, e.g. `/foo/bar/`.
 */
function nvx_schema_normalize_path( $path ) {
	$path = (string) $path;
	$path = strtok( $path, '?' );
	$path = '/' . trim( $path, '/' ) . '/';

	return ( '/' === $path || '//' === $path ) ? '/' : $path;
}

function nvx_schema_path_from_page_id( int $page_id ): string {
	$permalink = get_permalink( $page_id );
	if ( is_string( $permalink ) && '' !== $permalink ) {
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? untrailingslashit( $home_path ) : '';
		$page_path = wp_parse_url( $permalink, PHP_URL_PATH );
		$page_path = is_string( $page_path ) ? $page_path : '';

		if ( '' !== $home_path && 0 === strpos( $page_path, $home_path ) ) {
			$page_path = substr( $page_path, strlen( $home_path ) );
		}

		return nvx_schema_normalize_path( $page_path );
	}

	$uri = get_page_uri( $page_id );
	return ( is_string( $uri ) && '' !== $uri ) ? nvx_schema_normalize_path( $uri ) : '';
}

/**
 * Current request path relative to the site home.
 *
 * @param int $page_id Queried page ID when available.
 * @return string
 */
function nvx_schema_current_path( $page_id = 0 ) {
	if ( $page_id > 0 ) {
		$path = nvx_schema_path_from_page_id( (int) $page_id );
		if ( '' !== $path ) {
			return $path;
		}
	}

	$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	return nvx_schema_normalize_path( $request );
}

/**
 * Whether the current path matches a registered path (exact or nested).
 *
 * @param string $current Normalized current path.
 * @param string $target  Registered path.
 * @return bool
 */
function nvx_schema_path_matches( $current, $target ) {
	$current = nvx_schema_normalize_path( $current );
	$target  = nvx_schema_normalize_path( $target );

	if ( $current === $target ) {
		return true;
	}

	// Nested clinic under hub, e.g. goya under clinicas-...
	return '/' !== $target && 0 === strpos( $current, $target );
}

/**
 * Whether the page uses the Sede Local template.
 *
 * @param int $page_id Page ID.
 * @return bool
 */
function nvx_schema_is_sede_template( $page_id ) {
	if ( $page_id <= 0 || ! function_exists( 'get_page_template_slug' ) ) {
		return false;
	}

	$slug = (string) get_page_template_slug( $page_id );

	return (bool) preg_match( '#(^|/)page-sede\.php$#', $slug );
}

function nvx_schema_resolve_clinic_keys_from_meta( int $page_id, array $registry ): array {
	if ( $page_id <= 0 ) {
		return array();
	}
	$meta = strtolower( trim( (string) get_post_meta( $page_id, '_nvx_clinic_branch', true ) ) );
	if ( 'all' === $meta || 'both' === $meta ) {
		return array_keys( $registry['clinics'] );
	} elseif ( isset( $registry['clinics'][ $meta ] ) ) {
		return array( $meta );
	}
	return array();
}

function nvx_schema_resolve_clinic_keys_from_registry( int $page_id, string $path, array $registry ): array {
	$matched = array();
	foreach ( $registry['clinics'] as $key => $entry ) {
		if ( (int) $entry['id'] === $page_id || nvx_schema_path_matches( $path, $entry['path'] ) ) {
			$matched[] = $key;
		}
	}
	return ! empty( $matched ) ? array_values( array_unique( $matched ) ) : array();
}

function nvx_schema_resolve_clinic_keys_from_hub( int $page_id, string $path, array $registry ): array {
	$hub = $registry['clinic_hub'];
	if ( (int) $hub['id'] === $page_id || nvx_schema_path_matches( $path, $hub['path'] ) || nvx_schema_is_sede_template( $page_id ) ) {
		return array_keys( $registry['clinics'] );
	}
	return array();
}

/**
 * Resolve which clinic branch keys apply on the current page.
 *
 * Order: front/hub → post meta → sede template + path → path/ID registry.
 *
 * @param int $page_id Current page ID.
 * @return string[] Empty, one key, or both clinic keys (chamberi, goya).
 */
function nvx_schema_resolve_clinic_keys( $page_id ) {
	$registry = nvx_schema_page_registry();
	$path     = nvx_schema_current_path( $page_id );

	if ( is_front_page() ) {
		return array_keys( $registry['clinics'] );
	}

	$keys = nvx_schema_resolve_clinic_keys_from_meta( (int) $page_id, $registry );

	if ( empty( $keys ) ) {
		$keys = nvx_schema_resolve_clinic_keys_from_registry( (int) $page_id, $path, $registry );
	}

	if ( empty( $keys ) ) {
		$keys = nvx_schema_resolve_clinic_keys_from_hub( (int) $page_id, $path, $registry );
	}

	return $keys;
}

/**
 * Resolve a treatment registry key for the current page, if any.
 *
 * @param int $page_id Current page ID.
 * @return string|null Registry key or null.
 */
function nvx_schema_resolve_treatment_key( $page_id ) {
	$registry = nvx_schema_page_registry();
	$path     = nvx_schema_current_path( $page_id );

	foreach ( $registry['treatments'] as $key => $entry ) {
		$id_match   = ! empty( $entry['id'] ) && (int) $entry['id'] === $page_id;
		$path_match = nvx_schema_path_matches( $path, $entry['path'] );
		if ( $id_match || $path_match ) {
			return $key;
		}
	}

	return null;
}

/**
 * Return true when a Schema.org @type contains the requested type.
 *
 * @param mixed  $types Schema @type value.
 * @param string $type  Type to locate.
 * @return bool
 */
function nvx_schema_has_type( $types, $type ) {
	$types = is_array( $types ) ? $types : array( $types );

	return in_array( $type, $types, true );
}

/**
 * Append a type without discarding Yoast's original type.
 *
 * @param mixed  $types Schema @type value.
 * @param string $type  Type to append.
 * @return array
 */
function nvx_schema_add_type( $types, $type ) {
	$types = is_array( $types ) ? $types : array( $types );
	$types = array_values( array_filter( $types ) );

	if ( ! in_array( $type, $types, true ) ) {
		$types[] = $type;
	}

	return $types;
}

/**
 * Return the canonical branch definitions used by visible content and Schema.
 *
 * Coordinates are intentionally omitted until independently verified against
 * the official location records. Google accepts address as the required local
 * business location field and treats geo as recommended rather than required.
 *
 * @return array
 */
function nvx_schema_clinics() {
	$registry = nvx_schema_page_registry();
	$config   = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

	$ch = isset( $config['chamberi'] ) ? $config['chamberi'] : array();
	$go = isset( $config['goya'] ) ? $config['goya'] : array();

	// Build opening hours from config
	$ch_opening_hours = array();
	foreach ( $ch['opening_hours'] ?? array() as $spec ) {
		$ch_opening_hours[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => $spec['days'] ?? array(),
			'opens'     => $spec['opens'] ?? '',
			'closes'    => $spec['closes'] ?? '',
		);
	}

	$go_opening_hours = array();
	foreach ( $go['opening_hours'] ?? array() as $spec ) {
		$go_opening_hours[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => $spec['days'] ?? array(),
			'opens'     => $spec['opens'] ?? '',
	        'closes'    => $spec['closes'] ?? '',
		);
	}

	return array(
		'chamberi' => array(
			'@type'                     => array( 'MedicalClinic', 'LocalBusiness' ),
			'@id'                       => home_url( '/#/schema/medical-clinic/chamberi' ),
			'name'                      => $ch['name'] ?? 'NUVANX Medicina Estética Láser — Chamberí',
			'branchCode'                => 'chamberi',
			'url'                       => home_url( $registry['clinics']['chamberi']['path'] ),
			'telephone'                 => $ch['phone_href'] ?? '',
			'email'                     => NVX_CONTACT_EMAIL,
			'image'                     => function_exists( 'nvx_clinic_schema_image_urls' )
				? nvx_clinic_schema_image_urls( 'chamberi' )
				: array( trailingslashit( get_template_directory_uri() ) . 'assets/images/clinics/chamberi/01-interior.jpg' ),
			'address'                   => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $ch['address'] ?? '',
				'addressLocality' => $ch['locality'] ?? 'Madrid',
				'addressRegion'   => 'Comunidad de Madrid',
				'postalCode'      => $ch['postal_code'] ?? '',
				'addressCountry'  => 'ES',
			),
			'geo'                       => array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $ch['latitude'] ?? 40.431204,
				'longitude' => $ch['longitude'] ?? -3.693425,
			),
			'identifier'                => array(
				'@type'      => 'PropertyValue',
				'propertyID' => 'Registro sanitario de la Comunidad de Madrid',
				'value'      => $ch['reg'] ?? '',
			),
			'hasMap'                    => 'https://www.google.com/maps/search/?api=1&query=NUVANX%20Medicina%20Est%C3%A9tica%20L%C3%A1ser%20C%2F%20de%20Fern%C3%A1ndez%20de%20la%20Hoz%204%2028010%20Madrid',
			'areaServed'                => array( 'Chamberí', 'Almagro', 'Trafalgar', 'Malasaña', 'Ríos Rosas', 'Madrid' ),
			'description'               => 'Medicina estética láser premium en Chamberí, Madrid. Endolift®, endoláser corporal, láser CO₂ fraccionado y neuromoduladores con dirección médica especializada. Cerca de Almagro, Malasaña y Ríos Rosas.',
			'openingHoursSpecification' => ! empty( $ch_opening_hours ) ? $ch_opening_hours : array(
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ),
					'opens'     => '10:00',
					'closes'    => '20:00',
				),
			),
			'priceRange'                => '€€€',
			'medicalSpecialty'          => array( 'https://schema.org/PlasticSurgery', 'https://schema.org/Dermatology' ),
			'sameAs'                    => array(
				'https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser',
			),
		),
		'goya'     => array(
			'@type'                     => array( 'MedicalClinic', 'LocalBusiness' ),
			'@id'                       => home_url( '/#/schema/medical-clinic/goya' ),
			'name'                      => $go['name'] ?? 'NUVANX Medicina Estética Láser — Goya · Barrio Salamanca',
			'branchCode'                => 'goya',
			'url'                       => home_url( $registry['clinics']['goya']['path'] ),
			'telephone'                 => $go['phone_href'] ?? '',
			'email'                     => NVX_CONTACT_EMAIL,
			'image'                     => function_exists( 'nvx_clinic_schema_image_urls' )
				? nvx_clinic_schema_image_urls( 'goya' )
				: array( trailingslashit( get_template_directory_uri() ) . 'assets/images/clinics/goya/01-fachada.jpg' ),
			'address'                   => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $go['address'] ?? '',
				'addressLocality' => $go['locality'] ?? 'Madrid',
				'addressRegion'   => 'Comunidad de Madrid',
				'postalCode'      => $go['postal_code'] ?? '',
				'addressCountry'  => 'ES',
			),
			'geo'                       => array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $go['latitude'] ?? 40.423912,
				'longitude' => $go['longitude'] ?? -3.675648,
			),
			'identifier'                => array(
				'@type'      => 'PropertyValue',
				'propertyID' => 'Registro sanitario de la Comunidad de Madrid',
				'value'      => $go['reg'] ?? '',
			),
			'hasMap'                    => 'https://www.google.com/maps/search/?api=1&query=NUVANX%20Goya%20C%2F%20de%20Fern%C3%A1n%20Gonz%C3%A1lez%2026%2028009%20Madrid',
			'areaServed'                => array( 'Goya', 'Barrio de Salamanca', 'Lista', 'Recoletos', 'Velázquez', 'Serrano', 'Madrid' ),
			'description'               => 'Medicina estética láser premium en Goya, Barrio Salamanca, Madrid. Endolift®, endoláser corporal, láser CO₂ fraccionado y neuromoduladores con dirección médica especializada. Cerca de Lista, Recoletos, Velázquez y Serrano.',
			'openingHoursSpecification' => ! empty( $go_opening_hours ) ? $go_opening_hours : array(
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ),
					'opens'     => '11:00',
					'closes'    => '20:00',
				),
			),
			'priceRange'                => '€€€',
			'medicalSpecialty'          => array( 'https://schema.org/PlasticSurgery', 'https://schema.org/Dermatology' ),
			'sameAs'                    => array(
				'https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser-sede-goya',
			),
		),
	);
}

/**
 * Return the canonical Organization identifier.
 *
 * There is exactly one Organization entity in the graph: #organization.
 * This function ensures all schema producers reference the same ID.
 *
 * @return string Canonical Organization @id
 */
function nvx_schema_organization_id(): string {
	return home_url( '/#organization' );
}

/**
 * Find the Yoast Organization node and return its index and identifier.
 *
 * @param array $graph Yoast Schema graph.
 * @return array{index:int|null,id:string}
 */
function nvx_schema_find_organization( $graph ) {
	foreach ( $graph as $index => $piece ) {
		if (
			isset( $piece['@type'], $piece['@id'] )
			&& nvx_schema_has_type( $piece['@type'], 'Organization' )
			&& ! nvx_schema_has_type( $piece['@type'], 'WebSite' )
		) {
			return array(
				'index' => $index,
				'id'    => $piece['@id'],
			);
		}
	}

	return array(
		'index' => null,
		'id'    => nvx_schema_organization_id(),
	);
}
