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
 * DIAGNOSTIC-ONLY: PR #830 must never be merged.
 * Capture the late Home response lifecycle and output-buffer ownership. A
 * request writes its final PHP-side trace during shutdown; the next primary
 * Home request transports that previous trace in X-Robots-Tag. The secondary
 * fatal-capture loopback is excluded so it cannot consume or overwrite it.
 */
if ( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type() ) {
	$nvx_diag_path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$nvx_diag_ua   = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	if ( '/' === $nvx_diag_path && 'NUVANX-Staging-Fatal-Capture/1.1' !== $nvx_diag_ua ) {
		$nvx_trace_file = get_template_directory() . '/.nvx-home-late-trace-830.json';
		$nvx_previous   = null;
		if ( is_readable( $nvx_trace_file ) ) {
			$decoded = json_decode( (string) file_get_contents( $nvx_trace_file ), true );
			if ( is_array( $decoded ) ) {
				$nvx_previous = $decoded;
			}
			@unlink( $nvx_trace_file );
		}

		$nvx_buffer_snapshot = static function (): array {
			$buffers = array();
			foreach ( ob_get_status( true ) as $status ) {
				$buffers[] = array(
					'name'  => substr( (string) ( $status['name'] ?? '' ), 0, 160 ),
					'level' => isset( $status['level'] ) ? (int) $status['level'] : 0,
					'flags' => isset( $status['flags'] ) ? (int) $status['flags'] : 0,
				);
			}
			return $buffers;
		};

		$nvx_late_template_callbacks = static function (): array {
			global $wp_filter;
			$hook = $wp_filter['template_redirect'] ?? null;
			if ( ! $hook instanceof WP_Hook ) {
				return array();
			}
			$out = array();
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				if ( (int) $priority < 900000 ) {
					continue;
				}
				foreach ( $callbacks as $registered ) {
					$callback = $registered['function'] ?? null;
					$owner    = 'unknown';
					if ( $callback instanceof Closure ) {
						try {
							$reflection = new ReflectionFunction( $callback );
							$file       = $reflection->getFileName();
							$owner      = is_string( $file ) ? basename( $file ) : 'closure';
						} catch ( ReflectionException $exception ) {
							$owner = 'closure-reflection-failed';
						}
					} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
						$owner = ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1];
					} elseif ( is_string( $callback ) ) {
						$owner = $callback;
					}
					$out[] = array( 'priority' => (int) $priority, 'owner' => substr( $owner, 0, 180 ) );
				}
			}
			return $out;
		};

		$nvx_http_trace  = array();
		$nvx_trace_stage = static function ( string $stage, array $extra = array() ) use ( &$nvx_http_trace, $nvx_buffer_snapshot ): void {
			$code  = http_response_code();
			$entry = array(
				'stage'        => $stage,
				'code'         => is_int( $code ) ? $code : 0,
				'headers_sent' => headers_sent(),
				'buffers'      => $nvx_buffer_snapshot(),
			);
			foreach ( $extra as $key => $value ) {
				$entry[ (string) $key ] = $value;
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

		foreach ( array( 'after_setup_theme', 'init', 'send_headers', 'wp' ) as $nvx_trace_hook ) {
			add_action(
				$nvx_trace_hook,
				static function () use ( $nvx_trace_stage, $nvx_trace_hook ): void {
					$nvx_trace_stage( $nvx_trace_hook );
				},
				PHP_INT_MAX
			);
		}
		add_action(
			'wp_loaded',
			static function () use ( $nvx_trace_stage, $nvx_late_template_callbacks ): void {
				$nvx_trace_stage( 'wp_loaded', array( 'late_template_callbacks' => $nvx_late_template_callbacks() ) );
			},
			PHP_INT_MAX
		);
		add_action( 'template_redirect', static function () use ( $nvx_trace_stage ): void { $nvx_trace_stage( 'template_redirect_early' ); }, PHP_INT_MIN );
		add_action( 'template_redirect', static function () use ( $nvx_trace_stage ): void { $nvx_trace_stage( 'template_redirect_late' ); }, PHP_INT_MAX );
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
			static function () use ( &$nvx_http_trace, $nvx_previous, $nvx_trace_stage ): void {
				$nvx_trace_stage( 'wp_head' );
				$payload = wp_json_encode(
					array(
						'current'  => $nvx_http_trace,
						'previous' => $nvx_previous,
						'query'    => array(
							'is_front_page' => is_front_page(),
							'is_home'       => is_home(),
							'is_404'        => is_404(),
						),
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
				if ( is_string( $payload ) && '' !== $payload ) {
					$token = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
					header( 'X-Robots-Tag: noindex, nofollow, nvx-latetrace-' . $token, true );
				}
			},
			PHP_INT_MIN
		);

		add_action( 'get_footer', static function () use ( $nvx_trace_stage ): void { $nvx_trace_stage( 'get_footer' ); }, PHP_INT_MAX );
		add_action( 'wp_footer', static function () use ( $nvx_trace_stage ): void { $nvx_trace_stage( 'wp_footer_early' ); }, PHP_INT_MIN );
		add_action( 'wp_footer', static function () use ( $nvx_trace_stage ): void { $nvx_trace_stage( 'wp_footer_late' ); }, PHP_INT_MAX );
		add_action( 'shutdown', static function () use ( $nvx_trace_stage ): void { $nvx_trace_stage( 'wp_shutdown_early' ); }, PHP_INT_MIN );
		add_action( 'shutdown', static function () use ( $nvx_trace_stage ): void { $nvx_trace_stage( 'wp_shutdown_late' ); }, PHP_INT_MAX );

		register_shutdown_function(
			static function () use ( &$nvx_http_trace, $nvx_trace_file, $nvx_trace_stage ): void {
				$nvx_trace_stage( 'php_shutdown_after_wp' );
				$error   = error_get_last();
				$payload = array(
					'trace'      => $nvx_http_trace,
					'last_error' => is_array( $error )
						? array(
							'type' => isset( $error['type'] ) ? (int) $error['type'] : 0,
							'file' => isset( $error['file'] ) ? basename( (string) $error['file'] ) : '',
							'line' => isset( $error['line'] ) ? (int) $error['line'] : 0,
						)
						: null,
				);
				$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( is_string( $encoded ) && '' !== $encoded ) {
					file_put_contents( $nvx_trace_file, $encoded, LOCK_EX );
				}
			}
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
