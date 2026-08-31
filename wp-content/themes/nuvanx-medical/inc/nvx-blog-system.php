<?php
/**
 * Blog and journal presentation bootstrap.
 *
 * Keeps the editorial layer isolated from commercial pages while covering the
 * posts page, taxonomy/date/author archives, search results and single posts.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep the Journal search surface limited to published articles. This prevents
 * commercial pages from inheriting the editorial search template and styling.
 */
function nvx_theme_constrain_blog_search( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}

	$query->set( 'post_type', 'post' );
	$query->set( 'post_status', 'publish' );
}
add_action( 'pre_get_posts', 'nvx_theme_constrain_blog_search', 20 );

/**
 * Whether the current public request belongs to the editorial journal.
 */
function nvx_theme_is_blog_context(): bool {
	if ( is_admin() ) {
		return false;
	}

	return is_home()
		|| is_singular( 'post' )
		|| is_category()
		|| is_tag()
		|| is_date()
		|| is_author()
		|| is_search()
		|| is_post_type_archive( 'post' );
}

/**
 * Editorial card format for one archive slot.
 *
 * Desktop 3-col: featured (2) + vertical | two verticals + text | horizontal.
 * Photo cards always pair a 16:9 plate with copy — never a full-bleed overlay.
 *
 * @return 'hero'|'vertical'|'horizontal'|'text'
 */
function nvx_blog_archive_card_format( int $index, bool $has_media ): string {
	$slot = $index % 6;

	if ( 0 === $slot ) {
		return $has_media ? 'hero' : 'text';
	}

	if ( 5 === $slot ) {
		return $has_media ? 'horizontal' : 'text';
	}

	if ( 4 === $slot ) {
		return 'text';
	}

	return $has_media ? 'vertical' : 'text';
}

/**
 * Theme-hosted photos keyed by the names already on disk.
 *
 * Prefer the featured image when WordPress has one. Otherwise match the
 * post title/slug/category against these stems and clinic JPEGs.
 *
 * @return array<int, array{id:string,keys:string[],stem?:string,file?:string}>
 */
function nvx_blog_named_image_catalog(): array {
	return array(
		array(
			'id'   => 'laser-detalle',
			'keys' => array( 'laser', 'láser', 'co2', 'co₂', 'fraccionad' ),
			'file' => 'assets/images/clinics/chamberi/05-laser-detalle.jpg',
			'alt'  => 'Detalle de un procedimiento láser en consulta NUVANX',
		),
		array(
			'id'   => 'laser-piel',
			'keys' => array( 'laser', 'láser', 'piel', 'mancha', 'cicatriz' ),
			'file' => 'assets/images/clinics/chamberi/10-laser-piel.jpg',
			'alt'  => 'Aplicación láser sobre la piel en NUVANX',
		),
		array(
			'id'   => 'consulta',
			'keys' => array( 'consulta', 'valoracion', 'valoración', 'diagnost', 'bioestimul', 'colageno', 'colágeno' ),
			'stem' => 'consulta-medica-personalizada-nuvanx-madrid',
			'alt'  => 'Consulta médica personalizada en NUVANX',
		),
		array(
			'id'   => 'ojeras',
			'keys' => array( 'ojera', 'lagrimal', 'surco' ),
			'file' => 'assets/images/clinics/chamberi/08-lifestyle.jpg',
			'alt'  => 'Retrato clínico en consulta NUVANX',
		),
		array(
			'id'   => 'novias-papada',
			'keys' => array( 'novia', 'bridal', 'papada', 'cuello' ),
			'stem' => 'Papada-novias',
			'alt'  => 'Papada y contorno cervical en un plan de novia NUVANX',
		),
		array(
			'id'   => 'novias-brazos',
			'keys' => array( 'novia', 'brazo', 'manga' ),
			'stem' => 'Brazos-novias',
			'alt'  => 'Zona de brazos en un vestido de manga corta',
		),
		array(
			'id'   => 'novias-espalda',
			'keys' => array( 'novia', 'espalda', 'escote' ),
			'stem' => 'Espalda-novias',
			'alt'  => 'Espalda y escote de un vestido de novia',
		),
		array(
			'id'   => 'novias-box',
			'keys' => array( 'novia', 'box', 'protocolo' ),
			'stem' => 'Box-Clinica-Novias',
			'alt'  => 'Box de consulta en clínica NUVANX',
		),
		array(
			'id'   => 'sala-nuvanx',
			'keys' => array( 'clinica', 'clínica', 'chamber', 'sala', 'sede' ),
			'stem' => 'Sala-Nuvanx',
			'alt'  => 'Sala clínica de NUVANX Chamberí',
		),
		array(
			'id'   => 'interior-chamberi',
			'keys' => array( 'chamber', 'interior', 'clinica', 'clínica' ),
			'file' => 'assets/images/clinics/chamberi/01-interior.jpg',
			'alt'  => 'Interior de la clínica NUVANX Chamberí',
		),
		array(
			'id'   => 'fachada-goya',
			'keys' => array( 'goya', 'salamanca', 'fachada' ),
			'stem' => 'nvx-fachada-goya-900',
			'alt'  => 'Fachada de NUVANX Salamanca–Goya',
		),
		array(
			'id'   => 'medicina-1',
			'keys' => array( 'medicina', 'estetica', 'estética' ),
			'stem' => 'nuvanx-medicina-estetica1',
			'alt'  => 'Sala clínica de NUVANX Salamanca–Goya',
		),
		array(
			'id'   => 'medicina-2',
			'keys' => array( 'medicina', 'nuvanx' ),
			'stem' => 'nuvanx-medicina-2',
			'alt'  => 'Interior de clínica NUVANX en Madrid',
		),
	);
}

/**
 * Pick a catalog entry for a journal post without repeating photos on the page.
 *
 * @param array<int, array{id:string,keys:string[],stem?:string,file?:string}> $catalog Catalog.
 * @param array<string,bool>                                                    $used    Used ids.
 * @return array{id:string,keys:string[],stem?:string,file?:string}|null
 */
function nvx_blog_match_named_image( string $haystack, array $catalog, array &$used ): ?array {
	if ( empty( $catalog ) ) {
		return null;
	}

	if ( count( $used ) >= count( $catalog ) ) {
		$used = array();
	}

	$lower    = static function ( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	};
	$haystack = strtr( $lower( $haystack ), array( '-' => ' ', '_' => ' ', '/' => ' ' ) );
	$best     = null;
	$best_score = 0;

	foreach ( $catalog as $asset ) {
		$id = (string) $asset['id'];
		if ( isset( $used[ $id ] ) ) {
			continue;
		}

		$score = 0;
		foreach ( $asset['keys'] as $key ) {
			if ( '' !== $key && false !== strpos( $haystack, $lower( $key ) ) ) {
				++$score;
			}
		}

		if ( $score > $best_score ) {
			$best       = $asset;
			$best_score = $score;
		}
	}

	if ( null !== $best ) {
		$used[ (string) $best['id'] ] = true;
		return $best;
	}

	foreach ( $catalog as $asset ) {
		$id = (string) $asset['id'];
		if ( isset( $used[ $id ] ) ) {
			continue;
		}
		$used[ $id ] = true;
		return $asset;
	}

	$first = reset( $catalog );
	return is_array( $first ) ? $first : null;
}

/**
 * Build <img> markup from a named theme asset.
 *
 * @param array{id:string,keys:string[],stem?:string,file?:string,alt?:string} $asset Asset.
 * @param array{priority?:bool,sizes?:string}                     $args  Flags.
 */
function nvx_blog_named_image_html( array $asset, array $args = array() ): string {
	$alt = trim( (string) ( $asset['alt'] ?? '' ) );
	if ( '' === $alt ) {
		return '';
	}

	$priority = ! empty( $args['priority'] );
	$sizes    = isset( $args['sizes'] ) ? (string) $args['sizes'] : '(min-width: 1024px) 33vw, (min-width: 641px) 50vw, 100vw';
	$extra    = 'class="nvx-blog-card__image" loading="' . ( $priority ? 'eager' : 'lazy' ) . '" decoding="async" alt="' . esc_attr( $alt ) . '" sizes="' . esc_attr( $sizes ) . '"';
	if ( $priority ) {
		$extra .= ' fetchpriority="high"';
	}

	if ( isset( $asset['stem'] ) && is_string( $asset['stem'] ) && '' !== $asset['stem'] ) {
		$stem       = $asset['stem'];
		$candidates = function_exists( 'nvx_theme_responsive_candidates' )
			? nvx_theme_responsive_candidates( $stem )
			: array();

		if ( array() === $candidates ) {
			return '';
		}

		ksort( $candidates, SORT_NUMERIC );
		$default_width = 0;
		foreach ( array_keys( $candidates ) as $width ) {
			if ( $width >= 480 ) {
				$default_width = (int) $width;
				break;
			}
		}
		if ( 0 === $default_width ) {
			$default_width = (int) array_key_first( $candidates );
		}

		$srcset = array();
		foreach ( $candidates as $width => $candidate_url ) {
			$srcset[] = $candidate_url . ' ' . (int) $width . 'w';
		}

		$src  = $candidates[ $default_width ];
		$size = function_exists( 'nvx_image_dimensions_for_url' )
			? nvx_image_dimensions_for_url( $src )
			: array( 0, 0 );
		$dimensions = ( $size[0] > 0 && $size[1] > 0 )
			? ' width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"'
			: '';

		$html = '<img src="' . esc_url( $src ) . '" srcset="' . esc_attr( implode( ', ', $srcset ) ) . '"' . $dimensions . ' ' . $extra . '>';
		if ( function_exists( 'nvx_public_html_is_vendor_image' ) && nvx_public_html_is_vendor_image( $html ) ) {
			return '';
		}
		return $html;
	}

	if ( isset( $asset['file'] ) && is_string( $asset['file'] ) && '' !== $asset['file'] ) {
		$relative = ltrim( $asset['file'], '/' );
		if ( ! is_readable( get_template_directory() . '/' . $relative ) ) {
			return '';
		}
		$src  = trailingslashit( get_template_directory_uri() ) . $relative;
		$html = '<img src="' . esc_url( $src ) . '" ' . $extra . '>';
		if ( function_exists( 'nvx_public_html_is_vendor_image' ) && nvx_public_html_is_vendor_image( $html ) ) {
			return '';
		}
		return $html;
	}

	return '';
}

/**
 * Reset used named images tracking for new loops or requests.
 */
function nvx_blog_reset_used_images(): void {
	nvx_blog_archive_card_image( array( 'reset_used' => true ) );
}

/**
 * Featured image, or a named theme photo matched to the post.
 *
 * @param array{priority?:bool,sizes?:string,reset_used?:bool} $args Image flags.
 */
function nvx_blog_archive_card_image( array $args = array() ): string {
	static $used = array();

	if ( ! empty( $args['reset_used'] ) ) {
		$used = array();
		return '';
	}

	$priority = ! empty( $args['priority'] );
	$sizes    = isset( $args['sizes'] ) ? (string) $args['sizes'] : '(min-width: 1024px) 33vw, (min-width: 641px) 50vw, 100vw';

	if ( has_post_thumbnail() ) {
		$thumb_id = (int) get_post_thumbnail_id();
		$alt      = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
		$attr     = array(
			'class'    => 'nvx-blog-card__image',
			'loading'  => $priority ? 'eager' : 'lazy',
			'decoding' => 'async',
			'alt'      => $alt,
			'sizes'    => $sizes,
		);
		if ( $priority ) {
			$attr['fetchpriority'] = 'high';
		}

		$html = '' !== $alt ? get_the_post_thumbnail( null, 'large', $attr ) : '';
		if ( is_string( $html ) && '' !== $html
			&& 1 !== preg_match( '/logo-nuvanx|nuvanx-web\.webp|\/logo[-_]|nvx-logo|site-logo|custom-logo/iu', $html )
			&& ( ! function_exists( 'nvx_public_html_is_vendor_image' ) || ! nvx_public_html_is_vendor_image( $html ) )
		) {
			return $html;
		}
	}

	$parts = array( (string) get_the_title(), (string) get_post_field( 'post_name', get_the_ID() ) );
	foreach ( get_the_category() as $category ) {
		if ( $category instanceof WP_Term ) {
			$parts[] = $category->name;
			$parts[] = $category->slug;
		}
	}

	$asset = nvx_blog_match_named_image( implode( ' ', $parts ), nvx_blog_named_image_catalog(), $used );
	if ( null === $asset ) {
		return '';
	}

	return nvx_blog_named_image_html( $asset, $args );
}

/** Load the journal layer after the global design system. */
function nvx_theme_enqueue_blog_styles(): void {
	if ( ! nvx_theme_is_blog_context() ) {
		return;
	}

	$absolute = get_template_directory() . '/assets/css/nvx-posts.css';
	if ( ! is_readable( $absolute ) ) {
		return;
	}

	if ( function_exists( 'nvx_theme_public_delivers_inline_styles' ) && nvx_theme_public_delivers_inline_styles() ) {
		return;
	}

	wp_enqueue_style(
		'nvx-posts',
		get_template_directory_uri() . '/assets/css/nvx-posts.css',
		array( 'nvx-footer' ),
		(string) filemtime( $absolute )
	);
}
add_action( 'wp_enqueue_scripts', 'nvx_theme_enqueue_blog_styles', 40 );

/** Stable body hook for scoped editorial rules and smoke tests. */
function nvx_theme_blog_body_class( array $classes ): array {
	if ( nvx_theme_is_blog_context() ) {
		$classes[] = 'nvx-blog-context';
	}

	return array_values( array_unique( $classes ) );
}
add_filter( 'body_class', 'nvx_theme_blog_body_class' );

/**
 * The article template owns the only H1. Demote extra H1 tags saved inside older
 * post content so historical entries inherit the same accessible hierarchy.
 */
function nvx_theme_normalize_blog_headings( string $content ): string {
	if ( ! is_singular( 'post' ) || false === stripos( $content, '<h1' ) ) {
		return $content;
	}

	$content = (string) preg_replace( '/<h1(\b[^>]*)>/iu', '<h2$1>', $content );
	return str_ireplace( '</h1>', '</h2>', $content );
}
add_filter( 'the_content', 'nvx_theme_normalize_blog_headings', 8 );

/**
 * Wrap the first plain-text mention that is not already inside an anchor.
 *
 * @param array<int,string> $needles Candidate phrases, longest first preferred.
 */
function nvx_theme_wrap_first_plain_mention( string $html, array $needles, string $href, string $anchor ): string {
	if ( '' === $html || array() === $needles || '' === $href || '' === $anchor ) {
		return $html;
	}

	$parts = preg_split( '/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return $html;
	}

	$in_anchor = 0;
	$done      = false;
	foreach ( $parts as $index => $part ) {
		if ( $done || '' === $part ) {
			continue;
		}
		if ( '<' === $part[0] ) {
			if ( 1 === preg_match( '/^<a\b/i', $part ) ) {
				++$in_anchor;
			} elseif ( 1 === preg_match( '/^<\/a\b/i', $part ) ) {
				$in_anchor = max( 0, $in_anchor - 1 );
			}
			continue;
		}
		if ( $in_anchor > 0 ) {
			continue;
		}

		foreach ( $needles as $needle ) {
			$needle = (string) $needle;
			if ( '' === $needle || false === stripos( $part, $needle ) ) {
				continue;
			}
			$replaced = preg_replace(
				'/' . preg_quote( $needle, '/' ) . '/iu',
				'<a href="' . esc_url( $href ) . '">' . esc_html( $anchor ) . '</a>',
				$part,
				1
			);
			if ( is_string( $replaced ) ) {
				$parts[ $index ] = $replaced;
				$done = true;
				break;
			}
		}
	}

	return implode( '', $parts );
}

/**
 * Contextual Top-1 wraps from p6: only existing mentions, never new copy.
 */
function nvx_theme_wrap_top1_commercial_mentions( string $slug, string $content ): string {
	$map = array(
		'plan-anual-medicina-estetica-sin-sobretratar' => array(
			array(
				'path'    => '/endolift-facial-papada-mandibula/',
				'needles' => array( 'Endolift® Facial', 'Endolift®', 'Endolift®', 'tercio inferior' ),
				'anchor'  => 'Endolift® facial',
			),
			array(
				'path'    => '/endolaser-corporal-grasa-localizada/',
				'needles' => array( 'endoláser corporal', 'láser corporal', 'grasa localizada', 'remodelación corporal' ),
				'anchor'  => 'endoláser corporal',
			),
			array(
				'path'    => '/neuromoduladores-faciales-madrid/',
				'needles' => array( 'neuromoduladores', 'toxina', 'botox' ),
				'anchor'  => 'neuromoduladores',
			),
		),
		'well-aging-estrategia-medica-global'          => array(
			array(
				'path'    => '/neuromoduladores-faciales-madrid/',
				'needles' => array( 'relajación de expresión', 'mantenimiento muscular', 'tratamientos de apoyo', 'neuromoduladores' ),
				'anchor'  => 'neuromoduladores',
			),
			array(
				'path'    => '/endolaser-corporal-grasa-localizada/',
				'needles' => array( 'endoláser corporal', 'composición corporal', 'grasa corporal' ),
				'anchor'  => 'endoláser corporal',
			),
		),
	);

	if ( ! isset( $map[ $slug ] ) ) {
		return $content;
	}

	foreach ( $map[ $slug ] as $item ) {
		$path = (string) $item['path'];
		if ( false !== strpos( $content, $path ) ) {
			continue;
		}
		$content = nvx_theme_wrap_first_plain_mention(
			$content,
			(array) $item['needles'],
			home_url( $path ),
			(string) $item['anchor']
		);
	}

	return $content;
}

/**
 * Guarantee explicit internal links from Top-1 journal articles to their
 * treatment landings. Skips when the destination path or exact anchor exists.
 */
function nvx_theme_inject_priority_treatment_links( string $content ): string {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$slug    = (string) get_post_field( 'post_name', get_the_ID() );
	$content = nvx_theme_wrap_top1_commercial_mentions( $slug, $content );

	$map = array(
		'endolift-primeras-72-horas-que-esperar' => array(
			'url'    => home_url( '/endolift-facial-papada-mandibula/' ),
			'path'   => '/endolift-facial-papada-mandibula/',
			'anchor' => __( 'guía completa del Endolift® facial en Madrid', 'nuvanx-medical' ),
			'intro'  => __( 'Si aún estás evaluando si el Endolift® es tu opción, consulta nuestra', 'nuvanx-medical' ),
			'suffix' => __( ': zonas, candidatura, proceso y precios.', 'nuvanx-medical' ),
		),
		'endolift-vs-hifu-diferencias-reales' => array(
			'url'    => home_url( '/endolift-facial-papada-mandibula/' ),
			'path'   => '/endolift-facial-papada-mandibula/',
			'anchor' => __( 'ficha completa del tratamiento', 'nuvanx-medical' ),
			'intro'  => __( 'Para conocer cómo aplicamos el Endolift® en NUVANX — zonas, proceso y candidatura — consulta la', 'nuvanx-medical' ),
			'suffix' => __( '.', 'nuvanx-medical' ),
		),
		'endolaser-corporal-vs-no-invasivos-grasa-localizada' => array(
			'url'    => home_url( '/endolaser-corporal-grasa-localizada/' ),
			'path'   => '/endolaser-corporal-grasa-localizada/',
			'anchor' => __( 'endoláser corporal en NUVANX', 'nuvanx-medical' ),
			'intro'  => __( 'Conoce en detalle el', 'nuvanx-medical' ),
			'suffix' => __( ': zonas tratadas, candidatura, recuperación y precios.', 'nuvanx-medical' ),
		),
		'ipl-medica-btl-exilite-manchas-rojeces-acne-fotorejuvenecimiento' => array(
			'url'    => home_url( '/btl-exilite-ipl-madrid/' ),
			'path'   => '/btl-exilite-ipl-madrid/',
			'anchor' => __( 'tratamiento IPL médico en Madrid', 'nuvanx-medical' ),
			'intro'  => __( 'Si buscas indicación, precio o reserva, consulta el', 'nuvanx-medical' ),
			'suffix' => __( ': candidatura, proceso y tarifas de BTL EXILITE™.', 'nuvanx-medical' ),
		),
		'laserlipolisis-vs-liposuccion' => array(
			'url'    => home_url( '/endolaser-corporal-grasa-localizada/' ),
			'path'   => '/endolaser-corporal-grasa-localizada/',
			'anchor' => __( 'endoláser corporal en NUVANX', 'nuvanx-medical' ),
			'intro'  => __( 'Si hay indicación de focos corporales, consulta el', 'nuvanx-medical' ),
			'suffix' => __( ': candidatura, recuperación y tarifas.', 'nuvanx-medical' ),
		),
		'smartlipo-laserlipolisis-endolift' => array(
			'url'    => home_url( '/endolaser-corporal-grasa-localizada/' ),
			'path'   => '/endolaser-corporal-grasa-localizada/',
			'anchor' => __( 'endoláser corporal en NUVANX', 'nuvanx-medical' ),
			'intro'  => __( 'Si hay indicación de focos corporales, consulta el', 'nuvanx-medical' ),
			'suffix' => __( ': candidatura, recuperación y tarifas.', 'nuvanx-medical' ),
		),
		'papada-sin-cirugia-madrid-opciones-endolift' => array(
			'url'    => home_url( '/endolift-facial-papada-mandibula/' ),
			'path'   => '/endolift-facial-papada-mandibula/',
			'anchor' => __( 'ficha del Endolift® facial en Madrid', 'nuvanx-medical' ),
			'intro'  => __( 'Si hay indicación de láser intersticial, consulta la', 'nuvanx-medical' ),
			'suffix' => __( '.', 'nuvanx-medical' ),
		),
	);

	if ( ! isset( $map[ $slug ] ) ) {
		return $content;
	}

	$item = $map[ $slug ];
	$path = (string) ( $item['path'] ?? '' );
	if ( ( '' !== $path && false !== strpos( $content, $path ) ) || false !== strpos( $content, (string) $item['anchor'] ) ) {
		return $content;
	}

	$block  = '<div class="nvx-related-links"><p>' . esc_html( (string) $item['intro'] ) . ' ';
	$block .= '<a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( (string) $item['anchor'] ) . '</a>';
	$block .= esc_html( (string) $item['suffix'] ) . '</p></div>';

	return $content . $block;
}
add_filter( 'the_content', 'nvx_theme_inject_priority_treatment_links', 24 );

/**
 * Canonical medical author for journal (E-E-A-T). Not the WP login display name.
 *
 * Defaults can be overridden per post via meta:
 * - nvx_medical_author_name
 * - nvx_medical_author_url
 * - nvx_medical_author_role
 * Or site-wide via the `nvx_blog_medical_author` filter.
 *
 * @param int|null $post_id Optional post ID (defaults to current post).
 * @return array{name:string,url:string,role:string}
 */
function nvx_blog_medical_author( ?int $post_id = null ): array {
	$post_id = $post_id ?: (int) get_the_ID();
	$author  = array(
		'name' => __( 'Dr. José Javier Rivera Tejeda', 'nuvanx-medical' ),
		'url'  => home_url( '/equipo-medico/#physician-rivera-tejeda' ),
		'role' => __( 'Director Médico NUVANX', 'nuvanx-medical' ),
	);

	if ( $post_id > 0 ) {
		$meta_name = (string) get_post_meta( $post_id, 'nvx_medical_author_name', true );
		$meta_url  = (string) get_post_meta( $post_id, 'nvx_medical_author_url', true );
		$meta_role = (string) get_post_meta( $post_id, 'nvx_medical_author_role', true );
		if ( '' !== trim( $meta_name ) ) {
			$author['name'] = $meta_name;
		}
		if ( '' !== trim( $meta_url ) ) {
			$author['url'] = $meta_url;
		}
		if ( '' !== trim( $meta_role ) ) {
			$author['role'] = $meta_role;
		}
	}

	/**
	 * Filter medical author identity for journal E-E-A-T.
	 *
	 * @param array{name:string,url:string,role:string} $author  Author payload.
	 * @param int                                       $post_id Post ID (0 if unknown).
	 */
	$filtered = apply_filters( 'nvx_blog_medical_author', $author, $post_id );
	if ( ! is_array( $filtered ) ) {
		return $author;
	}

	return array(
		'name' => isset( $filtered['name'] ) ? (string) $filtered['name'] : $author['name'],
		'url'  => isset( $filtered['url'] ) ? (string) $filtered['url'] : $author['url'],
		'role' => isset( $filtered['role'] ) ? (string) $filtered['role'] : $author['role'],
	);
}

/**
 * Strip hardcoded CMS bylines (Autor / Fecha / Lectura) from post body.
 * Hero meta in nvx-blog-single.php owns author, date and reading time.
 *
 * Loose label patterns only run on the leading preamble (before first H2)
 * so mid-article copy mentioning “Autor” / “Fecha” is not removed.
 */
function nvx_theme_strip_blog_content_bylines( string $content ): string {
	if ( is_admin() || ! is_singular( 'post' ) || '' === trim( $content ) ) {
		return $content;
	}

	// Explicit byline class from publish scripts (safe anywhere).
	$content = (string) preg_replace(
		'/<p\b[^>]*\bclass=["\'][^"\']*\bnvx-blog-byline\b[^"\']*["\'][^>]*>[\s\S]*?<\/p>/iu',
		'',
		$content
	);

	// Split at first H2 so loose patterns never touch body sections.
	$parts = preg_split( '/(?=<h2\b)/iu', $content, 2 );
	$head  = ( is_array( $parts ) && isset( $parts[0] ) ) ? $parts[0] : $content;
	$tail  = $parts[1] ?? '';

	// Short preamble: Autor: … (optionally Fecha/Lectura on same paragraph).
	$head = (string) preg_replace(
		'/<p\b[^>]*>\s*(?:<strong>)?\s*Autor\s*:?\s*(?:<\/strong>)?\s*[^<]{0,160}(?:Fecha\s*:[^<]{0,80})?(?:Lectura\s*:[^<]{0,40})?\s*<\/p>/iu',
		'',
		$head,
		2
	);

	// Adjacent short Fecha / Lectura lines immediately after a stripped Autor line.
	$head = (string) preg_replace(
		'/<p\b[^>]*>\s*(?:<strong>)?\s*(?:Fecha|Lectura)\s*:?\s*(?:<\/strong>)?\s*[^<]{0,80}\s*<\/p>/iu',
		'',
		$head,
		2
	);

	// Orphan keyword dumps only in the preamble.
	$head = (string) preg_replace(
		'/<p\b[^>]*>\s*(?:<strong>)?\s*Palabras clave\s*:?\s*(?:<\/strong>)?\s*[^<]{0,200}\s*<\/p>/iu',
		'',
		$head,
		1
	);

	$content = $head . $tail;

	// Strip redundant trailing reviewer lines or markdown dividers at the end of post content.
	$content = (string) preg_replace(
		'/<p\b[^>]*>\s*(?:\*|_)?\s*(?:Revisado por|Autor|Fuente)\s*:[^<]{0,200}(?:\*|_)?\s*<\/p>\s*$/iu',
		'',
		$content
	);
	$content = (string) preg_replace(
		'/<p\b[^>]*>\s*(?:\*{3,}|_{3,}|-{3,}|##\s*[^<]*)\s*<\/p>\s*$/iu',
		'',
		$content
	);

	// Collapse excess leading whitespace after strips.
	$content = (string) preg_replace( '/^(?:\s|<br\s*\/?>|&nbsp;)+/iu', '', $content );

	return $content;
}
add_filter( 'the_content', 'nvx_theme_strip_blog_content_bylines', 9 );

/**
 * Rebind one WP_Query instance to the exact published post.
 */
function nvx_single_post_rebind_query( WP_Query $query, WP_Post $exact_post, string $slug ): void {
	// Bind core post state.
	$query->queried_object    = $exact_post;
	$query->queried_object_id = (int) $exact_post->ID;
	$query->post              = $exact_post;
	$query->posts             = array( $exact_post );
	$query->post_count        = 1;
	$query->found_posts       = 1;
	$query->max_num_pages     = 1;

	// Normalize query flags to a clean single/singular view.
	$query->is_404            = false;
	$query->is_singular       = true;
	$query->is_single         = ( 'post' === $exact_post->post_type );
	$query->is_page           = ( 'page' === $exact_post->post_type );
	$query->is_attachment     = ( 'attachment' === $exact_post->post_type );

	$query->is_home           = false;
	$query->is_front_page     = false;
	$query->is_archive        = false;
	$query->is_category       = false;
	$query->is_tag            = false;
	$query->is_tax            = false;
	$query->is_search         = false;
	$query->is_author         = false;
	$query->is_date           = false;
	$query->is_feed           = false;
	$query->is_comment_feed   = false;
	$query->is_paged          = false;

	// Reset query vars that may leak state from previous uses of this WP_Query.
	$query->query['name']           = $slug;
	$query->query['post_type']      = $exact_post->post_type;
	$query->query_vars['p']         = (int) $exact_post->ID;
	$query->query_vars['name']      = $slug;
	$query->query_vars['post_type'] = $exact_post->post_type;
	$query->query_vars['pagename']  = '';
	$query->query_vars['page_id']   = 0;

	unset(
		$query->query_vars['category_name'],
		$query->query_vars['cat'],
		$query->query_vars['tag'],
		$query->query_vars['tag_id'],
		$query->query_vars['tax_query'],
		$query->query_vars['s'],
		$query->query_vars['author'],
		$query->query_vars['author_name']
	);

	// Preserve page/paged for multipage articles (<!--nextpage--> support).
	if ( isset( $query->query_vars['page'] ) ) {
		$query->is_paged = true;
	}
}

// Register the governed-blog runtime during theme bootstrap, not from the
// single-post template. Its `wp` hook must run before template loading/Yoast
// presentation so a stale neighbouring singular context cannot survive.
require_once __DIR__ . '/nvx-governed-blog-runtime.php';
