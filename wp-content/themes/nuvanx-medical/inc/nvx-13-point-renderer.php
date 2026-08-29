<?php
/**
 * Unified 13-Point Data Matrix Renderer.
 *
 * Implements the NUVANX Contour Architecture™ for rendering clinical treatment pages,
 * anatomical hubs, and protocol specs in a highly DRY, centralized way.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the hero section with kicker, title, lead, and CTA.
 *
 * @param array<string, mixed> $data The 13-point schema data array.
 * @return string HTML markup for the hero section.
 */
function nvx_render_matrix_hero( array $data ): string {
	$html = '<section class="nvx-brand-hero"><div class="nvx-brand-hero__inner"><div class="nvx-brand-hero__copy">';
	if ( ! empty( $data['kicker'] ) ) {
		$html .= '<p class="nvx-brand-kicker">' . esc_html( $data['kicker'] ) . '</p>';
	}
	$html .= '<h1 class="nvx-brand-hero__title">' . esc_html( $data['h1'] ?? $data['title'] ?? '' ) . '</h1>';
	if ( ! empty( $data['lead'] ) ) {
		$html .= '<p class="nvx-brand-hero__lead">' . esc_html( $data['lead'] ) . '</p>';
	}
	$html .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a></div>';
	$html .= '</div></div></section>';
	return $html;
}

/**
 * Renders a text section with heading and paragraph.
 *
 * @param string $heading Section heading.
 * @param string $content Section content.
 * @return string HTML markup for the text section.
 */
function nvx_render_matrix_text_section( string $heading, string $content ): string {
	$html  = '<section class="nvx-brand-section"><div class="nvx-brand-section__inner">';
	$html .= '<h2>' . esc_html( $heading ) . '</h2>';
	$html .= '<p>' . esc_html( $content ) . '</p>';
	$html .= '</div></section>';
	return $html;
}

/**
 * Renders a list section (ul or ol) with heading and items.
 *
 * @param string $heading  Section heading.
 * @param array  $items    List items.
 * @param string $list_tag List element type ('ul' or 'ol').
 * @return string HTML markup for the list section.
 */
function nvx_render_matrix_list_section( string $heading, array $items, string $list_tag = 'ul' ): string {
	$html  = '<section class="nvx-brand-section"><div class="nvx-brand-section__inner">';
	$html .= '<h2>' . esc_html( $heading ) . '</h2>';
	$html .= '<' . esc_attr( $list_tag ) . ' class="nvx-brand-list">';
	foreach ( $items as $item ) {
		$html .= '<li>' . esc_html( $item ) . '</li>';
	}
	$html .= '</' . esc_attr( $list_tag ) . '></div></section>';
	return $html;
}

/**
 * Renders the evolution, risks, and combinations section for injectable treatments.
 *
 * @param array<string, mixed> $data The 13-point schema data array.
 * @return string HTML markup for the evolution section, or empty string if no evolution data.
 */
function nvx_render_matrix_evolution_section( array $data ): string {
	if ( empty( $data['evolution'] ) ) {
		return '';
	}

	$html  = '<section class="nvx-brand-section"><div class="nvx-brand-section__inner">';
	$html .= '<h2>' . esc_html__( 'Evolución y seguridad', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p>' . esc_html( $data['evolution'] ) . '</p>';

	if ( ! empty( $data['risks'] ) && is_array( $data['risks'] ) ) {
		$html .= '<h3>' . esc_html__( 'Riesgos que deben explicarse', 'nuvanx-medical' ) . '</h3>';
		$html .= '<ul class="nvx-brand-list" role="list">';
		foreach ( $data['risks'] as $risk ) {
			$html .= '<li>' . esc_html( $risk ) . '</li>';
		}
		$html .= '</ul>';
	}

	if ( ! empty( $data['combinations'] ) && is_array( $data['combinations'] ) ) {
		$html .= '<h3>' . esc_html__( 'Combinaciones posibles', 'nuvanx-medical' ) . '</h3>';
		$html .= '<ul class="nvx-brand-list" role="list">';
		foreach ( $data['combinations'] as $comb ) {
			$html .= '<li>' . esc_html( $comb ) . '</li>';
		}
		$html .= '</ul>';
	}

	$html .= '</div></section>';
	return $html;
}

/**
 * Renders the FAQs section with accordion markup.
 *
 * @param array $faqs FAQ items, each with 'q' and 'a' keys.
 * @return string HTML markup for the FAQs section, or empty string if no FAQs.
 */
function nvx_render_matrix_faqs_section( array $faqs ): string {
	if ( empty( $faqs ) || ! is_array( $faqs ) ) {
		return '';
	}

	$html  = '<section class="nvx-brand-section"><div class="nvx-brand-section__inner">';
	$html .= '<h2>' . esc_html__( 'Preguntas frecuentes', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-faq-accordion">';
	foreach ( $faqs as $faq ) {
		$html .= '<details class="nvx-faq-item">';
		$html .= '<summary class="nvx-faq-question">' . esc_html( $faq['q'] ) . '</summary>';
		$html .= '<div class="nvx-faq-answer"><p>' . esc_html( $faq['a'] ) . '</p></div>';
		$html .= '</details>';
	}
	$html .= '</div></div></section>';
	return $html;
}

/**
 * Render diagnosis and mechanism sections.
 */
function nvx_render_matrix_sections_primary( array $data ): string {
	$html = '';

	if ( ! empty( $data['diagnosis'] ) ) {
		$heading = ! empty( $data['diagnosis_heading'] ) ? $data['diagnosis_heading'] : __( 'El valor del diagnóstico médico', 'nuvanx-medical' );
		$html   .= nvx_render_matrix_text_section( $heading, $data['diagnosis'] );
	}

	if ( ! empty( $data['mechanism'] ) ) {
		$heading = ! empty( $data['mechanism_heading'] ) ? $data['mechanism_heading'] : __( 'Mecanismo de acción', 'nuvanx-medical' );
		$html   .= is_array( $data['mechanism'] )
			? nvx_render_matrix_list_section( $heading, $data['mechanism'] )
			: nvx_render_matrix_text_section( $heading, $data['mechanism'] );
	}

	return $html;
}

/**
 * Render indications, precautions, and process sections.
 */
function nvx_render_matrix_sections_secondary( array $data ): string {
	$html = '';

	if ( ! empty( $data['indications'] ) && is_array( $data['indications'] ) ) {
		$heading = ! empty( $data['indications_heading'] ) ? $data['indications_heading'] : __( 'Indicaciones: Qué tratamos', 'nuvanx-medical' );
		$html   .= nvx_render_matrix_list_section( $heading, $data['indications'], 'ul' );
	}

	if ( ! empty( $data['precautions'] ) && is_array( $data['precautions'] ) ) {
		$heading = ! empty( $data['precautions_heading'] ) ? $data['precautions_heading'] : __( 'Precauciones: Cuándo no tratar', 'nuvanx-medical' );
		$html   .= nvx_render_matrix_list_section( $heading, $data['precautions'], 'ul' );
	}

	if ( ! empty( $data['process'] ) && is_array( $data['process'] ) ) {
		$heading = ! empty( $data['process_heading'] ) ? $data['process_heading'] : __( 'Proceso en clínica', 'nuvanx-medical' );
		$html   .= nvx_render_matrix_list_section( $heading, $data['process'], 'ol' );
	}

	return $html;
}

/**
 * Render matrix core sections (diagnosis, mechanism, indications, precautions, process).
 */
function nvx_render_matrix_sections( array $data ): string {
	return nvx_render_matrix_sections_primary( $data ) . nvx_render_matrix_sections_secondary( $data );
}

/**
 * Universal renderer for the 13-point data matrix pattern.
 *
 * Replaces duplicate rendering logic across Phase 1, Phase 2, and Phase 3 files.
 *
 * @param array<string, mixed> $data The 13-point schema data array.
 * @return string Extracted and validated HTML block.
 */
function nvx_render_13_point_matrix( array $data ): string {
	$html  = '<article class="nvx-brand-page nvx-treatment-page nvx-protocol-page">';
	$html .= nvx_render_matrix_hero( $data );
	$html .= nvx_render_matrix_sections( $data );
	$html .= nvx_render_matrix_evolution_section( $data );
	$html .= nvx_render_matrix_faqs_section( $data['faqs'] ?? array() );
	$html .= '</article>';
	return $html;
}

/**
 * Matches a request slug against a catalog array.
 */
function nvx_match_catalog_page( string $slug, array $catalog ): ?array {
	foreach ( $catalog as $page ) {
		$slug_value = (string) ( $page['slug'] ?? '' );
		if ( '' === $slug_value ) {
			continue;
		}
		$catalog_slug_parts = explode( '/', $slug_value );
		$catalog_final_slug = end( $catalog_slug_parts );
		if ( $catalog_final_slug !== $slug ) {
			continue;
		}
		$review_status = (string) ( $page['review_status'] ?? 'approved_for_publication' );
		if ( 'approved_for_publication' === $review_status ) {
			return (array) $page;
		}
	}
	return null;
}


