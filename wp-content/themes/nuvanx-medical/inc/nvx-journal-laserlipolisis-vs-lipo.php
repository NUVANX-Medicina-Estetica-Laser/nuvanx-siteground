<?php
/**
 * Theme-owned Journal articles for laserlipolysis comparisons.
 *
 * Commercial owners stay on the fichas. These posts are educational.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NVX_JOURNAL_LASERLIPO_VS_LIPO_SLUG   = 'laserlipolisis-vs-liposuccion';
const NVX_JOURNAL_LASERLIPO_VS_LIPO_MARKER = '<!-- nvx-journal-laserlipolisis-vs-liposuccion -->';
const NVX_JOURNAL_SMARTLIPO_ENDOLIFT_SLUG  = 'smartlipo-laserlipolisis-endolift';

/**
 * @return array<string,array{file:string,marker:string}>
 */
function nvx_journal_tech_article_map(): array {
	return array(
		NVX_JOURNAL_LASERLIPO_VS_LIPO_SLUG  => array(
			'file'   => 'journal-laserlipolisis-vs-liposuccion.json',
			'marker' => NVX_JOURNAL_LASERLIPO_VS_LIPO_MARKER,
		),
		NVX_JOURNAL_SMARTLIPO_ENDOLIFT_SLUG => array(
			'file'   => 'journal-smartlipo-laserlipolisis-endolift.json',
			'marker' => '<!-- nvx-journal-smartlipo-laserlipolisis-endolift -->',
		),
	);
}

/** @return array<string,mixed> */
function nvx_journal_tech_article_catalog( string $slug ): array {
	static $cache = array();
	if ( isset( $cache[ $slug ] ) ) {
		return $cache[ $slug ];
	}

	$map = nvx_journal_tech_article_map();
	if ( ! isset( $map[ $slug ] ) ) {
		$cache[ $slug ] = array();
		return array();
	}

	$loaded           = nvx_catalog_json_resolved( (string) $map[ $slug ]['file'] );
	$cache[ $slug ]   = is_array( $loaded ) ? $loaded : array();
	return $cache[ $slug ];
}

function nvx_journal_tech_article_current_slug(): string {
	if ( is_admin() || wp_doing_ajax() || is_feed() ) {
		return '';
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';
	foreach ( array_keys( nvx_journal_tech_article_map() ) as $slug ) {
		if ( is_string( $path ) && function_exists( 'nvx_schema_path_matches' )
			&& nvx_schema_path_matches( $path, '/' . $slug . '/' ) ) {
			return $slug;
		}
	}

	if ( ! is_singular( 'post' ) ) {
		return '';
	}

	$name = (string) get_post_field( 'post_name', get_the_ID() );
	return isset( nvx_journal_tech_article_map()[ $name ] ) ? $name : '';
}

/**
 * @param array<int,string> $items
 */
function nvx_journal_laserlipo_vs_lipo_list( array $items ): string {
	$html = '<ul class="nvx-check-list">';
	foreach ( $items as $item ) {
		$item = (string) $item;
		if ( '' === $item ) {
			continue;
		}
		$html .= '<li>' . esc_html( $item ) . '</li>';
	}
	return $html . '</ul>';
}

function nvx_journal_tech_article_markup( string $slug ): string {
	$data = nvx_journal_tech_article_catalog( $slug );
	$map  = nvx_journal_tech_article_map();
	if ( array() === $data || ! isset( $map[ $slug ] ) ) {
		return '';
	}

	$valoracion = function_exists( 'nvx_cta_valoracion_url' )
		? nvx_cta_valoracion_url()
		: home_url( '/madrid/valoracion/' );

	$html  = (string) $map[ $slug ]['marker'];
	$html .= '<div class="nvx-journal-article nvx-prose">';
	$html .= function_exists( 'nvx_clinical_authority_byline_markup' )
		? nvx_clinical_authority_byline_markup()
		: '';
	$html .= '<p class="nvx-lead">' . esc_html( (string) ( $data['lead'] ?? '' ) ) . '</p>';

	foreach ( (array) ( $data['sections'] ?? array() ) as $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}
		$html .= '<h2>' . esc_html( (string) ( $section['h2'] ?? '' ) ) . '</h2>';
		foreach ( (array) ( $section['paragraphs'] ?? array() ) as $paragraph ) {
			$html .= '<p>' . esc_html( (string) $paragraph ) . '</p>';
		}
		if ( ! empty( $section['steps'] ) && is_array( $section['steps'] ) ) {
			$html .= nvx_journal_laserlipo_vs_lipo_list( $section['steps'] );
		}
		if ( ! empty( $section['yes'] ) || ! empty( $section['no'] ) ) {
			$yes = array_values( array_filter( array_map( 'strval', is_array( $section['yes'] ?? null ) ? $section['yes'] : array() ) ) );
			$no  = array_values( array_filter( array_map( 'strval', is_array( $section['no'] ?? null ) ? $section['no'] : array() ) ) );
			if ( function_exists( 'nvx_candidacy_markup' ) ) {
				$html .= nvx_candidacy_markup( $yes, $no );
			} elseif ( ! empty( $yes ) || ! empty( $no ) ) {
				if ( ! empty( $yes ) ) {
					$html .= '<h3>' . esc_html__( 'Candidato', 'nuvanx-medical' ) . '</h3>';
					$html .= nvx_journal_laserlipo_vs_lipo_list( $yes );
				}
				if ( ! empty( $no ) ) {
					$html .= '<h3>' . esc_html__( 'No candidato', 'nuvanx-medical' ) . '</h3>';
					$html .= nvx_journal_laserlipo_vs_lipo_list( $no );
				}
			}
		}
	}

	$compare = is_array( $data['compare'] ?? null ) ? $data['compare'] : array();
	$rows    = is_array( $compare['rows'] ?? null ) ? $compare['rows'] : array();
	if ( array() !== $rows ) {
		$headers = is_array( $compare['headers'] ?? null ) ? $compare['headers'] : array();
		$html   .= '<div class="nvx-recovery-table-wrap" role="region" tabindex="0" aria-label="' . esc_attr__( 'Comparativa de técnicas láser', 'nuvanx-medical' ) . '">';
		$html   .= '<table class="nvx-recovery-table nvx-endolift-compare-table">';
		if ( ! empty( $compare['caption'] ) ) {
			$html .= '<caption>' . esc_html( (string) $compare['caption'] ) . '</caption>';
		}
		if ( array() !== $headers ) {
			$html .= '<thead><tr>';
			foreach ( $headers as $header ) {
				$html .= '<th scope="col">' . esc_html( (string) $header ) . '</th>';
			}
			$html .= '</tr></thead>';
		}
		$html .= '<tbody>';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$html .= '<tr>';
			$row_values = array_values( $row );
			foreach ( $row_values as $index => $cell ) {
				$tag = 0 === $index ? 'th' : 'td';
				if ( 0 === $index ) {
					$html .= '<th scope="row">' . esc_html( (string) $cell ) . '</th>';
				} else {
					$header_text = isset( $headers[ $index ] ) ? $headers[ $index ] : '';
					$html .= '<td data-label="' . esc_attr( (string) $header_text ) . '">' . esc_html( (string) $cell ) . '</td>';
				}
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
	}

	$faq = is_array( $data['faq'] ?? null ) ? $data['faq'] : array();
	if ( array() !== $faq ) {
		$html .= '<h2>' . esc_html__( 'Preguntas frecuentes', 'nuvanx-medical' ) . '</h2>';
		foreach ( $faq as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$html .= '<h3>' . esc_html( (string) ( $item['q'] ?? '' ) ) . '</h3>';
			$html .= '<p>' . esc_html( (string) ( $item['a'] ?? '' ) ) . '</p>';
		}
	}

	$biblio = is_array( $data['bibliography'] ?? null ) ? $data['bibliography'] : array();
	if ( array() !== $biblio ) {
		$html .= '<h2>' . esc_html__( 'Bibliografía', 'nuvanx-medical' ) . '</h2><ol class="nvx-bibliography">';
		foreach ( $biblio as $entry ) {
			$html .= '<li>' . esc_html( (string) $entry ) . '</li>';
		}
		$html .= '</ol>';
	}

	$related = is_array( $data['related_fichas'] ?? null ) ? $data['related_fichas'] : array();
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

	$html .= '<p><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( $valoracion ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a></p>';
	$html .= '</div>';

	return $html;
}

function nvx_journal_tech_article_content( string $content ): string {
	$slug = nvx_journal_tech_article_current_slug();
	if ( '' === $slug ) {
		return $content;
	}

	return nvx_journal_tech_article_markup( $slug );
}
add_filter( 'the_content', 'nvx_journal_tech_article_content', NVX_HOOK_PRIO_JOURNAL_TECH_ARTICLE );

function nvx_journal_tech_article_title( $title, $post_id = 0 ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return $title;
	}
	$slug = (string) get_post_field( 'post_name', $post_id );
	if ( ! isset( nvx_journal_tech_article_map()[ $slug ] ) ) {
		return $title;
	}
	$data = nvx_journal_tech_article_catalog( $slug );
	return ! empty( $data['title'] ) ? (string) $data['title'] : $title;
}
add_filter( 'the_title', 'nvx_journal_tech_article_title', 20, 2 );

function nvx_journal_tech_article_seed_staging2(): void {
	if ( ! function_exists( 'nvx_environment_is_staging2' ) || ! nvx_environment_is_staging2() ) {
		return;
	}
	if ( ! function_exists( 'get_page_by_path' ) ) {
		return;
	}

	foreach ( nvx_journal_tech_article_map() as $slug => $meta ) {
		$existing = get_page_by_path( $slug, OBJECT, 'post' );
		if ( $existing instanceof WP_Post ) {
			continue;
		}
		$data = nvx_journal_tech_article_catalog( $slug );
		wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => (string) ( $data['title'] ?? $slug ),
				'post_excerpt' => (string) ( $data['excerpt'] ?? '' ),
				'post_name'    => $slug,
				'post_content' => (string) $meta['marker'],
			),
			true
		);
	}
}
add_action( 'init', 'nvx_journal_tech_article_seed_staging2', 32 );
