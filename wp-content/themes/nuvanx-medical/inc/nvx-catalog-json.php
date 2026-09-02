<?php
/**
 * Shared loader for structured JSON catalogs.
 *
 * Editorial JSON owns structure/copy. Public tariff values are owned only by
 * tariff-catalog.json and are hydrated here before renderers receive a catalog.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Log one catalog integrity problem at most once per request. */
function nvx_catalog_log_error( string $message ): void {
	static $seen = array();

	$fingerprint = md5( $message );
	if ( isset( $seen[ $fingerprint ] ) ) {
		return;
	}
	$seen[ $fingerprint ] = true;

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'NUVANX catalog: ' . $message );
	}
}

/**
 * Load and request-cache one JSON catalog from inc/data.
 *
 * @return array<mixed>
 */
function nvx_catalog_json_load( string $filename ): array {
	static $catalogs = array();

	$safe_name = basename( $filename );
	if ( array_key_exists( $safe_name, $catalogs ) ) {
		return $catalogs[ $safe_name ];
	}

	$path   = __DIR__ . '/data/' . $safe_name;
	$result = array( '_error' => false );

	if ( ! is_readable( $path ) ) {
		nvx_catalog_log_error( sprintf( 'Missing JSON file: %s', $safe_name ) );
		$result['_error']       = 'missing_file';
		$catalogs[ $safe_name ] = $result;
		return $result;
	}

	$json = file_get_contents( $path );
	$data = false !== $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
		nvx_catalog_log_error(
			sprintf( 'Malformed JSON "%s": %s', $safe_name, json_last_error_msg() )
		);
		$result['_error']       = 'malformed_json';
		$catalogs[ $safe_name ] = $result;
		return $result;
	}

	$catalogs[ $safe_name ] = array_merge( $result, $data );
	return $catalogs[ $safe_name ];
}

/**
 * Transform catalog values while preserving keys and nesting.
 *
 * @param mixed                   $value Catalog value.
 * @param callable                $transform String transformer.
 * @param array<string, callable> $object_resolvers Structured token resolvers.
 * @return mixed
 */
function nvx_catalog_transform_values(
	$value,
	callable $transform,
	array $object_resolvers = array()
) {
	if ( is_array( $value ) ) {
		if ( 1 === count( $value ) ) {
			$key = array_key_first( $value );
			if ( is_string( $key ) && isset( $object_resolvers[ $key ] ) ) {
				return $object_resolvers[ $key ]( $value[ $key ] );
			}
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = nvx_catalog_transform_values( $item, $transform, $object_resolvers );
		}
		return $value;
	}

	return is_string( $value ) ? $transform( $value ) : $value;
}

/** Built-in string-prefix resolvers. */
function nvx_catalog_builtin_token_resolvers(): array {
	$translate = static function ( string $payload ) {
		return '' === $payload ? '' : __( $payload, 'nuvanx-medical' );
	};

	return array(
		'@nvx-t:'   => $translate,
		'@nvx:t:'   => $translate,
		'@nvx-url:' => static function ( string $payload ) {
			return home_url( $payload );
		},
	);
}

/** Centralized catalog governance configuration. */
function nvx_catalog_governance_config(): array {
	static $config = null;
	if ( null !== $config ) {
		return $config;
	}

	$config = array(
		'endolift' => array(
			'price_faq_index' => 0,
			'investment_key'  => 'investment',
		),
		'exion' => array(
			'price_faq_index' => 0,
			'investment_key'  => 'investment',
		),
		'endolaser' => array(
			'price_faq_index' => 7,
			'planning_key'    => 'planning',
		),
		'laser_co2' => array(
			'price_faq_index' => 0,
			'investment_key'  => 'investment',
		),
	);

	return $config;
}

/** Safely extract numeric PVP from tariff data. */
function nvx_catalog_get_tariff_pvp( array $tariffs, string $group, string $key ): ?float {
	if ( isset( $tariffs[ $group ][ $key ]['pvp'] ) && is_numeric( $tariffs[ $group ][ $key ]['pvp'] ) ) {
		return (float) $tariffs[ $group ][ $key ]['pvp'];
	}
	return null;
}

/** Return one canonical tariff as a display-ready euro amount. */
function nvx_catalog_tariff_display_price( array $tariffs, string $group, string $key ): string {
	$amount = nvx_catalog_get_tariff_pvp( $tariffs, $group, $key );
	if ( null === $amount ) {
		return '';
	}

	$decimals = abs( $amount - round( $amount ) ) < 0.005 ? 0 : 2;
	return number_format( $amount, $decimals, ',', '.' ) . ' €';
}

/** Whether all required canonical tariffs exist and are numeric. */
function nvx_catalog_tariffs_complete( array $tariffs, array $requirements ): bool {
	if ( ! empty( $tariffs['_error'] ) ) {
		return false;
	}

	foreach ( $requirements as $requirement ) {
		if ( ! is_array( $requirement ) || 2 !== count( $requirement ) ) {
			return false;
		}
		if ( null === nvx_catalog_get_tariff_pvp( $tariffs, (string) $requirement[0], (string) $requirement[1] ) ) {
			return false;
		}
	}
	return true;
}

/** Neutral price copy used only when the canonical tariff source is unavailable. */
function nvx_catalog_price_unavailable_copy(): string {
	return __( 'Presupuesto individualizado tras valoración médica. Consulta la tarifa vigente con el equipo antes de confirmar el tratamiento.', 'nuvanx-medical' );
}

/** Locate an aesthetic-hub treatment by its stable editorial number. */
function nvx_catalog_aesthetic_treatment_index( array $catalog, string $number ): ?int {
	foreach ( $catalog['treatments'] ?? array() as $index => $treatment ) {
		if ( is_array( $treatment ) && $number === (string) ( $treatment['n'] ?? '' ) ) {
			return (int) $index;
		}
	}
	return null;
}

/** Set one aesthetic-hub price by stable treatment identity, never array position. */
function nvx_catalog_set_aesthetic_treatment_price( array &$catalog, string $number, string $price ): bool {
	$index = nvx_catalog_aesthetic_treatment_index( $catalog, $number );
	if ( null === $index || ! isset( $catalog['treatments'][ $index ] ) || ! is_array( $catalog['treatments'][ $index ] ) ) {
		return false;
	}
	$catalog['treatments'][ $index ]['price'] = $price;
	return true;
}

/** Fail closed on public price copy while preserving the rest of the page. */
function nvx_catalog_suppress_price_copy( array $catalog, string $safe_name, array $config ): array {
	$neutral = nvx_catalog_price_unavailable_copy();

	if ( 'endolift-page.json' === $safe_name ) {
		$inv_key = $config['endolift']['investment_key'] ?? 'investment';
		if ( isset( $catalog[ $inv_key ]['body'] ) ) {
			$catalog[ $inv_key ]['body'] = $neutral;
		}
		$faq_idx = $config['endolift']['price_faq_index'] ?? 0;
		if ( isset( $catalog['faq']['items'][ $faq_idx ]['a'] ) ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = $neutral;
		}
	} elseif ( 'exion-page.json' === $safe_name ) {
		$inv_key = $config['exion']['investment_key'] ?? 'investment';
		if ( isset( $catalog[ $inv_key ]['body'] ) ) {
			$catalog[ $inv_key ]['body'] = $neutral;
		}
		$faq_idx = $config['exion']['price_faq_index'] ?? 0;
		if ( isset( $catalog['faq']['items'][ $faq_idx ]['a'] ) ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = $neutral;
		}
	} elseif ( 'endolaser-page.json' === $safe_name ) {
		$plan_key = $config['endolaser']['planning_key'] ?? 'planning';
		if ( isset( $catalog[ $plan_key ]['body'] ) ) {
			$catalog[ $plan_key ]['body'] = $neutral;
		}
		$faq_idx = $config['endolaser']['price_faq_index'] ?? 7;
		if ( isset( $catalog['faq']['items'][ $faq_idx ]['a'] ) ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = $neutral;
		}
	} elseif ( 'aesthetic-medicine-page.json' === $safe_name ) {
		foreach ( array( '01', '02', '03', '05' ) as $number ) {
			nvx_catalog_set_aesthetic_treatment_price( $catalog, $number, $neutral );
		}
	} elseif ( 'laser-co2-page.json' === $safe_name ) {
		$inv_key = $config['laser_co2']['investment_key'] ?? 'investment';
		if ( isset( $catalog[ $inv_key ]['body'] ) ) {
			$catalog[ $inv_key ]['body'] = $neutral;
		}
		$faq_idx = $config['laser_co2']['price_faq_index'] ?? 0;
		if ( isset( $catalog['faq']['items'][ $faq_idx ]['a'] ) ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = $neutral;
		}
	}

	return $catalog;
}

/**
 * Reconcile public price copy with tariff-catalog.json.
 *
 * The function neutralizes price-bearing copy first. Canonical values are then
 * restored only when every tariff required by that public block exists.
 */
function nvx_catalog_apply_tariff_truth( array $catalog, string $safe_name, ?array $config = null ): array {
	$config = $config ?? nvx_catalog_governance_config();
	$governed_catalogs = array(
		'endolift-page.json',
		'exion-page.json',
		'endolaser-page.json',
		'aesthetic-medicine-page.json',
		'laser-co2-page.json',
	);
	if ( ! in_array( $safe_name, $governed_catalogs, true ) ) {
		return $catalog;
	}

	$catalog = nvx_catalog_suppress_price_copy( $catalog, $safe_name, $config );
	$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );

	if ( 'endolift-page.json' === $safe_name ) {
		$requirements = array(
			array( 'Endolift®', 'ojeras' ), array( 'Endolift®', 'papada' ), array( 'Endolift®', 'cuello' ),
			array( 'endolift_combo', 'papada_cuello' ), array( 'endolift_combo', 'full_face' ),
		);
		if ( ! nvx_catalog_tariffs_complete( $tariffs, $requirements ) ) {
			nvx_catalog_log_error( 'Endolift® price hydration failed closed: canonical tariff incomplete.' );
			return $catalog;
		}

		$ojeras    = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'ojeras' );
		$papada    = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'papada' );
		$cuello    = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'cuello' );
		$combo     = nvx_catalog_tariff_display_price( $tariffs, 'endolift_combo', 'papada_cuello' );
		$full_face = nvx_catalog_tariff_display_price( $tariffs, 'endolift_combo', 'full_face' );
		$inv_key   = $config['endolift']['investment_key'] ?? 'investment';
		$catalog[ $inv_key ]['body'] = sprintf(
			__( 'El plan y presupuesto de Endolift® se determinan tras la valoración médica presencial en Chamberí o Salamanca–Goya. Tarifas de referencia: desde %1$s (ojeras), %2$s (papada o marcación mandibular cada una), %3$s (cuello). Combos frecuentes como papada+cuello (%4$s) o full face (%5$s) se valoran según indicación. El presupuesto definitivo se documenta tras valoración anatómica presencial. El procedimiento se realiza en 1 sola sesión en la mayoría de indicaciones, con control evolutivo a los 3 y 6 meses. Cada tratamiento incluye:', 'nuvanx-medical' ),
			$ojeras, $papada, $cuello, $combo, $full_face
		);
		$faq_idx = $config['endolift']['price_faq_index'] ?? 0;
		$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
			__( 'La tarifa de referencia parte desde %1$s (ojeras). Papada y marcación mandibular: %2$s cada una. El presupuesto definitivo se documenta tras valoración anatómica presencial.', 'nuvanx-medical' ),
			$ojeras, $papada
		);
		return $catalog;
	}

	if ( 'exion-page.json' === $safe_name ) {
		$requirements = array(
			array( 'exion', 'exion_face_sesion' ), array( 'exion', 'exion_body_sesion' ), array( 'exion', 'exion_fractional_cara' ),
		);
		if ( ! nvx_catalog_tariffs_complete( $tariffs, $requirements ) ) {
			nvx_catalog_log_error( 'EXION® price hydration failed closed: canonical tariff incomplete.' );
			return $catalog;
		}
		$face       = nvx_catalog_tariff_display_price( $tariffs, 'exion', 'exion_face_sesion' );
		$body       = nvx_catalog_tariff_display_price( $tariffs, 'exion', 'exion_body_sesion' );
		$fractional = nvx_catalog_tariff_display_price( $tariffs, 'exion', 'exion_fractional_cara' );
		$inv_key    = $config['exion']['investment_key'] ?? 'investment';
		$catalog[ $inv_key ]['body'] = sprintf(
			__( 'El plan y presupuesto se determinan tras la valoración médica presencial en Chamberí o Salamanca–Goya. Tarifas de referencia vigentes: desde %1$s/sesión (EXION® Face), %2$s/sesión (EXION® Body) y %3$s (EXION® Fractional RF). El presupuesto definitivo se documenta tras valoración anatómica presencial. El protocolo incluye:', 'nuvanx-medical' ),
			$face, $body, $fractional
		);
		$faq_idx = $config['exion']['price_faq_index'] ?? 0;
		$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
			__( 'Las tarifas de referencia vigentes parten desde %1$s/sesión (EXION® Face), %2$s/sesión (EXION® Body) y %3$s (EXION® Fractional RF). El presupuesto definitivo se documenta tras valoración anatómica presencial.', 'nuvanx-medical' ),
			$face, $body, $fractional
		);
		return $catalog;
	}

	if ( 'endolaser-page.json' === $safe_name ) {
		$requirements = array(
			array( 'Endolift®', 'rodillas' ), array( 'Endolift®', 'brazos' ), array( 'Endolift®', 'flancos' ), array( 'Endolift®', 'abdomen' ),
		);
		if ( ! nvx_catalog_tariffs_complete( $tariffs, $requirements ) ) {
			nvx_catalog_log_error( 'Endoláser price hydration failed closed: canonical tariff incomplete.' );
			return $catalog;
		}
		$rodillas = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'rodillas' );
		$brazos   = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'brazos' );
		$flancos  = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'flancos' );
		$abdomen  = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'abdomen' );
		$plan_key = $config['endolaser']['planning_key'] ?? 'planning';
		$catalog[ $plan_key ]['body'] = sprintf(
			__( 'El presupuesto se calcula por zona o combinación de zonas según el tarifario vigente y se documenta tras la valoración médica presencial. Tarifas de referencia vigentes: desde %1$s (rodillas), %2$s (brazos o cara interna de muslos), %3$s (flancos) y %4$s (abdomen completo). Procedimiento en 1 sesión única con medición ecográfica previa y revisiones clínicas a 4 semanas, 3 y 6 meses. Incluye pauta de compresión post-procedimiento.', 'nuvanx-medical' ),
			$rodillas, $brazos, $flancos, $abdomen
		);
		$faq_idx = $config['endolaser']['price_faq_index'] ?? 7;
		$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
			__( 'En NUVANX las tarifas de referencia vigentes de Endoláser corporal parten desde %1$s (zonas focalizadas como rodillas) hasta %2$s (abdomen completo), según extensión, plan médico y anatomía. El presupuesto definitivo se documenta tras la valoración médica presencial.', 'nuvanx-medical' ),
			$rodillas, $abdomen
		);
		return $catalog;
	}

	if ( 'aesthetic-medicine-page.json' === $safe_name ) {
		$requirements = array(
			array( 'labios_ha', 'hidratacion' ),
			array( 'rinomodelacion_ha', 'rinomodelacion' ),
			array( 'ojeras_ha', 'surco_lagrimal' ),
			array( 'bioestimuladores', 'polynucleotides_cara' ),
		);
		if ( ! nvx_catalog_tariffs_complete( $tariffs, $requirements ) ) {
			nvx_catalog_log_error( 'Aesthetic medicine price hydration failed closed: canonical tariff incomplete.' );
			return $catalog;
		}

		$labios = nvx_catalog_tariff_display_price( $tariffs, 'labios_ha', 'hidratacion' );
		$rino   = nvx_catalog_tariff_display_price( $tariffs, 'rinomodelacion_ha', 'rinomodelacion' );
		$ojeras = nvx_catalog_tariff_display_price( $tariffs, 'ojeras_ha', 'surco_lagrimal' );
		$bio    = nvx_catalog_tariff_display_price( $tariffs, 'bioestimuladores', 'polynucleotides_cara' );

		$success  = nvx_catalog_set_aesthetic_treatment_price( $catalog, '01', sprintf( __( 'Desde %s', 'nuvanx-medical' ), $labios ) );
		$success &= nvx_catalog_set_aesthetic_treatment_price( $catalog, '02', sprintf( __( 'Desde %s', 'nuvanx-medical' ), $rino ) );
		$success &= nvx_catalog_set_aesthetic_treatment_price( $catalog, '03', sprintf( __( 'Desde %s (según diagnóstico y técnica)', 'nuvanx-medical' ), $ojeras ) );
		$success &= nvx_catalog_set_aesthetic_treatment_price( $catalog, '05', sprintf( __( 'Desde %s (según principio activo y zona)', 'nuvanx-medical' ), $bio ) );
		if ( ! $success ) {
			nvx_catalog_log_error( 'Aesthetic medicine treatment identities changed; canonical prices failed closed for missing cards.' );
			return nvx_catalog_suppress_price_copy( $catalog, $safe_name, $config );
		}
		return $catalog;
	}

	$requirements = array( array( 'laser_co2', 'facial' ), array( 'laser_co2', 'corporal' ) );
	if ( ! nvx_catalog_tariffs_complete( $tariffs, $requirements ) ) {
		nvx_catalog_log_error( 'Laser CO2 price hydration failed closed: canonical tariff incomplete.' );
		return $catalog;
	}
	$facial   = nvx_catalog_tariff_display_price( $tariffs, 'laser_co2', 'facial' );
	$corporal = nvx_catalog_tariff_display_price( $tariffs, 'laser_co2', 'corporal' );
	$inv_key  = $config['laser_co2']['investment_key'] ?? 'investment';
	$catalog[ $inv_key ]['body'] = sprintf(
		__( 'El plan y presupuesto se determinan tras la valoración médica presencial en Chamberí o Salamanca–Goya. Tarifas de referencia vigentes: desde %1$s/sesión (facial), %2$s/sesión (corporal). El presupuesto definitivo se documenta tras valoración anatómica presencial. El protocolo incluye:', 'nuvanx-medical' ),
		$facial, $corporal
	);
	$faq_idx = $config['laser_co2']['price_faq_index'] ?? 0;
	$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
		__( 'Las tarifas de referencia vigentes parten desde %1$s/sesión (facial) y %2$s/sesión (corporal). El presupuesto definitivo se documenta tras valoración anatómica presencial.', 'nuvanx-medical' ),
		$facial, $corporal
	);
	return $catalog;
}

/** Apply runtime governance corrections that depend on canonical code data. */
function nvx_catalog_apply_runtime_truth( array $catalog, string $filename, ?array $config = null ): array {
	$config    = $config ?? nvx_catalog_governance_config();
	$safe_name = basename( $filename );
	$catalog   = nvx_catalog_apply_tariff_truth( $catalog, $safe_name, $config );

	if ( 'equipo-medico-page.json' === $safe_name && isset( $catalog['rivera']['quote']['author'] ) ) {
		$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
		$catalog['rivera']['quote']['author'] = str_replace( '%s', $colegiado, (string) $catalog['rivera']['quote']['author'] );
	}

	if ( 'btl-detail-pages.json' === $safe_name ) {
		if ( isset( $catalog['exion-face']['mechanism']['items'][2]['body'] ) ) {
			$catalog['exion-face']['mechanism']['items'][2]['body'] = __( 'El sistema de IA monitoriza la impedancia cutánea y ajusta automáticamente la entrega de energía para limitar puntos calientes y mejorar el control térmico y el confort durante el procedimiento.', 'nuvanx-medical' );
		}
		if ( isset( $catalog['emfusion']['clinical_data']['downtime'] ) ) {
			$catalog['emfusion']['clinical_data']['downtime'] = __( 'Recuperación habitualmente mínima; pueden aparecer reacciones cutáneas transitorias según sensibilidad y protocolo.', 'nuvanx-medical' );
		}
	}

	return $catalog;
}

/** Prevent legacy EXION® content from overwriting the tariff-hydrated block. */
function nvx_catalog_disable_legacy_exion_investment_override(): void {
	if ( function_exists( 'nvx_content_ensure_exion_investment' ) ) {
		remove_filter( 'the_content', 'nvx_content_ensure_exion_investment', NVX_HOOK_PRIO_EXION_INVESTMENT );
	}
}
add_action( 'wp', 'nvx_catalog_disable_legacy_exion_investment_override', 1 );

/** Pure legacy Bridal provenance predicate. */
function nvx_catalog_is_legacy_bridal_seed( bool $has_meta_key, bool $has_seed_marker ): bool {
	return $has_meta_key && $has_seed_marker;
}

/** Resolve a single string token. */
function nvx_catalog_resolve_token_value( string $value, ?callable $claim_resolver, array $resolvers ): string {
	$resolved = $value;
	foreach ( $resolvers as $prefix => $resolver ) {
		if ( 0 === strpos( $value, $prefix ) ) {
			$resolved = $resolver( substr( $value, strlen( $prefix ) ) );
			break;
		}
	}

	if ( $resolved === $value && null !== $claim_resolver && 0 === strpos( $value, '@nvx-claim-key:' ) ) {
		$resolved = $claim_resolver( substr( $value, strlen( '@nvx-claim-key:' ) ) );
	}
	return $resolved;
}

/** Resolve WordPress-aware values captured in JSON catalogs. */
function nvx_catalog_resolve_tokens(
	array $catalog,
	?callable $claim_resolver = null,
	array $custom_resolvers = array(),
	array $object_resolvers = array()
): array {
	$resolvers = nvx_catalog_builtin_token_resolvers() + $custom_resolvers;
	uksort(
		$resolvers,
		static function ( string $left, string $right ): int {
			return strlen( $right ) <=> strlen( $left );
		}
	);

	return nvx_catalog_transform_values(
		$catalog,
		static function ( string $value ) use ( $claim_resolver, $resolvers ) {
			return nvx_catalog_resolve_token_value( $value, $claim_resolver, $resolvers );
		},
		$object_resolvers
	);
}

/** Load, resolve, govern and request-cache one catalog. */
function nvx_catalog_json_resolved(
	string $filename,
	?callable $claim_resolver = null,
	array $custom_resolvers = array(),
	array $object_resolvers = array(),
	string $cache_key = ''
): array {
	static $resolved = array();

	$locale = '';
	if ( function_exists( 'determine_locale' ) ) {
		$locale = (string) determine_locale();
	}
	if ( '' === $locale && function_exists( 'get_locale' ) ) {
		$locale = (string) get_locale();
	}

	$custom_keys = array_keys( $custom_resolvers );
	$object_keys = array_keys( $object_resolvers );
	sort( $custom_keys, SORT_STRING );
	sort( $object_keys, SORT_STRING );
	$resolver_signature = implode( ',', $custom_keys ) . '|' . implode( ',', $object_keys ) . '|' . ( null === $claim_resolver ? '0' : '1' );
	$base_key = '' === $cache_key ? basename( $filename ) . '|' . $resolver_signature : $cache_key;
	$key      = $base_key . '|locale:' . $locale;

	if ( ! array_key_exists( $key, $resolved ) ) {
		$catalog = nvx_catalog_resolve_tokens(
			nvx_catalog_json_load( $filename ),
			$claim_resolver,
			$custom_resolvers,
			$object_resolvers
		);
		$resolved[ $key ] = nvx_catalog_apply_runtime_truth( $catalog, $filename );
	}
	return $resolved[ $key ];
}

/** Supply neutral defaults for optional aesthetic presentation fields. */
function nvx_catalog_apply_optional_defaults( array $entry, string $catalog_name ): array {
	if ( 'aesthetic-treatment-pages.json' !== $catalog_name ) {
		return $entry;
	}

	$defaults = array(
		'brands'       => array(),
		'duration'     => '',
		'session_time' => '',
		'anesthesia'   => '',
		'techniques'   => array(),
		'price_range'  => '',
		'sessions'     => '',
		'downtime'     => '',
	);
	foreach ( $defaults as $key => $default ) {
		if ( ! array_key_exists( $key, $entry ) ) {
			$entry[ $key ] = $default;
		}
	}
	return $entry;
}

/** Retain only catalog records that contain every required key. */
function nvx_catalog_filter_records( array $catalog, array $required_keys, string $catalog_name ): array {
	$valid = array();
	foreach ( $catalog as $key => $entry ) {
		if ( is_array( $entry ) ) {
			$entry = nvx_catalog_apply_optional_defaults( $entry, $catalog_name );
		}
		if ( ! is_array( $entry ) || array() !== array_diff( $required_keys, array_keys( $entry ) ) ) {
			nvx_catalog_log_error( sprintf( 'Incomplete record %s in %s.', (string) $key, $catalog_name ) );
			continue;
		}
		$valid[ $key ] = $entry;
	}
	return $valid;
}
