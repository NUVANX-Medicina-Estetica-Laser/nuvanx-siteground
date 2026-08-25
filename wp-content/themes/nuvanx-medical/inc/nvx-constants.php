<?php
/**
 * Global registry for the_content filter priorities.
 *
 * This file maps all hook priorities to named constants to provide a
 * self-documenting record of hook priorities without magic numbers.
 *
 * ARCHITECTURE NOTE — Collisions are intentional and load-order dependent:
 * Several groups of constants share the same integer value (e.g. block-19
 * restructuradores, block-99 governance, block-21 signature). Within each
 * group, WordPress executes callbacks in the order they were registered via
 * add_filter(), which is determined by the require_once sequence in
 * functions.php. Renaming files or reordering the bootstrap WILL silently
 * change render order. To achieve true determinism, space these values apart.
 *
 * NOTE: This scope currently covers ONLY the_content filters. Other priority
 * graphs (e.g. wpseo_metadesc, template_include) are deferred as future debt.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * DIAGNOSTIC-ONLY: PR #829 must never be merged.
 * Record the primary Home request status before template output. The trusted
 * boundary verifier already persists X-Robots-Tag, so the trace can cross the
 * public edge without changing the deploy SHA or forcing a diagnostic status.
 */
if ( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type() ) {
	$nvx_diag_path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$nvx_diag_ua   = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	if ( '/' === $nvx_diag_path && 'NUVANX-Staging-Fatal-Capture/1.1' !== $nvx_diag_ua ) {
		$nvx_http_trace  = array();
		$nvx_trace_stage = static function ( string $stage, array $extra = array() ) use ( &$nvx_http_trace ): void {
			$code  = http_response_code();
			$entry = array(
				'stage' => $stage,
				'code'  => is_int( $code ) ? $code : 0,
			);
			foreach ( $extra as $key => $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$entry[ (string) $key ] = $value;
				}
			}
			$nvx_http_trace[] = $entry;
		};

		$nvx_trace_stage( 'constants_loaded' );

		add_filter(
			'status_header',
			static function ( $status_header, $code, $description, $protocol ) use ( $nvx_trace_stage ) {
				$nvx_trace_stage(
					'status_header',
					array(
						'requested_code' => (int) $code,
						'description'    => substr( (string) $description, 0, 60 ),
						'protocol'       => substr( (string) $protocol, 0, 16 ),
					)
				);
				return $status_header;
			},
			PHP_INT_MIN,
			4
		);

		foreach ( array( 'after_setup_theme', 'init', 'wp_loaded', 'send_headers', 'wp' ) as $nvx_trace_hook ) {
			add_action(
				$nvx_trace_hook,
				static function () use ( $nvx_trace_stage, $nvx_trace_hook ): void {
					$nvx_trace_stage( $nvx_trace_hook );
				},
				PHP_INT_MAX
			);
		}

		add_action(
			'template_redirect',
			static function () use ( $nvx_trace_stage ): void {
				$nvx_trace_stage( 'template_redirect_early' );
			},
			PHP_INT_MIN
		);
		add_action(
			'template_redirect',
			static function () use ( $nvx_trace_stage ): void {
				$nvx_trace_stage( 'template_redirect_late' );
			},
			PHP_INT_MAX
		);
		add_filter(
			'template_include',
			static function ( $template ) use ( $nvx_trace_stage ) {
				$nvx_trace_stage( 'template_include', array( 'template' => basename( (string) $template ) ) );
				return $template;
			},
			PHP_INT_MAX
		);

		add_action(
			'wp_head',
			static function () use ( &$nvx_http_trace, $nvx_trace_stage ): void {
				$nvx_trace_stage( 'wp_head' );
				$payload = wp_json_encode(
					array(
						'trace' => $nvx_http_trace,
						'query' => array(
							'is_front_page' => is_front_page(),
							'is_home'       => is_home(),
							'is_404'        => is_404(),
						),
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
				if ( is_string( $payload ) && '' !== $payload ) {
					$token = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
					header( 'X-Robots-Tag: noindex, nofollow, nvx-httptrace-' . $token, true );
				}
			},
			PHP_INT_MIN
		);
	}
}

// -----------------------------------------------------------------------------
// Robots Policy Directives
// -----------------------------------------------------------------------------
const NVX_ROBOTS_INHERIT          = 0;
const NVX_ROBOTS_INDEX_FOLLOW     = 1;
const NVX_ROBOTS_NOINDEX_FOLLOW   = 2;
const NVX_ROBOTS_NOINDEX_NOFOLLOW = 3;

// Early Content Modifiers
const NVX_HOOK_PRIO_JSONLD_STRIP  = 5;
const NVX_HOOK_PRIO_BLOG_HEADINGS = 8;
const NVX_HOOK_PRIO_BLOG_BYLINES  = 9;

// Page & Hub Rendering
const NVX_HOOK_PRIO_VALORACION_MANAGED = 10;
const NVX_HOOK_PRIO_CLINICS_HUB        = 11;
const NVX_HOOK_PRIO_SOLUTIONS_PAGE     = 11;
const NVX_HOOK_PRIO_HERO_MEDIA         = 12;

// Internal Links & Form Hooks
const NVX_HOOK_PRIO_INTERNAL_LINKS        = 13;
const NVX_HOOK_PRIO_VALORACION_FORM_FIRST = 14;
const NVX_HOOK_PRIO_VALORACION_FORM_CLASS = 15;
const NVX_HOOK_PRIO_VALORACION_ENHANCE    = 16;
const NVX_HOOK_PRIO_TREATMENTS_INDEX      = 18;

// Specific Restructuradores (Block 19)
const NVX_HOOK_PRIO_AESTHETIC_MEDICINE = 19;
const NVX_HOOK_PRIO_BTL_DETAIL         = 19;
const NVX_HOOK_PRIO_CO2_MODULE         = 19;
const NVX_HOOK_PRIO_ENDOLASER          = 19;
const NVX_HOOK_PRIO_ENDOLIFT           = 19;
const NVX_HOOK_PRIO_EQUIPO             = 19;
const NVX_HOOK_PRIO_LASER_MEDICINE     = 19;
const NVX_HOOK_PRIO_NOSOTROS           = 19;
const NVX_HOOK_PRIO_PROFIHILO_MODULE   = 19;

// Global Enhancements
const NVX_HOOK_PRIO_PRESENTATION_ENHANCE = 20;
const NVX_HOOK_PRIO_CONTACT_MAPS         = 20; // Collides with NVX_HOOK_PRIO_PRESENTATION_ENHANCE (intentional, load-order dependent)
const NVX_HOOK_PRIO_GLOBAL_TREATMENT     = 21;
const NVX_HOOK_PRIO_SIGNATURE_HUB        = 21;
const NVX_HOOK_PRIO_TRUST_BADGES         = 22;

// Layout Cleanups
const NVX_HOOK_PRIO_SEDE_INLINE_STYLES = 28;
const NVX_HOOK_PRIO_BRIDAL_MEDIA       = 29;
const NVX_HOOK_PRIO_CLINICS_ENHANCE    = 30;

// High Priority Overrides
const NVX_HOOK_PRIO_AESTHETIC_TREATMENT = 80;
const NVX_HOOK_PRIO_STRATEGY_PAGES      = 82;

// Governance & Rules
const NVX_HOOK_PRIO_ENDOLIFT_AUTHORITY_GRAPH = 97;
const NVX_HOOK_PRIO_CLINICAL_EVIDENCE        = 98;

// Governance & Rules (Block 99)
const NVX_HOOK_PRIO_BUSINESS_RULES  = 99;
const NVX_HOOK_PRIO_STRIP_PAGE_CTAS = 99;
const NVX_HOOK_PRIO_BTL_GOVERNANCE  = 99;

// Late Hijacks & Enforcements
const NVX_HOOK_PRIO_DR_RIVERA         = 121;
const NVX_HOOK_PRIO_QUE_EXIGIR        = 122;
const NVX_HOOK_PRIO_EXION®_INVESTMENT = 126;
const NVX_HOOK_PRIO_MEDICAL_REVIEW    = 144;

// Extreme Late Normalization
const NVX_HOOK_PRIO_SIGNATURE_NAMES = 219;
