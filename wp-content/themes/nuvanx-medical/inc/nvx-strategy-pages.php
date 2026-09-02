<?php
/**
 * Strategy-led authority and investment pages.
 *
 * Public copy stays within the clinical claims register: the authority and
 * investment pages explain the decision process.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string,array{slug:string,title:string,review_status:string}>
 */
function nvx_strategy_page_catalog(): array {
	return array(
		'why_nuvanx'     => array(
			'slug'          => 'por-que-nuvanx',
			'title'         => 'Por qué NUVANX',
			'review_status' => 'approved_for_publication',
		),
		'investment'     => array(
			'slug'          => 'inversion-medicina-estetica',
			'title'         => 'Inversión en medicina estética',
			'review_status' => 'approved_for_publication',
		),
	);
}

/**
 * Return the catalogue key for the current strategy page.
 */
function nvx_strategy_current_page_key(): ?string {
	if ( ! is_page() ) {
		return null;
	}

	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	foreach ( nvx_strategy_page_catalog() as $key => $page ) {
		if ( $page['slug'] === $slug ) {
			return $key;
		}
	}

	return null;
}

/**
 * Return a public URL only when a non-prototype strategy page is published.
 */
function nvx_strategy_published_url( string $key ): string {
	$catalog = nvx_strategy_page_catalog();
	if ( empty( $catalog[ $key ] ) || 'approved_for_publication' !== $catalog[ $key ]['review_status'] ) {
		return '';
	}

	$page = get_page_by_path( $catalog[ $key ]['slug'] );
	if ( ! $page || 'publish' !== get_post_status( $page ) ) {
		return '';
	}

	return (string) get_permalink( $page );
}

/**
 * Authority page with an explicit diagnostic-first promise.
 */
function nvx_strategy_why_nuvanx_markup(): string {
	$valuation_url = esc_url( home_url( '/madrid/valoracion/' ) );
	$team_url      = esc_url( home_url( '/equipo-medico/' ) );
	$investment    = nvx_strategy_published_url( 'investment' );
	$clinics       = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	$chamberi_reg = (string) ( $clinics['chamberi']['reg'] ?? '' );
	$goya_reg = (string) ( $clinics['goya']['reg'] ?? '' );
	$chamberi_name = (string) ( $clinics['chamberi']['short_name'] ?? '' );
	$goya_name = (string) ( $clinics['goya']['short_name'] ?? '' );
	$clinic_section = 'NUVANX atiende en ' . $chamberi_name . ' (' . $chamberi_reg . ') y ' . $goya_name . ' (' . $goya_reg . '), con equipo médico colegiado.';

	$html  = '<article class="nvx-brand-readable nvx-strategy-page">';
	$html .= '<section class="nvx-brand-hero"><div class="nvx-brand-hero__inner"><div class="nvx-brand-hero__copy"><p class="nvx-eyebrow">Criterio médico NUVANX</p>';
	$html .= '<h1 class="nvx-brand-hero__title">El diagnóstico precede a la indicación.</h1>';
	$html .= '<p class="nvx-brand-hero__lead">Madrid. Medicina estética láser y well-aging. Un único criterio médico desde la primera valoración hasta el alta.</p></div></div></section>';

	$html .= '<section class="nvx-brand-section" aria-labelledby="why-diag"><h2 id="why-diag">Diagnóstico antes de tecnología</h2><p>Revisamos anatomía, antecedentes, objetivos, contraindicaciones y expectativas. Solo entonces se valora si procede tratar, esperar, derivar o no intervenir.</p></section>';
	$html .= '<section class="nvx-brand-section" aria-labelledby="why-claridad"><h2 id="why-claridad">Claridad antes de decidir</h2><p>El plan explica la alternativa propuesta, sus límites, cuidados, posibles efectos y presupuesto. La decisión se toma con información comprensible y con tiempo para resolver dudas.</p></section>';
	$html .= '<section class="nvx-brand-section" aria-labelledby="why-seguimiento"><h2 id="why-seguimiento">Seguimiento como parte del plan</h2><p>La indicación incluye cómo y cuándo contactar con el equipo, qué evolución vigilar y cuándo revisar el caso. La recuperación no se presenta como idéntica para todas las personas.</p></section>';

	$html .= '<section class="nvx-brand-section nvx-strategy-checklist" aria-labelledby="why-hacemos">'
		. '<h2 id="why-hacemos">Lo que hacemos siempre</h2>'
		. '<ul class="nvx-check-list" role="list">'
		. '<li>Exploración médica antes de proponer cualquier tratamiento</li>'
		. '<li>Presupuesto cerrado y por escrito antes de iniciar el procedimiento</li>'
		. '<li>El médico que hace la valoración es el mismo que ejecuta el tratamiento</li>'
		. '<li>El código de lote de cada producto queda registrado en el historial clínico</li>'
		. '<li>Sala de espera individual: cada paciente ocupa su propio espacio</li>'
		. '<li>Seguimiento posterior accesible sin necesidad de agenda nueva</li>'
		. '</ul>'
		. '</section>';

	$html .= '<section class="nvx-brand-section nvx-strategy-checklist nvx-strategy-checklist--no" aria-labelledby="why-no-hacemos">'
		. '<h2 id="why-no-hacemos">Lo que no hacemos</h2>'
		. '<ul class="nvx-check-list nvx-check-list--no" role="list">'
		. '<li>Descuentos de temporada ni urgencia de precio</li>'
		. '<li>Financiación como argumento de venta principal</li>'
		. '<li>Tratamientos sin indicación clínica previa documentada</li>'
		. '<li>Rotación de médicos sin informar al paciente</li>'
		. '<li>Valoraciones "gratuitas" que son visitas comerciales</li>'
		. '</ul>'
		. '</section>';

	$html .= '<section class="nvx-brand-section" aria-labelledby="why-trazabilidad">'
		. '<h2 id="why-trazabilidad">Trazabilidad de productos</h2>'
		. '<p>En NUVANX el médico abre cada producto en presencia del paciente. El código de lote queda adherido al historial clínico. Trabajamos exclusivamente con distribuidores oficiales de las marcas que empleamos. El certificado de proveedor está disponible antes de firmar cualquier presupuesto.</p>'
		. '<p>Este procedimiento no es habitual en el sector. Lo describimos porque creemos que debería serlo.</p>'
		. '</section>';

	$html .= '<section class="nvx-brand-section" aria-labelledby="why-centros"><h2 id="why-centros">Atención en centros sanitarios autorizados</h2><p>' . esc_html( $clinic_section ) . '</p><p><a class="nvx-brand-btn" href="' . $valuation_url . '">Valoración gratuita — sin compromiso</a> <a class="nvx-brand-inline-link" href="' . $team_url . '">Conocer al equipo médico</a>';
	if ( '' !== $investment ) {
		$html .= ' <a class="nvx-brand-inline-link" href="' . esc_url( $investment ) . '">Consultar inversión orientativa</a>';
	}
	$html .= '</p></section></article>';

	return $html;
}

/**
 * Append catalog rows for the given keys into an investment group.
 *
 * @param array<string,array<int,array{label:string,price:string}>> $groups Groups map.
 * @param array<string,array<string,mixed>>                         $bucket Catalog bucket.
 * @param array<int,string>                                         $keys   Catalog keys to include.
 * @param string                                                    $group  Destination group key.
 * @param string                                                    $label_suffix Optional label suffix.
 * @return array<string,array<int,array{label:string,price:string}>>
 */
function nvx_strategy_append_investment_rows(
	array $groups,
	array $bucket,
	array $keys,
	string $group,
	string $label_suffix = ''
): array {
	foreach ( $keys as $key ) {
		if ( ! isset( $bucket[ $key ] ) ) {
			continue;
		}
		$item               = $bucket[ $key ];
		$groups[ $group ][] = array(
			'label' => $item['label'] . $label_suffix,
			'price' => nvx_format_price_eur( $item['pvp'] ) . ' €',
		);
	}
	return $groups;
}

/**
 * Append verified CO₂ laser tariff rows.
 *
 * @param array<string,array<int,array{label:string,price:string}>> $groups Groups map.
 * @param array<string,mixed>                                       $catalog Full tariff catalog.
 * @return array<string,array<int,array{label:string,price:string}>>
 */
function nvx_strategy_append_laser_co2_rows( array $groups, array $catalog ): array {
	foreach ( array( 'facial', 'corporal' ) as $key ) {
		if ( ! isset( $catalog['laser_co2'][ $key ] ) ) {
			continue;
		}
		$groups['laser_co2'][] = array(
			'label' => $catalog['laser_co2'][ $key ]['label'],
			'price' => nvx_format_price_eur( $catalog['laser_co2'][ $key ]['pvp'] ) . ' €',
		);
	}
	return $groups;
}

/**
 * Return only tariffs that the clinical-claims register has approved for use,
 * grouped by category for display.
 *
 * @return array<string,array<int,array{label:string,price:string}>>
 */
function nvx_strategy_verified_investment_groups(): array {
	if ( ! function_exists( 'nvx_tariff_catalog' ) || ! function_exists( 'nvx_format_price_eur' ) ) {
		return array();
	}

	$catalog = nvx_tariff_catalog();
	$groups  = array();

	// Endolift® facial (individual zones)
	$groups = nvx_strategy_append_investment_rows(
		$groups,
		(array) ( $catalog['Endolift®'] ?? array() ),
		array( 'ojeras', 'papada', 'marcacion_mandibular', 'pomulos', 'cuello' ),
		'endolift_facial'
	);

	// Endolift® facial combos
	$groups = nvx_strategy_append_investment_rows(
		$groups,
		(array) ( $catalog['endolift_combo'] ?? array() ),
		array( 'papada_cuello', 'marcacion_papada', 'full_face' ),
		'endolift_facial',
		' (zona combinada)'
	);

	// Endolift® corporal (individual zones)
	$groups = nvx_strategy_append_investment_rows(
		$groups,
		(array) ( $catalog['Endolift®'] ?? array() ),
		array( 'abdomen', 'flancos', 'brazos', 'cartucheras', 'subgluteos', 'muslos_internos', 'subescapular', 'rodillas' ),
		'endolift_corporal'
	);

	// Endolift® corporal combos
	$groups = nvx_strategy_append_investment_rows(
		$groups,
		(array) ( $catalog['endolift_combo'] ?? array() ),
		array( 'abdomen_flancos', 'subgluteos_cartucheras', 'muslos_rodilla', 'sujetador_brazos', 'cartucheras_muslos', 'cartucheras_subgluteos_muslos' ),
		'endolift_corporal',
		' (zona combinada)'
	);

	// Láser CO₂
	return nvx_strategy_append_laser_co2_rows( $groups, $catalog );
}

/**
 * Render one price-table section for a group of tariff rows.
 *
 * @param string                                      $heading  Section H2 text.
 * @param array<int,array{label:string,price:string}> $rows  Tariff rows.
 * @return string
 */
function nvx_strategy_investment_table_section( string $heading, array $rows ): string {
	if ( empty( $rows ) ) {
		return '';
	}

	$hid   = sanitize_title( $heading );
	$html  = '<section class="nvx-brand-section" aria-labelledby="' . esc_attr( $hid ) . '">';
	$html .= '<h2 id="' . esc_attr( $hid ) . '">' . esc_html( $heading ) . '</h2>';
	$html .= '<div class="nvx-endolift-price-table-wrap"><table class="nvx-endolift-price-table">';
	$html .= '<thead><tr><th scope="col">Procedimiento</th><th scope="col">PVP con IVA</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$html .= '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . esc_html( $row['price'] ) . '</td></tr>';
	}
	$html .= '</tbody></table></div>';
	$html .= '</section>';

	return $html;
}

/**
 * Investment page: transparent tariffs, grouped by category, with clinical context.
 */
function nvx_strategy_investment_markup(): string {
	$groups        = nvx_strategy_verified_investment_groups();
	$valuation_url = esc_url( home_url( '/madrid/valoracion/' ) );

	$html  = '<article class="nvx-brand-readable nvx-strategy-page nvx-shell">';
	$html .= '<header class="nvx-strategy-intro">'
		. '<p class="nvx-brand-kicker">Inversión en medicina estética · NUVANX Madrid</p>'
		. '<h1 class="nvx-strategy-title">El presupuesto forma parte de una decisión informada.</h1>'
		. '<p class="nvx-brand-lead">Publicamos tarifas verificadas porque la opacidad de precio no es sinónimo de exclusividad: es una barrera para quien tiene que tomar una decisión clínica. El importe final y la indicación se confirman siempre después de la valoración médica presencial.</p>'
		. '</header>';

	if ( ! empty( $groups ) ) {
		$group_labels = array(
			'endolift_facial'   => 'Endolift® facial — zonas y combinaciones',
			'endolift_corporal' => 'Endolift® corporal — zonas y combinaciones',
			'laser_co2'         => 'Láser CO₂ fraccionado',
		);
		foreach ( $group_labels as $key => $label ) {
			if ( ! empty( $groups[ $key ] ) ) {
				$html .= nvx_strategy_investment_table_section( $label, $groups[ $key ] );
			}
		}
	} else {
		$html .= '<section class="nvx-brand-section" aria-labelledby="inv-tarifas"><h2 id="inv-tarifas" class="screen-reader-text">Tarifas pendientes</h2><p>Las tarifas verificadas se mostrarán cuando estén disponibles en el catálogo clínico vigente.</p></section>';
	}

	$html .= '<section class="nvx-brand-section" aria-labelledby="inv-incluye">'
		. '<h2 id="inv-incluye">Qué incluye el precio</h2>'
		. '<p>Las tarifas mostradas corresponden al procedimiento técnico. La valoración médica previa, el protocolo anestésico tópico, la información detallada del proceso y el seguimiento posterior están incluidos en el plan general. El presupuesto final se documenta por escrito tras la exploración.</p>'
		. '<p>Otras zonas, procedimientos de medicina estética facial (neuromodulación, bioestimuladores, rellenos) y combinaciones no listadas aquí requieren exploración, indicación y presupuesto individualizado.</p>'
		. '</section>';

	$html .= '<section class="nvx-brand-section" aria-labelledby="inv-precios-madrid">'
		. '<h2 id="inv-precios-madrid">Sobre los precios en medicina estética en Madrid</h2>'
		. '<p>En Madrid, los precios de los mismos tratamientos varían de forma significativa entre clínicas. La razón habitual no es la tecnología: es el tiempo dedicado al diagnóstico, la experiencia del médico que ejecuta y el protocolo de seguimiento posterior. Un presupuesto muy bajo en un procedimiento invasivo no suele reflejar eficiencia; suele reflejar recortes en alguno de esos factores.</p>'
		. '<p>En NUVANX no usamos descuentos estacionales, precios de captación ni financiaciones como argumento de venta. Si el importe no encaja con tu situación, preferimos decírtelo en la valoración antes que comprometer la indicación o el protocolo.</p>'
		. '</section>';

	$html .= '<section class="nvx-brand-section" aria-label="' . esc_attr__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '">'
		. '<p><a class="nvx-brand-btn" href="' . $valuation_url . '">Valoración gratuita — sin compromiso</a></p>'
		. '</section>';

	$html .= '</article>';

	return $html;
}

/**
 * Render the correct body for a strategy route.
 */
function nvx_strategy_page_markup( string $key ): string {
	if ( 'why_nuvanx' === $key ) {
		return nvx_strategy_why_nuvanx_markup();
	}

	if ( 'investment' === $key ) {
		return nvx_strategy_investment_markup();
	}

	return '';
}

add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner; }
		if ( function_exists( 'nvx_strategy_current_page_key' ) && null !== nvx_strategy_current_page_key() ) {
			return 'nvx_strategy_pages';
		}
		return $owner;
	}
);

/**
 * Use a stable, theme-owned rendering path rather than editable CMS fragments.
 */
function nvx_strategy_page_content_filter( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_strategy_pages' ) {
		return $content;
	}

	if ( is_admin() || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}

	$key = nvx_strategy_current_page_key();
	return null === $key ? $content : nvx_strategy_page_markup( $key );
}
add_filter( 'the_content', 'nvx_strategy_page_content_filter', NVX_HOOK_PRIO_STRATEGY_PAGES );

/**
 * Create strategy pages only in staging2. Production requires a deliberate
 * editorial publication step.
 */
