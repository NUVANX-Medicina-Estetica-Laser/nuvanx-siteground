<?php
/**
 * Medicina Estética hub — high-authority editorial rebuild.
 *
 * Wire-frame: Hero → Diagnóstico 3 columnas → Catálogo facial → Regeneración
 *             → FAQ reológicas AEO → Action banner.
 * Pattern-based (medicina-estetica markers). Excludes láser hub and detail pages.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singular page context for aesthetic hub rewrite.
 */
function nvx_aesthetic_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	return is_singular( 'page' ) || is_page();
}

/**
 * Lookup a published page URL by path (null if missing). Request-static cache.
 *
 * @param string $path Relative path without domain.
 * @return string|null Permalink or null when not found / not published.
 */
function nvx_aesthetic_lookup_published_url( string $path ): ?string {
	static $cache = array();

	$path = trim( $path, '/' );
	if ( array_key_exists( $path, $cache ) ) {
		return $cache[ $path ];
	}

	$found = null;
	$page  = get_page_by_path( $path );
	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		$url = get_permalink( $page );
		if ( is_string( $url ) && '' !== $url ) {
			$found = $url;
		}
	}

	$cache[ $path ] = $found;
	return $found;
}


/**
 * Detect Medicina Estética hub (injectables / regenerativa), not láser hub.
 */
function nvx_content_is_aesthetic_medicine_page( string $content ): bool {
	if ( ! nvx_aesthetic_is_singular_context() || is_front_page() || is_home() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( is_string( $path ) && (
		false !== strpos( $path, 'medicina-estetica-laser' )
		|| false !== strpos( $path, 'medicina-estetica-chamberi' )
		|| false !== strpos( $path, 'medicina-estetica-goya' )
	) ) {
		return false;
	}

	if (
		( is_string( $path ) && function_exists( 'nvx_schema_path_matches' ) && nvx_schema_path_matches( $path, '/medicina-estetica/' ) )
		|| 'medicina-estetica' === (string) get_post_field( 'post_name', get_queried_object_id() )
	) {
		return true;
	}

	if ( preg_match(
		'/nvx-laser-hub-page|nvx-brand-page--laser-hub|nvx-laser-editorial|id=["\']nvx-laser-h1["\']/iu',
		$content
	) ) {
		return false;
	}

	return (bool) preg_match(
		'/class=["\'][^"\']*nvx-brand-page--medicina-estetica|id=["\']nvx-med-h1["\']|aria-label=["\']Medicina estética NUVANX["\']/iu',
		$content
	);
}

/**
 * Linear premium icons — Champagne Bronce stroke 1.5px, 32×32.
 *
 * @param string $name Icon key.
 */
function nvx_aesthetic_icon( string $name ): string {
	$icons = array(
		'support'  => '<svg class="nvx-aes-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 24V12l8-6 8 6v12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 24v-8h8v8" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
		'express'  => '<svg class="nvx-aes-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="16" cy="16" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M11 14h.01M21 14h.01M12 20c1.5 2 6.5 2 8 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'rheology' => '<svg class="nvx-aes-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 8h12v4c0 4-2.5 6-6 8-3.5-2-6-4-6-8V8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 24h8M14 28h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'lips'     => '<svg class="nvx-aes-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 16c2-4 5-6 10-6s8 2 10 6c-2 4-5 6-10 6s-8-2-10-6Z" stroke="currentColor" stroke-width="1.5"/><path d="M8 16h16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'nose'     => '<svg class="nvx-aes-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 6h8l-1 14c0 3-2 6-3 6s-3-3-3-6L12 6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14 24h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'eye'      => '<svg class="nvx-aes-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 16c3-6 7-9 12-9s9 3 12 9c-3 6-7 9-12 9s-9-3-12-9Z" stroke="currentColor" stroke-width="1.5"/><circle cx="16" cy="16" r="3.5" stroke="currentColor" stroke-width="1.5"/></svg>',
		'regen'    => '<svg class="nvx-aes-icon" viewBox="0 0 32 32" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M16 28V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M16 24c-6 0-10-3.5-10-8.5 6 0 10 3.5 10 8.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M16 21c6 0 10-3.5 10-8.5-6 0-10 3.5-10 8.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M11 10c3-3 6-4.5 9-4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
	);

	return $icons[ $name ] ?? $icons['support'];
}

/**
 * Hero dual CTA: valoración + WhatsApp (proposal).
 */
function nvx_aesthetic_hero_ctas_markup(): string {
	$valoracion = function_exists( 'nvx_cta_valoracion_url' )
		? nvx_cta_valoracion_url()
		: home_url( '/madrid/valoracion/' );

	$html  = '<div class="nvx-brand-actions">';
	$html .= sprintf(
		'<a class="nvx-brand-btn nvx-brand-btn--primary" href="%1$s">%2$s</a>',
		esc_url( $valoracion ),
		esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' )
	);

	$whatsapp = '';
	if ( function_exists( 'nvx_cta_whatsapp_markup' ) ) {
		$whatsapp = nvx_cta_whatsapp_markup( 'nvx-brand-btn nvx-brand-btn--secondary' );
	}
	if ( '' === $whatsapp ) {
		$whatsapp = sprintf(
			'<a class="nvx-brand-btn nvx-brand-btn--secondary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( function_exists( 'nvx_whatsapp_url' ) ? nvx_whatsapp_url( 'primary' ) : 'https://wa.me/34669319836' ),
			esc_html__( 'Contactar por WhatsApp', 'nuvanx-medical' )
		);
	}
	$html .= $whatsapp;

	$html .= '</div>';

	return $html;
}

/**
 * Hero copy.
 */
function nvx_aesthetic_hero_copy_markup(): string {
	$clinics = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	$chamberi_reg = (string) ( $clinics['chamberi']['reg'] ?? '' );
	$goya_reg = (string) ( $clinics['goya']['reg'] ?? '' );
	$chamberi_name = (string) ( $clinics['chamberi']['short_name'] ?? '' );
	$goya_name = (string) ( $clinics['goya']['short_name'] ?? '' );

	$html  = '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'NUVANX · Madrid', 'nuvanx-medical' ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-med-h1">' . esc_html__( 'Medicina Estética Avanzada con Criterio Clínico', 'nuvanx-medical' ) . '</h1>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html__( 'Restauramos el soporte estructural, la turgencia y la armonía del rostro mediante procedimientos médicos inyectables y regenerativos de alta precisión. Sin alterar tu identidad y guiados exclusivamente por el diagnóstico personalizado de nuestro equipo médico.', 'nuvanx-medical' ) . '</p>';
	$html .= nvx_aesthetic_hero_ctas_markup();
	$html .= '<p class="nvx-brand-meta nvx-reg-copy">' . esc_html( $chamberi_name . ' (' . $chamberi_reg . ') · ' . $goya_name . ' (' . $goya_reg . ') · Preservación anatómica' ) . '</p>';
	$html .= '</div>';

	return $html;
}

/**
 * Canonical static data for the aesthetic medicine editorial hub.
 *
 * @return array<string, array<mixed>>
 */
function nvx_aesthetic_editorial_catalog(): array {
	static $catalog = null;

	if ( is_array( $catalog ) ) {
		return $catalog;
	}

	require_once __DIR__ . '/nvx-catalog-json.php';
	$catalog = nvx_catalog_json_resolved(
		'aesthetic-medicine-page.json',
		null,
		array(),
		array(
			'@nvx-aesthetic-url' => static function ( $arguments ) {
				$primary = is_array( $arguments ) && isset( $arguments['primary'] )
					? (string) $arguments['primary']
					: '';
				$alts = is_array( $arguments ) && isset( $arguments['alts'] ) && is_array( $arguments['alts'] )
					? $arguments['alts']
					: array();
				return nvx_aesthetic_resolve_treatment_url( $primary, $alts );
			},
		),
		'aesthetic-medicine-page'
	);

	return $catalog;
}

/**
 * Diagnosis pillars section.
 */
function nvx_aesthetic_diagnosis_section_markup(): string {
	$raw     = nvx_aesthetic_editorial_catalog()['pillars'] ?? array();
	$pillars = function_exists( 'nvx_catalog_filter_records' ) ? nvx_catalog_filter_records( $raw, array( 'icon', 'title', 'body' ), 'aesthetic-medicine-page.json' ) : $raw;

	$html  = '<section class="nvx-aes-section nvx-aes-diagnosis" aria-labelledby="nvx-aes-diagnosis-title">';
	$html .= '<div class="nvx-aes-section__inner">';
	$html .= '<p class="nvx-aes-kicker">' . esc_html__( 'El diagnóstico', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-aes-diagnosis-title" class="nvx-aes-heading">' . esc_html__( 'El diagnóstico antes del tratamiento', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-aes-body nvx-aes-body--lead">' . esc_html__( 'En NUVANX, la indicación de un inyectable no parte de un menú estandarizado, sino de una lectura clínica profunda de los vectores de envejecimiento del rostro. Evaluamos tres parámetros críticos:', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-aes-focus-grid">';

	foreach ( $pillars as $pillar ) {
		$html .= '<article class="nvx-aes-pillar">';
		$html .= nvx_aesthetic_icon( $pillar['icon'] );
		$html .= '<h3 class="nvx-aes-pillar__title">' . esc_html( $pillar['title'] ) . '</h3>';
		$html .= '<p class="nvx-aes-body">' . esc_html( $pillar['body'] ) . '</p>';
		$html .= '</article>';
	}

	$html .= '</div></div></section>';
	return $html;
}

/**
 * Resolve published page URL by primary path or alternate slugs (single cached lookup chain).
 *
 * @param string             $primary Primary path slug.
 * @param array<int, string> $alts    Alternate path slugs.
 */
function nvx_aesthetic_resolve_treatment_url( string $primary, array $alts = array() ): string {
	static $resolved = array();

	$key = $primary . '|' . implode( ',', $alts );
	if ( isset( $resolved[ $key ] ) ) {
		return $resolved[ $key ];
	}

	$found_url = null;
	foreach ( array_merge( array( $primary ), $alts ) as $slug ) {
		$slug = trim( (string) $slug, '/' );
		if ( '' !== $slug ) {
			$found = nvx_aesthetic_lookup_published_url( $slug );
			if ( null !== $found ) {
				$found_url = $found;
				break;
			}
		}
	}

	if ( null === $found_url ) {
		$found_url = home_url( '/' . trim( $primary, '/' ) . '/' );
	}

	$resolved[ $key ] = $found_url;
	return $found_url;
}

/**
 * Facial catalog cards.
 */
function nvx_aesthetic_catalog_section_markup(): string {
	$raw        = nvx_aesthetic_editorial_catalog()['treatments'] ?? array();
	$treatments = function_exists( 'nvx_catalog_filter_records' ) ? nvx_catalog_filter_records( $raw, array( 'n', 'icon', 'title', 'body', 'price', 'core', 'url' ), 'aesthetic-medicine-page.json' ) : $raw;

	$html  = '<section class="nvx-aes-section nvx-aes-catalog" aria-labelledby="nvx-aes-catalog-title">';
	$html .= '<div class="nvx-aes-section__inner">';
	$html .= '<p class="nvx-aes-kicker">' . esc_html__( 'Catálogo facial', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-aes-catalog-title" class="nvx-aes-heading">' . esc_html__( 'Procedimientos médico-estéticos faciales', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-aes-card-grid">';

	foreach ( $treatments as $treatment ) {
		$html .= '<article class="nvx-aes-card">';
		$html .= '<div class="nvx-aes-card__head">';
		$html .= nvx_aesthetic_icon( $treatment['icon'] );
		$html .= '<span class="nvx-aes-card__n">' . esc_html( $treatment['n'] ) . '</span>';
		$html .= '</div>';
		$html .= '<h3 class="nvx-aes-card__title">' . esc_html( $treatment['title'] ) . '</h3>';
		$html .= '<p class="nvx-aes-body">' . esc_html( $treatment['body'] ) . '</p>';
		// Valid description list: dt/dd are direct children of dl (no wrapping divs).
		$html .= '<dl class="nvx-aes-card__meta">';
		$html .= '<dt>' . esc_html__( 'Tarifa', 'nuvanx-medical' ) . '</dt>';
		$html .= '<dd>' . esc_html( $treatment['price'] ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Indicación core', 'nuvanx-medical' ) . '</dt>';
		$html .= '<dd>' . esc_html( $treatment['core'] ) . '</dd>';
		$html .= '</dl>';
		$html .= '<p class="nvx-aes-card__link-wrap"><a class="nvx-aes-card__link" href="' . esc_url( $treatment['url'] ) . '">' . esc_html__( 'Ver protocolo', 'nuvanx-medical' ) . '</a></p>';
		$html .= '</article>';
	}

	$html .= '</div></div></section>';
	return $html;
}

/**
 * Regeneration callout.
 */
function nvx_aesthetic_regen_section_markup(): string {
	$html  = '<section class="nvx-aes-section nvx-aes-regen" aria-labelledby="nvx-aes-regen-title">';
	$html .= '<div class="nvx-aes-section__inner nvx-aes-regen__grid">';
	$html .= '<div>';
	$html .= '<p class="nvx-aes-kicker">' . esc_html__( 'Regeneración', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-aes-regen-title" class="nvx-aes-heading">' . esc_html__( 'El estímulo biológico: firmeza sin volumen', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-aes-body">' . esc_html__( 'Los bioestimuladores (Sculptra®, Radiesse® y protocolos con PDRN) no rellenan: inducen una respuesta celular controlada en la dermis profunda. Los fibroblastos aumentan la síntesis de colágeno y matriz extracelular, densificando la piel y mejorando la turgencia con un resultado progresivo y natural.', 'nuvanx-medical' ) . '</p>';
	$html .= '</div>';
	$html .= '<aside class="nvx-aes-regen__panel" aria-label="' . esc_attr__( 'Criterio regenerativo', 'nuvanx-medical' ) . '">';
	$html .= '<p class="nvx-aes-meta-label">' . esc_html__( 'Criterio clínico', 'nuvanx-medical' ) . '</p>';
	$html .= '<ul class="nvx-aes-panel-list" role="list">';
	$html .= '<li><strong>' . esc_html__( 'Sin volumen artificial', 'nuvanx-medical' ) . '</strong> — ' . esc_html__( 'Tensado por neocolagénesis, no por relleno masivo.', 'nuvanx-medical' ) . '</li>';
	$html .= '<li><strong>' . esc_html__( 'Resultado bifásico', 'nuvanx-medical' ) . '</strong> — ' . esc_html__( 'Mejora progresiva entre semanas y meses según el protocolo.', 'nuvanx-medical' ) . '</li>';
	$html .= '<li><strong>' . esc_html__( 'Indicación médica', 'nuvanx-medical' ) . '</strong> — ' . esc_html__( 'Fototipo, elastosis y calidad dérmica definen el plan.', 'nuvanx-medical' ) . '</li>';
	$html .= '</ul></aside></div></section>';
	return $html;
}

/**
 * Clinical FAQs (AEO).
 */
function nvx_aesthetic_faq_section_markup(): string {
	$html  = '<section class="nvx-aes-section nvx-aes-faq" aria-labelledby="nvx-aes-faq-title">';
	$html .= '<div class="nvx-aes-section__inner">';
	$html .= '<p class="nvx-aes-kicker">' . esc_html__( 'Preguntas clínicas', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-aes-faq-title" class="nvx-aes-heading">' . esc_html__( 'Rigor científico sobre inyectables y regeneración', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-faq nvx-aes-faq-list">';

	$html .= '<details class="nvx-brand-faq-item">';
	$html .= '<summary><span>' . esc_html__( '¿Cómo influye la reología del ácido hialurónico en el éxito de una armonización facial y cómo se elige el producto adecuado?', 'nuvanx-medical' ) . '</span></summary>';
	$html .= '<div class="nvx-brand-faq-content">';
	$html .= '<p>' . esc_html__( 'En términos sencillos: la reología mide la firmeza y elasticidad del producto para elegir el gel exacto según la zona (firmeza para estructurar pómulos o mentón, y flexibilidad para zonas móviles como labios u ojeras). Clínicamente, el comportamiento del gel se define mediante el módulo de elasticidad complejo (G*), compuesto por el módulo de almacenamiento elástico (G′) y el módulo de pérdida viscoso (G″):', 'nuvanx-medical' ) . '</p>';
	$html .= '<figure class="nvx-aes-formula" aria-label="' . esc_attr__( 'Módulo de almacenamiento elástico G′', 'nuvanx-medical' ) . '">';
	$html .= '<p class="nvx-aes-formula__eq" role="math"><span class="nvx-aes-formula__g">G′</span> = <span class="nvx-aes-formula__frac"><span class="nvx-aes-formula__num">σ<sub>0</sub></span><span class="nvx-aes-formula__den">γ<sub>0</sub></span></span> cos(δ)</p>';
	$html .= '<figcaption class="nvx-aes-formula__cap">' . esc_html__( 'Donde σ₀ representa la amplitud del esfuerzo mecánico aplicado, γ₀ es la amplitud de la deformación resultante, y δ corresponde al ángulo de fase del gel. Un gel con alto G′ ofrece gran resistencia a la deformación y capacidad de elevación: lo indicamos en planos profundos y supraperiosteales (mandíbula, pómulos). En labios u ojeras seleccionamos G′ bajo y alta cohesividad para integración imperceptible sin migración.', 'nuvanx-medical' ) . '</figcaption>';
	$html .= '</figure></div></details>';

	$raw  = nvx_aesthetic_editorial_catalog()['faqs'] ?? array();
	$faqs = function_exists( 'nvx_catalog_filter_records' ) ? nvx_catalog_filter_records( $raw, array( 'q', 'a' ), 'aesthetic-medicine-page.json' ) : $raw;

	foreach ( $faqs as $faq ) {
		$html .= '<details class="nvx-brand-faq-item">';
		$html .= '<summary><span>' . esc_html( $faq['q'] ) . '</span></summary>';
		$html .= '<div class="nvx-brand-faq-content"><p>' . esc_html( $faq['a'] ) . '</p></div>';
		$html .= '</details>';
	}

	$html .= '</div></div></section>';
	return $html;
}

/**
 * Full editorial body after hero.
 * Closing valoración CTA: site-wide nvx-cta-banner in footer.php.
 */
function nvx_aesthetic_editorial_body_markup(): string {
	return '<div class="nvx-aesthetic-editorial">'
		. nvx_aesthetic_diagnosis_section_markup()
		. nvx_aesthetic_catalog_section_markup()
		. nvx_aesthetic_regen_section_markup()
		. nvx_aesthetic_faq_section_markup()
		. '</div>';
}

/**
 * Rebuild Medicina Estética hub page.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_aesthetic_medicine_page' ) && nvx_content_is_aesthetic_medicine_page( $content ) ) {
			return 'nvx_aesthetic_medicine_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_aesthetic_medicine_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_aesthetic_medicine_page' ) {
		return $content;
	}

	$media = function_exists( 'nvx_page_extract_brand_hero_media' ) ? nvx_page_extract_brand_hero_media( $content ) : '';

	$hero  = '<section class="nvx-brand-hero" aria-labelledby="nvx-med-h1" aria-label="' . esc_attr__( 'Medicina estética NUVANX', 'nuvanx-medical' ) . '">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= nvx_aesthetic_hero_copy_markup();
	$hero .= $media;
	$hero .= '</div></section>';

	$body = nvx_aesthetic_editorial_body_markup();
	$out  = $hero . $body;

	return '<div class="entry-content nvx-page__content">' . $out . '</div>';
}
add_filter( 'the_content', 'nvx_content_restructure_aesthetic_medicine_page', NVX_HOOK_PRIO_AESTHETIC_MEDICINE );
