<?php
/**
 * Canonical browser owner for the full-page valoración conversion surface.
 *
 * The visible form is first-party HTML. HubSpot remains the authoritative
 * destination through the authenticated server-side bridge, and the durable
 * Supabase capture relay runs only after HubSpot accepts the submission.
 *
 * This file is loaded before nvx-hero-and-forms.php so its conditional
 * compatibility functions are replaced at the ownership boundary rather than
 * patched after output has already been governed.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the canonical first-party form inside the managed landing mount.
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
 * Remove browser-HubSpot identity/runtime markers from the presentation host.
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
 * Replace every browser-owned HubSpot mount with one first-party owner.
 *
 * The first range is replaced in place rather than removed and reinserted by a
 * stale byte offset. Any duplicate ranges are deleted from highest to lowest
 * offset so earlier positions remain stable during mutation.
 */
if ( ! function_exists( 'nvx_valoracion_native_hubspot_enforce_single_mount' ) ) {
	function nvx_valoracion_native_hubspot_enforce_single_mount( string $html ): string {
		$mount_pattern = '/<div\b[^>]*\bid=["\']nvx-hubspot-native-form["\'][^>]*>/i';
		if ( ! preg_match_all( $mount_pattern, $html, $mounts, PREG_OFFSET_CAPTURE ) || empty( $mounts[0] ) ) {
			return $html;
		}

		if ( ! function_exists( 'nvx_valoracion_balanced_div_range' ) || ! function_exists( 'nvx_valoracion_remove_divs_by_class' ) ) {
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

		$first_start   = (int) $ranges[0]['start'];
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

		$canonical = $first_opening . nvx_valoracion_native_hubspot_mount_markup() . '</div>';

		$descending = $ranges;
		usort(
			$descending,
			static function ( array $a, array $b ): int {
				return $b['start'] <=> $a['start'];
			}
		);
		foreach ( $descending as $range ) {
			$replacement = (int) $range['start'] === $first_start ? $canonical : '';
			$html        = substr_replace( $html, $replacement, (int) $range['start'], (int) $range['length'] );
		}

		// Retire every browser-owned HubSpot form surface on this managed route.
		$stripped = preg_replace( '#<script\b[^>]*\bsrc=["\'][^"\']*hsforms\.net/[^"\']*["\'][^>]*>\s*</script>#iu', '', $html );
		$html     = is_string( $stripped ) ? $stripped : $html;
		$stripped = preg_replace( '#<iframe\b[^>]*(?:hsforms|hubspot)[^>]*>[\s\S]*?</iframe>#iu', '', $html );
		$html     = is_string( $stripped ) ? $stripped : $html;
		$html     = nvx_valoracion_remove_divs_by_class( $html, 'hs-form-frame' );
		$html     = nvx_valoracion_remove_divs_by_class( $html, 'hbspt-form' );

		return $html;
	}
}
