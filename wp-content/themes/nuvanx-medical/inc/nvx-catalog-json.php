<?php
/**
 * Shared loader for large structured catalogs stored outside PHP source.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Log a catalog integrity error when WordPress debugging is enabled. */
function nvx_catalog_log_error( string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'NUVANX catalog: ' . $message );
	}
}

/**
 * Load and cache a JSON catalog from inc/data.
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
	$result = array(
		'_error' => false,
	);

	if ( ! file_exists( $path ) ) {
		error_log( sprintf( '[nvx_catalog_json_load] Missing JSON file: %s', $path ) );
		$result['_error']       = 'missing_file';
		$catalogs[ $safe_name ] = $result;
		return $result;
	}

	$json = file_get_contents( $path );
	$data = json_decode( $json, true );

	if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
		error_log(
			sprintf(
				'[nvx_catalog_json_load] Malformed JSON "%s": %s',
				$path,
				json_last_error_msg()
			)
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

/**
 * Built-in string-prefix resolvers (longest-prefix match applied later).
 *
 * @return array<string, callable>
 */
function nvx_catalog_builtin_token_resolvers(): array {
	$translate = static function ( string $payload ) {
		return '' === $payload ? '' : __( $payload, 'nuvanx-medical' );
	};

	return array(
		'@nvx-t:'   => $translate,
		// Legacy typo accepted during hydration so an editorial token can never leak to HTML.
		'@nvx:t:'   => $translate,
		'@nvx-url:' => static function ( string $payload ) {
			return home_url( $payload );
		},
	);
}

/**
 * Centralized governance configuration for catalog truth and schema overrides.
 *
 * Uses static caching to avoid repeated array allocations.
 *
 * @return array<string, mixed>
 */
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
			'planning_key'   => 'planning',
		),
	);

	return $config;
}

/**
 * Safely extract numeric PVP from tariff data.
 *
 * @param array<mixed> $tariffs
 */
function nvx_catalog_get_tariff_pvp( array $tariffs, string $group, string $key ): ?float {
	if ( isset( $tariffs[ $group ][ $key ]['pvp'] ) && is_numeric( $tariffs[ $group ][ $key ]['pvp'] ) ) {
		return (float) $tariffs[ $group ][ $key ]['pvp'];
	}

	return null;
}

/**
 * Return one canonical tariff as a display-ready euro amount.
 */
function nvx_catalog_tariff_display_price( array $tariffs, string $group, string $key ): string {
	$amount = nvx_catalog_get_tariff_pvp( $tariffs, $group, $key );
	if ( null === $amount ) {
		return '';
	}

	$decimals = abs( $amount - round( $amount ) ) < 0.005 ? 0 : 2;
	return number_format( $amount, $decimals, ',', '.' ) . ' €';
}

/**
 * Reconcile catalog copy that exposes prices with the canonical tariff catalog.
 *
 * The editorial JSON remains responsible for copy and structure; published PVPs
 * are hydrated from tariff-catalog.json so hubs/FAQs cannot become a second
 * source of truth.
 *
 * @param array<mixed>      $catalog Resolved catalog.
 * @param array<string,mixed>|null $config Optional governance configuration.
 * @return array<mixed>
 */
function nvx_catalog_apply_tariff_truth( array $catalog, string $safe_name, ?array $config = null ): array {
	$config = $config ?? nvx_catalog_governance_config();

	if ( 'endolift-page.json' === $safe_name ) {
		$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
		if ( ! empty( $tariffs['_error'] ) ) {
			nvx_catalog_log_error( 'Unable to hydrate Endolift® prices: tariff-catalog.json is unavailable.' );
			return $catalog;
		}

		$ojeras    = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'ojeras' );
		$papada    = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'papada' );
		$cuello    = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'cuello' );
		$combo     = nvx_catalog_tariff_display_price( $tariffs, 'endolift_combo', 'papada_cuello' );
		$full_face = nvx_catalog_tariff_display_price( $tariffs, 'endolift_combo', 'full_face' );

		$inv_key = $config['endolift']['investment_key'] ?? 'investment';
		if ( isset( $catalog[ $inv_key ]['body'] ) && '' !== $ojeras && '' !== $papada ) {
			$catalog[ $inv_key ]['body'] = sprintf(
				/* translators: 1: ojeras price, 2: papada price, 3: cuello price, 4: combo price, 5: full face price */
				__( 'El plan y presupuesto de Endolift® se determinan tras la valoración médica presencial en Chamberí o Salamanca–Goya. Tarifas de referencia: desde %1$s (ojeras), %2$s (papada o marcación mandibular cada una), %3$s (cuello). Combos frecuentes como papada+cuello (%4$s) o full face (%5$s) se valoran según indicación. El presupuesto definitivo se documenta tras valoración anatómica presencial. El procedimiento se realiza en 1 sola sesión en la mayoría de indicaciones, con control evolutivo a los 3 y 6 meses. Cada tratamiento incluye:', 'nuvanx-medical' ),
				$ojeras,
				$papada,
				$cuello,
				$combo,
				$full_face
			);
		}

		// The Endolift® catalog schema reserves FAQ item 0 for the pricing question.
		$faq_idx = $config['endolift']['price_faq_index'] ?? 0;
		if ( isset( $catalog['faq']['items'][ $faq_idx ] ) && is_array( $catalog['faq']['items'][ $faq_idx ] ) && '' !== $ojeras && '' !== $papada ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
				/* translators: 1: ojeras price, 2: papada price */
				__( 'La tarifa de referencia parte desde %1$s (ojeras). Papada y marcación mandibular: %2$s cada una. El presupuesto definitivo se documenta tras valoración anatómica presencial.', 'nuvanx-medical' ),
				$ojeras,
				$papada
			);
		}
	}

	if ( 'exion-page.json' === $safe_name ) {
		$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
		if ( ! empty( $tariffs['_error'] ) || ! isset( $tariffs['exion'] ) || ! is_array( $tariffs['exion'] ) ) {
			nvx_catalog_log_error( 'Unable to hydrate EXION® hub prices: tariff-catalog.json is unavailable or malformed.' );
			return $catalog;
		}

		$face       = nvx_catalog_tariff_display_price( $tariffs, 'exion', 'exion_face_sesion' );
		$body       = nvx_catalog_tariff_display_price( $tariffs, 'exion', 'exion_body_sesion' );
		$fractional = nvx_catalog_tariff_display_price( $tariffs, 'exion', 'exion_fractional_cara' );

		if ( '' === $fractional || '' === $face || '' === $body ) {
			nvx_catalog_log_error( 'Unable to hydrate EXION® hub prices from tariff-catalog.json.' );
			return $catalog;
		}

		$inv_key = $config['exion']['investment_key'] ?? 'investment';
		if ( isset( $catalog[ $inv_key ]['body'] ) ) {
			$catalog[ $inv_key ]['body'] = sprintf(
				/* translators: 1: EXION® Face price, 2: EXION® Body price, 3: Fractional RF price. */
				__( 'El plan y presupuesto se determinan tras la valoración médica presencial en Chamberí o Salamanca–Goya. Tarifas de referencia vigentes: desde %1$s/sesión (EXION® Face), %2$s/sesión (EXION® Body) y %3$s (EXION® Fractional RF). El presupuesto definitivo se documenta tras valoración anatómica presencial. El protocolo incluye:', 'nuvanx-medical' ),
				$face,
				$body,
				$fractional
			);
		}

		// The EXION® catalog schema reserves FAQ item 0 for the pricing question.
		$faq_idx = $config['exion']['price_faq_index'] ?? 0;
		if ( isset( $catalog['faq']['items'][ $faq_idx ] ) && is_array( $catalog['faq']['items'][ $faq_idx ] ) ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
				/* translators: 1: EXION® Face price, 2: EXION® Body price, 3: Fractional RF price. */
				__( 'Las tarifas de referencia vigentes parten desde %1$s/sesión (EXION® Face), %2$s/sesión (EXION® Body) y %3$s (EXION® Fractional RF). El presupuesto definitivo se documenta tras valoración anatómica presencial.', 'nuvanx-medical' ),
				$face,
				$body,
				$fractional
			);
		}
	}

	if ( 'endolaser-page.json' === $safe_name ) {
		$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
		if ( ! empty( $tariffs['_error'] ) ) {
			nvx_catalog_log_error( 'Unable to hydrate endolaser prices: tariff-catalog.json is unavailable.' );
			return $catalog;
		}

		$rodillas = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'rodillas' );
		$brazos   = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'brazos' );
		$flancos  = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'flancos' );
		$abdomen  = nvx_catalog_tariff_display_price( $tariffs, 'Endolift®', 'abdomen' );

		$plan_key = $config['endolaser']['planning_key'] ?? 'planning';
		if ( isset( $catalog[ $plan_key ]['body'] ) && '' !== $rodillas && '' !== $abdomen ) {
			$catalog[ $plan_key ]['body'] = sprintf(
				/* translators: 1: rodillas price, 2: brazos price, 3: flancos price, 4: abdomen price */
				__( 'El presupuesto se calcula por zona o combinación de zonas según el tarifario vigente y se documenta tras la valoración médica presencial. Tarifas de referencia vigentes: desde %1$s (rodillas), %2$s (brazos o cara interna de muslos), %3$s (flancos) y %4$s (abdomen completo). Procedimiento en 1 sesión única con medición ecográfica previa y revisiones clínicas a 4 semanas, 3 y 6 meses. Incluye pauta de compresión post-procedimiento.', 'nuvanx-medical' ),
				$rodillas,
				$brazos,
				$flancos,
				$abdomen
			);
		}

		// The Endoláser catalog schema reserves FAQ item 7 for the pricing question.
		$faq_idx = $config['endolaser']['price_faq_index'] ?? 7;
		if ( isset( $catalog['faq']['items'][ $faq_idx ] ) && is_array( $catalog['faq']['items'][ $faq_idx ] ) && '' !== $rodillas && '' !== $abdomen ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
				/* translators: 1: rodillas price, 2: abdomen price */
				__( 'En NUVANX las tarifas de referencia vigentes de Endoláser corporal parten desde %1$s (zonas focalizadas como rodillas) hasta %2$s (abdomen completo), según extensión, plan médico y anatomía. El presupuesto definitivo se documenta tras la valoración médica presencial.', 'nuvanx-medical' ),
				$rodillas,
				$abdomen
			);
		}
	}

	if ( 'aesthetic-medicine-page.json' === $safe_name ) {
		$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
		if ( ! empty( $tariffs['_error'] ) ) {
			nvx_catalog_log_error( 'Unable to hydrate aesthetic medicine hub prices: tariff-catalog.json is unavailable.' );
			return $catalog;
		}

		$labios = nvx_catalog_tariff_display_price( $tariffs, 'labios_ha', 'perfilado_hidratacion' );
		$rino   = nvx_catalog_tariff_display_price( $tariffs, 'rinomodelacion_ha', 'rinomodelacion' );
		$ojeras = nvx_catalog_tariff_display_price( $tariffs, 'ojeras_ha', 'surco_lagrimal' );
		$bio    = nvx_catalog_tariff_display_price( $tariffs, 'bioestimuladores', 'polynucleotides_cara' );

		if ( isset( $catalog['treatments'][0] ) && '' !== $labios ) {
			$catalog['treatments'][0]['price'] = sprintf(
				/* translators: %s: formatted price */
				__( 'Desde %s', 'nuvanx-medical' ),
				$labios
			);
		}
		if ( isset( $catalog['treatments'][1] ) && '' !== $rino ) {
			$catalog['treatments'][1]['price'] = sprintf(
				/* translators: %s: formatted price */
				__( 'Desde %s', 'nuvanx-medical' ),
				$rino
			);
		}
		if ( isset( $catalog['treatments'][2] ) && '' !== $ojeras ) {
			$catalog['treatments'][2]['price'] = sprintf(
				/* translators: %s: formatted price */
				__( 'Desde %s (según diagnóstico y técnica)', 'nuvanx-medical' ),
				$ojeras
			);
		}
		if ( isset( $catalog['treatments'][3] ) && '' !== $bio ) {
			$catalog['treatments'][3]['price'] = sprintf(
				/* translators: %s: formatted price */
				__( 'Desde %s (según principio activo y zona)', 'nuvanx-medical' ),
				$bio
			);
		}
	}

	if ( 'laser-co2-page.json' === $safe_name ) {
		$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
		if ( ! empty( $tariffs['_error'] ) ) {
			nvx_catalog_log_error( 'Unable to hydrate laser CO2 prices: tariff-catalog.json is unavailable.' );
			return $catalog;
		}

		$facial   = nvx_catalog_tariff_display_price( $tariffs, 'laser_co2', 'facial' );
		$corporal = nvx_catalog_tariff_display_price( $tariffs, 'laser_co2', 'corporal' );

		$inv_key = $config['laser_co2']['investment_key'] ?? 'investment';
		if ( isset( $catalog[ $inv_key ]['body'] ) && '' !== $facial && '' !== $corporal ) {
			$catalog[ $inv_key ]['body'] = sprintf(
				/* translators: 1: facial price, 2: corporal price */
				__( 'El plan y presupuesto se determinan tras la valoración médica presencial en Chamberí o Salamanca–Goya. Tarifas de referencia vigentes: desde %1$s/sesión (facial), %2$s/sesión (corporal). El presupuesto definitivo se documenta tras valoración anatómica presencial. El protocolo incluye:', 'nuvanx-medical' ),
				$facial,
				$corporal
			);
		}

		$faq_idx = $config['laser_co2']['price_faq_index'] ?? 0;
		if ( isset( $catalog['faq']['items'][ $faq_idx ] ) && is_array( $catalog['faq']['items'][ $faq_idx ] ) && '' !== $facial && '' !== $corporal ) {
			$catalog['faq']['items'][ $faq_idx ]['a'] = sprintf(
				/* translators: 1: facial price, 2: corporal price */
				__( 'Las tarifas de referencia vigentes parten desde %1$s/sesión (facial) y %2$s/sesión (corporal). El presupuesto definitivo se documenta tras valoración anatómica presencial.', 'nuvanx-medical' ),
				$facial,
				$corporal
			);
		}
	}

	return $catalog;
}

/**
 * Apply runtime governance corrections that depend on canonical code data.
 *
 * @param array<mixed>      $catalog Resolved catalog.
 * @param array<string,mixed>|null $config Optional governance configuration.
 * @return array<mixed>
 */
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

/**
 * Prevent the legacy EXION® content filter from overwriting the canonical,
 * tariff-hydrated investment block produced by the governed page renderer.
 */
function nvx_catalog_disable_legacy_exion_investment_override(): void {
	if ( function_exists( 'nvx_content_ensure_exion_investment' ) ) {
		remove_filter( 'the_content', 'nvx_content_ensure_exion_investment', NVX_HOOK_PRIO_EXION_INVESTMENT );
	}
}
add_action( 'wp', 'nvx_catalog_disable_legacy_exion_investment_override', 1 );

/**
 * Retire the temporary Bridal seed on staging when it was created by the
 * aesthetic-page seeder. Editorial pages with stale historical seed metadata
 * but without the seed marker are never modified.
 */

/**
 * Resolve a single catalog string token via prefix resolvers and claim tokens.
 *
 * @param array<string, callable> $resolvers Prefix => resolver map (longest first).
 */
function nvx_catalog_resolve_token_value(
	string $value,
	?callable $claim_resolver,
	array $resolvers
): string {
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

/**
 * Resolve WordPress-aware values captured in JSON catalogs.
 *
 * Supported string prefixes: @nvx-t: (i18n), @nvx-url: (home_url), @nvx-claim-key: (BTL claims).
 *
 * @param array<mixed>            $catalog Catalog data.
 * @param callable|null           $claim_resolver Optional BTL claim resolver.
 * @param array<string, callable> $custom_resolvers Optional string-prefix resolvers.
 * @param array<string, callable> $object_resolvers Optional structured-token resolvers.
 * @return array<mixed>
 */
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

/**
 * Load, resolve and cache a catalog for the current request.
 *
 * Use an explicit cache key when a file can be resolved with different resolver sets.
 *
 * @param array<string, callable> $custom_resolvers String-prefix resolvers.
 * @param array<string, callable> $object_resolvers Structured-token resolvers.
 * @return array<mixed>
 */
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
	$resolver_signature = implode( ',', $custom_keys )
		. '|' . implode( ',', $object_keys )
		. '|' . ( null === $claim_resolver ? '0' : '1' );
	$base_key           = '' === $cache_key
		? basename( $filename ) . '|' . $resolver_signature
		: $cache_key;
	$key                = $base_key . '|locale:' . $locale;

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

/**
 * Supply neutral defaults for optional aesthetic presentation fields.
 *
 * These values are optional in the renderer and are omitted from visible output
 * when empty. Normalizing their shape here prevents an otherwise complete
 * clinical record from being discarded solely because an optional presentation
 * field is absent from the source JSON.
 *
 * @param array<mixed> $entry Catalog record.
 * @return array<mixed>
 */
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

/**
 * Retain only catalog records that contain every required key.
 *
 * @param array<mixed>      $catalog Catalog records.
 * @param array<int,string> $required_keys Required keys.
 * @return array<mixed>
 */
function nvx_catalog_filter_records(
	array $catalog,
	array $required_keys,
	string $catalog_name
): array {
	$valid = array();
	foreach ( $catalog as $key => $entry ) {
		if ( is_array( $entry ) ) {
			$entry = nvx_catalog_apply_optional_defaults( $entry, $catalog_name );
		}
		if ( ! is_array( $entry ) || array() !== array_diff( $required_keys, array_keys( $entry ) ) ) {
			nvx_catalog_log_error(
				sprintf( 'Incomplete record %s in %s.', (string) $key, $catalog_name )
			);
			continue;
		}
		$valid[ $key ] = $entry;
	}

	return $valid;
}
