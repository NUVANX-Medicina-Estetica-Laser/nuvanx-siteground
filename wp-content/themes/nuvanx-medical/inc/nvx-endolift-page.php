<?php
/**
 * Endolift® facial treatment page — editorial high-authority structure.
 *
 * Wire-frame: Hero → Qué es → Indicaciones → vs cirugía → Biofísica → Proceso → Tarifas → FAQ → CTA.
 * Pattern-based (Endolift® markers), not page-ID gated.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current main query is a singular page suitable for rewrite.
 */
function nvx_endolift_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	// Prefer real page views; still allow content that carries structural Endolift® markers
	// when queried via the main loop (avoids rewriting random posts/excerpts).
	return is_singular( 'page' ) || is_page();
}

/**
 * Detect Endolift® facial treatment content before rewrite.
 * Anchors primarily on stable structural markers (aria-label / ids / brand classes).
 */
function nvx_content_is_endolift_page( string $content ): bool {
	if ( ! nvx_endolift_is_singular_context() || is_front_page() || is_home() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( is_string( $path ) && (
		false !== strpos( $path, 'endolaser-corporal' )
		|| false !== strpos( $path, 'laser-co2-fraccionado' )
		|| false !== strpos( $path, 'equipo-medico' )
		|| ( false !== strpos( $path, 'exion' ) && false === strpos( $path, 'Endolift®' ) )
	) ) {
		return false;
	}

	// Path is authoritative. A CMS HTML comment such as
	// <!-- nvx-endolift-editorial --> must not block the theme renderer.
	if (
		is_string( $path )
		&& (
			false !== strpos( $path, 'endolift-facial' )
			|| false !== strpos( $path, 'endolift-lifting' )
		)
	) {
		return true;
	}

	if ( preg_match(
		'/aria-label=["\']Endolift® facial NUVANX["\']|id=["\']nvx-endolift-h1["\']|class=["\'][^"\']*nvx-endolift-hero(?![^"\']*nvx-endolaser)(?![^"\']*nvx-co2)(?![^"\']*nvx-equipo)/iu',
		$content
	) ) {
		return true;
	}

	return (bool) preg_match(
		'/nvx-brand-hero--laser[\s\S]{0,1200}Endolift®?[\s\S]{0,400}(papada|mand[ií]bul)/iu',
		$content
	);
}

/**
 * Linear process icons — Champagne Bronce stroke only (1.5px).
 *
 * @param string $name Icon key: assess|anesthesia|procedure|recover.
 */
function nvx_endolift_process_icon( string $name ): string {
	$icons = array(
		'assess'     => '<svg class="nvx-endolift-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="22" cy="22" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M30 30 40 40" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M18 22h8M22 18v8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'anesthesia' => '<svg class="nvx-endolift-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 8h12v8l4 6v18H14V22l4-6V8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M18 16h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'procedure'  => '<svg class="nvx-endolift-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 34 28 8l10 6-18 26H10v-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M24 14l10 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'recover'    => '<svg class="nvx-endolift-step__icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 28c4-10 8-14 12-14s8 4 12 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M16 18c3-2 5-3 8-3s5 1 8 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="24" cy="30" r="3" stroke="currentColor" stroke-width="1.5"/></svg>',
	);

	return $icons[ $name ] ?? $icons['assess'];
}

/**
 * Builds the Endolift® hero copy with medical authority details, descriptive content, calls to action, and metadata.
 *
 * @return string The rendered hero copy markup.
 */
function nvx_endolift_hero_copy_markup(): string {
	$data = function_exists( 'nvx_catalog_json_resolved' ) ? ( nvx_catalog_json_resolved( 'endolift-page.json' )['hero'] ?? array() ) : array();

	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';

	return nvx_brand_hero_copy_markup(
		array(
			'kicker'             => (string) ( $data['kicker'] ?? '' ),
			'h1_id'              => 'nvx-endolift-h1',
			'h1'                 => (string) ( $data['h1'] ?? '' ),
			'byline'             => function_exists( 'nvx_clinical_authority_byline_markup' ),
			'lead'               => (string) ( $data['lead'] ?? '' ),
			'description_html'   => esc_html(
				sprintf(
					/* translators: %s: medical license number */
					(string) ( $data['description'] ?? '' ),
					$colegiado
				)
			),
			'cta_fallback_label' => __( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ),
			'meta'               => (string) ( $data['meta'] ?? '' ),
		)
	);
}

/**
 * Builds the Endolift® editorial body markup, including clinical information, treatment details, pricing, recovery guidance, and FAQs.
 *
 * @return string The rendered editorial body HTML.
 */
function nvx_endolift_editorial_body_markup(): string {
	$data = function_exists( 'nvx_catalog_json_resolved' ) ? nvx_catalog_json_resolved( 'endolift-page.json' ) : array();

	$colegiado     = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$clinical_ssot = nvx_get_clinical_treatment( 'endolift_facial' );
	$review_label  = ! empty( $clinical_ssot['scientific_review_date'] )
		? wp_date( 'F Y', strtotime( $clinical_ssot['scientific_review_date'] ) )
		: ( function_exists( 'nvx_clinical_review_month_label' ) ? nvx_clinical_review_month_label() : 'agosto 2026' );
	$equipo_url    = home_url( '/equipo-medico/' );

	$html = '<div class="nvx-endolift-editorial">';

	// Clinical review byline — E-E-A-T (visible + matches schema reviewedBy).
	$html .= '<p class="nvx-endolift-reviewed">';
	$html .= esc_html(
		sprintf(
			/* translators: 1: medical license number, 2: review month label */
			$data['review']['text'] ?? '',
			$colegiado,
			$review_label
		)
	);
	$html .= ' <a class="nvx-brand-inline-link" href="' . esc_url( $equipo_url ) . '">' . esc_html( $data['review']['link'] ?? '' ) . '</a>';
	$html .= '</p>';

	// Ficha Clínica E-E-A-T (SSOT)
	$clinical = nvx_get_clinical_treatment( 'endolift_facial' );
	if ( $clinical ) {
		$html .= '<aside class="nvx-clinical-factsheet" aria-label="Ficha clínica estructurada">';
		$html .= '<h3 class="nvx-clinical-factsheet__title">Ficha Técnica: ' . esc_html( $clinical['name'] ) . '</h3>';
		$html .= '<dl class="nvx-clinical-factsheet__list">';
		$html .= '<dt>Mecanismo</dt><dd>' . esc_html( $clinical['mechanism'] ) . '</dd>';
		$html .= '<dt>Anestesia</dt><dd>' . esc_html( $clinical['anesthesia'] ) . '</dd>';
		$html .= '<dt>Duración</dt><dd>' . esc_html( $clinical['duration'] ) . '</dd>';
		$html .= '<dt>Recuperación</dt><dd>' . esc_html( $clinical['recovery'] ) . '</dd>';
		$html .= '<dt>Sesiones</dt><dd>' . esc_html( $clinical['sessions'] ) . '</dd>';
		$html .= '</dl>';
		$html .= '</aside>';
	}

	// A. Qué es (clinical framing; biophysics section keeps 1470 nm / formula detail).
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-what', 'nvx-endolift-what-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['what']['kicker'] ?? '' ), 'nvx-endolift-what-title', esc_html( $data['what']['title'] ?? '' ) );
	foreach ( $data['what']['body'] ?? array() as $paragraph ) {
		$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $paragraph ) . '</p>';
	}
	$html .= '</div></section>';

	// B. Indicaciones + diagnóstico diferencial (panel) — no price here.
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-diagnosis', 'nvx-endolift-diagnosis-title', 'nvx-endolift-diagnosis__grid' );
	$html .= '<div class="nvx-endolift-diagnosis__copy">';
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['diagnosis']['kicker'] ?? '' ), 'nvx-endolift-diagnosis-title', esc_html( $data['diagnosis']['title'] ?? '' ) );
	foreach ( $data['diagnosis']['body'] ?? array() as $paragraph ) {
		$html .= '<p class="nvx-body">' . esc_html( $paragraph ) . '</p>';
	}
	$html .= '</div>';
	$html .= '<aside class="nvx-fact-panel" aria-label="' . esc_attr__( 'Criterio de diagnóstico', 'nuvanx-medical' ) . '">';
	$html .= '<p class="nvx-fact-panel__label">' . esc_html( $data['diagnosis']['panel_title'] ?? '' ) . '</p>';
	$html .= '<ul class="nvx-fact-panel__list" role="list">';
	foreach ( $data['diagnosis']['panel_items'] ?? array() as $item ) {
		$html .= '<li><strong>' . esc_html( $item['title'] ?? '' ) . '</strong> — ' . esc_html( $item['body'] ?? '' ) . '</li>';
	}
	$html .= '</ul></aside>';
	$yes = array();
	$no  = array();
	foreach ( (array) ( $data['candidacy']['yes'] ?? array() ) as $item ) {
		$yes[] = (string) $item;
	}
	foreach ( (array) ( $data['candidacy']['no'] ?? array() ) as $item ) {
		$no[] = (string) $item;
	}
	if ( array() === $yes ) {
		$yes = array(
			__( 'Flacidez leve–moderada de papada, mandíbula o cuello.', 'nuvanx-medical' ),
			__( 'Grasa submentoniana localizada con buena calidad de piel.', 'nuvanx-medical' ),
			__( 'Busca remodelación estructural sin resección quirúrgica.', 'nuvanx-medical' ),
		);
	}
	if ( array() === $no ) {
		$no = array(
			__( 'Ptosis severa o exceso cutáneo que requiere lifting quirúrgico.', 'nuvanx-medical' ),
			__( 'Embarazo, lactancia, infección activa o anticoagulantes no controlados.', 'nuvanx-medical' ),
			__( 'Expectativa de un resultado idéntico a una cirugía de lifting.', 'nuvanx-medical' ),
		);
	}
	if ( function_exists( 'nvx_candidacy_markup' ) ) {
		$html .= nvx_candidacy_markup( $yes, $no );
	}
	$html .= '</div></section>';

	// C. Comparativa vs lifting (new — not elsewhere on page).
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-compare', 'nvx-endolift-compare-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['compare']['kicker'] ?? '' ), 'nvx-endolift-compare-title', esc_html( $data['compare']['title'] ?? '' ) );
	$html .= '<div class="nvx-endolift-compare-wrap" role="region" aria-label="' . esc_attr__( 'Tabla comparativa de Endolift® y lifting quirúrgico', 'nuvanx-medical' ) . '" tabindex="0">';
	$html .= '<table class="nvx-endolift-compare-table">';
	$html .= '<caption>' . esc_html__( 'Comparativa entre Endolift® y lifting quirúrgico', 'nuvanx-medical' ) . '</caption>';
	$html .= '<thead><tr>';
	$html .= '<th scope="col">' . esc_html( $data['compare']['col_param'] ?? '' ) . '</th>';
	$html .= '<th scope="col">' . esc_html( $data['compare']['col_endo'] ?? '' ) . '</th>';
	$html .= '<th scope="col">' . esc_html( $data['compare']['col_lift'] ?? '' ) . '</th>';
	$html .= '</tr></thead><tbody>';
	foreach ( $data['compare']['rows'] ?? array() as $row ) {
		$html .= '<tr>';
		$html .= '<th scope="row">' . esc_html( $row['param'] ?? '' ) . '</th>';
		$html .= '<td data-label="' . esc_attr( (string) ( $data['compare']['col_endo'] ?? '' ) ) . '">' . esc_html( $row['endo'] ?? '' ) . '</td>';
		$html .= '<td data-label="' . esc_attr( (string) ( $data['compare']['col_lift'] ?? '' ) ) . '">' . esc_html( $row['lift'] ?? '' ) . '</td>';
		$html .= '</tr>';
	}
	$html .= '</tbody></table></div></div></section>';

	// D. Biofísica (detail layer — complements “qué es”, no rewrite of clinical intro).
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-biophysics', 'nvx-endolift-bio-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['biophysics']['kicker'] ?? '' ), 'nvx-endolift-bio-title', esc_html( $data['biophysics']['title'] ?? '' ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['biophysics']['body1'] ?? '' ) . '</p>';
	$html .= '<figure class="nvx-endolift-formula">';
	$html .= '<p class="nvx-endolift-formula__eq" aria-hidden="true"><span class="nvx-endolift-formula__q">Q</span> = <span class="nvx-endolift-formula__mu">μ<sub>a</sub></span> · <span class="nvx-endolift-formula__phi">Φ</span></p>';
	$html .= '<figcaption class="nvx-endolift-formula__cap">' . esc_html( $data['biophysics']['caption'] ?? '' ) . '</figcaption>';
	$html .= '</figure>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['biophysics']['body2'] ?? '' ) . '</p>';
	$html .= '</div></section>';

	// E. Proceso clínico (planimetría / tumescente / abanico / 60–90 min — no second FAQ recovery essay).
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-process', 'nvx-endolift-process-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['process']['kicker'] ?? '' ), 'nvx-endolift-process-title', esc_html( $data['process']['title'] ?? '' ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['process']['body'] ?? '' ) . '</p>';
	$html .= '<div class="nvx-endolift-process-grid">';

	$step_idx = 0;
	foreach ( $data['process']['steps'] ?? array() as $step ) {
		$sid   = 'nvx-endolift-step-' . $step_idx;
		$html .= '<article class="nvx-endolift-step" aria-labelledby="' . esc_attr( $sid ) . '">';
		$html .= nvx_endolift_process_icon( $step['icon'] ?? 'assess' );
		$html .= '<span class="nvx-endolift-step__n">' . esc_html( $step['n'] ?? '' ) . '</span>';
		$html .= '<h3 id="' . esc_attr( $sid ) . '" class="nvx-endolift-step__title">' . esc_html( $step['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $step['body'] ?? '' ) . '</p>';
		$html .= '</article>';
		++$step_idx;
	}

	$html .= '</div></div></section>';

	// E-Bis. Postoperatorio Real (SEO Capture for recovery pain/fears)
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-postop', 'nvx-endolift-postop-title', '', array( 'id' => 'postoperatorio-endolift' ) );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['postop']['kicker'] ?? '' ), 'nvx-endolift-postop-title', esc_html( $data['postop']['title'] ?? '' ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['postop']['body'] ?? '' ) . '</p>';
	$recovery_rows = (array) ( $data['postop']['table'] ?? array() );
	if ( array() === $recovery_rows ) {
		$recovery_rows = array(
			array(
				'when'     => __( 'Días 1–3', 'nuvanx-medical' ),
				'expect'   => __( 'Edema, sensibilidad y posibles microhematomas en los puntos de entrada.', 'nuvanx-medical' ),
				'activity' => __( 'Reposo relativo. Mentonera según pauta.', 'nuvanx-medical' ),
			),
			array(
				'when'     => __( '24–48 horas', 'nuvanx-medical' ),
				'expect'   => __( 'Reincorporación laboral habitual en la mayoría de casos de oficina.', 'nuvanx-medical' ),
				'activity' => __( 'Vuelta al trabajo sedentario si el médico lo autoriza.', 'nuvanx-medical' ),
			),
			array(
				'when'     => __( 'Días 3–7', 'nuvanx-medical' ),
				'expect'   => __( 'La inflamación social cede. La zona sigue en curación.', 'nuvanx-medical' ),
				'activity' => __( 'Reuniones y vida social habituales.', 'nuvanx-medical' ),
			),
			array(
				'when'     => __( 'Semanas 2–4', 'nuvanx-medical' ),
				'expect'   => __( 'Retracción tisular progresiva. Molestias residuales mínimas.', 'nuvanx-medical' ),
				'activity' => __( 'Actividad normal. Deporte según indicación.', 'nuvanx-medical' ),
			),
			array(
				'when'     => __( 'Meses 3–6', 'nuvanx-medical' ),
				'expect'   => __( 'Consolidación del nuevo colágeno y resultado clínico maduro.', 'nuvanx-medical' ),
				'activity' => __( 'Seguimiento protocolizado.', 'nuvanx-medical' ),
			),
		);
	}
	if ( function_exists( 'nvx_recovery_table_markup' ) ) {
		$html .= nvx_recovery_table_markup( $recovery_rows, __( 'Recuperación orientativa del Endolift® facial', 'nuvanx-medical' ) );
	}
	$html .= '<p class="nvx-body nvx-body--measure"><em>' . esc_html( $data['postop']['note'] ?? '' ) . '</em></p>';
	$html .= '</div></section>';

	// F. Presupuesto Clínico — Valoración personalizada.
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-investment', 'nvx-endolift-price-title', '', array( 'id' => 'inversion-endolift' ) );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['investment']['kicker'] ?? '' ), 'nvx-endolift-price-title', esc_html( $data['investment']['title'] ?? '' ) );

	$tariff_complete = function_exists( 'nvx_tariff_public_truth_is_complete' ) && nvx_tariff_public_truth_is_complete();
	if ( $tariff_complete && function_exists( 'nvx_tariff_price_label' ) ) {
		$ojeras    = nvx_tariff_price_label( 'Endolift®', 'ojeras' );
		$papada    = nvx_tariff_price_label( 'Endolift®', 'papada' );
		$cuello    = nvx_tariff_price_label( 'Endolift®', 'cuello' );
		$combo     = nvx_tariff_price_label( 'endolift_combo', 'papada_cuello' );
		$full_face = nvx_tariff_price_label( 'endolift_combo', 'full_face' );
		$price_body = sprintf(
			/* translators: 1-5: canonical tariff labels from tariff-catalog.json */
			__( 'El plan y presupuesto de Endolift® se determinan tras la valoración médica presencial en Chamberí o Salamanca–Goya. Tarifas de referencia del catálogo: desde %1$s (ojeras), %2$s (papada o marcación mandibular cada una), %3$s (cuello). Combos frecuentes como papada+cuello (%4$s) o full face (%5$s) se valoran según indicación. El presupuesto definitivo se documenta tras valoración anatómica presencial. El procedimiento se realiza en 1 sola sesión en la mayoría de indicaciones, con control evolutivo a los 3 y 6 meses. Cada tratamiento incluye:', 'nuvanx-medical' ),
			$ojeras,
			$papada,
			$cuello,
			$combo,
			$full_face
		);
	} else {
		$price_body = function_exists( 'nvx_tariff_public_neutral_copy' )
			? nvx_tariff_public_neutral_copy()
			: __( 'Presupuesto individualizado tras valoración médica. Consulta la tarifa vigente con el equipo antes de confirmar el tratamiento.', 'nuvanx-medical' );
	}

	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $price_body ) . '</p>';
	$html .= '<ul class="nvx-endolift-price-includes" role="list">';
	foreach ( $data['investment']['items'] ?? array() as $item ) {
		$html .= '<li>' . esc_html( $item ) . '</li>';
	}
	$html .= '</ul>';
	$html .= '<p class="nvx-body nvx-body--measure"><em>' . esc_html( $data['investment']['note'] ?? '' ) . '</em></p>';
	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup( 'nvx-brand-actions' );
	}
	$html .= '</div></section>';

	// F-Bis. Dirección Médica y Responsable Facultativo (E-E-A-T / YMYL).
	if ( function_exists( 'nvx_treatment_physician_author_markup' ) ) {
		$html .= nvx_treatment_physician_author_markup( 'Endolift® Facial' );
	}

	// G. FAQ — same Q/A as FAQPage schema (nvx_schema_faq_catalog endolift_facial).
	$html .= nvx_page_brand_section_open_markup( 'nvx-endolift-faq', 'nvx-endolift-faq-title' );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $data['faq']['kicker'] ?? '' ), 'nvx-endolift-faq-title', esc_html( $data['faq']['title'] ?? '' ) );

	// Shared catalog so HTML and JSON-LD never diverge.
	$faqs = array();
	if ( function_exists( 'nvx_schema_faq_catalog' ) ) {
		$catalog = nvx_schema_faq_catalog();
		if ( ! empty( $catalog['endolift_facial'] ) ) {
			$faqs = $catalog['endolift_facial'];
		}
	}
	if ( empty( $faqs ) && ! empty( $data['faq']['items'] ) && is_array( $data['faq']['items'] ) ) {
		$faqs = $data['faq']['items'];
	}
	if ( empty( $faqs ) ) {
		$neutral_price_copy = function_exists( 'nvx_tariff_public_neutral_copy' )
			? nvx_tariff_public_neutral_copy()
			: __( 'Presupuesto individualizado tras valoración médica. Consulta la tarifa vigente con el equipo antes de confirmar el tratamiento.', 'nuvanx-medical' );
		$faqs = array(
			array(
				'q' => '¿Cuánto cuesta el Endolift® facial en NUVANX Madrid?',
				'a' => $neutral_price_copy,
			),
		);
	}

	if ( function_exists( 'nvx_faq_direct_answer_markup' ) ) {
		$html .= nvx_faq_direct_answer_markup( $faqs, 'nvx-endolift-faq-list' );
	} else {
		$html .= '<div class="nvx-faq nvx-endolift-faq-list">';
		foreach ( $faqs as $faq ) {
			$html .= '<details class="nvx-brand-faq-item">';
			$html .= '<summary><span>' . esc_html( $faq['q'] ) . '</span></summary>';
			$html .= '<div class="nvx-brand-faq-content"><p>' . esc_html( $faq['a'] ) . '</p></div>';
			$html .= '</details>';
		}
		$html .= '</div>';
	}

	$html .= '</div></section>';

	// Closing valoración CTA: site-wide nvx-cta-banner in footer.php (not page-local).
	$html .= '<div class="nvx-related-links"><p>';
	$html .= esc_html__( 'Endolift® no es una laserlipólisis corporal. Para comparar longitudes de onda y protocolos, consulta', 'nuvanx-medical' ) . ' ';
	$html .= '<a href="' . esc_url( home_url( '/smartlipo-laserlipolisis-endolift/' ) ) . '">' . esc_html__( 'Smartlipo®, laserlipólisis y Endolift®', 'nuvanx-medical' ) . '</a>.';
	$html .= '</p></div>';
	$html .= '</div>';

	return $html;
}

/**
 * Rebuild Endolift® page: authority hero + diagnosis + biophysics + process + FAQ + CTA.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_endolift_page' ) && nvx_content_is_endolift_page( $content ) ) {
			return 'nvx_endolift_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_endolift_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_endolift_page' ) {
		return $content;
	}

	$media = function_exists( 'nvx_page_extract_brand_hero_media' ) ? nvx_page_extract_brand_hero_media( $content ) : '';

	$hero  = '<section class="nvx-brand-hero" aria-labelledby="nvx-endolift-h1">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= nvx_endolift_hero_copy_markup();
	$hero .= $media;
	$hero .= '</div></section>';

	$body = nvx_endolift_editorial_body_markup();

	return '<div class="entry-content nvx-page__content">' . $hero . $body . '</div>';
}
add_filter( 'the_content', 'nvx_content_restructure_endolift_page', NVX_HOOK_PRIO_ENDOLIFT );

/**
 * Inyectar el schema MedicalProcedure basado en la evidencia clínica de NUVANX.
 */
function nvx_endolift_extend_yoast_schema( $graph ) {
	if ( ! is_array( $graph ) || ! nvx_endolift_is_singular_context() ) {
		return $graph;
	}

	global $post;
	$content = $post ? $post->post_content : '';
	if ( ! function_exists( 'nvx_content_is_endolift_page' ) || ! nvx_content_is_endolift_page( $content ) ) {
		return $graph;
	}

	$url            = get_permalink( get_queried_object_id() );
	$medical_schema = nvx_clinical_generate_schema( 'endolift_facial', $url );

	if ( ! $medical_schema ) {
		return $graph;
	}

	// Check if a node with the same @id already exists to avoid duplicates
	$procedure_id   = $medical_schema['@id'] ?? '';
	$existing_index = null;

	if ( $procedure_id && is_array( $graph ) ) {
		foreach ( $graph as $index => $node ) {
			if ( isset( $node['@id'] ) && $node['@id'] === $procedure_id ) {
				$existing_index = $index;
				break;
			}
		}
	}

	// Upsert: replace existing node or append new one
	if ( null !== $existing_index ) {
		$graph[ $existing_index ] = $medical_schema;
	} else {
		$graph[] = $medical_schema;
	}

	return $graph;
}
add_filter( 'wpseo_schema_graph', 'nvx_endolift_extend_yoast_schema', 50 );
