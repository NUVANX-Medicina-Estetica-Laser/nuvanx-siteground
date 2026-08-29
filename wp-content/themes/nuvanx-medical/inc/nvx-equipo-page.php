<?php
/**
 * Equipo médico — E-E-A-T: Rivera Tejeda + Rivera Deras + Quiñónez Bareiro + rest of staff.
 *
 * Wire-frame: Hero → Director → Dra. Ivon → Dr. Fabio → Resto CMS → CTA.
 * Schema Physicians via Yoast graph only (no standalone ld+json). No AggregateRating hardcode.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NVX_EQUIPO_MEDIA_PATTERN = '/<figure\b[\s\S]*?<\/figure>|<img\b[^>]*>/iu';
const NVX_EQUIPO_ICOMEM_PREFIX = 'ICOMEM ';

/**
 * Singular context.
 */
function nvx_equipo_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	return is_page();
}

/**
 * Detect equipo médico page only (path/markers — not every Rivera mention sitewide).
 */
function nvx_content_is_equipo_page( string $content ): bool {
	if ( false !== strpos( $content, 'nvx-equipo-editorial' ) ) {
		return false;
	}

	if ( ! nvx_equipo_is_singular_context() ) {
		return false;
	}

	if ( is_front_page() || is_home() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( is_string( $path ) && function_exists( 'nvx_schema_path_matches' ) && nvx_schema_path_matches( $path, '/equipo-medico/' ) ) {
		return true;
	}

	return (bool) preg_match(
		'/aria-label=["\']Equipo médico NUVANX["\']|id=["\']nvx-equipo-h1["\']|class=["\'][^"\']*nvx-equipo-hero/iu',
		$content
	);
}

/**
 * Builds the medical team page hero copy and calls to action.
 *
 * @return string The rendered hero markup.
 */
function nvx_equipo_hero_copy_markup(): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'equipo-medico-page.json' )['hero'] ?? array();

	$colegiado_dir   = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$colegiado_ivon  = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'ivon' ) : '';
	$colegiado_fabio = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'fabio' ) : '';

	$html  = '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( $data['kicker'] ?? '' ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-equipo-h1">' . esc_html( $data['h1'] ?? '' ) . '</h1>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html( $data['lead'] ?? '' ) . '</p>';
	$html .= '<p class="nvx-brand-hero__description">' . esc_html(
		sprintf(
			/* translators: 1: director license, 2: Dra. Ivon license, 3: Dr. Fabio license */
			$data['description'] ?? '',
			$colegiado_dir,
			$colegiado_ivon,
			$colegiado_fabio
		)
	) . '</p>';

	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup( 'nvx-brand-actions' );
	} else {
		$html .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a></div>';
	}

	$html .= '<p class="nvx-brand-meta">' . esc_html( $data['meta'] ?? '' ) . '</p>';
	$html .= '</div>';

	return $html;
}

/**
 * Whether media HTML is a logo / non-portrait asset (never use as staff/hero photo).
 */
function nvx_equipo_media_is_logo( string $html ): bool {
	return (bool) preg_match(
		'/logo-nuvanx|nuvanx-web\.webp|\/logo[-_]|nvx-logo|site-logo|custom-logo/iu',
		$html
	);
}

/** Promote data-src to src attribute. */
function nvx_equipo_promote_attr_src( string $attrs ): string {
	if ( ( preg_match( '/\ssrc=["\']data:image\//i', $attrs ) || preg_match( '/\ssrc=["\']["\']/i', $attrs ) )
		&& preg_match( '/\sdata-(?:src|lazy-src|original)=["\']([^"\']+)["\']/i', $attrs, $ds )
	) {
		$real = esc_url( $ds[1] );
		if ( '' !== $real ) {
			return preg_match( '/\ssrc=/i', $attrs )
				? ( preg_replace( '/\ssrc=["\'][^"\']*["\']/i', ' src="' . $real . '"', $attrs, 1 ) ?? $attrs )
				: $attrs . ' src="' . $real . '"';
		}
	}
	return $attrs;
}

/** Promote data-srcset to srcset attribute. */
function nvx_equipo_promote_attr_srcset( string $attrs ): string {
	if ( preg_match( '/\sdata-(?:srcset|lazy-srcset)=["\']([^"\']+)["\']/i', $attrs, $dset ) ) {
		$real_srcset = esc_attr( $dset[1] );
		if ( '' !== $real_srcset ) {
			return preg_match( '/\ssrcset=/i', $attrs )
				? ( preg_replace( '/\ssrcset=["\'][^"\']*["\']/i', ' srcset="' . $real_srcset . '"', $attrs, 1 ) ?? $attrs )
				: $attrs . ' srcset="' . $real_srcset . '"';
		}
	}
	return $attrs;
}

/** Promote data-sizes to sizes attribute. */
function nvx_equipo_promote_attr_sizes( string $attrs ): string {
	if ( preg_match( '/\sdata-(?:sizes|lazy-sizes)=["\']([^"\']+)["\']/i', $attrs, $dsizes ) ) {
		$real_sizes = esc_attr( $dsizes[1] );
		if ( '' !== $real_sizes ) {
			return preg_match( '/\ssizes=/i', $attrs )
				? ( preg_replace( '/\ssizes=["\'][^"\']*["\']/i', ' sizes="' . $real_sizes . '"', $attrs, 1 ) ?? $attrs )
				: $attrs . ' sizes="' . $real_sizes . '"';
		}
	}
	return $attrs;
}

/** Promote lazy attributes (src, srcset, sizes) to their native counterparts. */
function nvx_equipo_promote_lazy_src( string $attrs ): string {
	$attrs = nvx_equipo_promote_attr_src( $attrs );
	$attrs = nvx_equipo_promote_attr_srcset( $attrs );
	$attrs = nvx_equipo_promote_attr_sizes( $attrs );
	return $attrs;
}

/**
 * Cleans CMS portrait markup and returns a single lazy-loaded doctor portrait image.
 *
 * @param string $media Figure or image HTML from the CMS.
 * @param string $label Optional physician name used for a fallback alt attribute.
 * @return string Cleaned image markup, or an empty string when no usable portrait is found.
 */
function nvx_equipo_clean_portrait_img( string $media, string $label = '' ): string {
	if ( '' === trim( $media ) || nvx_equipo_media_is_logo( $media ) ) {
		return '';
	}

	// Prefer real <img> over noscript twin / decorative placeholders.
	if ( ! preg_match( '/<img\b([^>]*)>/iu', $media, $m ) ) {
		return '';
	}

	$attrs = nvx_equipo_promote_lazy_src( $m[1] );

	// Drop inline size/style that fights portrait crop; strip body role.
	$attrs = preg_replace( '/\s+style=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
	$attrs = preg_replace( '/\s+(?:width|height)=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
	$attrs = preg_replace( '/\s*nvx-media--body\s*/i', ' ', $attrs ) ?? $attrs;
	// Re-emit loading/decoding once (CMS + cleaners often duplicate).
	$attrs = preg_replace( '/\s+loading=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;
	$attrs = preg_replace( '/\s+decoding=["\'][^"\']*["\']/i', '', $attrs ) ?? $attrs;

	if ( function_exists( 'nvx_html_attrs_add_class' ) ) {
		$attrs = nvx_html_attrs_add_class( $attrs, 'nvx-media' );
		$attrs = nvx_html_attrs_add_class( $attrs, 'nvx-media--doctor' );
	} elseif ( ! preg_match( '/\bclass=/i', $attrs ) ) {
		$attrs .= ' class="nvx-media nvx-media--doctor"';
	}

	if ( '' !== $label && ! preg_match( '/\balt=/i', $attrs ) ) {
		$attrs .= ' alt="' . esc_attr( 'Retrato de ' . $label ) . '"';
	}

	return '<img' . $attrs . ' loading="lazy" decoding="async">';
}

/**
 * Whether a CMS card is a real clinician (photo + person name), not sedes/reseñas/listas.
 */
function nvx_equipo_is_person_staff_card( string $card ): bool {
	if ( ! preg_match( '/<img\b/i', $card ) ) {
		return false;
	}
	if ( nvx_equipo_media_is_logo( $card ) ) {
		return false;
	}

	// Prefer cards with a named title (person).
	if ( preg_match( '/(?:nvx-brand-card__title|nvx-brand-subtitle)[^>]*>([\s\S]*?)<\//iu', $card, $tm ) ) {
		$title = trim( wp_strip_all_tags( $tm[1] ) );
		if ( '' === $title ) {
			return false;
		}
		// Titles that are places, proof widgets, or section headers — not people.
		if ( preg_match(
			'/^(Chamber[ií]|Goya\b|Especialidades|NUVANX Medicina|NUVANX en Doctoralia|Reseñas)/iu',
			$title
		) ) {
			return false;
		}
		return true;
	}

	// No title: drop review/list chrome; keep only cards with portrait media.
	if ( preg_match( '/NUVANX en Doctoralia|Reseñas públicas|Especialidades y tecnolog/iu', $card ) ) {
		return false;
	}

	return (bool) preg_match( '/nvx-brand-card__media/i', $card );
}

/**
 * Wraps a cleaned physician portrait in a figure element.
 *
 * @param string $media The source portrait markup.
 * @param string $label The physician name used for fallback image text.
 * @return string Portrait figure markup, or an empty string when no valid portrait is available.
 */
function nvx_equipo_portrait_figure_markup( string $media, string $label ): string {
	$img = nvx_equipo_clean_portrait_img( $media, $label );
	if ( '' === $img ) {
		return '';
	}

	return '<figure class="nvx-equipo-portrait">' . $img . '</figure>';
}

/**
 * Whether a card/block is the director Rivera Tejeda.
 */
function nvx_equipo_block_is_rivera_tejeda( string $html ): bool {
	return (bool) preg_match( '/Rivera\s+Tejeda|Jos[eé]\s+Javier\s+Rivera/iu', $html );
}

/**
 * Whether a card/block is Dra. Ivon Yamileth Rivera Deras.
 */
function nvx_equipo_block_is_ivon( string $html ): bool {
	return (bool) preg_match( '/Ivon|Yamileth|Rivera\s+Deras/iu', $html );
}

/**
 * Whether a card/block is Dr. Fabio Augusto Quiñónez Bareiro.
 */
function nvx_equipo_block_is_fabio( string $html ): bool {
	return (bool) preg_match( '/Fabio|Qui[nñ][oó]nez|Bareiro/iu', $html );
}

/**
 * Whether a card/block is Dra. Cristina Márquez González.
 */
function nvx_equipo_block_is_cristina( string $html ): bool {
	return (bool) preg_match( '/Cristina\s+M[áa]rquez(?:\s+Gonz[áa]lez)?/iu', $html );
}

/**
 * Capture the first media fragment from a staff card when missing.
 */
function nvx_equipo_capture_media_if_empty( string $card, string &$media ): void {
	if ( '' !== $media ) {
		return;
	}
	if ( preg_match( NVX_EQUIPO_MEDIA_PATTERN, $card, $im ) ) {
		$media = $im[0];
	}
}

function nvx_equipo_categorize_staff_card( string $card, string &$rivera_media, string &$ivon_media, string &$fabio_media, string &$cristina_media, array &$other_cards ): void {
	if ( nvx_equipo_block_is_rivera_tejeda( $card ) ) {
		nvx_equipo_capture_media_if_empty( $card, $rivera_media );
		return;
	}
	if ( nvx_equipo_block_is_ivon( $card ) ) {
		nvx_equipo_capture_media_if_empty( $card, $ivon_media );
		return;
	}
	if ( nvx_equipo_block_is_fabio( $card ) ) {
		nvx_equipo_capture_media_if_empty( $card, $fabio_media );
		return;
	}
	if ( nvx_equipo_block_is_cristina( $card ) ) {
		nvx_equipo_capture_media_if_empty( $card, $cristina_media );
		return;
	}
	if ( nvx_equipo_is_person_staff_card( $card ) ) {
		$other_cards[] = $card;
	}
}

/**
 * Generate an identity key from the card's heading, falling back to full HTML hash.
 *
 * @param string $card HTML card.
 * @return string Identity hash.
 */
function nvx_equipo_card_identity_key( string $card ): string {
	if ( preg_match( '/<h[2-6][^>]*>(.*?)<\/h[2-6]>/iu', $card, $m ) ) {
		return hash( 'sha256', strtolower( trim( wp_strip_all_tags( $m[1] ) ) ) );
	}
	return hash( 'sha256', trim( $card ) );
}

/**
 * Extract staff cards from CMS: director, Dra. Ivon, Dr. Fabio, Dra. Cristina, rest of team.
 *
 * @param string $content CMS content.
 * @return array{rivera_media:string,ivon_media:string,fabio_media:string,cristina_media:string,other_cards:string[]}
 */
function nvx_equipo_extract_staff_cards( string $content ): array {
	$other_cards    = array();
	$rivera_media   = '';
	$ivon_media     = '';
	$fabio_media    = '';
	$cristina_media = '';

	$patterns = array(
		'/<article\b[^>]*\bclass=["\'][^"\']*\bnvx-brand-card\b[^"\']*["\'][^>]*>[\s\S]*?<\/article>/iu',
		'/<div\b[^>]*\bclass=["\'][^"\']*\bnvx-brand-card\b[^"\']*["\'][^>]*>[\s\S]*?<\/div>\s*(?=<div\b[^>]*\bnvx-brand-card\b|<section\b|<\/section>|$)/iu',
	);

	$found = array();
	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $content, $m ) && ! empty( $m[0] ) ) {
			foreach ( $m[0] as $card ) {
				$identity_key = nvx_equipo_card_identity_key( $card );
				if ( ! isset( $found[ $identity_key ] ) ) {
					$found[ $identity_key ] = true;
					nvx_equipo_categorize_staff_card( $card, $rivera_media, $ivon_media, $fabio_media, $cristina_media, $other_cards );
				}
			}
		}
	}

	return array(
		'rivera_media'   => $rivera_media,
		'ivon_media'     => $ivon_media,
		'fabio_media'    => $fabio_media,
		'cristina_media' => $cristina_media,
		'other_cards'    => $other_cards,
	);
}

/**
 * Normalize a CMS staff card: team class + portrait media crop.
 */
function nvx_equipo_normalize_staff_card( string $card ): string {
	if ( preg_match( '/\bclass=(["\'])/u', $card ) && false === strpos( $card, 'nvx-brand-card--team' ) ) {
		$card = preg_replace( '/\bclass=(["\'])/u', 'class=$1nvx-brand-card--team ', $card, 1 ) ?? $card;
	}

	// Portrait frame: single clean img, no noscript/br noise inside figure.
	$card = preg_replace_callback(
		'/(<figure\b[^>]*\bclass=["\'][^"\']*\bnvx-brand-card__media\b)([^"\']*)(["\'][^>]*>)([\s\S]*?)(<\/figure>)/iu',
		static function ( array $m ): string {
			$open = $m[1] . $m[2];
			if ( false === strpos( $open . $m[3], 'nvx-brand-card__media--portrait' ) ) {
				$open .= ' nvx-brand-card__media--portrait';
			}
			$open = preg_replace( '/\s*nvx-content-figure\s*/i', ' ', $open ) ?? $open;
			$img  = nvx_equipo_clean_portrait_img( $m[4] );
			if ( '' === $img ) {
				return $open . $m[3] . $m[5];
			}
			return $open . $m[3] . $img . $m[5];
		},
		$card
	) ?? $card;

	// Bare img without figure.
	if ( false === strpos( $card, 'nvx-brand-card__media' ) && preg_match( '/<img\b[^>]*>/iu', $card, $im ) ) {
		$img = nvx_equipo_clean_portrait_img( $im[0] );
		if ( '' !== $img ) {
			$card = preg_replace( '/<noscript\b[\s\S]*?<\/noscript>/iu', '', $card ) ?? $card;
			$card = preg_replace(
				'/<img\b[^>]*>/iu',
				'<figure class="nvx-brand-card__media nvx-brand-card__media--portrait">' . $img . '</figure>',
				$card,
				1
			) ?? $card;
		}
	}

	$card = preg_replace( '/<br\s*\/?>/iu', '', $card ) ?? $card;

	return is_string( $card ) ? $card : '';
}

/**
 * Markup for remaining clinical team (CMS cards, not the two authority profiles).
 *
 * @param string[] $other_cards HTML cards.
 */
function nvx_equipo_other_staff_section_markup( array $other_cards ): string {
	if ( empty( $other_cards ) ) {
		return '';
	}

	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'equipo-medico-page.json' )['equipo_staff'] ?? array();

	$html  = '<section class="nvx-brand-section nvx-equipo-staff" aria-labelledby="nvx-equipo-staff-title">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( $data['kicker'] ?? '' ) . '</p>';
	$html .= '<h2 id="nvx-equipo-staff-title" class="nvx-heading">' . esc_html( $data['title'] ?? '' ) . '</h2>';
	$html .= '<p class="nvx-body">' . esc_html( $data['body'] ?? '' ) . '</p>';
	$html .= '<div class="nvx-equipo-staff-grid">';
	foreach ( $other_cards as $card ) {
		$card = nvx_equipo_normalize_staff_card( $card );
		if ( '' !== $card ) {
			$html .= $card;
		}
	}
	$html .= '</div></div></section>';

	return $html;
}

/**
 * Renders an authority profile section with optional introductory text and item cards.
 *
 * @param array $section Section configuration containing identifiers, text, classes, and items.
 * @return string The rendered HTML section.
 */
function nvx_equipo_render_items_section( array $section ): string {
	$section_id    = $section['id'] ?? '';
	$section_class = trim( 'nvx-brand-section ' . ( $section['class'] ?? '' ) );
	$kicker        = $section['kicker'] ?? '';
	$heading       = $section['heading'] ?? '';
	$lead          = $section['lead'] ?? '';
	$items         = $section['items'] ?? array();

	$html  = '<section class="' . esc_attr( $section_class ) . '" aria-labelledby="' . esc_attr( $section_id ) . '">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	if ( '' !== $kicker ) {
		$html .= '<p class="nvx-brand-kicker">' . esc_html( $kicker ) . '</p>';
	}
	if ( '' !== $heading ) {
		$html .= '<h2 id="' . esc_attr( $section_id ) . '" class="nvx-heading">' . esc_html( $heading ) . '</h2>';
	}
	if ( '' !== $lead ) {
		$html .= '<p class="nvx-body">' . esc_html( $lead ) . '</p>';
	}

	if ( ! empty( $items ) ) {
		$html .= '<ul class="nvx-brand-grid nvx-brand-grid--3" role="list">';
		foreach ( $items as $item ) {
			$html .= '<li class="nvx-brand-card">';
			if ( ! empty( $item['title'] ) ) {
				$html .= '<h3 class="nvx-brand-subtitle">' . esc_html( $item['title'] ) . '</h3>';
			}
			if ( ! empty( $item['body'] ) ) {
				$html .= '<p class="nvx-body">' . esc_html( $item['body'] ) . '</p>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
	}

	$html .= '</div></section>';
	return $html;
}

/**
 * Builds a grid of cards from title and body content rows.
 *
 * @param array<int,array<string,mixed>> $items Card rows containing optional title and body values.
 * @return string The rendered card grid, or an empty string when no items are provided.
 */
function nvx_equipo_brand_card_grid_markup( array $items ): string {
	if ( empty( $items ) ) {
		return '';
	}

	$html = '<ul class="nvx-brand-grid nvx-brand-grid--3" role="list">';
	foreach ( $items as $item ) {
		$html .= '<li class="nvx-brand-card">';
		if ( ! empty( $item['title'] ) ) {
			$html .= '<h3 class="nvx-brand-subtitle">' . esc_html( $item['title'] ) . '</h3>';
		}
		if ( ! empty( $item['body'] ) ) {
			$html .= '<p class="nvx-body">' . esc_html( $item['body'] ) . '</p>';
		}
		$html .= '</li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Builds an identity facts panel from labeled professional facts.
 *
 * @param array<int,array{label:string,val:string}> $facts Fact rows to display.
 * @return string The rendered facts panel, or an empty string when no facts are provided.
 */
function nvx_equipo_identity_facts_panel_markup( array $facts ): string {
	if ( empty( $facts ) ) {
		return '';
	}

	$html  = '<aside class="nvx-fact-panel" aria-label="' . esc_attr__( 'Identidad profesional', 'nuvanx-medical' ) . '">';
	$html .= '<p class="nvx-fact-panel__label" aria-hidden="true">' . esc_html__( 'Identidad', 'nuvanx-medical' ) . '</p>';
	$html .= '<ul class="nvx-fact-panel__list" role="list">';
	foreach ( $facts as $fact ) {
		$html .= '<li><strong>' . esc_html( $fact['label'] ) . '</strong> — ' . esc_html( $fact['val'] ) . '</li>';
	}
	$html .= '</ul></aside>';
	return $html;
}

/**
 * Renders a split formation / docencia section with identity fact panel.
 */
function nvx_equipo_render_split_identity_section( array $config ): string {
	$section_id = $config['id'] ?? '';
	$kicker     = $config['kicker'] ?? '';
	$heading    = $config['heading'] ?? '';
	$paragraphs = $config['paragraphs'] ?? array();
	$items      = $config['items'] ?? array();
	$facts      = $config['facts'] ?? array();

	$aria_attr = ( '' !== $heading )
		? 'aria-labelledby="' . esc_attr( $section_id ) . '"'
		: 'aria-label="' . esc_attr__( 'Identidad profesional', 'nuvanx-medical' ) . '"';

	$html  = '<section class="nvx-brand-section" ' . $aria_attr . '>';
	$html .= '<div class="nvx-shell nvx-brand-section__inner nvx-equipo-diagnosis__grid">';
	$html .= '<div class="nvx-equipo-diagnosis__copy">';
	if ( '' !== $kicker ) {
		$html .= '<p class="nvx-brand-kicker">' . esc_html( $kicker ) . '</p>';
	}
	if ( '' !== $heading ) {
		$html .= '<h2 id="' . esc_attr( $section_id ) . '" class="nvx-heading">' . esc_html( $heading ) . '</h2>';
	}

	foreach ( $paragraphs as $paragraph ) {
		$html .= '<p class="nvx-body">' . esc_html( $paragraph ) . '</p>';
	}

	$html .= nvx_equipo_brand_card_grid_markup( $items );
	$html .= '</div>';
	$html .= nvx_equipo_identity_facts_panel_markup( $facts );
	$html .= '</div></section>';
	return $html;
}

/**
 * Profile layout (portrait + intro copy) for a physician authority block.
 *
 * @param array<string,mixed> $config Physician configuration data.
 */
function nvx_equipo_physician_profile_section_markup( array $config ): string {
	$h2_id     = $config['h2_id'] ?? 'nvx-equipo-profile-' . sanitize_title( $config['name'] ?? 'doc' );
	$aria_attr = ! empty( $config['h2'] ) ? 'aria-labelledby="' . esc_attr( $h2_id ) . '"' : 'aria-label="' . esc_attr( $config['name'] ?? 'Perfil médico' ) . '"';

	$html     = '<section class="nvx-brand-section nvx-equipo-profile" ' . $aria_attr . '>';
	$html    .= '<div class="nvx-shell nvx-brand-section__inner nvx-equipo-profile-layout">';
	$portrait = nvx_equipo_portrait_figure_markup( $config['media'] ?? '', $config['name'] ?? '' );
	if ( '' !== $portrait ) {
		$html .= $portrait;
	}
	$html .= '<div class="nvx-equipo-profile-layout__copy">';
	if ( ! empty( $config['kicker'] ) ) {
		$html .= '<p class="nvx-brand-kicker">' . esc_html( $config['kicker'] ) . '</p>';
	}
	if ( ! empty( $config['h2'] ) ) {
		$html .= '<h2 id="' . esc_attr( $h2_id ) . '" class="nvx-heading">' . esc_html( $config['h2'] ) . '</h2>';
	}
	if ( ! empty( $config['bio_paragraphs'] ) ) {
		foreach ( $config['bio_paragraphs'] as $para ) {
			$html .= '<p class="nvx-body">' . $para . '</p>';
		}
	}
	$html .= '</div></div></section>';
	return $html;
}

/**
 * Middle sections (subspecialties, clinical activities, research, docencia split).
 *
 * @param array<int,array<string,mixed>> $sections Section configs.
 */
function nvx_equipo_physician_sections_markup( array $sections ): string {
	$html = '';
	foreach ( $sections as $sec ) {
		if ( ! empty( $sec['type'] ) && 'split_identity' === $sec['type'] ) {
			$html .= nvx_equipo_render_split_identity_section( $sec );
			continue;
		}
		$html .= nvx_equipo_render_items_section( $sec );
	}
	return $html;
}

/**
 * Renders a physician's clinical-vision quote section.
 *
 * @param array{text:string,author:string} $quote Quote text and physician attribution.
 * @return string The rendered quote section markup.
 */
function nvx_equipo_physician_quote_section_markup( array $quote ): string {
	$html  = '<section class="nvx-brand-section nvx-equipo-quote" aria-label="' . esc_attr( sprintf( __( 'Visión clínica de %s', 'nuvanx-medical' ), $quote['author'] ) ) . '">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= '<blockquote class="nvx-equipo-blockquote">';
	$html .= '<p>' . esc_html( $quote['text'] ) . '</p>';
	$html .= '<footer>— ' . esc_html( $quote['author'] ) . '</footer>';
	$html .= '</blockquote></div></section>';
	return $html;
}

/**
 * Builds a physician's authority profile markup.
 *
 * @param array $config Physician configuration data.
 * @return string The rendered authority profile HTML.
 */
function nvx_equipo_physician_authority_markup( array $config ): string {
	$wrapper_class = $config['wrapper_class'] ?? 'nvx-equipo-director';
	$wrapper_id    = $config['wrapper_id'] ?? '';

	$html = '<div class="' . esc_attr( $wrapper_class ) . '"';
	if ( '' !== $wrapper_id ) {
		$html .= ' id="' . esc_attr( $wrapper_id ) . '"';
	}
	$html .= '>';

	$html .= nvx_equipo_physician_profile_section_markup( $config );

	if ( ! empty( $config['sections'] ) ) {
		$html .= nvx_equipo_physician_sections_markup( $config['sections'] );
	}

	if ( ! empty( $config['quote'] ) ) {
		$html .= nvx_equipo_physician_quote_section_markup( $config['quote'] );
	}

	$html .= '</div>';
	return $html;
}

/**
 * Builds the editorial authority profile for Dr. José Javier Rivera Tejeda.
 *
 * @param string $rivera_media Optional portrait media extracted from CMS staff card.
 * @return string The rendered HTML markup for the profile.
 */
function nvx_equipo_director_authority_markup( string $rivera_media = '' ): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'equipo-medico-page.json' )['rivera'] ?? array();
	$authorized_consultation = function_exists( 'nvx_medical_staff_profile_media_attachment_id' ) ? nvx_medical_staff_profile_media_attachment_id( 'director' ) : 0;
	$consultation_file       = get_attached_file( $authorized_consultation );
	if ( is_string( $consultation_file ) && is_readable( $consultation_file ) ) {
		$consultation = wp_get_attachment_image(
			$authorized_consultation,
			'full',
			false,
			array(
				'alt'      => __( 'Javier Rivera — valoración médica NUVANX', 'nuvanx-medical' ),
				'loading'  => 'eager',
				'decoding' => 'async',
				'sizes'    => '(min-width: 900px) 28vw, 100vw',
			)
		);
		if ( '' !== $consultation ) {
			$rivera_media = $consultation;
		}
	}

	$colegiado  = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$doctoralia = function_exists( 'nvx_medical_staff_doctoralia_url' ) ? nvx_medical_staff_doctoralia_url( 'director' ) : '';

	return nvx_equipo_physician_authority_markup(
		array(
			'wrapper_class'  => 'nvx-equipo-director',
			'wrapper_id'     => 'physician-rivera-tejeda',
			'media'          => $rivera_media,
			'name'           => $data['name'] ?? '',
			'kicker'         => $data['kicker'] ?? '',
			'h2'             => $data['h2'] ?? '',
			'bio_paragraphs' => array(
				esc_html(
					sprintf(
						/* translators: %s: medical license number */
						$data['bio_paragraphs'][0] ?? '',
						$colegiado
					)
				),
				wp_kses(
					sprintf(
						/* translators: %s: Doctoralia URL */
						$data['bio_paragraphs'][1] ?? '',
						esc_url( $doctoralia )
					),
					array(
						'a' => array(
							'class'  => true,
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				),
			),
			'sections'       => array(
				array(
					'id'      => 'nvx-equipo-scope-title',
					'class'   => 'nvx-equipo-scope',
					'kicker'  => $data['scope']['kicker'] ?? '',
					'heading' => $data['scope']['heading'] ?? '',
					'items'   => $data['scope']['items'] ?? array(),
				),
				array(
					'type'       => 'split_identity',
					'id'         => 'nvx-equipo-form-title',
					'kicker'     => $data['formation']['kicker'] ?? '',
					'heading'    => $data['formation']['heading'] ?? '',
					'paragraphs' => $data['formation']['paragraphs'] ?? array(),
					'facts'      => array(
						array(
							'label' => $data['formation']['facts'][0]['label'] ?? '',
							'val'   => sprintf( $data['formation']['facts'][0]['val'] ?? '', $colegiado ),
						),
						array(
							'label' => $data['formation']['facts'][1]['label'] ?? '',
							'val'   => $data['formation']['facts'][1]['val'] ?? '',
						),
						array(
							'label' => $data['formation']['facts'][2]['label'] ?? '',
							'val'   => $data['formation']['facts'][2]['val'] ?? '',
						),
						array(
							'label' => $data['formation']['facts'][3]['label'] ?? '',
							'val'   => $data['formation']['facts'][3]['val'] ?? '',
						),
					),
				),
			),
			'quote'          => $data['quote'] ?? array(),
		)
	);
}

/**
 * Builds the editorial authority profile for Dra. Ivon Yamileth Rivera Deras.
 *
 * @param string $ivon_media Optional portrait media extracted from CMS staff card.
 * @return string The rendered HTML markup for the profile.
 */
function nvx_equipo_ivon_authority_markup( string $ivon_media = '' ): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'equipo-medico-page.json' )['ivon'] ?? array();

	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'ivon' ) : '';

	return nvx_equipo_physician_authority_markup(
		array(
			'wrapper_class'  => 'nvx-equipo-ivon',
			'wrapper_id'     => 'physician-rivera-deras',
			'media'          => $ivon_media,
			'name'           => $data['name'] ?? '',
			'kicker'         => $data['kicker'] ?? '',
			'h2'             => $data['h2'] ?? '',
			'bio_paragraphs' => array(
				esc_html(
					sprintf(
						/* translators: %s: medical license number */
						$data['bio_paragraphs'][0] ?? '',
						$colegiado
					)
				),
			),
			'sections'       => array(
				array(
					'id'      => 'nvx-equipo-ivon-public-title',
					'kicker'  => $data['public']['kicker'] ?? '',
					'heading' => $data['public']['heading'] ?? '',
					'lead'    => $data['public']['lead'] ?? '',
				),
				array(
					'type'    => 'split_identity',
					'id'      => 'nvx-equipo-ivon-research-title',
					'kicker'  => $data['research']['kicker'] ?? '',
					'heading' => $data['research']['heading'] ?? '',
					'items'   => $data['research']['items'] ?? array(),
					'facts'   => array(
						array(
							'label' => $data['research']['facts'][0]['label'] ?? '',
							'val'   => sprintf( $data['research']['facts'][0]['val'] ?? '', $colegiado ),
						),
						array(
							'label' => $data['research']['facts'][1]['label'] ?? '',
							'val'   => $data['research']['facts'][1]['val'] ?? '',
						),
						array(
							'label' => $data['research']['facts'][2]['label'] ?? '',
							'val'   => $data['research']['facts'][2]['val'] ?? '',
						),
						array(
							'label' => $data['research']['facts'][3]['label'] ?? '',
							'val'   => $data['research']['facts'][3]['val'] ?? '',
						),
					),
				),
			),
		)
	);
}

/**
 * Builds the editorial authority profile for Dr. Fabio Augusto Quiñónez Bareiro.
 *
 * @param string $fabio_media Optional portrait media extracted from CMS staff card.
 * @return string The rendered HTML markup for the profile.
 */
function nvx_equipo_fabio_authority_markup( string $fabio_media = '' ): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data = nvx_catalog_json_resolved( 'equipo-medico-page.json' )['fabio'] ?? array();

	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'fabio' ) : '';

	return nvx_equipo_physician_authority_markup(
		array(
			'wrapper_class'  => 'nvx-equipo-fabio',
			'wrapper_id'     => 'physician-quinonez-bareiro',
			'media'          => $fabio_media,
			'name'           => $data['name'] ?? '',
			'kicker'         => $data['kicker'] ?? '',
			'h2'             => $data['h2'] ?? '',
			'bio_paragraphs' => array(
				esc_html(
					sprintf(
						/* translators: %s: medical license number */
						$data['bio_paragraphs'][0] ?? '',
						$colegiado
					)
				),
			),
			'sections'       => array(
				array(
					'id'      => 'nvx-equipo-fabio-clinical-title',
					'kicker'  => $data['clinical']['kicker'] ?? '',
					'heading' => $data['clinical']['heading'] ?? '',
					'lead'    => $data['clinical']['lead'] ?? '',
				),
				array(
					'id'      => 'nvx-equipo-fabio-research-title',
					'kicker'  => $data['research']['kicker'] ?? '',
					'heading' => $data['research']['heading'] ?? '',
					'items'   => $data['research']['items'] ?? array(),
				),
				array(
					'type'       => 'split_identity',
					'id'         => 'nvx-equipo-fabio-teach-title',
					'kicker'     => $data['teach']['kicker'] ?? '',
					'heading'    => $data['teach']['heading'] ?? '',
					'paragraphs' => $data['teach']['paragraphs'] ?? array(),
					'facts'      => array(
						array(
							'label' => $data['teach']['facts'][0]['label'] ?? '',
							'val'   => sprintf( $data['teach']['facts'][0]['val'] ?? '', $colegiado ),
						),
						array(
							'label' => $data['teach']['facts'][1]['label'] ?? '',
							'val'   => $data['teach']['facts'][1]['val'] ?? '',
						),
						array(
							'label' => $data['teach']['facts'][2]['label'] ?? '',
							'val'   => $data['teach']['facts'][2]['val'] ?? '',
						),
						array(
							'label' => $data['teach']['facts'][3]['label'] ?? '',
							'val'   => $data['teach']['facts'][3]['val'] ?? '',
						),
					),
				),
			),
		)
	);
}

/**
 * Builds the authority profile for Dra. Cristina Márquez González.
 *
 * @param string $cristina_media Optional portrait media extracted from CMS staff card.
 * @return string The rendered HTML markup for the profile.
 */
function nvx_equipo_cristina_authority_markup( string $cristina_media = '' ): string {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$data       = nvx_catalog_json_resolved( 'equipo-medico-page.json' )['cristina'] ?? array();
	$doctoralia = function_exists( 'nvx_medical_staff_doctoralia_url' ) ? nvx_medical_staff_doctoralia_url( 'cristina' ) : '';

	return nvx_equipo_physician_authority_markup(
		array(
			'wrapper_class'  => 'nvx-equipo-cristina',
			'wrapper_id'     => 'physician-cristina-marquez',
			'media'          => $cristina_media,
			'name'           => $data['name'] ?? '',
			'kicker'         => $data['kicker'] ?? '',
			'h2'             => $data['h2'] ?? '',
			'bio_paragraphs' => array_values(
				array_filter(
					array_map(
						static function ( $paragraph ): string {
							return wp_kses(
								(string) $paragraph,
								array(
									'strong' => array(),
									'a'      => array(
										'class'  => true,
										'href'   => true,
										'target' => true,
										'rel'    => true,
									),
								)
							);
						},
						array(
							$data['bio_paragraphs'][0] ?? '',
							$data['bio_paragraphs'][1] ?? '',
							sprintf( $data['bio_paragraphs'][2] ?? '', esc_url( $doctoralia ) ),
						)
					)
				)
			),
			'sections'       => array(
				array(
					'type'       => 'split_identity',
					'id'         => 'nvx-equipo-cristina-identity',
					'kicker'     => '',
					'heading'    => '',
					'paragraphs' => array(),
					'facts'      => $data['facts'] ?? array(),
				),
			),
		)
	);
}

/**
 * Rebuild equipo page: dual authority profiles + preserve other CMS clinicians.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_equipo_page' ) && nvx_content_is_equipo_page( $content ) ) {
			return 'nvx_equipo_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_equipo_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_equipo_page' ) {
		return $content;
	}

	$staff = nvx_equipo_extract_staff_cards( $content );

	// Hero media: only real page hero — never logo, never a stolen staff portrait.
	$media = '';
	if ( preg_match( '/<figure class="nvx-brand-hero__media"[\s\S]*?<\/figure>/iu', $content, $m ) ) {
		$media = $m[0];
	} elseif ( preg_match( '/<div class="nvx-brand-hero__media"[\s\S]*?<\/div>/iu', $content, $m ) ) {
		$media = $m[0];
	}
	if ( '' !== $media && nvx_equipo_media_is_logo( $media ) ) {
		$media = '';
	}

	$hero  = '<section class="nvx-brand-hero" aria-labelledby="nvx-equipo-h1">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= nvx_equipo_hero_copy_markup();
	$hero .= $media;
	$hero .= '</div></section>';

	// Director → Dra. Ivon → Dr. Fabio → Dra. Cristina → resto del equipo (CMS).
	// Closing valoración CTA: site-wide nvx-cta-banner in footer.php.
	$body  = '<div class="entry-content nvx-page__content">';
	$body .= nvx_equipo_director_authority_markup( $staff['rivera_media'] );
	$body .= nvx_equipo_ivon_authority_markup( $staff['ivon_media'] );
	$body .= nvx_equipo_fabio_authority_markup( $staff['fabio_media'] ?? '' );
	$body .= nvx_equipo_cristina_authority_markup( $staff['cristina_media'] ?? '' );
	$body .= nvx_equipo_other_staff_section_markup( $staff['other_cards'] );
	$body .= '</div>';

	// Return content directly without wrapping to avoid duplicate nvx-brand-page
	return $hero . $body;
}
add_filter( 'the_content', 'nvx_content_restructure_equipo_page', NVX_HOOK_PRIO_EQUIPO );
