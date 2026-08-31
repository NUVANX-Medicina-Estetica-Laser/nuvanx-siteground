<?php
/**
 * Hero media injection, valoración form order/stage (content filters).
 * Kept out of functions.php to satisfy CSS Gate inventory rules.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Featured-image figure markup for hero injection, or empty when unusable.
 */
function nvx_hero_featured_media_figure(): string {
	$thumb = get_the_post_thumbnail(
		null,
		'full',
		array(
			'class'         => 'nvx-media nvx-media--hero',
			'loading'       => 'eager',
			'fetchpriority' => 'high',
			'alt'           => the_title_attribute( array( 'echo' => false ) ),
		)
	);

	if ( ! $thumb ) {
		return '';
	}

	// Never inject site logo / brand mark as hero photography.
	if ( preg_match( '/logo-nuvanx|nuvanx-web\.webp|\/logo[-_]|nvx-logo|site-logo|custom-logo/iu', $thumb ) ) {
		return '';
	}

	if ( function_exists( 'nvx_public_html_is_vendor_image' ) && nvx_public_html_is_vendor_image( $thumb ) ) {
		return '';
	}

	return '<figure class="nvx-brand-hero__media">' . $thumb . '</figure>';
}

/**
 * Find the end offset of a balanced <div> opened at $open_pos.
 *
 * @return int|null Offset after the matching closing tag.
 */
function nvx_hero_find_balanced_div_end( string $content, int $open_pos ): ?int {
	$len   = strlen( $content );
	$i     = $open_pos;
	$depth = 0;

	while ( $i < $len ) {
		if ( ! preg_match( '/<\/?div\b[^>]*>/i', $content, $tag_match, PREG_OFFSET_CAPTURE, $i ) ) {
			break;
		}
		$tag_pos = (int) $tag_match[0][1];
		$tag     = $tag_match[0][0];
		if ( $tag_pos > $i && 0 === $depth && $i !== $open_pos ) {
			break;
		}
		$i = $tag_pos;
		if ( 0 === strncasecmp( $tag, '</div', 5 ) ) {
			--$depth;
			$i += strlen( $tag );
			if ( 0 === $depth ) {
				return $i;
			}
			continue;
		}
		// Opening div (ignore malformed self-closing).
		++$depth;
		$i += strlen( $tag );
	}

	return null;
}

/**

/**
 * Ensure content heroes that lack media use the featured image when available.
 * Inserts media as a SIBLING after the hero copy (never nested inside copy),
 * so kicker + title can overlay the image. Global — not a per-page patch.
 */
function nvx_ensure_hero_featured_media( string $content ): string {
	if ( is_admin() || ! is_singular() || is_front_page() || ! has_post_thumbnail() ) {
		return $content;
	}

	// Skip for pages using nvx-clinics-hub (custom hero injection)
	// Detect by checking if content has clinic-hub-specific markers
	if ( function_exists( 'nvx_clinics_hub_page_markup' ) && has_shortcode( $content, 'nvx_clinics_hub' ) ) {
		return $content;
	}

	// Skip if global flag is set (nvx-page-shell with hero media)
	global $nvx_page_shell_has_hero;
	if ( isset( $nvx_page_shell_has_hero ) && $nvx_page_shell_has_hero ) {
		return $content;
	}

	// Content already owns a media rail inside the hero.
	if ( preg_match( '/nvx-(?:brand-hero|editorial-hero|page-hero|hero)__media/i', $content ) ) {
		return $content;
	}

	// Only inject into known hero containers.
	if ( ! preg_match( '/nvx-(?:brand-hero|editorial-hero|page-hero)|\bclass="[^"]*\bnvx-hero\b/i', $content ) ) {
		return $content;
	}

	$figure = nvx_hero_featured_media_figure();
	if ( '' === $figure ) {
		return $content;
	}

	return nvx_hero_insert_media_figure( $content, $figure );
}
add_filter( 'the_content', 'nvx_ensure_hero_featured_media', NVX_HOOK_PRIO_HERO_MEDIA );

/**
 * Extract a balanced HTML element starting at $open_pos (must point at "<tag").
 *
 * @return string|null Full element markup including open/close tags.
 */
function nvx_extract_balanced_element( string $html, int $open_pos, string $tag ): ?string {
	$tag  = strtolower( $tag );
	$len  = strlen( $html );
	$open = '<' . $tag;
	if ( $open_pos < 0 || $open_pos >= $len || 0 !== strncasecmp( substr( $html, $open_pos, strlen( $open ) ), $open, strlen( $open ) ) ) {
		return null;
	}

	$depth   = 0;
	$i       = $open_pos;
	$pattern = '/<\/?' . preg_quote( $tag, '/' ) . '\b[^>]*>/i';

	while ( $i < $len ) {
		if ( ! preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $i ) ) {
			return null;
		}
		$tag_pos = (int) $m[0][1];
		$el      = $m[0][0];
		$i       = $tag_pos;
		if ( 0 === strncasecmp( $el, '</', 2 ) ) {
			--$depth;
			$i += strlen( $el );
			if ( 0 === $depth ) {
				return substr( $html, $open_pos, $i - $open_pos );
			}
			continue;
		}
		// Self-closing section is rare; treat as open.
		if ( preg_match( '/\/>\s*$/', $el ) ) {
			$i += strlen( $el );
			if ( 0 === $depth ) {
				return substr( $html, $open_pos, $i - $open_pos );
			}
			continue;
		}
		++$depth;
		$i += strlen( $el );
	}

	return null;
}

/**
 * Landing valoración: form is the first content after the hero.
 */
function nvx_theme_is_valoracion_landing(): bool {
	if ( ! is_page() ) {
		return false;
	}
	if ( 'templates/page-landing-valoracion.php' === (string) get_page_template_slug() ) {
		return true;
	}
	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	return 'valoracion' === $slug;
}

/**
 * Move #nvx-hubspot-form section to sit immediately after the page hero.
 */
function nvx_valoracion_form_first( string $content ): string {
	if ( is_admin() || ! nvx_theme_is_valoracion_landing() ) {
		return $content;
	}

	if ( ! preg_match( '/<section\b[^>]*(?:\bid=["\']nvx-hubspot-form["\']|class=["\'][^"\']*nvx-hubspot-form-section[^"\']*["\'])[^>]*>/i', $content, $match, PREG_OFFSET_CAPTURE ) ) {
		return $content;
	}

	$form_start = (int) $match[0][1];
	$form       = nvx_extract_balanced_element( $content, $form_start, 'section' );
	if ( ! is_string( $form ) || $form === '' ) {
		return $content;
	}

	// Already first body block after hero? Detect adjacency.
	$without = substr( $content, 0, $form_start ) . substr( $content, $form_start + strlen( $form ) );

	if ( ! preg_match( '/<section\b[^>]*class=["\'][^"\']*nvx-(?:hero|page-hero|brand-hero)[^"\']*["\'][^>]*>/i', $without, $hero_match, PREG_OFFSET_CAPTURE ) ) {
		// No hero: put form first inside main page wrapper if present.
		if ( preg_match( '/id=["\']nvx-valoracion-main["\'][^>]*>/i', $without, $wrap, PREG_OFFSET_CAPTURE ) ) {
			$pos = (int) $wrap[0][1] + strlen( $wrap[0][0] );
			return substr( $without, 0, $pos ) . $form . substr( $without, $pos );
		}
		return $form . $without;
	}

	$hero_start = (int) $hero_match[0][1];
	$hero       = nvx_extract_balanced_element( $without, $hero_start, 'section' );
	if ( ! is_string( $hero ) || $hero === '' ) {
		return $content;
	}

	$hero_end = $hero_start + strlen( $hero );
	// Skip optional whitespace / injected media siblings already inside hero.
	return substr( $without, 0, $hero_end ) . $form . substr( $without, $hero_end );
}
add_filter( 'the_content', 'nvx_valoracion_form_first', NVX_HOOK_PRIO_VALORACION_FORM_FIRST );

/**
 * Valoración form stage: use featured/header image as section atmosphere.
 */
function nvx_valoracion_form_stage_image_css(): void {
	if ( ! nvx_theme_is_valoracion_landing() ) {
		return;
	}

	$image_url = get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
	$readable  = is_string( $image_url ) && '' !== $image_url
		&& ( ! function_exists( 'nvx_public_media_upload_url_is_readable' ) || nvx_public_media_upload_url_is_readable( $image_url ) );

	if ( ! $readable ) {
		// Fall back only to a file verified in the active uploads tree. This CSS
		// path bypasses wp_get_attachment_image() attributes, so it must not keep
		// a stale featured-image URL when no physical derivative is available.
		$fallback = content_url( 'uploads/2026/07/fondo-formulario.webp' );
		$image_url = ( ! function_exists( 'nvx_public_media_upload_url_is_readable' ) || nvx_public_media_upload_url_is_readable( $fallback ) )
			? $fallback
			: '';
	}

	if ( '' === $image_url ) {
		return;
	}

	$css = sprintf(
		'.nvx-hubspot-form-section,.nvx-form-stage{--nvx-form-stage-image:url("%s");}',
		esc_url_raw( $image_url )
	);

	wp_add_inline_style( 'nvx-layout', $css );
}
add_action( 'wp_enqueue_scripts', 'nvx_valoracion_form_stage_image_css', 30 );

/**
 * Mark the valoración form section for stage styling.
 */
function nvx_valoracion_form_stage_class( string $content ): string {
	if ( is_admin() || ! nvx_theme_is_valoracion_landing() ) {
		return $content;
	}

	$updated = preg_replace(
		'/(<section\b[^>]*\bid=["\']nvx-hubspot-form["\'][^>]*\bclass=["\'])([^"\']*)(["\'])/i',
		'$1$2 nvx-form-stage$3',
		$content,
		1,
		$count
	);

	if ( is_string( $updated ) && $count > 0 ) {
		return $updated;
	}

	$replaced = preg_replace(
		'/(<section\b[^>]*\bclass=["\'])([^"\']*nvx-hubspot-form-section[^"\']*)(["\'])/i',
		'$1$2 nvx-form-stage$3',
		$content,
		1
	);
	return $replaced ? $replaced : $content;
}
add_filter( 'the_content', 'nvx_valoracion_form_stage_class', NVX_HOOK_PRIO_VALORACION_FORM_CLASS );

/*
═══════════════════════════════════════════════════════════
	Valoración conversion mount (absorbed from retired MU plugin)
	Single canonical first-party form; HubSpot transport remains server-side.
	═══════════════════════════════════════════════════════════ */

/**
 * Whether the current request is the canonical valoración form route.
 */
if ( ! function_exists( 'nvx_valoracion_native_hubspot_is_target_page' ) ) {
	function nvx_valoracion_native_hubspot_is_target_page(): bool {
		if ( function_exists( 'nvx_is_valoracion_page_request' ) && nvx_is_valoracion_page_request() ) {
			return true;
		}
		return is_page( 'valoracion' ) || is_page( 'madrid/valoracion' );
	}
}

/**
 * Canonical first-party valoración mount.
 *
 * The visible form is owned by WordPress; successful submissions are forwarded
 * through the authenticated HubSpot bridge and mirrored to the durable Supabase
 * capture ledger only after HubSpot accepts the request.
 */
if ( ! function_exists( 'nvx_valoracion_native_hubspot_mount_markup' ) ) {
	function nvx_valoracion_native_hubspot_mount_markup(): string {
		if (
			! function_exists( 'nvx_hubspot_secure_identity_configured' )
			|| ! nvx_hubspot_secure_identity_configured()
			|| ! function_exists( 'nvx_valoracion_direct_form_markup' )
		) {
			return '<!-- NVX_VALORACION_FORM_UNAVAILABLE secure_identity_not_configured -->';
		}

		return nvx_valoracion_direct_form_markup();
	}
}

/**
 * Balanced <div> range starting at $open_offset.
 *
 * @return array{start:int,length:int}|null
 */
if ( ! function_exists( 'nvx_valoracion_balanced_div_range' ) ) {
	function nvx_valoracion_balanced_div_range( string $html, int $open_offset ): ?array {
		if ( $open_offset < 0 || ! preg_match( '/\G<div\b[^>]*>/i', $html, $opening, 0, $open_offset ) ) {
			return null;
		}
		if ( ! preg_match_all( '/<div\b[^>]*>|<\/div\s*>/i', $html, $tokens, PREG_OFFSET_CAPTURE, $open_offset ) ) {
			return null;
		}

		$depth = 0;
		foreach ( $tokens[0] as $token ) {
			$markup = (string) $token[0];
			$offset = (int) $token[1];
			$depth += 0 === stripos( $markup, '</div' ) ? -1 : 1;
			if ( 0 === $depth ) {
				return array(
					'start'  => $open_offset,
					'length' => $offset + strlen( $markup ) - $open_offset,
				);
			}
		}
		return null;
	}
}

/**
 * Remove balanced divs that carry a given class token.
 */
if ( ! function_exists( 'nvx_valoracion_remove_divs_by_class' ) ) {
	function nvx_valoracion_remove_divs_by_class( string $html, string $class_token ): string {
		$pattern = '/<div\b(?=[^>]*\bclass=["\'][^"\']*\b'
			. preg_quote( $class_token, '/' )
			. '\b[^"\']*["\'])[^>]*>/i';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$ranges = array();
		foreach ( $matches[0] as $match ) {
			$range = nvx_valoracion_balanced_div_range( $html, (int) $match[1] );
			if ( is_array( $range ) ) {
				$ranges[] = $range;
			}
		}
		usort(
			$ranges,
			static function ( array $a, array $b ): int {
				return $b['start'] <=> $a['start'];
			}
		);
		foreach ( $ranges as $range ) {
			$html = substr_replace( $html, '', $range['start'], $range['length'] );
		}
		return $html;
	}
}

/**
 * Keep only presentation attributes on the managed conversion host.
 */
if ( ! function_exists( 'nvx_valoracion_sanitize_hubspot_host_opening' ) ) {
	function nvx_valoracion_sanitize_hubspot_host_opening( string $opening ): string {
		$cleaned = preg_replace(
			'/\s+(?:data-(?:form-id|portal-id|region|hs-[a-z0-9_-]+|nvx-hubspot-(?:lazy|native|eager))|aria-label)=("([^"]*)"|\'([^\']*)\'|[^\s>]+)/iu',
			'',
			$opening
		);
		return is_string( $cleaned ) ? $cleaned : $opening;
	}
}

/**
 * Keep one canonical first-party conversion mount on the valoración page output.
 */
if ( ! function_exists( 'nvx_valoracion_native_hubspot_enforce_single_mount' ) ) {
	function nvx_valoracion_native_hubspot_enforce_single_mount( string $html ): string {
		$mount_pattern = '/<div\b[^>]*\bid=["\']nvx-hubspot-native-form["\'][^>]*>/i';
		if ( ! preg_match_all( $mount_pattern, $html, $mounts, PREG_OFFSET_CAPTURE ) || empty( $mounts[0] ) ) {
			return $html;
		}

		$ranges = array();
		foreach ( $mounts[0] as $mount ) {
			$range = nvx_valoracion_balanced_div_range( $html, (int) $mount[1] );
			if ( is_array( $range ) ) {
				$range['opening'] = (string) $mount[0];
				$ranges[]         = $range;
			}
		}
		if ( empty( $ranges ) ) {
			return $html;
		}

		usort(
			$ranges,
			static function ( array $a, array $b ): int {
				return $a['start'] <=> $b['start'];
			}
		);
		$first_offset  = (int) $ranges[0]['start'];
		$first_opening = nvx_valoracion_sanitize_hubspot_host_opening( (string) $ranges[0]['opening'] );
		$first_opening = preg_replace(
			'/\bid=(["\'])nvx-hubspot-native-form\1/i',
			'id="nvx-valoracion-first-party-form"',
			$first_opening,
			1
		);
		if ( ! is_string( $first_opening ) ) {
			return $html;
		}
		$first_opening = preg_replace( '/>\s*$/', ' data-nvx-first-party-owner="1">', $first_opening, 1 );
		if ( ! is_string( $first_opening ) ) {
			return $html;
		}
		$marker = '<!-- NVX_VALORACION_CANONICAL_MOUNT -->';

		$descending = $ranges;
		usort(
			$descending,
			static function ( array $a, array $b ): int {
				return $b['start'] <=> $a['start'];
			}
		);
		foreach ( $descending as $range ) {
			$html = substr_replace( $html, '', (int) $range['start'], (int) $range['length'] );
		}
		$html = substr( $html, 0, $first_offset ) . $marker . substr( $html, $first_offset );

		// Retire every browser-owned HubSpot form surface on the managed landing.
		// HubSpot remains the authoritative destination through the secure server bridge.
		$stripped = preg_replace( '#<script\b[^>]*\bsrc=["\'][^"\']*hsforms\.net/[^"\']*["\'][^>]*>\s*</script>#iu', '', $html );
		$html     = is_string( $stripped ) ? $stripped : $html;
		$stripped = preg_replace( '#<iframe\b[^>]*(?:hsforms|hubspot)[^>]*>[\s\S]*?</iframe>#iu', '', $html );
		$html     = is_string( $stripped ) ? $stripped : $html;
		$html     = nvx_valoracion_remove_divs_by_class( $html, 'hs-form-frame' );
		$html     = nvx_valoracion_remove_divs_by_class( $html, 'hbspt-form' );

		$canonical = $first_opening . nvx_valoracion_native_hubspot_mount_markup() . '</div>';
		return str_replace( $marker, $canonical, $html );
	}
}

add_action(
	'template_redirect',
	static function (): void {
		if ( nvx_valoracion_native_hubspot_is_target_page() ) {
			ob_start( 'nvx_valoracion_native_hubspot_enforce_single_mount' );
		}
	}
);

// If MU still present this request, still ensure theme does not double-register the buffer.
// (MU owns the callback until disk retirement completes.)
