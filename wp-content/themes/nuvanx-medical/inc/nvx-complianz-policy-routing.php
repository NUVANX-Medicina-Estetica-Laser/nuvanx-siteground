<?php
/**
 * Canonical front-end routing for Complianz policy links.
 *
 * Complianz may render already-translated anchors with href="#" plus a
 * data-relative_url attribute. Routing therefore cannot depend on unreplaced
 * {title}/{url} template tokens being present in the banner HTML.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize text for case-insensitive policy matching.
 */
function nvx_complianz_normalize_text( string $value ): string {
	$value = trim( wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
}

/**
 * Classify one policy hint without combining independent sources.
 */
function nvx_complianz_policy_destination_from_hint( string $hint ): string {
	$hint = nvx_complianz_normalize_text( $hint );

	if ( false !== strpos( $hint, 'privacidad' ) || false !== strpos( $hint, 'privacy' ) ) {
		return home_url( '/politica-privacidad/' );
	}
	if ( false !== strpos( $hint, 'cookie' ) ) {
		return home_url( '/politica-de-cookies-ue/' );
	}
	if ( false !== strpos( $hint, 'aviso-legal' ) || false !== strpos( $hint, 'aviso legal' ) || false !== strpos( $hint, 'legal notice' ) ) {
		return home_url( '/aviso-legal/' );
	}

	return '';
}

/**
 * Resolve a canonical policy destination from Complianz metadata or label.
 *
 * Non-hash data-relative_url metadata is authoritative when it identifies a
 * known policy. The visible label is consulted only as fallback. Hash-valued
 * metadata represents a consent-dialog control and must remain untouched.
 */
function nvx_complianz_policy_destination( string $label, string $relative_url = '' ): string {
	$relative_url = trim( html_entity_decode( $relative_url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' !== $relative_url && '#' === $relative_url[0] ) {
		return '';
	}

	if ( '' !== $relative_url ) {
		$metadata_destination = nvx_complianz_policy_destination_from_hint( $relative_url );
		if ( '' !== $metadata_destination ) {
			return $metadata_destination;
		}
	}

	return nvx_complianz_policy_destination_from_hint( $label );
}

/**
 * Extract one quoted HTML attribute from an anchor attribute fragment.
 */
function nvx_complianz_anchor_attribute( string $attributes, string $name ): string {
	$pattern = '/(?:^|\\s)' . preg_quote( $name, '/' ) . '\\s*=\\s*([\'\"])(.*?)\\1/is';
	if ( 1 !== preg_match( $pattern, $attributes, $matches ) ) {
		return '';
	}
	return isset( $matches[2] ) ? (string) $matches[2] : '';
}

/**
 * Rewrite Complianz policy anchors while preserving consent-dialog controls.
 */
function nvx_rewrite_complianz_policy_links( string $html ): string {
	$has_template_token = false !== strpos( $html, '{title}' ) || false !== strpos( $html, '{url}' );
	$has_relative_link  = false !== strpos( $html, 'data-relative_url' );
	$has_hash_anchor    = false !== strpos( $html, 'href="#"' );

	if ( ! $has_template_token && ! $has_relative_link && ! $has_hash_anchor ) {
		return $html;
	}

	$filtered = preg_replace_callback(
		'/<a\\s+([^>]*?)href=([\'\"])(.*?)\\2([^>]*)>(.*?)<\\/a>/is',
		static function ( array $matches ): string {
			$attr_before  = $matches[1];
			$quote        = $matches[2];
			$href         = $matches[3];
			$attr_after   = $matches[4];
			$inner_html   = $matches[5];
			$attributes   = $attr_before . ' ' . $attr_after;
			$relative_url = nvx_complianz_anchor_attribute( $attributes, 'data-relative_url' );
			$destination  = nvx_complianz_policy_destination( $inner_html, $relative_url );

			if ( false !== strpos( $inner_html, '{title}' ) ) {
				$title_destination = '' !== $destination
					? $destination
					: nvx_complianz_policy_destination( '', $href );
				$title = 'Política de cookies';
				if ( false !== strpos( $title_destination, '/politica-privacidad/' ) ) {
					$title = 'Política de privacidad';
				} elseif ( false !== strpos( $title_destination, '/aviso-legal/' ) ) {
					$title = 'Aviso legal';
				}
				$inner_html = str_replace( '{title}', $title, $inner_html );
			}

			// Canonicalize only unresolved hash/empty policy hrefs. Existing concrete
			// URLs and Complianz's {url} template placeholder are preserved verbatim.
			if ( ( '#' === $href || '' === $href ) && '' !== $relative_url && '' !== $destination ) {
				$href = esc_url( $destination );
			} elseif ( ( '#' === $href || '' === $href ) && '' === $relative_url ) {
				// Allow label-based fallback for policy anchors without data-relative_url
				$label_destination = nvx_complianz_policy_destination( $inner_html );
				if ( '' !== $label_destination ) {
					$href = esc_url( $label_destination );
				}
			}

			return '<a ' . $attr_before . 'href=' . $quote . $href . $quote . $attr_after . '>' . $inner_html . '</a>';
		},
		$html
	);

	if ( ! is_string( $filtered ) ) {
		return $html;
	}

	// Preserve the prior accessibility fallback for any bare title token not
	// contained in an anchor. Complianz still owns {url} substitution.
	return str_replace( '{title}', 'Política de cookies', $filtered );
}

// Retire the earlier token-dependent sanitizer as the effective routing owner,
// then register one canonical finalizer for both Complianz filter surfaces.
remove_filter( 'cmplz_banner_html', 'nvx_sanitize_complianz_banner_html', 20 );
remove_filter( 'cmplz_template', 'nvx_sanitize_complianz_banner_html', 20 );
add_filter( 'cmplz_banner_html', 'nvx_rewrite_complianz_policy_links', 20 );
add_filter( 'cmplz_template', 'nvx_rewrite_complianz_policy_links', 20 );
