<?php
/**
 * Governed Phase 1 and Phase 2 Signature landing pages.
 *
 * Clinical catalogue content lives in inc/data/nvx-signature-phase-catalog.json.
 * This module hydrates that data and owns routing, markup, SEO and navigation.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


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
 * Load raw Signature phase specs from the versioned JSON catalogue.
 *
 * @return array<string, array<string, mixed>>
 */
function nvx_signature_phase_catalog_specs(): array {

	return nvx_catalog_json_load( 'nvx-signature-phase-catalog.json' );
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

/**
 * Builds an HTML section containing a titled list of items.
 *
 * @param string $title The section heading.
 * @param array  $items The list items to display.
 * @param string $class Optional additional CSS class.
 * @return string The rendered HTML section.
 */
/**
 * Escape a Signature list line and wrap the first approved commercial mention.
 *
 * @param array<int,array{needle?:string,path?:string,anchor?:string}> $links
 */
function nvx_signature_apply_ficha_links( string $text, array $links ): string {
	$html = esc_html( $text );
	foreach ( $links as $link ) {
		if ( ! is_array( $link ) ) {
			continue;
		}
		$needle = (string) ( $link['needle'] ?? '' );
		$path   = (string) ( $link['path'] ?? '' );
		$anchor = (string) ( $link['anchor'] ?? $needle );
		if ( '' === $needle || '' === $path || false === strpos( $html, $needle ) ) {
			continue;
		}
		$html = preg_replace(
			'/' . preg_quote( $needle, '/' ) . '/u',
			'<a href="' . esc_url( home_url( $path ) ) . '">' . esc_html( $anchor ) . '</a>',
			$html,
			1
		);
		break;
	}

	return is_string( $html ) ? $html : esc_html( $text );
}

function nvx_signature_phase_list( string $title, array $items, string $class = '', array $ficha_links = array() ): string {
	$html  = '<section class="nvx-brand-section ' . esc_attr( $class ) . '"><div class="nvx-brand-section__inner">';
	$html .= '<h2>' . esc_html( $title ) . '</h2><ul class="nvx-check-list">';
	$idx   = 1;
	foreach ( $items as $item ) {
		$number = sprintf( '%02d', $idx );
		$line   = array() !== $ficha_links
			? nvx_signature_apply_ficha_links( (string) $item, $ficha_links )
			: esc_html( (string) $item );
		$html  .= '<li><span class="nvx-signature-list-number" aria-hidden="true">' . esc_html( $number ) . '</span> ' . $line . '</li>';
		++$idx;
	}
	return $html . '</ul></div></section>';
}

/**
 * Renders the optional "orientative parameters and care" section for a Signature page.
 *
 * Emits the wrapper section only when at least one of the mapped fields is present.
 *
 * @param array<string, mixed> $page Catalog entry.
 * @return string The rendered HTML section, or an empty string when no fields apply.
 */
function nvx_signature_phase_details_section( array $page ): string {
	$fields = array(
		'price_range'          => __( 'Tarifa orientativa:', 'nuvanx-medical' ),
		'sessions'             => __( 'Sesiones orientativas:', 'nuvanx-medical' ),
		'timeline'             => __( 'Evolución:', 'nuvanx-medical' ),
		'post_care'            => __( 'Cuidados recomendados:', 'nuvanx-medical' ),
		'comparison'           => __( 'Comparativa de abordajes:', 'nuvanx-medical' ),
		'scarring'             => __( 'Abordaje tisular:', 'nuvanx-medical' ),
		'laxity_levels'        => __( 'Niveles de laxitud aptos:', 'nuvanx-medical' ),
		'first_visit'          => __( 'Primera consulta:', 'nuvanx-medical' ),
		'financing'            => __( 'Financiación:', 'nuvanx-medical' ),
		'anatomy_note'         => __( 'Nota anatómica:', 'nuvanx-medical' ),
		'anatomy_diff'         => __( 'Diferenciación anatómica:', 'nuvanx-medical' ),
		'gynecomastia'         => __( 'Ginecomastia:', 'nuvanx-medical' ),
		'abdominal_definition' => __( 'Definición abdominal:', 'nuvanx-medical' ),
		'male_fat'             => __( 'Grasa masculina:', 'nuvanx-medical' ),
		'advantage'            => __( 'Ventaja:', 'nuvanx-medical' ),
	);


	$items = '';
	foreach ( $fields as $key => $label ) {
		if ( ! empty( $page[ $key ] ) ) {
			$items .= '<li><strong>' . esc_html( $label ) . '</strong> ' . esc_html( (string) $page[ $key ] ) . '</li>';
		}
	}

	if ( '' === $items ) {
		return '';
	}

	return '<section class="nvx-brand-section nvx-signature-details"><div class="nvx-brand-section__inner"><h2>'
		. esc_html__( 'Parámetros orientativos y cuidados', 'nuvanx-medical' )
		. '</h2><ul class="nvx-strategy-checklist">' . $items . '</ul></div></section>';
}

/**
 * Generates the governed landing page markup for a catalog entry.
 *
 * @param array $page Catalog entry containing the page content and related protocol.
 * @return string The generated landing page HTML.
 */
function nvx_signature_phase_markup( array $page ): string {
	$slug         = (string) ( $page['slug'] ?? '' );
	$ficha_links  = is_array( $page['ficha_links'] ?? null ) ? $page['ficha_links'] : array();
	$related      = is_array( $page['related_fichas'] ?? null ) ? $page['related_fichas'] : array();
	$price_range  = (string) ( $page['price_range'] ?? '' );
	if ( 'papada-definicion-mandibular-madrid' === $slug && function_exists( 'nvx_tariff_price_label' ) ) {
		$from = nvx_tariff_price_label( 'Endolift®', 'papada' );
		if ( '' !== $from ) {
			$price_range = sprintf(
				/* translators: %s: canonical papada tariff */
				__( 'desde %s', 'nuvanx-medical' ),
				$from
			);
		}
	}

	$html       = '<article class="nvx-brand-page nvx-treatment-page nvx-protocol-page nvx-signature-phase-page">';
	$html      .= '<section class="nvx-brand-hero" aria-labelledby="nvx-signature-title"><div class="nvx-brand-hero__inner"><div class="nvx-brand-hero__copy">';
	$html      .= '<p class="nvx-brand-kicker">' . esc_html( (string) $page['kicker'] ) . '</p>';
	$html      .= '<h1 id="nvx-signature-title" class="nvx-brand-hero__title">' . esc_html( (string) $page['title'] ) . '</h1>';
	$html      .= function_exists( 'nvx_clinical_authority_byline_markup' )
		? nvx_clinical_authority_byline_markup()
		: '';
	$valoracion = esc_url( nvx_signature_valoracion_url() );
	$html      .= '<p class="nvx-brand-hero__lead" id="nvx-signature-lead">' . esc_html( (string) $page['lead'] ) . '</p><p>' . esc_html( (string) $page['intro'] ) . '</p>';

	if ( '' !== $price_range ) {
		$html .= '<p class="nvx-brand-hero__price"><strong>' . esc_html__( 'Tarifa orientativa:', 'nuvanx-medical' ) . '</strong> '
			. esc_html( $price_range ) . '. '
			. ( ! empty( $page['price_technology'] )
				? esc_html__( 'Tecnología habitual: ', 'nuvanx-medical' ) . esc_html( (string) $page['price_technology'] ) . '. '
				: '' )
			. esc_html( (string) ( $page['price_note'] ?? '' ) )
			. '</p>';
	}

	$html      .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . $valoracion . '">' . esc_html__( 'Solicitar valoración médica privada', 'nuvanx-medical' ) . '</a></div>';
	$html      .= '<p class="nvx-brand-meta">' . esc_html__( 'Valoración presencial en nuestras clínicas de Madrid: Chamberí (Reg. Sanitario CS20144) y Salamanca–Goya (Reg. Sanitario CS20073). La indicación, la tecnología, el número de sesiones, el período de recuperación y el presupuesto se confirman después de la exploración médica.', 'nuvanx-medical' ) . '</p></div></div></section>';
	$html      .= nvx_signature_phase_list( 'Qué se valora', (array) $page['assessment'] );
	$html      .= '<section class="nvx-brand-section"><div class="nvx-brand-section__inner"><h2>' . esc_html__( 'Cómo se decide el plan', 'nuvanx-medical' ) . '</h2>';
	$html      .= '<p>' . esc_html__( 'El médico identifica el componente predominante, revisa zonas contiguas y descarta problemas que no deben abordarse con medicina estética. Solo entonces se selecciona una modalidad y se documentan alternativas, cuidados y seguimiento.', 'nuvanx-medical' ) . '</p>';
	$html      .= '<p><strong>' . esc_html__( 'Protocolo relacionado:', 'nuvanx-medical' ) . '</strong> ' . esc_html( (string) $page['protocol'] ) . '</p></div></section>';
	$html      .= nvx_signature_phase_list( 'Tecnologías que pueden formar parte del plan', (array) $page['technology'], '', $ficha_links );
	if ( array() !== $related ) {
		$html .= '<div class="nvx-related-links">';
		foreach ( $related as $item ) {
			if ( ! is_array( $item ) || empty( $item['path'] ) || empty( $item['anchor'] ) ) {
				continue;
			}
			$html .= '<p>' . esc_html( (string) ( $item['intro'] ?? '' ) ) . ' ';
			$html .= '<a href="' . esc_url( home_url( (string) $item['path'] ) ) . '">' . esc_html( (string) $item['anchor'] ) . '</a>';
			$html .= esc_html( (string) ( $item['suffix'] ?? '' ) ) . '</p>';
		}
		$html .= '</div>';
	}
	$html      .= nvx_signature_phase_list( 'Límites y cuándo derivamos', (array) $page['limits'], 'nvx-strategy-checklist nvx-strategy-checklist--no' );
	$html      .= nvx_signature_phase_details_section( $page );
	$html      .= nvx_signature_faq_section( isset( $page['faq'] ) && is_array( $page['faq'] ) ? $page['faq'] : array() );

	$html      .= '<section class="nvx-brand-section"><div class="nvx-brand-section__inner"><h2>' . esc_html__( 'Tu primera valoración clínica', 'nuvanx-medical' ) . '</h2>';

	$html      .= '<p>' . esc_html__( 'La valoración revisa antecedentes, anatomía, tejido predominante, tratamientos previos, expectativas y disponibilidad para cuidados. Si no existe una indicación proporcionada, se explica la alternativa, la derivación o la decisión de no intervenir.', 'nuvanx-medical' ) . '</p>';
	$html      .= '<p><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . $valoracion . '">' . esc_html__( 'Iniciar valoración médica', 'nuvanx-medical' ) . '</a> <a class="nvx-brand-inline-link nvx-brand-inline-link--light" href="' . esc_url( home_url( '/protocolos-signature/' ) ) . '">' . esc_html__( 'Explorar Protocolos Signature', 'nuvanx-medical' ) . '</a></p></div></section></article>';
	return $html;
}

/**
 * Signature architecture hubs (index + Contour + Post-Maternity).
 *
 * These pages are thin CMS shells with protocol markers; the theme owns the
 * full editorial markup and internal links into the governed catalog.
 *
 * @return array<string, array{
 *   slug:string,
 *   marker?:string,
 *   h1:string,
 *   kicker:string,
 *   lead:string,
 *   intro:string,
 *   seo_title:string,
 *   seo_desc:string,
 *   kind:string
 * }>
 */
function nvx_signature_hub_catalog(): array {
	$contour = nvx_signature_contour_label();
	$short   = nvx_signature_contour_label_short();

	return array(
		'signature-index'      => array(
			'slug'      => 'protocolos-signature',
			'marker'    => 'NUVANX_PROTOCOL_HUB',
			'kind'      => 'index',
			'kicker'    => 'NUVANX · Protocolos Signature · Madrid',
			'h1'        => 'Protocolos Signature: medicina estética de diagnóstico.',
			'lead'      => 'Cada protocolo organiza la decisión clínica alrededor de un objetivo. No son paquetes cerrados ni combinaciones automáticas: la tecnología se elige después de valorar anatomía, tejido y expectativas.',
			'intro'     => 'Los Protocolos Signature conectan diagnóstico, modalidad y seguimiento. El nombre del protocolo ordena la conversación; la indicación, el número de sesiones, la recuperación y el presupuesto se confirman en consulta. Chamberí (CS20144) · Salamanca–Goya (CS20073).',
			'seo_title' => 'Protocolos Signature Madrid | NUVANX',
			'seo_desc'  => 'Protocolos Signature NUVANX en Madrid: rutas clínicas de diagnóstico para contorno, calidad de piel, textura, tono y perfil facial.',
		),
		'contour-architecture' => array(
			'slug'      => 'remodelacion-corporal-laser-madrid',
			'marker'    => 'NUVANX_PROTOCOL_PAGE:contour-architecture',
			'kind'      => 'contour',
			'kicker'    => $contour,
			'h1'        => 'Remodelación corporal láser en Madrid: contorno según tu anatomía.',
			'lead'      => $short . ' evalúa grasa localizada, laxitud y continuidad entre zonas antes de indicar una tecnología. El plan se diseña por anatomía, no por una lista de aparatos.',
			'intro'     => 'Abdomen, flancos, brazos, espalda, muslos, rodillas o contorno masculino pueden formar parte del mismo marco de decisión. Cada zona se presupuesta solo si tiene indicación documentada tras la exploración. Chamberí (CS20144) · Salamanca–Goya (CS20073) · Presupuesto por zona tras valoración.',
			'seo_title' => 'Remodelación Corporal Láser Madrid | Contorno por Zonas | NUVANX',
			'seo_desc'  => 'Contour Architecture™ en Madrid: abdomen, flancos, brazos, espalda, muslos y rodillas. Valoración de grasa y laxitud en Chamberí (CS20144) y Goya (CS20073).',
		),
		'post-maternity'       => array(
			'slug'      => 'tratamiento-postparto-abdomen-contorno-corporal-madrid',
			'marker'    => 'NUVANX_PROTOCOL_PAGE:post-maternity',
			'kind'      => 'post-maternity',
			'kicker'    => 'NUVANX Post-Maternity Contour™',
			'h1'        => 'Tratamiento postparto: abdomen y contorno corporal en Madrid.',
			'lead'      => 'Lectura respetuosa de abdomen, flancos y calidad del tejido después del embarazo. Se separa grasa subcutánea, laxitud, diástasis y expectativas realistas antes de proponer cualquier modalidad.',
			'intro'     => 'El postparto no es un protocolo estándar. La valoración considera lactancia, tiempo desde el parto, pared abdominal, cicatrices y disponibilidad de recuperación. Si no hay indicación proporcionada, se explica la alternativa o la espera. Chamberí (CS20144) · Salamanca–Goya (CS20073) · Indicación solo tras valoración.',
			'seo_title' => 'Tratamiento Postparto Abdomen Madrid | Contorno tras el Embarazo | NUVANX',
			'seo_desc'  => 'Post-Maternity Contour™ en Madrid: valoración de grasa, laxitud y pared abdominal tras el embarazo. Diástasis y lactancia se evalúan antes de indicar. Chamberí (CS20144) y Salamanca–Goya (CS20073).',
		),
	);
}

/**
 * Match a Signature hub catalog key by page slug.
 */
function nvx_signature_hub_key_by_slug( string $slug ): ?string {
	if ( '' === $slug ) {
		return null;
	}
	foreach ( nvx_signature_hub_catalog() as $key => $hub ) {
		if ( isset( $hub['slug'] ) && $hub['slug'] === $slug ) {
			return $key;
		}
	}
	return null;
}

/**
 * Match a Signature hub catalog key by CMS protocol marker in content.
 */
function nvx_signature_hub_key_by_marker( string $content ): ?string {
	if ( '' === $content ) {
		return null;
	}
	foreach ( nvx_signature_hub_catalog() as $key => $hub ) {
		$marker = isset( $hub['marker'] ) ? (string) $hub['marker'] : '';
		if ( '' !== $marker && false !== strpos( $content, $marker ) ) {
			return $key;
		}
	}
	return null;
}

/**
 * Resolve the Signature hub key for the current page (slug or CMS marker).
 */
function nvx_signature_hub_current_key( ?string $content = null ): ?string {
	// If content is provided, check marker first (works in filter context)
	if ( null !== $content && false !== strpos( $content, 'NUVANX_PROTOCOL_PAGE:' ) ) {
		return nvx_signature_hub_key_by_marker( $content );
	}

	// If in page context, check by slug
	if ( is_page() && ! is_404() ) {
		$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
		$key  = nvx_signature_hub_key_by_slug( $slug );
		if ( null !== $key ) {
			return $key;
		}

		// Fallback to marker check from post content
		if ( null === $content ) {
			$content = (string) get_post_field( 'post_content', get_queried_object_id() );
		}
		return nvx_signature_hub_key_by_marker( $content );
	}

	return null;
}

/**
 * Published URL for a Signature catalog or hub slug when the page exists.
 */
function nvx_signature_published_url( string $slug ): string {
	$slug = trim( $slug, '/' );
	if ( '' === $slug ) {
		return '';
	}
	$page = get_page_by_path( $slug );
	if ( $page && 'publish' === get_post_status( $page ) ) {
		return (string) get_permalink( $page );
	}
	return home_url( '/' . $slug . '/' );
}

/**
 * Card grid markup for Signature hub listings.
 *
 * @param array<int, array{kicker?:string,title:string,body:string,url:string,cta?:string,price?:string}> $cards
 * @return string
 */
function nvx_signature_hub_cards_markup( array $cards, string $section_title, string $section_kicker = '' ): string {
	$html  = '<section class="nvx-brand-section nvx-brand-section--soft" aria-label="' . esc_attr( $section_title ) . '">';
	$html .= '<div class="nvx-brand-section__inner">';
	if ( '' !== $section_kicker ) {
		$html .= '<p class="nvx-brand-kicker">' . esc_html( $section_kicker ) . '</p>';
	}
	$html .= '<h2 class="nvx-brand-title">' . esc_html( $section_title ) . '</h2>';
	$html .= '<ul class="nvx-brand-grid nvx-brand-grid--3">';
	foreach ( $cards as $card ) {
		$title = (string) ( $card['title'] ?? '' );
		$body  = (string) ( $card['body'] ?? '' );
		$url   = (string) ( $card['url'] ?? '' );
		$cta   = (string) ( $card['cta'] ?? __( 'Explorar protocolo', 'nuvanx-medical' ) );
		if ( '' === $title || '' === $url ) {
			continue;
		}
		$card_id = 'signature-card-' . sanitize_title( $title );
		$html   .= '<li class="nvx-brand-card" aria-labelledby="' . esc_attr( $card_id ) . '">';
		if ( ! empty( $card['kicker'] ) ) {
			$html .= '<p class="nvx-brand-card__kicker">' . esc_html( (string) $card['kicker'] ) . '</p>';
		}
		$html .= '<h3 id="' . esc_attr( $card_id ) . '" class="nvx-brand-card__title">' . esc_html( $title ) . '</h3>';
		$html .= '<p class="nvx-brand-card__body">' . esc_html( $body ) . '</p>';
		if ( ! empty( $card['price'] ) ) {
			$html .= '<p class="nvx-brand-card__price">' . esc_html( (string) $card['price'] ) . '</p>';
		}
		$html .= '<a class="nvx-brand-card__cta" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $cta . ': ' . $title ) . '">' . esc_html( $cta ) . '</a>';
		$html .= '</li>';
	}
	$html .= '</ul></div></section>';
	return $html;
}

/**
 * Cards for Phase 1 facial / skin Signature protocols from the catalog.
 *
 * @return array<int, array{kicker:string,title:string,body:string,url:string,cta:string,price?:string}>
 */
function nvx_signature_hub_phase1_cards(): array {
	$cards = array();
	foreach ( nvx_signature_phase_catalog() as $page ) {
		if ( 1 !== (int) ( $page['phase'] ?? 0 ) ) {
			continue;
		}
		$slug = (string) ( $page['slug'] ?? '' );
		if ( '' === $slug ) {
			continue;
		}
		$card = array(
			'kicker' => (string) ( $page['protocol'] ?? $page['kicker'] ?? '' ),
			'title'  => (string) ( $page['title'] ?? '' ),
			'body'   => (string) ( $page['lead'] ?? '' ),
			'url'    => nvx_signature_published_url( $slug ),
			'cta'    => __( 'Explorar protocolo', 'nuvanx-medical' ),
		);
		if ( ! empty( $page['price_range'] ) ) {
			$card['price'] = (string) $page['price_range'];
		}
		$cards[] = $card;
	}
	return $cards;
}

/**
 * Cards for Contour Architecture™ body zones (Phase 2 catalog + nav labels).
 *
 * @return array<int, array{kicker:string,title:string,body:string,url:string,cta:string,price?:string}>
 */
function nvx_signature_hub_contour_cards(): array {
	$by_slug = array();
	foreach ( nvx_signature_phase_catalog() as $page ) {
		if ( 2 !== (int) ( $page['phase'] ?? 0 ) ) {
			continue;
		}
		$slug = (string) ( $page['slug'] ?? '' );
		if ( '' === $slug ) {
			continue;
		}
		$by_slug[ $slug ] = $page;
	}

	$kicker = nvx_signature_contour_label();
	$cards  = array();
	foreach ( nvx_signature_contour_nav_children() as $child ) {
		$label = (string) ( $child['label'] ?? '' );
		$slugs = isset( $child['slugs'] ) && is_array( $child['slugs'] ) ? $child['slugs'] : array();
		$slug  = isset( $slugs[0] ) ? (string) $slugs[0] : '';
		if ( '' === $slug ) {
			continue;
		}
		$page    = $by_slug[ $slug ] ?? null;
		$body    = is_array( $page ) ? (string) ( $page['lead'] ?? $page['intro'] ?? '' ) : '';
		$card    = array(
			'kicker' => $kicker,
			'title'  => $label,
			'body'   => $body,
			'url'    => nvx_signature_published_url( $slug ),
			'cta'    => __( 'Valorar esta zona', 'nuvanx-medical' ),
		);
		if ( is_array( $page ) && ! empty( $page['price_range'] ) ) {
			$card['price'] = (string) $page['price_range'];
		}
		$cards[] = $card;
	}
	return $cards;
}

/**
 * Shared hero + closing CTA for Signature hubs.
 *
 * @param array<string, string> $hub
 */
function nvx_signature_hub_shell_open( array $hub ): string {
	$valoracion = esc_url( nvx_signature_valoracion_url() );
	$html       = '<article class="nvx-brand-page nvx-brand-page--signature"><div class="entry-content nvx-page__content">';
	$html      .= '<section class="nvx-brand-hero" aria-labelledby="nvx-signature-hub-h1"><div class="nvx-brand-hero__inner"><div class="nvx-brand-hero__copy">';
	$html      .= '<p class="nvx-brand-kicker">' . esc_html( (string) ( $hub['kicker'] ?? '' ) ) . '</p>';
	$html      .= '<h1 id="nvx-signature-hub-h1" class="nvx-brand-hero__title">' . esc_html( (string) ( $hub['h1'] ?? '' ) ) . '</h1>';
	$html      .= '<p class="nvx-brand-hero__lead">' . esc_html( (string) ( $hub['lead'] ?? '' ) ) . '</p>';
	$html      .= '<p class="nvx-brand-hero__description">' . esc_html( (string) ( $hub['intro'] ?? '' ) ) . '</p>';
	$html      .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . $valoracion . '">' . esc_html__( 'Solicitar valoración médica', 'nuvanx-medical' ) . '</a>';
	if ( 'index' !== ( $hub['kind'] ?? '' ) ) {
		$html .= ' <a class="nvx-brand-inline-link nvx-brand-inline-link--light" href="' . esc_url( home_url( '/protocolos-signature/' ) ) . '">' . esc_html__( 'Ver todos los Protocolos Signature', 'nuvanx-medical' ) . '</a>';
	}
	$html .= '</div>';
	$html .= '<p class="nvx-brand-microcopy nvx-brand-microcopy--dark">' . esc_html__( 'La indicación, la tecnología, el número de sesiones, el período de recuperación y el presupuesto se confirman después de la exploración médica.', 'nuvanx-medical' ) . '</p>';
	$html .= '</div></div></section>';
	return $html;
}

/** Closing valuation band for Signature hubs. */
function nvx_signature_hub_shell_close(): string {
	$html  = '<section class="nvx-brand-section"><div class="nvx-brand-section__inner">';
	$html .= '<h2>' . esc_html__( 'Tu primera valoración clínica', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p>' . esc_html__( 'La valoración revisa antecedentes, anatomía, tejido predominante, tratamientos previos y expectativas. Si no existe una indicación proporcionada, se explica la alternativa, la derivación o la decisión de no intervenir.', 'nuvanx-medical' ) . '</p>';
	$html .= '<p><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( nvx_signature_valoracion_url() ) . '">' . esc_html__( 'Iniciar valoración médica', 'nuvanx-medical' ) . '</a></p>';
	$html .= '</div></section></div></article>';
	return $html;
}

/**
 * Full markup for a Signature hub page.
 *
 * @param array<string, string> $hub
 */
function nvx_signature_hub_markup( array $hub ): string {
	$kind = (string) ( $hub['kind'] ?? '' );
	$html = nvx_signature_hub_shell_open( $hub );

	if ( 'index' === $kind ) {
		$html .= '<section class="nvx-brand-section"><div class="nvx-brand-section__inner">';
		$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Qué significa Signature', 'nuvanx-medical' ) . '</p>';
		$html .= '<h2 class="nvx-brand-title">' . esc_html__( 'Una ruta de decisión, no un paquete cerrado', 'nuvanx-medical' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Cada protocolo parte de una necesidad clínica concreta y puede integrar consulta, preparación de la piel, tecnología, procedimiento médico y seguimiento. El nombre del protocolo ordena la conversación, pero no determina por sí solo qué tratamiento se realizará.', 'nuvanx-medical' ) . '</p>';
		$html .= '</div></section>';

		// Architecture hubs first.
		$architecture_cards = array(
			array(
				'kicker' => 'Contorno corporal',
				'title'  => nvx_signature_contour_label(),
				'body'   => 'Evaluación de grasa localizada, firmeza y proporción para definir un abordaje corporal coherente por zonas.',
				'url'    => nvx_signature_published_url( 'remodelacion-corporal-laser-madrid' ),
				'cta'    => __( 'Explorar contorno corporal', 'nuvanx-medical' ),
			),
			array(
				'kicker' => 'Cambios posgestacionales',
				'title'  => 'NUVANX Post-Maternity Contour™',
				'body'   => 'Lectura respetuosa de abdomen, flancos y calidad del tejido después del embarazo, sin prometer una transformación estándar.',
				'url'    => nvx_signature_published_url( 'tratamiento-postparto-abdomen-contorno-corporal-madrid' ),
				'cta'    => __( 'Consultar valoración posgestacional', 'nuvanx-medical' ),
			),
		);
		$html              .= nvx_signature_hub_cards_markup( $architecture_cards, 'Arquitecturas clínicas', 'Protocolos de contorno' );
		$html              .= nvx_signature_hub_cards_markup( nvx_signature_hub_phase1_cards(), 'Protocolos de rostro y piel', 'Fase 1' );
	} elseif ( 'contour' === $kind ) {
		$html .= '<section class="nvx-brand-section"><div class="nvx-brand-section__inner">';
		$html .= '<h2>' . esc_html__( 'Cómo se decide el plan corporal', 'nuvanx-medical' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'El médico identifica el componente predominante (grasa subcutánea, laxitud, calidad cutánea), revisa zonas contiguas y descarta problemas que no deben abordarse con medicina estética: grasa visceral, diástasis significativa, hernia o exceso cutáneo que requiera otra vía.', 'nuvanx-medical' ) . '</p>';
		$html .= '</div></section>';
		$html .= nvx_signature_hub_cards_markup( nvx_signature_hub_contour_cards(), 'Zonas de valoración', nvx_signature_contour_label_short() );
	} elseif ( 'post-maternity' === $kind ) {
		$html   .= nvx_signature_phase_list(
			'Qué se valora en postparto',
			array(
				'Tiempo desde el parto, lactancia y estado general de salud.',
				'Abdomen superior e inferior, flancos y continuidad con la espalda.',
				'Grasa subcutánea frente a cambios de pared abdominal o sospecha de diástasis.',
				'Calidad cutánea, cicatrices y disponibilidad real de recuperación.',
			)
		);
		$html   .= nvx_signature_phase_list(
			'Límites y cuándo esperamos o derivamos',
			array(
				'No es un protocolo de "recuperar el cuerpo de antes" en un plazo fijo.',
				'La grasa visceral y la diástasis con indicación quirúrgica no se resuelven con un tratamiento estético focal.',
				'Si el momento clínico no es adecuado, se propone espera o derivación en lugar de intervenir.',
			),
			'nvx-strategy-checklist nvx-strategy-checklist--no'
		);
		$html .= nvx_signature_faq_section(
			array(
				array(
					'q' => '¿Puedo tratarme en lactancia?',
					'a' => 'Solo tras valoración individual. En muchos casos se espera o se limita el plan; no hay calendario mágico "a los X meses" igual para todas.',
				),
				array(
					'q' => '¿Corrige la diástasis de rectos?',
					'a' => 'La diástasis se evalúa antes de indicar. Un protocolo de contorno no sustituye la reparación quirúrgica cuando esta es la vía adecuada.',
				),
				array(
					'q' => '¿Es una abdominoplastia sin cirugía?',
					'a' => 'No. No se promete el resultado de una cirugía de contorno. El objetivo es mejorar grasa y/o calidad tisular si hay indicación y el momento es seguro.',
				),
				array(
					'q' => '¿Cuándo tiene sentido valorar?',
					'a' => 'Cuando hay queja localizada (abdomen, flancos, calidad de piel), expectativas realistas y condiciones clínicas que permitan un plan seguro.',
				),
				array(
					'q' => '¿Dónde?',
					'a' => 'Valoración en Chamberí (CS20144) y Salamanca–Goya (CS20073), con plan documentado si procede.',
				),
			)
		);
		$related = array(
			array(
				'kicker' => 'Zona relacionada',
				'title'  => 'Abdomen y flancos',
				'body'   => 'Cuando el componente principal es grasa subcutánea localizada tras la valoración.',
				'url'    => nvx_signature_published_url( 'grasa-localizada-abdomen-flancos-madrid' ),
				'cta'    => __( 'Ver valoración de abdomen', 'nuvanx-medical' ),
			),
			array(
				'kicker' => 'Marco corporal',
				'title'  => nvx_signature_contour_label(),
				'body'   => 'Visión por zonas de contorno cuando el plan no se limita al abdomen postparto.',
				'url'    => nvx_signature_published_url( 'remodelacion-corporal-laser-madrid' ),
				'cta'    => __( 'Explorar contorno corporal', 'nuvanx-medical' ),
			),
		);
		$html   .= nvx_signature_hub_cards_markup( $related, 'Rutas relacionadas', 'Continuidad clínica' );
	}

	$html .= nvx_signature_hub_shell_close();
	return $html;
}


/**
 * Canonical short-path map for Contour and Post-Maternity public hubs.
 *
 * Short slugs that resolve as media attachments or missing pages must land on
 * the governed clinical URLs (not the attachment file).
 *
 * @return array<string, string>
 */
function nvx_signature_short_hub_redirect_map(): array {
	return array(
		'remodelacion-corporal' => '/remodelacion-corporal-laser-madrid/',
		'postparto'             => '/tratamiento-postparto-abdomen-contorno-corporal-madrid/',
	);
}

/**
 * Whether the current request path should redirect to a governed Signature hub.
 */
function nvx_signature_should_redirect_short_hub( string $request_path ): bool {
	$map = nvx_signature_short_hub_redirect_map();
	if ( '' === $request_path || ! isset( $map[ $request_path ] ) ) {
		return false;
	}
	if ( false !== strpos( $request_path, 'wp-content/uploads' ) ) {
		return false;
	}

	// A published page may own the short slug; do not override it.
	$page = get_page_by_path( $request_path );
	if ( $page && 'publish' === get_post_status( $page ) && 'page' === $page->post_type ) {
		return false;
	}

	// Attachment, 404, or non-page resolution on the short slug → governed hub.
	return is_attachment() || is_404() || is_singular( 'attachment' ) || ! is_page();
}

/**
 * 301 short Contour/Post-Maternity paths to the governed clinical hub URLs.
 */
function nvx_signature_redirect_short_hub_paths(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
	$request_path = trim( $request_path, '/' );
	if ( ! nvx_signature_should_redirect_short_hub( $request_path ) ) {
		return;
	}

	$map = nvx_signature_short_hub_redirect_map();
	wp_safe_redirect( home_url( $map[ $request_path ] ), 301 );
	exit;
}
add_action( 'template_redirect', 'nvx_signature_redirect_short_hub_paths', 0 );

/** Suppress the generic shell title because this module renders the canonical H1. */
function nvx_signature_phase_prepare_shell(): void {
	if ( null !== nvx_signature_phase_current_key() || null !== nvx_signature_hub_current_key() ) {
		set_query_var( 'nvx_shell_skip_header', true );
	}
}
add_action( 'wp', 'nvx_signature_phase_prepare_shell', 5 );

/**
 * Injects the governed markup for Signature phase and hub pages.
 */
function nvx_signature_phase_inject_markup( string $content ): string {
	$phase_key = nvx_signature_phase_current_key();
	if ( null !== $phase_key ) {
		$catalog = nvx_signature_phase_catalog();
		if ( isset( $catalog[ $phase_key ] ) ) {
			return nvx_signature_phase_markup( $catalog[ $phase_key ] );
		}
	}

	$hub_key = nvx_signature_hub_current_key( $content );
	if ( null !== $hub_key ) {
		$hubs = nvx_signature_hub_catalog();
		if ( isset( $hubs[ $hub_key ] ) ) {
			return nvx_signature_hub_markup( $hubs[ $hub_key ] );
		}
	}

	return $content;
}
add_filter(
	'nvx_page_owner',
	static function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		if ( null !== nvx_signature_phase_current_key() ) {
			return 'nvx_signature_phase_pages';
		}
		if ( null !== nvx_signature_hub_current_key() ) {
			return 'nvx_signature_phase_pages';
		}
		return $owner;
	}
);
add_filter( 'the_content', 'nvx_signature_phase_inject_markup', 20 );

/**
 * Contour Architecture™ child routes for the primary navigation mega-menu.
 *
 * @return array<int, array{label:string,slugs:array<int,string>}>
 */
function nvx_signature_contour_nav_children(): array {
	return array(
		array(
			'label' => 'Abdomen y flancos',
			'slugs' => array( 'grasa-localizada-abdomen-flancos-madrid' ),
		),
		array(
			'label' => 'Brazos y axila',
			'slugs' => array( 'flacidez-grasa-localizada-brazos-madrid' ),
		),
		array(
			'label' => 'Espalda y zona del sujetador',
			'slugs' => array( 'grasa-espalda-zona-sujetador-madrid' ),
		),
		array(
			'label' => 'Muslos y región subglútea',
			'slugs' => array( 'flacidez-muslos-internos-subgluteo-madrid' ),
		),
		array(
			'label' => 'Rodillas',
			'slugs' => array( 'tratamiento-rodillas-grasa-flacidez-madrid' ),
		),
		array(
			'label' => 'Contorno masculino',
			'slugs' => array( 'contorno-corporal-masculino-madrid' ),
		),
	);
}

/**
 * Updates navigation routes for Contour or post-maternity labels.
 *
 * @param array $child The navigation child to update.
 * @return array The updated navigation child.
 */
function nvx_signature_apply_contour_children( array $child ): array {
	$child_label = isset( $child['label'] ) ? (string) $child['label'] : '';
	$is_contour  = false !== stripos( $child_label, 'Contour Architecture™' )
		|| false !== stripos( $child_label, 'Contour Sculpt' )
		|| false !== stripos( $child_label, 'Couture Sculpt' );

	if ( $is_contour ) {
		$child['label']    = nvx_signature_contour_label();
		$child['slugs']    = array( 'remodelacion-corporal-laser-madrid' );
		$child['children'] = nvx_signature_contour_nav_children();
		return $child;
	}

	if ( false !== stripos( $child_label, 'Post-Maternity' ) || false !== stripos( $child_label, 'Profile Definition' ) ) {
		$child['children'] = array();
	}
	return $child;
}

/** Filter protocol children for Signature menu items. */
function nvx_signature_filter_protocol_children( array $children ): array {
	$filtered = array();
	foreach ( $children as $child ) {
		$child_label = isset( $child['label'] ) ? (string) $child['label'] : '';
		if ( false !== stripos( $child_label, 'Eye Frame' ) ) {
			continue;
		}
		$filtered[] = nvx_signature_apply_contour_children( $child );
	}
	return $filtered;
}

/**
 * Restricts the primary navigation to supported Signature routes and published clinical case routes.
 *
 * @param array $blueprint The primary navigation blueprint.
 * @return array The updated navigation blueprint.
 */
function nvx_signature_phase_navigation_blueprint( array $blueprint ): array {
	foreach ( $blueprint as $top_index => $top ) {
		$label = isset( $top['label'] ) ? (string) $top['label'] : '';
		if ( 'Casos clínicos' === $label ) {
			$blueprint[ $top_index ]['slugs'] = array( 'casos-de-pacientes', 'casos-clinicos' );
		}
		if ( 'Protocolos Signature' === $label && ! empty( $top['children'] ) && is_array( $top['children'] ) ) {
			$blueprint[ $top_index ]['children'] = nvx_signature_filter_protocol_children( $top['children'] );
		}
	}
	return $blueprint;
}
add_filter( 'nvx_navigation_primary_blueprint', 'nvx_signature_phase_navigation_blueprint', 30 );

/**
 * Replaces retired product names with the approved public product name.
 *
 * @param string $content Content containing product names to normalize.
 * @return string Content with retired product names replaced by the approved public product name.
 */
function nvx_signature_phase_normalize_public_names( string $content ): string {
	return str_ireplace( array( 'Couture Sculpt™', 'NUVANX Contour Sculpt™', 'Contour Sculpt™' ), NVX_CONTOUR_ARCHITECTURE, $content );
}
add_filter( 'the_content', 'nvx_signature_phase_normalize_public_names', NVX_HOOK_PRIO_SIGNATURE_NAMES );

/** Resolve metadata for the current governed landing page (detail or hub). */
function nvx_signature_phase_current_metadata(): ?array {
	$key = nvx_signature_phase_current_key();
	if ( null !== $key ) {
		$catalog = nvx_signature_phase_catalog();
		return $catalog[ $key ] ?? null;
	}

	$hub_key = nvx_signature_hub_current_key();
	$hub     = ( null !== $hub_key ) ? ( nvx_signature_hub_catalog()[ $hub_key ] ?? null ) : null;
	if ( ! is_array( $hub ) ) {
		return null;
	}

	// Normalize hub fields to the same SEO keys as detail pages.
	return array(
		'seo_title' => (string) ( $hub['seo_title'] ?? '' ),
		'seo_desc'  => (string) ( $hub['seo_desc'] ?? '' ),
		'title'     => (string) ( $hub['h1'] ?? '' ),
	);
}

/**
 * Provides the governed page's SEO title when available.
 *
 * @param string $title The current SEO title.
 * @return string The governed page's SEO title or the original title.
 */
function nvx_signature_phase_seo_title( $title ) {
	$page = nvx_signature_phase_current_metadata();
	if ( ! is_array( $page ) || empty( $page['seo_title'] ) ) {
		return $title;
	}
	return (string) $page['seo_title'];
}

/**
 * Get SEO description from Signature phase metadata.
 *
 * @param string $description The default description.
 * @return string The SEO description from metadata or the default.
 */
function nvx_signature_phase_seo_description( $description ) {
	$page = nvx_signature_phase_current_metadata();
	if ( ! is_array( $page ) || empty( $page['seo_desc'] ) ) {
		return $description;
	}
	return (string) $page['seo_desc'];
}

add_filter( 'wpseo_title', 'nvx_signature_phase_seo_title', 90 );
add_filter( 'wpseo_metadesc', 'nvx_signature_phase_seo_description', 90 );
add_filter( 'wpseo_opengraph_title', 'nvx_signature_phase_seo_title', 90 );
add_filter( 'wpseo_opengraph_desc', 'nvx_signature_phase_seo_description', 90 );
add_filter( 'wpseo_twitter_title', 'nvx_signature_phase_seo_title', 90 );
add_filter( 'wpseo_twitter_description', 'nvx_signature_phase_seo_description', 90 );

/**
 * Papada hub is a decision page, not the Endolift® procedure.
 *
 * Types the WebPage as MedicalWebPage and points relatedLink at the ficha.
 *
 * @param mixed $graph Yoast schema graph.
 * @return mixed
 */
function nvx_papada_hub_schema_graph( $graph ) {
	if ( ! is_array( $graph ) || is_admin() || is_feed() ) {
		return $graph;
	}

	$page = nvx_signature_phase_current_metadata();
	if ( ! is_array( $page ) || 'papada-definicion-mandibular-madrid' !== (string) ( $page['slug'] ?? '' ) ) {
		return $graph;
	}

	$url         = home_url( '/papada-definicion-mandibular-madrid/' );
	$ficha       = home_url( '/endolift-facial-papada-mandibula/' );
	$colegiado   = defined( 'NVX_DIRECTOR_COLEGIADO' ) ? (string) NVX_DIRECTOR_COLEGIADO : '282864786';
	$description = (string) ( $page['lead'] ?? $page['seo_desc'] ?? '' );
	$reviewer    = array(
		'@type'      => 'Physician',
		'name'       => 'Dr. José Javier Rivera Tejeda',
		'url'        => home_url( '/equipo-medico/#physician-rivera-tejeda' ),
		'identifier' => array(
			'@type' => 'PropertyValue',
			'name'  => defined( 'NVX_SD_LABEL_NUM_COLEGIADO' ) ? NVX_SD_LABEL_NUM_COLEGIADO : 'Número de colegiado ICOMEM',
			'value' => $colegiado,
		),
	);

	foreach ( $graph as $index => $node ) {
		if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
			continue;
		}
		$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
		if ( ! in_array( 'WebPage', $types, true ) && ! in_array( 'MedicalWebPage', $types, true ) ) {
			continue;
		}

		if ( function_exists( 'nvx_schema_add_type' ) ) {
			$graph[ $index ]['@type'] = nvx_schema_add_type( $node['@type'], 'MedicalWebPage' );
		} else {
			$types[]                  = 'MedicalWebPage';
			$graph[ $index ]['@type'] = array_values( array_unique( $types ) );
		}

		$graph[ $index ]['name']         = (string) ( $page['title'] ?? '' );
		$graph[ $index ]['description']  = $description;
		$graph[ $index ]['url']          = $url;
		$graph[ $index ]['inLanguage']   = 'es-ES';
		$graph[ $index ]['lastReviewed'] = '2026-08-01';
		$graph[ $index ]['reviewedBy']   = $reviewer;
		$graph[ $index ]['speakable']    = array(
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => array( '#nvx-signature-title', '#nvx-signature-lead' ),
		);
		$graph[ $index ]['about']        = array(
			'@type' => 'MedicalIndication',
			'name'  => __( 'Papada y pérdida de definición mandibular', 'nuvanx-medical' ),
		);
		$graph[ $index ]['relatedLink']  = $ficha;
		$graph[ $index ]['mainEntity']   = array(
			'@type'       => 'DiagnosticProcedure',
			'name'        => __( 'Valoración médica de papada y definición mandibular', 'nuvanx-medical' ),
			'description' => $description,
			'url'         => $url,
		);
		break;
	}

	return $graph;
}
nvx_add_filter_with_priority( 'wpseo_schema_graph', 'nvx_papada_hub_schema_graph' );

/**
 * Resolve the Signature catalog key for the current page (for schema/FAQ look-up).
 *
 * Returns the raw catalog key (e.g. 'profile-definition', 'abdomen-flancos',
 * 'post-maternity') or null when the current request is not a Signature page.
 *
 * @return string|null
 */
function nvx_signature_phase_current_faq_key(): ?string {
	$phase_key = nvx_signature_phase_current_key();
	if ( null !== $phase_key ) {
		return $phase_key;
	}
	$hub_key = nvx_signature_hub_current_key();
	if ( null !== $hub_key ) {
		// Map hub keys to FAQ catalog keys.
		$hub_faq_map = array(
			'post-maternity' => 'post-maternity',
		);
		return $hub_faq_map[ $hub_key ] ?? null;
	}
	return null;
}

/**
 * Render FAQ Signature from catalog items: [ ['q' => '', 'a' => ''], ... ]
 *
 * @param array $items FAQ items with 'q' and 'a' keys.
 * @return string Rendered HTML section, or empty string if no items.
 */
function nvx_signature_faq_section( array $items ): string {
	if ( array() === $items ) {
		return '';
	}
	$html  = '<section class="nvx-brand-section nvx-signature-faq" aria-labelledby="nvx-signature-faq-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<h2 id="nvx-signature-faq-title">' . esc_html__( 'Preguntas frecuentes', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-faq">';
	foreach ( $items as $item ) {
		$q = isset( $item['q'] ) ? (string) $item['q'] : '';
		$a = isset( $item['a'] ) ? (string) $item['a'] : '';
		if ( '' === $q || '' === $a ) {
			continue;
		}
		$html .= '<details class="nvx-faq__item">';
		$html .= '<summary class="nvx-faq__q">' . esc_html( $q ) . '</summary>';
		$html .= '<div class="nvx-faq__a"><p>' . esc_html( $a ) . '</p></div>';
		$html .= '</details>';
	}
	$html .= '</div></div></section>';
	return $html;
}
