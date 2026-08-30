<?php
/**
 * Shared helpers for canonical page rebuild modules.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Devuelve el "dueño" lógico de la página actual.
 *
 * Consulta primero el Page Registry canónico para garantizar resolución
 * determinista e independiente del orden de bootstrap; si no está definido,
 * evalúa el filtro 'nvx_page_owner' como fallback dinámico.
 */
function nvx_get_page_owner() {
	if ( function_exists( 'nvx_get_canonical_page_owner' ) ) {
		$canonical_owner = nvx_get_canonical_page_owner();
		if ( ! empty( $canonical_owner ) ) {
			return $canonical_owner;
		}
	}

	/**
	 * Filtro que permite a los módulos declarar la propiedad de la página.
	 *
	 * Debe devolver un identificador estable de propietario (string) o null.
	 */
	$owner = apply_filters( 'nvx_page_owner', null );

	return $owner;
}

/** Extract a balanced div media slot without truncating nested markup. */
function nvx_page_extract_brand_hero_div( string $content ): string {
	if ( ! preg_match( '/<div class="nvx-brand-hero__media"[^>]*>/iu', $content, $opening, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$start = (int) $opening[0][1];
	$tail  = substr( $content, $start );
	if ( ! preg_match_all( '/<\/?div\b[^>]*>/iu', $tail, $tags, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$depth = 0;
	foreach ( $tags[0] as $tag ) {
		$is_closing = 0 === strpos( $tag[0], '</' );
		$depth     += $is_closing ? -1 : 1;
		if ( 0 === $depth ) {
			$length = (int) $tag[1] + strlen( $tag[0] );
			return substr( $tail, 0, $length );
		}
	}

	return '';
}

/** Preserve the existing canonical hero media slot when rebuilding a page, omitting vendor hero media. */
function nvx_page_extract_brand_hero_media( string $content ): string {
	$media = '';
	if ( preg_match( '/<figure class="nvx-brand-hero__media"[\s\S]*?<\/figure>/iu', $content, $matches ) ) {
		$media = $matches[0];
	}
	if ( '' === $media ) {
		$media = nvx_page_extract_brand_hero_div( $content );
	}

	if ( '' !== $media && function_exists( 'nvx_public_html_is_vendor_image' ) && nvx_public_html_is_vendor_image( $media ) ) {
		return '';
	}

	return $media;
}

/**
 * Open a canonical brand section and its inner shell.
 *
 * Callers keep translated copy in their own source and pass escaped markup.
 *
 * @param array<string,string> $section_attributes Additional safe attributes.
 */
function nvx_page_brand_section_open_markup(
	string $section_class,
	string $labelledby,
	string $inner_extra_class = '',
	array $section_attributes = array()
): string {
	$section_classes = 'nvx-brand-section';
	$section_suffix  = trim( $section_class );
	if ( '' !== $section_suffix ) {
		$section_classes .= ' ' . $section_suffix;
	}

	$inner_classes = 'nvx-shell nvx-brand-section__inner';
	$inner_suffix  = trim( $inner_extra_class );
	if ( '' !== $inner_suffix ) {
		$inner_classes .= ' ' . $inner_suffix;
	}

	$html               = '<section class="' . esc_attr( $section_classes ) . '" aria-labelledby="' . esc_attr( $labelledby ) . '"';
	$allowed_attributes = array( 'id' );
	foreach ( $section_attributes as $attribute => $value ) {
		if ( ! is_string( $attribute ) || ! in_array( $attribute, $allowed_attributes, true ) ) {
			continue;
		}
		$html .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
	}

	return $html . '><div class="' . esc_attr( $inner_classes ) . '">';
}

/**
 * Render the canonical kicker and H2 pair.
 *
 * The kicker and heading arguments must already be escaped by the caller.
 */
function nvx_page_brand_section_heading_markup(
	string $kicker,
	string $heading_id,
	string $heading
): string {
	return '<p class="nvx-brand-kicker">' . $kicker . '</p>'
		. '<h2 id="' . esc_attr( $heading_id ) . '" class="nvx-brand-title">' . $heading . '</h2>';
}

/**
 * Shared brand-hero copy used by treatment landings.
 *
 * Callers keep their own h1 ids, fallback CTA labels and optional description.
 * Output is escaped except description_html, which the caller must already escape.
 *
 * @param array<string,mixed> $config Copy fields.
 */
function nvx_brand_hero_copy_markup( array $config ): string {
	$html = '<div class="nvx-brand-hero__copy">';

	$kicker = trim( (string) ( $config['kicker'] ?? '' ) );
	if ( '' !== $kicker ) {
		$html .= '<p class="nvx-brand-kicker">' . esc_html( $kicker ) . '</p>';
	}

	$html .= '<h1 class="nvx-brand-hero__title" id="' . esc_attr( (string) ( $config['h1_id'] ?? '' ) ) . '">' . esc_html( (string) ( $config['h1'] ?? '' ) ) . '</h1>';

	$byline_html = (string) ( $config['byline_html'] ?? '' );
	if ( '' !== $byline_html ) {
		$html .= $byline_html;
	} elseif ( ! empty( $config['byline'] ) && function_exists( 'nvx_clinical_authority_byline_markup' ) ) {
		$html .= nvx_clinical_authority_byline_markup();
	}

	$lead = (string) ( $config['lead'] ?? '' );
	if ( '' !== $lead ) {
		$html .= '<p class="nvx-brand-hero__lead">' . esc_html( $lead ) . '</p>';
	}

	$description_html = (string) ( $config['description_html'] ?? '' );
	if ( '' !== $description_html ) {
		$html .= '<p class="nvx-brand-hero__description">' . $description_html . '</p>';
	}

	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup( 'nvx-brand-actions' );
	} else {
		$fallback = (string) ( $config['cta_fallback_label'] ?? __( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) );
		$html    .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html( $fallback ) . '</a></div>';
	}

	$meta = (string) ( $config['meta'] ?? '' );
	if ( '' !== $meta ) {
		$html .= '<p class="nvx-brand-meta">' . esc_html( $meta ) . '</p>';
	}

	$html .= '</div>';

	return $html;
}

/**
 * Resolve a same-host WordPress uploads URL to its local filesystem path.
 *
 * Returns an empty string for external/CDN URLs or URLs that cannot be mapped
 * safely into the active uploads directory.
 */
function nvx_local_upload_file_from_url( string $url ): string {
	if ( '' === trim( $url ) ) {
		return '';
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}

	$base_url  = (string) $uploads['baseurl'];
	$base_host = strtolower( (string) wp_parse_url( $base_url, PHP_URL_HOST ) );
	$base_path = rawurldecode( (string) wp_parse_url( $base_url, PHP_URL_PATH ) );
	$base_path = '/' . trim( $base_path, '/' );
	$base_dir  = untrailingslashit( (string) $uploads['basedir'] );

	$source_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$source_path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );

	if ( '' === $base_host || '/' === $base_path || '' === $base_dir || '' === $source_path || $source_host !== $base_host ) {
		return '';
	}

	if ( $source_path !== $base_path && 0 !== strpos( $source_path, $base_path . '/' ) ) {
		return '';
	}

	$relative_path = ltrim( substr( $source_path, strlen( $base_path ) ), '/' );
	if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) ) {
		return '';
	}

	return $base_dir . '/' . $relative_path;
}

/**
 * Determine whether a local uploads URL exists on disk.
 *
 * @return bool|null True/false for local uploads, null for external/unmappable URLs.
 */
function nvx_local_upload_url_exists( string $url ) {
	$local_file = nvx_local_upload_file_from_url( $url );
	if ( '' === $local_file ) {
		return null;
	}

	static $file_exists = array();
	if ( ! array_key_exists( $local_file, $file_exists ) ) {
		$file_exists[ $local_file ] = is_file( $local_file );
	}

	return $file_exists[ $local_file ];
}

/**
 * Remove stale local responsive-image candidates whose files are missing.
 *
 * WordPress attachment metadata can outlive generated upload derivatives after
 * a migration or media cleanup. Advertising those stale files in `srcset`
 * makes browsers request predictable 404s even when the primary image exists.
 * External/CDN candidates are left untouched because their filesystem cannot
 * be verified from the WordPress uploads directory.
 *
 * @param array|false $sources       Responsive image candidates keyed by width.
 * @param int[]       $size_array    Requested image dimensions.
 * @param string      $image_src     Primary image URL.
 * @param array       $image_meta    Attachment metadata.
 * @param int         $attachment_id Attachment ID.
 * @return array|false Filtered sources, or false when no valid local candidate remains.
 */
function nvx_filter_missing_local_srcset_sources( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	unset( $size_array, $image_src, $image_meta, $attachment_id );

	if ( ! is_array( $sources ) || array() === $sources ) {
		return $sources;
	}

	foreach ( $sources as $width => $source ) {
		if ( ! is_array( $source ) || empty( $source['url'] ) ) {
			continue;
		}

		$exists = nvx_local_upload_url_exists( (string) $source['url'] );
		if ( false === $exists ) {
			unset( $sources[ $width ] );
		}
	}

	return array() === $sources ? false : $sources;
}
add_filter( 'wp_calculate_image_srcset', 'nvx_filter_missing_local_srcset_sources', 20, 5 );

/**
 * Remove rendered content images that point to missing local upload files.
 *
 * This is a fail-safe for migrated/editorial content whose HTML still points to
 * media that no longer exists. It never fabricates replacement patient media
 * and does not alter external/CDN images. The surrounding copy remains visible.
 */
function nvx_remove_missing_local_content_images( $content ) {
	if ( ! is_string( $content ) || false === stripos( $content, '<img' ) ) {
		return $content;
	}

	$filtered = preg_replace_callback(
		'/<img\b[^>]*>/iu',
		static function ( $matches ) {
			$tag = isset( $matches[0] ) ? (string) $matches[0] : '';
			if ( '' === $tag ) {
				return $tag;
			}

			if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/iu', $tag, $src_match ) ) {
				return $tag;
			}

			$src = html_entity_decode( (string) $src_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return false === nvx_local_upload_url_exists( $src ) ? '' : $tag;
		},
		$content
	);

	if ( ! is_string( $filtered ) ) {
		return $content;
	}

	// Remove only figures left completely empty after deleting a broken image.
	$filtered = preg_replace( '/<figure\b[^>]*>\s*<\/figure>/iu', '', $filtered ) ?? $filtered;

	return $filtered;
}
add_filter( 'the_content', 'nvx_remove_missing_local_content_images', 20 );

/** Read an HTML attribute value from an attribute string. */
function nvx_html_attrs_get( string $attrs, string $name ): string {
	$pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/iu';
	if ( ! preg_match( $pattern, $attrs, $match ) ) {
		return '';
	}

	return html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** Set or replace an HTML attribute on an attribute string. */
function nvx_html_attrs_set( string $attrs, string $name, string $value ): string {
	$safe    = esc_attr( $value );
	$pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/iu';
	if ( preg_match( $pattern, $attrs ) ) {
		$updated = preg_replace( $pattern, $name . '="' . $safe . '"', $attrs, 1 );
		return is_string( $updated ) ? $updated : $attrs;
	}

	return rtrim( $attrs ) . ' ' . $name . '="' . $safe . '"';
}

/** Filename stem without a WordPress -WIDTHxHEIGHT suffix. */
function nvx_image_stem_from_url( string $url ): string {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$name = pathinfo( $path, PATHINFO_FILENAME );
	$stem = preg_replace( '/-\d+x\d+$/', '', $name );

	return is_string( $stem ) && '' !== $stem ? $stem : $name;
}

/**
 * Theme-hosted WebP candidates keyed by width.
 *
 * @return array<int,string>
 */
function nvx_theme_responsive_candidates( string $stem ): array {
	if ( '' === $stem || false !== strpbrk( $stem, '*?[]\\' ) ) {
		return array();
	}

	$dir = get_template_directory() . '/assets/images/responsive';
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$uri = trailingslashit( get_template_directory_uri() ) . 'assets/images/responsive';
	$out = array();
	foreach ( (array) glob( $dir . '/' . $stem . '-*.webp' ) as $file ) {
		if ( ! is_string( $file ) || ! preg_match( '/-(\d+)\.webp$/', $file, $match ) ) {
			continue;
		}
		$out[ (int) $match[1] ] = $uri . '/' . basename( $file );
	}

	return $out;
}

/**
 * Upload-dir sized siblings keyed by width. Prefers WebP over PNG/JPEG.
 *
 * @return array<int,string>
 */
function nvx_upload_responsive_candidates( string $url ): array {
	$local = nvx_local_upload_file_from_url( $url );
	if ( '' === $local ) {
		return array();
	}

	$dir  = dirname( $local );
	$stem = nvx_image_stem_from_url( $url );
	if ( '' === $stem || ! is_dir( $dir ) || false !== strpbrk( $stem, '*?[]\\' ) ) {
		return array();
	}

	$base_url = (string) wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( $url, PHP_URL_HOST );
	$base_url = trailingslashit( $base_url . dirname( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	$out      = array();

	$files = array_merge(
		(array) glob( $dir . '/' . $stem . '-*x*.webp' ),
		(array) glob( $dir . '/' . $stem . '-*x*.jpg' ),
		(array) glob( $dir . '/' . $stem . '-*x*.jpeg' ),
		(array) glob( $dir . '/' . $stem . '-*x*.png' )
	);
	foreach ( $files as $file ) {
		if ( ! is_string( $file ) || ! preg_match( '/-(\d+)x(\d+)\.(webp|jpe?g|png)$/i', $file, $match ) ) {
			continue;
		}
		$width = (int) $match[1];
		$ext   = strtolower( (string) $match[3] );
		if ( isset( $out[ $width ] ) && 'webp' !== $ext ) {
			continue;
		}
		$out[ $width ] = $base_url . basename( $file );
	}

	// Prefer the uncropped original (stem.webp), not getimagesize() of a
	// -WIDTHxHEIGHT thumbnail that may already be $local.
	$original_found = false;
	foreach ( array( 'webp', 'jpg', 'jpeg', 'png' ) as $ext ) {
		$original_file = $dir . '/' . $stem . '.' . $ext;
		if ( ! is_readable( $original_file ) ) {
			continue;
		}
		$size = @getimagesize( $original_file );
		if ( is_array( $size ) && (int) $size[0] > 0 ) {
			$out[ (int) $size[0] ] = $base_url . basename( $original_file );
			$original_found        = true;
		}
		break;
	}

	if ( ! $original_found ) {
		$original_size = @getimagesize( $local );
		if ( is_array( $original_size ) && (int) $original_size[0] > 0 ) {
			$out[ (int) $original_size[0] ] = $url;
		}
	}

	return $out;
}

/**
 * Collect width-keyed candidates for a content image URL.
 *
 * @return array<int,string>
 */
function nvx_responsive_candidates_for_url( string $url ): array {
	$stem = nvx_image_stem_from_url( $url );
	$out  = nvx_theme_responsive_candidates( $stem );
	foreach ( nvx_upload_responsive_candidates( $url ) as $width => $candidate ) {
		if ( ! isset( $out[ $width ] ) ) {
			$out[ $width ] = $candidate;
		}
	}

	return $out;
}

/**
 * Intrinsic pixel size of known clinic/home originals (before any -WIDTHxHEIGHT crop).
 *
 * @return array<string,array{0:int,1:int}>
 */
function nvx_known_image_intrinsics(): array {
	return array(
		'Sala-Nuvanx'                                    => array( 1086, 1448 ),
		'nuvanx-medicina-2'                              => array( 1220, 960 ),
		'Endolift-ISO9001-Laser'                         => array( 850, 470 ),
		'SmartLipo-for-Laserlipolysis-DEKA-1'            => array( 447, 800 ),
		'consulta-medica-personalizada-nuvanx-madrid'    => array( 1672, 941 ),
		'nvx-fachada-goya-900'                           => array( 900, 675 ),
		'nuvanx-medicina-estetica1'                      => array( 1220, 960 ),
		'BTL-Exion-Mobile-Version-1024x956-1'            => array( 1024, 956 ),
		'endolift-lasemar-1500-eufoton'                  => array( 850, 470 ),
		'Box-Clinica-Novias'                             => array( 1024, 1536 ),
		'Brazos-novias'                                  => array( 941, 1672 ),
		'Espalda-novias'                                 => array( 941, 1672 ),
		'Papada-novias'                                  => array( 1536, 1024 ),
		'nvx-co2-hero-760'                               => array( 760, 510 ),
		'ipl-exilite-luz-pulsada'                        => array( 554, 554 ),
		'Emfusion-btl-lentigo-aranitas-vasculares-punto-de-rubi-marcas-de-acne' => array( 524, 903 ),
		'SMARTXIDE-DOT_EQUIPO-TOUCH-DEKA-LASER-CO2-FRACCIONAL' => array( 447, 1105 ),
	);
}

/**
 * Resolve width/height for a content image URL without a network fetch.
 *
 * @return array{0:int,1:int}
 */
function nvx_image_dimensions_for_url( string $url ): array {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$file = pathinfo( $path, PATHINFO_FILENAME );

	if ( preg_match( '/-(\d+)x(\d+)(?:-\d+)?$/', $file, $match ) ) {
		return array( (int) $match[1], (int) $match[2] );
	}

	$intrinsics = nvx_known_image_intrinsics();
	if ( preg_match( '/-(\d+)$/', $file, $width_match ) ) {
		$stem = (string) preg_replace( '/-\d+$/', '', $file );
		if ( isset( $intrinsics[ $stem ] ) && $intrinsics[ $stem ][0] > 0 ) {
			$width  = (int) $width_match[1];
			$height = (int) round( $intrinsics[ $stem ][1] * $width / $intrinsics[ $stem ][0] );
			return array( $width, max( 1, $height ) );
		}
	}

	$stem = nvx_image_stem_from_url( $url );
	if ( isset( $intrinsics[ $stem ] ) ) {
		return $intrinsics[ $stem ];
	}

	$local = nvx_local_upload_file_from_url( $url );
	if ( '' === $local ) {
		$theme_file = get_template_directory() . '/assets/images/responsive/' . basename( $path );
		$local      = is_readable( $theme_file ) ? $theme_file : '';
	}
	if ( '' !== $local ) {
		$size = @getimagesize( $local );
		if ( is_array( $size ) && isset( $size[0], $size[1] ) && (int) $size[0] > 0 && (int) $size[1] > 0 ) {
			return array( (int) $size[0], (int) $size[1] );
		}
	}

	return array( 0, 0 );
}

/**
 * Add srcset, sizes, dimensions and a modern src to a body/content img.
 */
function nvx_content_enhance_img_tag_attrs( string $attrs ): string {
	if ( preg_match( '/nvx-logo|nvx-home-hero|nvx-media--hero|nvx-brand-hero__media|fetchpriority/i', $attrs ) ) {
		return $attrs;
	}

	$src = nvx_html_attrs_get( $attrs, 'src' );
	if ( '' === $src ) {
		return $attrs;
	}

	$already_theme = false !== strpos( $src, '/assets/images/responsive/' );
	if ( ! $already_theme ) {
		$candidates = nvx_responsive_candidates_for_url( $src );
		if ( array() !== $candidates ) {
			ksort( $candidates, SORT_NUMERIC );
			$parts = array();
			foreach ( $candidates as $width => $candidate_url ) {
				$parts[] = $candidate_url . ' ' . $width . 'w';
			}

			$default_width = 0;
			foreach ( array_keys( $candidates ) as $width ) {
				if ( $width >= 480 ) {
					$default_width = $width;
					break;
				}
			}
			if ( 0 === $default_width ) {
				$default_width = (int) array_key_first( $candidates );
			}

			$attrs = nvx_html_attrs_set( $attrs, 'src', $candidates[ $default_width ] );
			$attrs = nvx_html_attrs_set( $attrs, 'srcset', implode( ', ', $parts ) );
			if ( '' === nvx_html_attrs_get( $attrs, 'sizes' ) ) {
				$attrs = nvx_html_attrs_set( $attrs, 'sizes', '(max-width: 680px) calc(100vw - 48px), 680px' );
			}
		}
	}

	$src = nvx_html_attrs_get( $attrs, 'src' );
	if ( '' === nvx_html_attrs_get( $attrs, 'width' ) || '' === nvx_html_attrs_get( $attrs, 'height' ) ) {
		$size = nvx_image_dimensions_for_url( $src );
		if ( $size[0] > 0 && $size[1] > 0 ) {
			$attrs = nvx_html_attrs_set( $attrs, 'width', (string) $size[0] );
			$attrs = nvx_html_attrs_set( $attrs, 'height', (string) $size[1] );
		}
	}

	if ( '' === nvx_html_attrs_get( $attrs, 'loading' ) ) {
		$attrs = nvx_html_attrs_set( $attrs, 'loading', 'lazy' );
	}
	if ( '' === nvx_html_attrs_get( $attrs, 'decoding' ) ) {
		$attrs = nvx_html_attrs_set( $attrs, 'decoding', 'async' );
	}

	return $attrs;
}

/**
 * Last-pass responsive attributes for every public content image.
 *
 * Page modules rebuild the_content after the first presentation pass; this
 * catch-all still rewrites leftover full-size uploads.
 */
function nvx_content_apply_responsive_images( string $content ): string {
	if ( is_admin() || '' === $content || false === stripos( $content, '<img' ) ) {
		return $content;
	}

	$updated = preg_replace_callback(
		'/<img\b([^>]*)>/iu',
		static function ( array $matches ): string {
			return '<img' . nvx_content_enhance_img_tag_attrs( $matches[1] ) . '>';
		},
		$content
	);

	return is_string( $updated ) ? $updated : $content;
}
add_filter( 'the_content', 'nvx_content_apply_responsive_images', 200 );

/** Build an img tag with srcset/sizes when theme or upload derivatives exist. */
function nvx_responsive_img_markup( string $src, string $alt, string $extra_attrs = '' ): string {
	$attrs = ' src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"';
	if ( '' !== trim( $extra_attrs ) ) {
		$attrs .= ' ' . trim( $extra_attrs );
	}
	$attrs = nvx_content_enhance_img_tag_attrs( $attrs );

	return '<img' . $attrs . '>';
}

/**
 * Click-to-load Google Maps embed. Avoids maps.googleapis.com until the user asks.
 */
function nvx_lazy_map_embed_markup( string $embed_src, string $title, string $modifier = '' ): string {
	$class = 'nvx-map-embed';
	if ( '' !== $modifier ) {
		$class .= ' ' . $modifier;
	}

	return '<div class="' . esc_attr( $class ) . '" data-nvx-map-src="' . esc_url( $embed_src ) . '" data-nvx-map-title="' . esc_attr( $title ) . '">'
		. '<button type="button" class="nvx-map-embed__button">' . esc_html__( 'Cargar mapa de Google', 'nuvanx-medical' ) . '</button>'
		. '</div>';
}

/** Whether a URL is a Google Maps embed (iframe payload), not a search/link. */
function nvx_is_google_maps_embed_url( string $url ): bool {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

	if ( '' === $host ) {
		return false;
	}

	$is_maps_host = str_ends_with( $host, 'google.com' ) || str_ends_with( $host, 'googleapis.com' ) || str_ends_with( $host, 'gstatic.com' );
	if ( ! $is_maps_host ) {
		return false;
	}

	return str_contains( $host, 'maps' )
		|| str_contains( $path, '/maps' )
		|| str_contains( $query, 'output=embed' );
}

/**
 * Replace eager Google Maps iframes with the click-to-load control.
 *
 * `loading="lazy"` is not enough: Lighthouse still downloads places.js / main.js
 * (~227 KiB unused) once the iframe src is in the document.
 */
function nvx_rewrite_eager_maps_iframes( string $html ): string {
	if ( '' === $html || false === stripos( $html, '<iframe' ) ) {
		return $html;
	}

	$rewritten = preg_replace_callback(
		'/<iframe\b[^>]*>\s*<\/iframe>/iu',
		static function ( array $matches ): string {
			$tag = $matches[0];
			if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/iu', $tag, $src_match ) ) {
				return $tag;
			}

			$src = html_entity_decode( (string) $src_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( ! nvx_is_google_maps_embed_url( $src ) ) {
				return $tag;
			}

			$title = 'Google Maps';
			if ( preg_match( '/\btitle\s*=\s*(["\'])(.*?)\1/iu', $tag, $title_match ) ) {
				$decoded = html_entity_decode( (string) $title_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( '' !== trim( $decoded ) ) {
					$title = $decoded;
				}
			}

			return nvx_lazy_map_embed_markup( $src, $title );
		},
		$html
	);

	return is_string( $rewritten ) ? $rewritten : $html;
}
add_filter( 'the_content', 'nvx_rewrite_eager_maps_iframes', 201 );
add_filter( 'widget_text', 'nvx_rewrite_eager_maps_iframes', 20 );
add_filter( 'widget_block_content', 'nvx_rewrite_eager_maps_iframes', 20 );
add_filter( 'embed_oembed_html', 'nvx_rewrite_eager_maps_iframes', 20 );

/**
 * Catch Maps iframes emitted by Gutenberg embeds or custom HTML blocks.
 *
 * @param string $block_content Rendered block HTML.
 */
function nvx_rewrite_eager_maps_iframes_in_block( string $block_content ): string {
	return nvx_rewrite_eager_maps_iframes( $block_content );
}
add_filter( 'render_block', 'nvx_rewrite_eager_maps_iframes_in_block', 20 );

/**
 * article cannot host role="listitem". Strip leftover invalid list ARIA so
 * cached or CMS Signature cards do not fail the agent accessibility tree.
 */
function nvx_sanitize_invalid_list_roles( string $html ): string {
	if ( '' === $html || false === stripos( $html, 'role=' ) ) {
		return $html;
	}

	// Remove role="listitem" from article elements (invalid ARIA)
	$updated = preg_replace( '/(<article\b[^>]*?)\srole=(["\'])listitem\2/iu', '$1', $html );
	$updated = is_string( $updated ) ? $updated : $html;

	// Remove role="list" from div elements (invalid ARIA)
	$updated = preg_replace( '/(<div\b[^>]*\bnvx-brand-grid\b[^>]*?)\srole=(["\'])list\2/iu', '$1', $updated );
	$updated = is_string( $updated ) ? $updated : $html;

	// Remove role="listitem" from any nvx-brand-card article (aggressive catch-all)
	$updated = preg_replace( '/(<article\b[^>]*\bnvx-brand-card\b[^>]*?)\srole=(["\'])listitem\2/iu', '$1', $updated );
	$updated = is_string( $updated ) ? $updated : $html;

	return is_string( $updated ) ? $updated : $html;
}
add_filter( 'the_content', 'nvx_sanitize_invalid_list_roles', 202 );
add_filter( 'render_block', 'nvx_sanitize_invalid_list_roles', 202 );

/**
 * Render canonical FAQ accordion section markup.
 *
 * @param array{kicker?:string,title?:string,items?:array<int,array{q:string,a:string}>} $faq FAQ data array.
 * @param string $prefix Section CSS prefix (e.g. 'nvx-co2').
 * @return string Rendered section HTML.
 */
function nvx_render_editorial_faq_markup( array $faq, string $prefix ): string {
	$kicker     = esc_html( $faq['kicker'] ?? '' );
	$title      = esc_html( $faq['title'] ?? '' );
	$title_id   = esc_attr( $prefix . '-faq-title' );
	$sec_class  = esc_attr( $prefix . '-faq' );
	$list_class = esc_attr( $prefix . '-faq-list' );

	$html  = nvx_page_brand_section_open_markup( $sec_class, $title_id );
	$html .= nvx_page_brand_section_heading_markup( $kicker, $title_id, $title );
	$html .= '<div class="nvx-faq ' . $list_class . '">';

	foreach ( $faq['items'] ?? array() as $item ) {
		$q     = esc_html( $item['q'] ?? '' );
		$a     = esc_html( $item['a'] ?? '' );
		$html .= '<details class="nvx-brand-faq-item">';
		$html .= '<summary><span>' . $q . '</span></summary>';
		$html .= '<div class="nvx-brand-faq-content"><p>' . $a . '</p></div>';
		$html .= '</details>';
	}

	$html .= '</div></div></section>';
	return $html;
}

/**
 * Render canonical fact panel sidebar component markup.
 *
 * @param array{panel_title?:string,panel_items?:array<int,array{title:string,body:string}>} $diagnosis Diagnosis data.
 * @param string $aria_label Panel accessible label.
 * @return string Rendered sidebar HTML.
 */
function nvx_render_editorial_fact_panel_markup( array $diagnosis, string $aria_label = 'Criterio de diagnóstico' ): string {
	$title = esc_html( $diagnosis['panel_title'] ?? '' );
	$html  = '<aside class="nvx-fact-panel" aria-label="' . esc_attr( $aria_label ) . '">';
	$html .= '<p class="nvx-fact-panel__label">' . $title . '</p>';
	$html .= '<ul class="nvx-fact-panel__list" role="list">';

	foreach ( $diagnosis['panel_items'] ?? array() as $item ) {
		$t     = esc_html( $item['title'] ?? '' );
		$b     = esc_html( $item['body'] ?? '' );
		$html .= '<li><strong>' . $t . '</strong> — ' . $b . '</li>';
	}

	$html .= '</ul></aside>';
	return $html;
}

/**
 * Render canonical process steps grid section markup.
 *
 * @param array{kicker?:string,title?:string,body?:string,steps?:array<int,array{n?:string,title?:string,body?:string,icon?:string}>} $process Process data.
 * @param string $prefix Section CSS prefix (e.g. 'nvx-co2').
 * @param callable $icon_cb Callback function that takes icon name and returns SVG markup.
 * @return string Rendered section HTML.
 */
function nvx_render_editorial_process_grid_markup( array $process, string $prefix, callable $icon_cb ): string {
	$title_id = esc_attr( $prefix . '-process-title' );
	$sec_cls  = esc_attr( $prefix . '-process' );
	$grid_cls = esc_attr( $prefix . '-process-grid' );

	$html  = nvx_page_brand_section_open_markup( $sec_cls, $title_id );
	$html .= nvx_page_brand_section_heading_markup( esc_html( $process['kicker'] ?? '' ), $title_id, esc_html( $process['title'] ?? '' ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $process['body'] ?? '' ) . '</p>';
	$html .= '<div class="' . $grid_cls . '">';

	$step_idx = 0;
	foreach ( $process['steps'] ?? array() as $step ) {
		$sid   = esc_attr( $prefix . '-step-' . $step_idx );
		$icon  = is_callable( $icon_cb ) ? $icon_cb( $step['icon'] ?? 'assess' ) : '';
		$html .= '<article class="' . esc_attr( $prefix . '-step' ) . '" aria-labelledby="' . $sid . '">';
		$html .= $icon;
		$html .= '<span class="' . esc_attr( $prefix . '-step__n' ) . '">' . esc_html( $step['n'] ?? '' ) . '</span>';
		$html .= '<h3 id="' . $sid . '" class="' . esc_attr( $prefix . '-step__title' ) . '">' . esc_html( $step['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $step['body'] ?? '' ) . '</p>';
		$html .= '</article>';
		++$step_idx;
	}

	$html .= '</div></div></section>';
	return $html;
}

/**
 * Render a complete, canonical editorial treatment page body from JSON schema data.
 *
 * Handles all 8 standard sections: What, Diagnosis (+Fact Panel), Compare Table,
 * Biophysics/Tech, Process Grid, Postop, Investment, and FAQ.
 *
 * @param array<string, mixed> $data Treatment page data array.
 * @param string $prefix Section CSS class prefix (e.g. 'nvx-co2').
 * @param callable $icon_cb Icon renderer callback.
 * @return string Rendered HTML markup.
 */
function nvx_render_generic_brand_treatment_page_body( array $data, string $prefix, callable $icon_cb ): string {
	$html = '<div class="nvx-brand-page-body">';

	// A. What / Intro
	if ( ! empty( $data['what'] ) ) {
		$html .= nvx_page_brand_section_open_markup( $prefix . '-what', $prefix . '-what-title' );
		$html .= nvx_page_brand_section_heading_markup( esc_html( $data['what']['kicker'] ?? '' ), $prefix . '-what-title', esc_html( $data['what']['title'] ?? '' ) );
		foreach ( (array) ( $data['what']['body'] ?? array() ) as $paragraph ) {
			$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( (string) $paragraph ) . '</p>';
		}
		$html .= '</div></section>';
	}

	// B. Diagnosis + Fact Panel
	if ( ! empty( $data['diagnosis'] ) ) {
		$html .= nvx_page_brand_section_open_markup( $prefix . '-diagnosis', $prefix . '-diagnosis-title', $prefix . '-diagnosis__grid' );
		$html .= '<div class="' . esc_attr( $prefix ) . '-diagnosis__copy">';
		$html .= nvx_page_brand_section_heading_markup( esc_html( $data['diagnosis']['kicker'] ?? '' ), $prefix . '-diagnosis-title', esc_html( $data['diagnosis']['title'] ?? '' ) );
		foreach ( (array) ( $data['diagnosis']['body'] ?? array() ) as $paragraph ) {
			$html .= '<p class="nvx-body">' . esc_html( (string) $paragraph ) . '</p>';
		}
		$html .= '</div>';
		$html .= nvx_render_editorial_fact_panel_markup( (array) $data['diagnosis'] );
		$html .= '</div></section>';
	}

	// C. Comparison Table
	if ( ! empty( $data['compare'] ) ) {
		$rows     = (array) ( $data['compare']['rows'] ?? array() );
		$first    = reset( $rows );
		$col_keys = is_array( $first ) ? array_keys( array_filter( $first, static function ( $k ) { return 'param' !== $k; }, ARRAY_FILTER_USE_KEY ) ) : array();

		$html .= nvx_page_brand_section_open_markup( $prefix . '-compare', $prefix . '-compare-title' );
		$html .= nvx_page_brand_section_heading_markup( esc_html( $data['compare']['kicker'] ?? '' ), $prefix . '-compare-title', esc_html( $data['compare']['title'] ?? '' ) );
		$wrapper_label = ! empty( $data['compare']['title'] ) ? esc_attr( $data['compare']['title'] ) : esc_attr__( 'Tabla comparativa', 'nuvanx-medical' );
		$html .= '<div class="' . esc_attr( $prefix ) . '-compare-wrap" role="region" tabindex="0" aria-label="' . $wrapper_label . '">';
		$html .= '<table class="' . esc_attr( $prefix ) . '-compare-table">';
		$html .= '<caption>' . ( ! empty( $data['compare']['title'] ) ? esc_html( $data['compare']['title'] ) : esc_html__( 'Comparativa', 'nuvanx-medical' ) ) . '</caption>';
		$html .= '<thead><tr>';
		$html .= '<th scope="col">' . esc_html( $data['compare']['col_param'] ?? '' ) . '</th>';
		foreach ( $col_keys as $ckey ) {
			$html .= '<th scope="col">' . esc_html( $data['compare'][ 'col_' . $ckey ] ?? '' ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			$html .= '<th scope="row">' . esc_html( $row['param'] ?? '' ) . '</th>';
			foreach ( $col_keys as $ckey ) {
				$label = isset( $data['compare'][ 'col_' . $ckey ] ) ? $data['compare'][ 'col_' . $ckey ] : '';
				$html .= '<td data-label="' . esc_attr( $label ) . '">' . esc_html( $row[ $ckey ] ?? '' ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div></div></section>';
	}

	// D. Biophysics / Technology
	if ( ! empty( $data['biophysics'] ) ) {
		$html .= nvx_page_brand_section_open_markup( $prefix . '-biophysics', $prefix . '-bio-title' );
		$html .= nvx_page_brand_section_heading_markup( esc_html( $data['biophysics']['kicker'] ?? '' ), $prefix . '-bio-title', esc_html( $data['biophysics']['title'] ?? '' ) );
		if ( ! empty( $data['biophysics']['body1'] ) ) {
			$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( (string) $data['biophysics']['body1'] ) . '</p>';
		}
		if ( ! empty( $data['biophysics']['caption'] ) ) {
			$html .= '<p class="nvx-body nvx-body--measure"><em>' . esc_html( (string) $data['biophysics']['caption'] ) . '</em></p>';
		}
		if ( ! empty( $data['biophysics']['body2'] ) ) {
			$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( (string) $data['biophysics']['body2'] ) . '</p>';
		}
		$html .= '</div></section>';
	}

	// E. Process Grid
	if ( ! empty( $data['process'] ) ) {
		$html .= nvx_render_editorial_process_grid_markup( (array) $data['process'], $prefix, $icon_cb );
	}

	// F. Postop / Recovery
	if ( ! empty( $data['postop'] ) ) {
		$slug_suffix = str_replace( 'nvx-', '', $prefix );
		$html       .= nvx_page_brand_section_open_markup( $prefix . '-postop', $prefix . '-postop-title', '', array( 'id' => 'postoperatorio-' . $slug_suffix ) );
		$html       .= nvx_page_brand_section_heading_markup( esc_html( $data['postop']['kicker'] ?? '' ), $prefix . '-postop-title', esc_html( $data['postop']['title'] ?? '' ) );
		$html       .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['postop']['body'] ?? '' ) . '</p>';
		$html       .= '<ul class="' . esc_attr( $prefix ) . '-postop-list" role="list">';
		foreach ( (array) ( $data['postop']['items'] ?? array() ) as $item ) {
			$html .= '<li><strong>' . esc_html( $item['title'] ?? '' ) . '</strong> ' . esc_html( $item['body'] ?? '' ) . '</li>';
		}
		$html .= '</ul>';
		if ( ! empty( $data['postop']['note'] ) ) {
			$html .= '<p class="nvx-body nvx-body--measure"><em>' . esc_html( (string) $data['postop']['note'] ) . '</em></p>';
		}
		$html .= '</div></section>';
	}

	// G. Investment / Pricing
	if ( ! empty( $data['investment'] ) ) {
		$slug_suffix = str_replace( 'nvx-', '', $prefix );
		$html       .= nvx_page_brand_section_open_markup( $prefix . '-investment', $prefix . '-price-title', '', array( 'id' => 'inversion-' . $slug_suffix ) );
		$html       .= nvx_page_brand_section_heading_markup( esc_html( $data['investment']['kicker'] ?? '' ), $prefix . '-price-title', esc_html( $data['investment']['title'] ?? '' ) );
		$html       .= '<p class="nvx-body nvx-body--measure">' . esc_html( $data['investment']['body'] ?? '' ) . '</p>';
		$html       .= '<ul class="' . esc_attr( $prefix ) . '-price-includes" role="list">';
		foreach ( (array) ( $data['investment']['items'] ?? array() ) as $item ) {
			$html .= '<li>' . esc_html( (string) $item ) . '</li>';
		}
		$html .= '</ul>';
		if ( ! empty( $data['investment']['note'] ) ) {
			$html .= '<p class="nvx-body nvx-body--measure"><em>' . esc_html( (string) $data['investment']['note'] ) . '</em></p>';
		}
		$html .= '</div></section>';
	}

	// H. FAQ
	if ( ! empty( $data['faq'] ) ) {
		$html .= nvx_render_editorial_faq_markup( (array) $data['faq'], $prefix );
	}

	$html .= '</div>';
	return $html;
}

/** Visible medical-review month used on competitive treatment landings. */
function nvx_clinical_review_month_label(): string {
	return 'agosto 2026';
}

/**
 * Canonical PVP from tariff-catalog.json.
 */
function nvx_tariff_pvp( string $group, string $key ): ?float {
	if ( ! function_exists( 'nvx_tariff_catalog' ) ) {
		return null;
	}
	$catalog = nvx_tariff_catalog();
	if ( isset( $catalog[ $group ][ $key ]['pvp'] ) && is_numeric( $catalog[ $group ][ $key ]['pvp'] ) ) {
		return (float) $catalog[ $group ][ $key ]['pvp'];
	}
	return null;
}

/** Formatted euro label for one tariff key, or an empty string. */
function nvx_tariff_price_label( string $group, string $key ): string {
	$pvp = nvx_tariff_pvp( $group, $key );
	if ( null === $pvp ) {
		return '';
	}
	if ( function_exists( 'nvx_format_price_eur' ) ) {
		return nvx_format_price_eur( $pvp ) . ' €';
	}
	return number_format_i18n( $pvp, 2 ) . ' €';
}

/**
 * Visible YMYL signature: name, specialty, colegiado, review month.
 */
function nvx_clinical_authority_byline_markup( string $review_label = '' ): string {
	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$label     = '' !== $review_label ? $review_label : nvx_clinical_review_month_label();

	return '<p class="nvx-medical-review">' . esc_html(
		sprintf(
			/* translators: 1: ICOMEM license number, 2: review month label */
			__( 'Revisado por Dr. Javier Rivera Tejeda, médico estético, %2$s. Nº Col. ICOMEM %1$s.', 'nuvanx-medical' ),
			$colegiado,
			$label
		)
	) . '</p>';
}

/**
 * Guarantee the visible Rivera signature on gated treatment landings.
 *
 * Endoláser clinical files cannot be edited without an APPROVED fingerprint.
 * The renderer byline is also stripped by nvx_medical_review when post meta
 * is not approved. This late pass restores name + specialty + colegiado.
 */
function nvx_inject_clinical_authority_byline( string $content ): string {
	if ( is_admin() || wp_doing_ajax() || is_feed() || ! is_singular( 'page' ) ) {
		return $content;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';
	if ( ! is_string( $path ) || false === strpos( $path, 'endolaser-corporal' ) ) {
		return $content;
	}

	if ( false !== strpos( $content, 'ICOMEM' ) || false !== strpos( $content, 'nvx-medical-review' ) ) {
		return $content;
	}

	$byline  = nvx_clinical_authority_byline_markup();
	$updated = preg_replace( '/(<h1\b[^>]*>[\s\S]*?<\/h1>)/iu', '$1' . $byline, $content, 1 );

	return is_string( $updated ) ? $updated : $content;
}
add_filter( 'the_content', 'nvx_inject_clinical_authority_byline', NVX_HOOK_PRIO_MEDICAL_REVIEW + 1 );

/**
 * Injects physician portrait authority card and clinical cases preview onto core treatment landing pages.
 */
function nvx_inject_treatment_authority_and_cases( string $content ): string {
	if ( is_admin() || wp_doing_ajax() || is_feed() || ( ! is_singular( 'page' ) && ! is_page() ) ) {
		return $content;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( ! is_string( $path ) ) {
		return $content;
	}

	// 1. Endoláser Corporal
	if ( false !== strpos( $path, 'endolaser-corporal' ) ) {
		$extra = '';
		if ( false === strpos( $content, 'nvx-treatment-cases' ) && function_exists( 'nvx_treatment_cases_preview_markup' ) ) {
			$extra .= nvx_treatment_cases_preview_markup(
				array( 'caso-03-abdomen-firmeza', 'caso-04-flancos-contorno', 'caso-05-brazos-firmeza' ),
				__( 'Evidencia Gráfica Documentada', 'nuvanx-medical' ),
				__( 'Casos Clínicos de Endoláser Corporal', 'nuvanx-medical' )
			);
		}
		if ( false === strpos( $content, 'nvx-treatment-physician' ) && function_exists( 'nvx_treatment_physician_author_markup' ) ) {
			$extra .= nvx_treatment_physician_author_markup( 'Endoláser Corporal' );
		}

		if ( '' !== $extra ) {
			if ( preg_match( '/(<section\b[^>]*\bnvx-endolaser-faq\b[^>]*>)/iu', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$offset  = (int) $matches[0][1];
				$content = substr( $content, 0, $offset ) . $extra . substr( $content, $offset );
			} else {
				$content .= $extra;
			}
		}
	}

	// 2. Láser CO₂ Fraccionado
	if ( false !== strpos( $path, 'laser-co2-fraccionado' ) || false !== strpos( $path, 'resurfacing-laser-co2' ) ) {
		if ( false === strpos( $content, 'nvx-treatment-physician' ) && function_exists( 'nvx_treatment_physician_author_markup' ) ) {
			$extra = nvx_treatment_physician_author_markup( 'Láser CO₂ Fraccionado' );
			if ( preg_match( '/(<section\b[^>]*\bnvx-co2-faq\b[^>]*>)/iu', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$offset  = (int) $matches[0][1];
				$content = substr( $content, 0, $offset ) . $extra . substr( $content, $offset );
			} elseif ( preg_match( '/(<div\b[^>]*\bnvx-faq\b[^>]*>)/iu', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$offset  = (int) $matches[0][1];
				$content = substr( $content, 0, $offset ) . $extra . substr( $content, $offset );
			} else {
				$content .= $extra;
			}
		}
	}

	return $content;
}
add_filter( 'the_content', 'nvx_inject_treatment_authority_and_cases', NVX_HOOK_PRIO_MEDICAL_REVIEW + 2 );


/**
 * Recovery table: moment / what to expect / return to activity.
 *
 * @param array<int,array{when?:string,expect?:string,activity?:string}> $rows
 */
function nvx_recovery_table_markup( array $rows, string $caption = '' ): string {
	if ( array() === $rows ) {
		return '';
	}

	$html  = '<div class="nvx-recovery-table-wrap" role="region" tabindex="0" aria-label="' . esc_attr( $caption !== '' ? $caption : __( 'Tabla de recuperación', 'nuvanx-medical' ) ) . '">';
	$html .= '<table class="nvx-recovery-table">';
	if ( '' !== $caption ) {
		$html .= '<caption>' . esc_html( $caption ) . '</caption>';
	}
	$html .= '<thead><tr>';
	$html .= '<th scope="col">' . esc_html__( 'Momento', 'nuvanx-medical' ) . '</th>';
	$html .= '<th scope="col">' . esc_html__( 'Qué esperar', 'nuvanx-medical' ) . '</th>';
	$html .= '<th scope="col">' . esc_html__( 'Actividad', 'nuvanx-medical' ) . '</th>';
	$html .= '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$html .= '<tr>';
		$html .= '<th scope="row">' . esc_html( (string) ( $row['when'] ?? '' ) ) . '</th>';
		$html .= '<td data-label="' . esc_attr__( 'Qué esperar', 'nuvanx-medical' ) . '">' . esc_html( (string) ( $row['expect'] ?? '' ) ) . '</td>';
		$html .= '<td data-label="' . esc_attr__( 'Actividad', 'nuvanx-medical' ) . '">' . esc_html( (string) ( $row['activity'] ?? '' ) ) . '</td>';
		$html .= '</tr>';
	}
	$html .= '</tbody></table></div>';
	return $html;
}

/**
 * Explicit candidacy: who is / is not a candidate.
 *
 * @param array<int,string> $yes
 * @param array<int,string> $no
 */
function nvx_candidacy_markup( array $yes, array $no ): string {
	if ( array() === $yes && array() === $no ) {
		return '';
	}

	$html = '<div class="nvx-candidacy-grid">';
	if ( array() !== $yes ) {
		$html .= '<div class="nvx-candidacy-col nvx-candidacy-col--yes">';
		$html .= '<h3 class="nvx-candidacy-title">' . esc_html__( 'Buen candidato', 'nuvanx-medical' ) . '</h3><ul>';
		foreach ( $yes as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
		}
		$html .= '</ul></div>';
	}
	if ( array() !== $no ) {
		$html .= '<div class="nvx-candidacy-col nvx-candidacy-col--no">';
		$html .= '<h3 class="nvx-candidacy-title">' . esc_html__( 'No está indicado', 'nuvanx-medical' ) . '</h3><ul>';
		foreach ( $no as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
		}
		$html .= '</ul></div>';
	}
	$html .= '</div>';
	return $html;
}

/**
 * FAQ with the first sentence as the direct answer (AEO / AI citation pattern).
 *
 * @param array<int,array{q?:string,a?:string}> $faqs
 */
function nvx_faq_direct_answer_markup( array $faqs, string $list_class = '' ): string {
	$classes = trim( 'nvx-faq ' . $list_class );
	$html    = '<div class="' . esc_attr( $classes ) . '">';
	foreach ( $faqs as $faq ) {
		if ( ! is_array( $faq ) ) {
			continue;
		}
		$q = trim( (string) ( $faq['q'] ?? '' ) );
		$a = trim( (string) ( $faq['a'] ?? '' ) );
		if ( '' === $q || '' === $a ) {
			continue;
		}
		$parts  = preg_split( '/(?<=[.!?])\s+/u', $a, 2 );
		$direct = is_array( $parts ) && isset( $parts[0] ) ? (string) $parts[0] : $a;
		$rest   = is_array( $parts ) && isset( $parts[1] ) ? (string) $parts[1] : '';

		$html .= '<details class="nvx-brand-faq-item">';
		$html .= '<summary><span>' . esc_html( $q ) . '</span></summary>';
		$html .= '<div class="nvx-brand-faq-content">';
		$html .= '<p class="nvx-faq-direct">' . esc_html( $direct ) . '</p>';
		if ( '' !== $rest ) {
			$html .= '<p>' . esc_html( $rest ) . '</p>';
		}
		$html .= '</div></details>';
	}
	$html .= '</div>';
	return $html;
}

/**
 * Renders the visible E-E-A-T Medical Director / Treating Physician authority card for treatment landing pages.
 *
 * @param string $treatment_context Optional treatment context name.
 */
function nvx_treatment_physician_author_markup( string $treatment_context = '' ): string {
	$director_photo = get_template_directory_uri() . '/assets/images/team/nvx-dr-javier-rivera-director-medico.webp';
	$colegiado      = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$doctoralia     = function_exists( 'nvx_medical_staff_doctoralia_url' ) ? nvx_medical_staff_doctoralia_url( 'director' ) : '';
	$equipo_url     = home_url( '/equipo-medico/#physician-rivera-tejeda' );

	$html  = '<section class="nvx-brand-section nvx-treatment-physician" aria-labelledby="nvx-physician-title">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= '<div class="nvx-treatment-physician__card" itemscope itemtype="https://schema.org/Physician">';
	$html .= '<meta itemprop="url" content="' . esc_url( $equipo_url ) . '" />';
	$html .= '<div class="nvx-treatment-physician__portrait">';
	$html .= '<img src="' . esc_url( $director_photo ) . '" alt="' . esc_attr__( 'Dr. José Javier Rivera Tejeda — Director Médico NUVANX', 'nuvanx-medical' ) . '" class="nvx-treatment-physician__img" width="140" height="140" loading="lazy" decoding="async" itemprop="image" />';
	$html .= '</div>';
	$html .= '<div class="nvx-treatment-physician__info">';
	$html .= '<div class="nvx-treatment-physician__kicker">' . esc_html__( 'Dirección Médica & Responsable Clínico', 'nuvanx-medical' ) . '</div>';
	$html .= '<h3 id="nvx-physician-title" class="nvx-treatment-physician__name" itemprop="name">' . esc_html__( 'Dr. José Javier Rivera Tejeda', 'nuvanx-medical' ) . '</h3>';
	$html .= '<div class="nvx-treatment-physician__role" itemprop="jobTitle">' . esc_html__( 'Director Médico · Especialista en Medicina Estética Láser Avanzada', 'nuvanx-medical' ) . '</div>';
	$html .= '<div class="nvx-treatment-physician__meta">';
	$html .= '<span class="nvx-treatment-physician__badge nvx-treatment-physician__badge--accent">' . esc_html__( 'Colegiado ICOMEM Nº ', 'nuvanx-medical' ) . esc_html( $colegiado ) . '</span>';
	$html .= '<span class="nvx-treatment-physician__badge">' . esc_html__( 'Máster Medicina Estética UCM', 'nuvanx-medical' ) . '</span>';
	$html .= '<span class="nvx-treatment-physician__badge">' . esc_html__( 'Máster Tricología AMIR', 'nuvanx-medical' ) . '</span>';
	$html .= '<span class="nvx-treatment-physician__badge">' . esc_html__( '+17 años de práctica clínica', 'nuvanx-medical' ) . '</span>';
	if ( '' !== $doctoralia ) {
		$html .= '<a href="' . esc_url( $doctoralia ) . '" target="_blank" rel="noopener noreferrer" class="nvx-treatment-physician__badge nvx-text-link" itemprop="sameAs">' . esc_html__( 'Doctoralia (166 opiniones)', 'nuvanx-medical' ) . '</a>';
	}
	$html .= '</div>';
	$html .= '<p class="nvx-treatment-physician__quote">«' . esc_html__( 'El diagnóstico tisular y la indicación anatómica mandan sobre la tecnología. Cada procedimiento en NUVANX es evaluado, planificado y ejecutado por médicos especialistas con tecnología médica certificada.', 'nuvanx-medical' ) . '»</p>';
	$html .= '</div>';
	$html .= '</div>';
	$html .= '</div></section>';

	return $html;
}

/**
 * Renders a curated clinical cases preview gallery for treatment landing pages.
 *
 * @param array<int,string> $case_ids Array of case IDs to display.
 * @param string            $kicker   Optional section kicker.
 * @param string            $title    Optional section title.
 */
function nvx_treatment_cases_preview_markup( array $case_ids, string $kicker = '', string $title = '' ): string {
	$cases_file = __DIR__ . '/data/patient-cases.json';
	if ( ! file_exists( $cases_file ) ) {
		return '';
	}

	$cases_data = json_decode( (string) file_get_contents( $cases_file ), true );
	if ( ! is_array( $cases_data ) || empty( $cases_data['cases'] ) || ! is_array( $cases_data['cases'] ) ) {
		return '';
	}

	$indexed_cases = array();
	foreach ( $cases_data['cases'] as $c ) {
		if ( ! empty( $c['id'] ) ) {
			$indexed_cases[ $c['id'] ] = $c;
		}
	}

	$matched_cases = array();
	foreach ( $case_ids as $id ) {
		if ( isset( $indexed_cases[ $id ] ) ) {
			$matched_cases[] = $indexed_cases[ $id ];
		}
	}

	if ( empty( $matched_cases ) ) {
		return '';
	}

	$kicker = '' !== $kicker ? $kicker : __( 'Evidencia Gráfica Documentada', 'nuvanx-medical' );
	$title  = '' !== $title ? $title : __( 'Resultados y Evolución Clínica en Consulta', 'nuvanx-medical' );
	$hub_url = home_url( '/casos-de-pacientes/' );

	$html  = '<section class="nvx-brand-section nvx-treatment-cases" aria-labelledby="nvx-cases-preview-title">';
	$html .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html .= nvx_page_brand_section_heading_markup( esc_html( $kicker ), 'nvx-cases-preview-title', esc_html( $title ) );
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Registros fotográficos clínicos protocolizados bajo las mismas condiciones de luz y plano anatómico. Casos reales tratados por nuestro equipo médico con consentimiento informado documentado.', 'nuvanx-medical' ) . '</p>';

	$html .= '<ul class="nvx-cases-grid" role="list">';
	foreach ( $matched_cases as $case ) {
		$before_src = ! empty( $case['image_before'] ) ? get_template_directory_uri() . '/' . ltrim( (string) $case['image_before'], '/' ) : '';
		$after_src  = ! empty( $case['image_after'] ) ? get_template_directory_uri() . '/' . ltrim( (string) $case['image_after'], '/' ) : '';
		$case_title = $case['title'] ?? __( 'Caso clínico documentado', 'nuvanx-medical' );

		$html .= '<li class="nvx-case-card">';
		$html .= '<div class="nvx-case-card__header">';
		$html .= '<span class="nvx-case-card__badge">' . esc_html( $case['category_label'] ?? '' ) . '</span>';
		$html .= '<h3 class="nvx-case-card__title">' . esc_html( $case_title ) . '</h3>';
		$html .= '</div>';

		if ( '' !== $before_src && '' !== $after_src ) {
			$html .= '<div class="nvx-case-card__visual">';
			$html .= '<div class="nvx-case-card__gallery">';
			$html .= '<figure class="nvx-case-card__gallery-item">';
			$html .= '<span class="nvx-case-card__gallery-label">' . esc_html__( 'Antes', 'nuvanx-medical' ) . '</span>';
			$html .= '<img src="' . esc_url( $before_src ) . '" alt="' . esc_attr( $case_title . ' — Antes' ) . '" class="nvx-case-card__img" width="900" height="600" loading="lazy" decoding="async" />';
			$html .= '</figure>';
			$html .= '<figure class="nvx-case-card__gallery-item">';
			$html .= '<span class="nvx-case-card__gallery-label">' . esc_html__( 'Después', 'nuvanx-medical' ) . '</span>';
			$html .= '<img src="' . esc_url( $after_src ) . '" alt="' . esc_attr( $case_title . ' — Después' ) . '" class="nvx-case-card__img" width="900" height="600" loading="lazy" decoding="async" />';
			$html .= '</figure>';
			$html .= '</div>';
			$html .= '<div class="nvx-case-card__visual-caption">';
			$html .= '<span class="nvx-case-card__visual-badge">' . esc_html__( 'Seguimiento: ', 'nuvanx-medical' ) . esc_html( $case['followup'] ?? '' ) . '</span>';
			$html .= '<span class="nvx-case-card__visual-consent">' . esc_html( $case['technique'] ?? '' ) . '</span>';
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '<div class="nvx-case-card__notes-box">';
		$html .= '<p class="nvx-case-card__notes-body">' . esc_html( $case['clinical_notes'] ?? '' ) . '</p>';
		$html .= '</div>';
		$html .= '</li>';
	}
	$html .= '</ul>';

	$html .= '<div class="nvx-treatment-cases__cta">';
	$html .= '<a href="' . esc_url( $hub_url ) . '" class="nvx-brand-btn nvx-btn--secondary">' . esc_html__( 'Ver galería completa de casos clínicos', 'nuvanx-medical' ) . '</a>';
	$html .= '</div>';

	$html .= '</div></section>';

	return $html;
}

