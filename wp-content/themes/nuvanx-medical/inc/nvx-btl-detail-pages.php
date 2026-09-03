<?php
/**
 * BTL detail treatment pages: EXION® Face / Body / Fractional RF + EMFUSION + EXILITE IPL.
 *
 * Same editorial pattern as IPL EXILITE / CO₂: Hero → Mecanismo → Indicaciones →
 * Comparativa breve → Procedimiento → FAQ → CTA.
 * Does not replace hub /exion-btl/ or comparative blogs (linked as depth reading).
 *
 * Paths:
 *   /exion-face/
 *   /exion-body/
 *   /exion-fractional/
 *   /emfusion/
 *   /btl-exilite-ipl-madrid/
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/nvx-page-render-helpers.php';

/**
 * Singular page context.
 */
function nvx_btl_detail_is_singular(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	return is_page();
}

/**
 * Registry of BTL detail pages (SEO + clinical copy).
 *
 * @return array<string, array<string, mixed>>
 */
function nvx_btl_detail_registry(): array {
	require_once __DIR__ . '/nvx-catalog-json.php';

	$reg = nvx_catalog_json_resolved(
		'btl-detail-pages.json',
		static function ( string $key ) {
			return nvx_btl_claim( $key );
		},
		array(),
		array(),
		'btl-detail-pages'
	);

	// El loader siempre inyecta '_error'. Hay que sacarlo y asegurar solo arrays.
	if ( ! empty( $reg['_error'] ) ) {
		return array();
	}
	unset( $reg['_error'] );

	return array_filter( $reg, 'is_array' );
}

/**
 * Resolve detail key from current request / content.
 *
 * @return string|null Registry key.
 */
function nvx_btl_detail_current_key( string $content = '' ): ?string {
	if ( ! nvx_btl_detail_is_singular() || is_front_page() || is_home() ) {
		return null;
	}

	// Skip if content has a Protocolos Signature marker
	if ( false !== strpos( $content, 'NUVANX_PROTOCOL_PAGE:' ) ) {
		return null;
	}

	// Never hijack posts (blogs share similar titles).
	if ( is_single() ) {
		return null;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';
	$path = is_string( $path ) ? $path : '';

	foreach ( nvx_btl_detail_registry() as $slug => $cfg ) {
		// Skip if cfg is not a valid array (catalog loading error)
		if ( ! is_array( $cfg ) || empty( $cfg['path'] ) ) {
			continue;
		}

		if ( function_exists( 'nvx_schema_path_matches' ) && nvx_schema_path_matches( $path, $cfg['path'] ) ) {
			return $slug;
		}
		if ( false !== strpos( $content, $cfg['marker'] . '-editorial' ) ) {
			return null; // already rebuilt
		}
		if (
			false !== strpos( $content, 'id="' . $cfg['marker'] . '-h1"' )
			|| false !== strpos( $content, "id='{$cfg['marker']}-h1'" )
		) {
			return $slug;
		}
	}

	$slug     = (string) get_post_field( 'post_name', get_queried_object_id() );
	$registry = nvx_btl_detail_registry();
	if ( isset( $registry[ $slug ] ) && is_array( $registry[ $slug ] ) ) {
		return $slug;
	}

	return null;
}

/**
 * Render a titled zone/list item (title + body).
 *
 * @param array<string,mixed> $item Item with optional title/body.
 */
function nvx_btl_detail_zone_item_markup( array $item ): string {
	$title = trim( (string) ( $item['title'] ?? '' ) );
	$text  = trim( (string) ( $item['body'] ?? '' ) );
	if ( '' === $title && '' === $text ) {
		return '';
	}

	$html = '<li class="nvx-feature-zone">';
	if ( '' !== $title ) {
		$html .= '<h3 class="nvx-feature-zone__title">' . esc_html( $title ) . '</h3>';
	}
	if ( '' !== $text ) {
		$html .= '<p class="nvx-body">' . esc_html( $text ) . '</p>';
	}
	$html .= '</li>';
	return $html;
}

/**
 * Render a list of titled zone items.
 *
 * @param array<int,mixed> $items Zone items.
 * @param string           $tag   List tag (ul|ol).
 */
function nvx_btl_detail_zone_list_markup( array $items, string $tag = 'ul' ): string {
	$html = '<' . $tag . ' class="nvx-feature-zone-list">';
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$html .= nvx_btl_detail_zone_item_markup( $item );
	}
	$html .= '</' . $tag . '>';
	return $html;
}

/**
 * Hero section for a BTL detail page.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_hero_markup( array $c ): string {
	$id    = $c['marker'];
	$hero  = '<section class="nvx-brand-hero" aria-labelledby="' . esc_attr( $id ) . '-h1">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= '<div class="nvx-brand-hero__copy">';
	$hero .= '<p class="nvx-brand-kicker">' . esc_html( $c['kicker'] ) . '</p>';
	$hero .= '<h1 class="nvx-brand-hero__title" id="' . esc_attr( $id ) . '-h1">' . esc_html( $c['h1'] ) . '</h1>';

	// E-E-A-T Medical Authority Byline
	$hero .= '<address class="nvx-medical-byline">';
	$hero .= '<div class="nvx-medical-byline__text">';
	$hero .= '<strong>' . esc_html__( 'Escrito y revisado por Dr. Javier Rivera Tejeda', 'nuvanx-medical' ) . '</strong><br>';
	$hero .= '<span class="nvx-medical-byline__title">' . esc_html__( 'Director médico NUVANX · Fecha de revisión: julio 2026', 'nuvanx-medical' ) . '</span>';
	$hero .= '</div></address>';
	$hero .= '<p class="nvx-brand-hero__lead">' . esc_html( $c['lead'] ) . '</p>';
	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$hero .= nvx_cta_pair_markup();
	}
	$hero .= '<p class="nvx-brand-meta">' . esc_html( $c['meta'] ) . '</p>';
	$hero .= '</div></div></section>';
	return $hero;
}

/**
 * Mechanism section for a BTL detail page.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_mechanism_markup( array $c ): string {
	$id         = $c['marker'];
	$mech_title = trim( (string) ( $c['mechanism']['title'] ?? '' ) );
	if ( '' === $mech_title ) {
		$mech_title = __( 'Mecanismo de acción', 'nuvanx-medical' );
	}

	$html  = nvx_page_brand_section_open_markup( '', $id . '-mech' );
	$html .= nvx_page_brand_section_heading_markup( esc_html__( 'Mecanismo', 'nuvanx-medical' ), $id . '-mech', esc_html( $mech_title ) );
	foreach ( (array) ( $c['mechanism']['body'] ?? array() ) as $p ) {
		$p = is_string( $p ) ? trim( $p ) : '';
		if ( '' === $p ) {
			continue;
		}
		$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $p ) . '</p>';
	}
	if ( ! empty( $c['mechanism']['items'] ) && is_array( $c['mechanism']['items'] ) ) {
		$html .= nvx_btl_detail_zone_list_markup( $c['mechanism']['items'], 'ul' );
	}
	$hub_url = trim( (string) ( $c['hub'] ?? '' ) );
	if ( '' !== $hub_url ) {
		$html .= '<p class="nvx-body"><a class="nvx-brand-inline-link" href="' . esc_url( $hub_url ) . '">' . esc_html__( 'Ver plataforma EXION® BTL (hub)', 'nuvanx-medical' ) . '</a></p>';
	}
	$html .= '</div></section>';
	return $html;
}

/**
 * Indications section for a BTL detail page.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_indications_markup( array $c ): string {
	$id    = $c['marker'];
	$html  = nvx_page_brand_section_open_markup( '', $id . '-ind' );
	$html .= nvx_page_brand_section_heading_markup( esc_html__( 'Indicaciones', 'nuvanx-medical' ), $id . '-ind', esc_html__( 'Cuándo tiene sentido este protocolo', 'nuvanx-medical' ) );
	$html .= nvx_btl_detail_zone_list_markup( (array) ( $c['indications'] ?? array() ), 'ul' );
	$html .= '</div></section>';
	return $html;
}

/**
 * Related-link paragraphs for the compare section.
 *
 * @param array<int,mixed> $related Related link rows.
 */
function nvx_btl_detail_related_links_markup( array $related ): string {
	$html = '';
	foreach ( $related as $rel ) {
		if ( ! is_array( $rel ) ) {
			continue;
		}
		$rel_url   = trim( (string) ( $rel['url'] ?? '' ) );
		$rel_label = trim( (string) ( $rel['label'] ?? '' ) );
		if ( '' === $rel_url || '' === $rel_label ) {
			continue;
		}
		$html .= '<p class="nvx-body"><a class="nvx-brand-inline-link" href="' . esc_url( $rel_url ) . '">' . esc_html( $rel_label ) . '</a></p>';
	}
	return $html;
}

/**
 * Inline compare/combo link row for the criterio diferencial section.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_compare_links_row_markup( array $c ): string {
	$compare_link  = trim( (string) ( $c['compare']['link'] ?? '' ) );
	$compare_label = trim( (string) ( $c['compare']['label'] ?? '' ) );
	$parts         = array();

	if ( '' !== $compare_link && '' !== $compare_label ) {
		$parts[] = '<a class="nvx-brand-inline-link" href="' . esc_url( $compare_link ) . '">' . esc_html( $compare_label ) . '</a>';
	}
	if ( ! empty( $c['combo'] ) ) {
		$parts[] = '<a class="nvx-brand-inline-link" href="' . esc_url( (string) $c['combo'] ) . '">' . esc_html__( 'Protocolos combinados NUVANX', 'nuvanx-medical' ) . '</a>';
	}
	if ( empty( $parts ) ) {
		return '';
	}
	return '<p class="nvx-body">' . implode( ' · ', $parts ) . '</p>';
}

/**
 * Whether the compare section has any content to render.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_has_compare_content( array $c ): bool {
	$compare_title = trim( (string) ( $c['compare']['title'] ?? '' ) );
	$compare_body  = trim( (string) ( $c['compare']['body'] ?? '' ) );
	$compare_link  = trim( (string) ( $c['compare']['link'] ?? '' ) );
	$compare_label = trim( (string) ( $c['compare']['label'] ?? '' ) );

	$has_title_body = '' !== $compare_title || '' !== $compare_body;
	$has_link       = '' !== $compare_link && '' !== $compare_label;
	$has_related    = ! empty( $c['related'] ) && is_array( $c['related'] );
	$has_combo      = ! empty( $c['combo'] );

	return $has_title_body || $has_link || $has_related || $has_combo;
}

/**
 * Compare / criterio diferencial section (optional).
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_compare_markup( array $c ): string {
	if ( ! nvx_btl_detail_has_compare_content( $c ) ) {
		return '';
	}

	$id            = $c['marker'];
	$compare_title = trim( (string) ( $c['compare']['title'] ?? '' ) );
	$compare_body  = trim( (string) ( $c['compare']['body'] ?? '' ) );

	// Prefer aria-labelledby only when the heading (and its id) is rendered.
	if ( '' !== $compare_title ) {
		$html  = '<section class="nvx-brand-section" aria-labelledby="' . esc_attr( $id ) . '-cmp">';
		$html .= '<div class="nvx-shell nvx-brand-section__inner">';
		$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Criterio diferencial', 'nuvanx-medical' ) . '</p>';
		$html .= '<h2 id="' . esc_attr( $id ) . '-cmp" class="nvx-brand-title">' . esc_html( $compare_title ) . '</h2>';
	} else {
		$html  = '<section class="nvx-brand-section" aria-labelledby="' . esc_attr( $id ) . '-cmp">';
		$html .= '<div class="nvx-shell nvx-brand-section__inner">';
		$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Criterio diferencial', 'nuvanx-medical' ) . '</p>';
		$html .= '<h2 id="' . esc_attr( $id ) . '-cmp" class="screen-reader-text">' . esc_html__( 'Criterio diferencial', 'nuvanx-medical' ) . '</h2>';
	}

	if ( '' !== $compare_body ) {
		$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $compare_body ) . '</p>';
	}
	$html .= nvx_btl_detail_compare_links_row_markup( $c );
	if ( ! empty( $c['related'] ) && is_array( $c['related'] ) ) {
		$html .= nvx_btl_detail_related_links_markup( $c['related'] );
	}
	$html .= '</div></section>';
	return $html;
}

/**
 * Single process step (string or titled array).
 *
 * @param mixed $step Process step.
 */
function nvx_btl_detail_process_step_markup( $step ): string {
	if ( is_array( $step ) ) {
		return nvx_btl_detail_zone_item_markup( $step );
	}

	$text = trim( (string) $step );
	if ( '' === $text ) {
		return '';
	}
	return '<li class="nvx-feature-zone"><p class="nvx-body">' . esc_html( $text ) . '</p></li>';
}

/**
 * Process section for a BTL detail page.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_process_markup( array $c ): string {
	$id    = $c['marker'];
	$html  = nvx_page_brand_section_open_markup( '', $id . '-proc' );
	$html .= nvx_page_brand_section_heading_markup( esc_html__( 'Proceso médico', 'nuvanx-medical' ), $id . '-proc', esc_html__( 'Procedimiento, sesiones y cuidados', 'nuvanx-medical' ) );
	$html .= '<ol class="nvx-feature-zone-list">';
	foreach ( (array) ( $c['process'] ?? array() ) as $step ) {
		$html .= nvx_btl_detail_process_step_markup( $step );
	}
	$html .= '</ol></div></section>';
	return $html;
}

/**
 * Explicit candidacy from indications + contraindications.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_candidacy_markup( array $c ): string {
	$yes = array();
	foreach ( (array) ( $c['indications'] ?? array() ) as $item ) {
		if ( is_array( $item ) ) {
			$title = trim( (string) ( $item['title'] ?? '' ) );
			if ( '' !== $title ) {
				$yes[] = $title;
			}
		}
	}
	$no = array();
	foreach ( (array) ( $c['clinical_data']['contraindications'] ?? array() ) as $item ) {
		$item = trim( (string) $item );
		if ( '' !== $item ) {
			$no[] = $item;
		}
	}
	if ( array() === $yes && array() === $no ) {
		return '';
	}

	$id    = (string) ( $c['marker'] ?? 'nvx-btl' );
	$html  = nvx_page_brand_section_open_markup( '', $id . '-cand' );
	$html .= nvx_page_brand_section_heading_markup( esc_html__( 'Candidatura', 'nuvanx-medical' ), $id . '-cand', esc_html__( 'Quién es candidato y quién no', 'nuvanx-medical' ) );
	if ( function_exists( 'nvx_candidacy_markup' ) ) {
		$html .= nvx_candidacy_markup( $yes, $no );
	}
	$html .= '</div></section>';
	return $html;
}

/**
 * Visible reservation block so the treatment page can convert without the footer.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_reservation_markup( array $c ): string {
	$id    = (string) ( $c['marker'] ?? 'nvx-btl' );
	$html  = nvx_page_brand_section_open_markup( '', $id . '-reserva' );
	$html .= nvx_page_brand_section_heading_markup( esc_html__( 'Reserva', 'nuvanx-medical' ), $id . '-reserva', esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'La indicación, el número de sesiones y el presupuesto se confirman en consulta presencial. Puedes reservar valoración en Chamberí o Salamanca–Goya.', 'nuvanx-medical' ) . '</p>';
	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup();
	} else {
		$html .= '<p><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a></p>';
	}
	$html .= '</div></section>';
	return $html;
}

/**
 * FAQ section for a BTL detail page.
 *
 * @param array<string,mixed> $c Registry entry.
 */
function nvx_btl_detail_faq_markup( array $c ): string {
	$id    = $c['marker'];
	$html  = nvx_page_brand_section_open_markup( '', $id . '-faq' );
	$html .= nvx_page_brand_section_heading_markup( esc_html__( 'FAQ', 'nuvanx-medical' ), $id . '-faq', esc_html__( 'Preguntas frecuentes', 'nuvanx-medical' ) );
	$html .= '<div class="nvx-faq nvx-brand-faq-accordion">';
	foreach ( (array) ( $c['faqs'] ?? array() ) as $faq ) {
		if ( ! is_array( $faq ) ) {
			continue;
		}
		$q = trim( (string) ( $faq['q'] ?? '' ) );
		$a = trim( (string) ( $faq['a'] ?? '' ) );
		if ( '' === $q && '' === $a ) {
			continue;
		}
		$html .= '<details class="nvx-brand-faq-item">';
		$html .= '<summary><span>' . esc_html( $q ) . '</span></summary>';
		$html .= '<div class="nvx-brand-faq-content"><p>' . esc_html( $a ) . '</p></div>';
		$html .= '</details>';
	}
	$html .= '</div></div></section>';
	return $html;
}

/**
 * Render clinical parameters section for BTL detail pages if present.
 *
 * @param array<string,mixed> $c Registry entry config.
 */
function nvx_btl_detail_clinical_data_markup( array $c ): string {
	if ( empty( $c['clinical_data'] ) || ! is_array( $c['clinical_data'] ) ) {
		return '';
	}

	$cd     = $c['clinical_data'];
	$marker = (string) ( $c['marker'] ?? 'nvx-btl' );
	$sid    = esc_attr( $marker . '-clinical-data-title' );

	$labels = array(
		'technology'           => __( 'Tecnología:', 'nuvanx-medical' ),
		'energy_depth'         => __( 'Profundidad:', 'nuvanx-medical' ),
		'collagen_stimulation' => __( 'Efecto matriz:', 'nuvanx-medical' ),
		'sessions'             => __( 'Sesiones:', 'nuvanx-medical' ),
		'downtime'             => __( 'Recuperación:', 'nuvanx-medical' ),
		'price_range'          => __( 'Tarifa orientativa:', 'nuvanx-medical' ),
		'duration_result'      => __( 'Evolución:', 'nuvanx-medical' ),
	);

	$items_html = '';
	foreach ( $labels as $key => $label ) {
		if ( ! empty( $cd[ $key ] ) ) {
			$val         = is_array( $cd[ $key ] ) ? implode( ', ', $cd[ $key ] ) : (string) $cd[ $key ];
			$items_html .= '<li><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $val ) . '</li>';
		}
	}

	if ( '' === $items_html ) {
		return '';
	}

	$html  = nvx_page_brand_section_open_markup( 'nvx-btl-clinical-data', $sid );
	$html .= nvx_page_brand_section_heading_markup( esc_html__( 'Parámetros de tratamiento', 'nuvanx-medical' ), $sid, esc_html__( 'Datos clínicos y de consulta', 'nuvanx-medical' ) );
	$html .= '<ul class="nvx-strategy-checklist">' . $items_html . '</ul></div></section>';

	return $html;
}

/**
 * Build full editorial markup for a detail key.
 */
function nvx_btl_detail_page_markup( string $key ): string {

	$reg = nvx_btl_detail_registry();
	if ( empty( $reg[ $key ] ) || ! is_array( $reg[ $key ] ) ) {
		return '';
	}
	$c = $reg[ $key ];
	$c = nvx_btl_detail_hydrate_tariffs( $key, $c );

	$hero  = nvx_btl_detail_hero_markup( $c );
	$body  = '<div class="' . esc_attr( $c['marker'] ) . '-editorial nvx-brand-editorial nvx-btl-detail-editorial">';
	$body .= nvx_btl_detail_mechanism_markup( $c );
	$body .= nvx_btl_detail_indications_markup( $c );
	$body .= nvx_btl_detail_candidacy_markup( $c );
	$body .= nvx_btl_detail_clinical_data_markup( $c );
	$body .= nvx_btl_detail_compare_markup( $c );
	$body .= nvx_btl_detail_process_markup( $c );
	$body .= '<!-- nvx:clinical-note-anchor -->';
	$body .= nvx_btl_detail_reservation_markup( $c );
	$body .= nvx_btl_detail_faq_markup( $c );
	$body .= '</div>';

	return $hero . $body;
}

/**
 * Restructure the_content for BTL detail pages.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_btl_detail_current_key' ) && null !== nvx_btl_detail_current_key( $content ) ) {
			return 'nvx_btl_detail_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_btl_detail_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_btl_detail_page' ) {
		return $content;
	}

	$key = nvx_btl_detail_current_key( $content );
	if ( null === $key ) {
		return $content;
	}

	$registry = nvx_btl_detail_registry();
	if ( ! isset( $registry[ $key ] ) || ! is_array( $registry[ $key ] ) ) {
		return $content;
	}
	$cfg = $registry[ $key ];
	if ( false !== strpos( $content, $cfg['marker'] . '-editorial' ) ) {
		return $content;
	}

	// Same media sources as Endolift® / Endoláser / CO₂: content slot, then featured image.
	$media = nvx_page_extract_brand_hero_media( $content );
	if ( '' === $media && has_post_thumbnail() ) {
		$thumb = get_the_post_thumbnail(
			null,
			'full',
			array(
				'class'   => 'nvx-media nvx-media--hero wp-post-image',
				'alt'     => the_title_attribute( array( 'echo' => false ) ),
				'loading' => 'eager',
			)
		);
		if ( is_string( $thumb ) && '' !== $thumb && ( ! function_exists( 'nvx_public_html_is_vendor_image' ) || ! nvx_public_html_is_vendor_image( $thumb ) ) ) {
			$media = '<figure class="nvx-brand-hero__media">' . $thumb . '</figure>';
		}
	}

	$built = nvx_btl_detail_page_markup( $key );
	// Inject media into hero if present (after copy, inside __inner).
	if ( '' !== $media && false !== strpos( $built, 'nvx-brand-hero__inner' ) ) {
		$built = preg_replace(
			'/(class="nvx-brand-hero__inner">[\s\S]*?<\/div>)(\s*<\/div>\s*<\/section>)/u',
			'$1' . $media . '$2',
			$built,
			1
		) ?? $built;
	}

	$modifier = 'nvx-brand-page--' . sanitize_html_class( $key );
	if ( preg_match( '/(<div class="nvx-brand-page[^"]*"[^>]*>)/iu', $content, $wrap ) ) {
		$opening = $wrap[1];
		if ( false === strpos( $opening, $modifier ) ) {
			$updated = preg_replace( '/\bclass=(["\'])/u', 'class=$1' . $modifier . ' ', $opening, 1 ) ?? $opening;
			$content = preg_replace( '/<div class="nvx-brand-page[^"]*"[^>]*>/iu', $updated, $content, 1 ) ?? $content;
		}
	}

	return '<div class="entry-content nvx-page__content">' . $built . '</div>';
}

/**
 * Replace hardcoded EXILITE prices with tariff-catalog.json labels.
 *
 * @param array<string,mixed> $c Registry entry.
 * @return array<string,mixed>
 */
function nvx_btl_detail_hydrate_tariffs( string $key, array $c ): array {
	if ( 'exilite' !== $key || ! function_exists( 'nvx_tariff_price_label' ) ) {
		return $c;
	}

	$face  = nvx_tariff_price_label( 'btl_exilite', 'cara_completa' );
	$chest = nvx_tariff_price_label( 'btl_exilite', 'escote' );
	$hands = nvx_tariff_price_label( 'btl_exilite', 'manos' );
	if ( '' === $face || '' === $chest || '' === $hands ) {
		return $c;
	}

	if ( ! isset( $c['clinical_data'] ) || ! is_array( $c['clinical_data'] ) ) {
		$c['clinical_data'] = array();
	}
	$c['clinical_data']['price_range'] = sprintf(
		/* translators: 1: face price, 2: décolletage price, 3: hands price */
		__( 'Cara completa desde %1$s · Escote desde %2$s · Manos desde %3$s', 'nuvanx-medical' ),
		$face,
		$chest,
		$hands
	);

	if ( empty( $c['faqs'] ) || ! is_array( $c['faqs'] ) ) {
		return $c;
	}

	foreach ( $c['faqs'] as &$faq ) {
		if ( ! is_array( $faq ) ) {
			continue;
		}
		$q = (string) ( $faq['q'] ?? '' );
		if ( false === stripos( $q, 'cuesta' ) && false === stripos( $q, 'precio' ) ) {
			continue;
		}
		$faq['a'] = sprintf(
			/* translators: 1: face price, 2: décolletage price, 3: hands price */
			__( 'El precio en NUVANX parte de %1$s por sesión de cara completa, %2$s para escote y %3$s para manos. El número de sesiones depende del diagnóstico y se confirma en valoración médica.', 'nuvanx-medical' ),
			$face,
			$chest,
			$hands
		);
	}
	unset( $faq );

	return $c;
}
add_filter( 'the_content', 'nvx_content_restructure_btl_detail_page', NVX_HOOK_PRIO_BTL_DETAIL );

/**
 * Yoast title for BTL detail pages.
 *
 * @param string $title Title.
 * @return string
 */
function nvx_filter_btl_detail_title( $title ) {
	$key = nvx_btl_detail_current_key( '' );
	if ( null === $key ) {
		return $title;
	}
	$registry = nvx_btl_detail_registry();
	if ( ! isset( $registry[ $key ] ) || ! is_array( $registry[ $key ] ) ) {
		return $title;
	}
	return $registry[ $key ]['yoast_title'];
}
add_filter( 'wpseo_title', 'nvx_filter_btl_detail_title', 21 );

/**
 * Yoast metadesc for BTL detail pages.
 *
 * @param string $desc Description.
 * @return string
 */
function nvx_filter_btl_detail_metadesc( $desc ) {
	$key = nvx_btl_detail_current_key( '' );
	if ( null === $key ) {
		return $desc;
	}
	$registry = nvx_btl_detail_registry();
	if ( ! isset( $registry[ $key ] ) || ! is_array( $registry[ $key ] ) ) {
		return $desc;
	}
	return $registry[ $key ]['yoast_desc'];
}
add_filter( 'wpseo_metadesc', 'nvx_filter_btl_detail_metadesc', 21 );
