<?php
/**
 * Conditional frontend page-module loader.
 *
 * Heavy page renderers are loaded only for the canonical route that owns them.
 *
 * Early/global infrastructure, SEO, query governance and integration modules
 * remain outside this loader.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical page-owner => renderer-module map.
 *
 * Only modules proven safe to load after init belong here.
 *
 * @return array<string,string>
 */
if ( ! function_exists( 'nvx_page_module_owner_map' ) ) {
	function nvx_page_module_owner_map(): array {
		return array(
			'nvx_soluciones_medicas_page' =>
				'inc/nvx-solutions-page.php',

			'nvx_endolift_page' =>
				'inc/nvx-endolift-page.php',

			'nvx_exion_page' =>
				'inc/nvx-exion-page.php',

			'nvx_profhilo_page' =>
				'inc/nvx-profhilo-page.php',

			'nvx_endolaser_page' =>
				'inc/nvx-endolaser-page.php',

			'nvx_co2_page' =>
				'inc/nvx-co2-page.php',

			'nvx_equipo_page' =>
				'inc/nvx-equipo-page.php',

			'nvx_nosotros_page' =>
				'inc/nvx-nosotros-page.php',
		);
	}
}

/**
 * Supplemental routes not yet represented by the canonical Page Registry.
 *
 * This list should shrink, not grow. New governed pages should be added to
 * nvx-page-registry.php first.
 *
 * @return array<string,string>
 */
if ( ! function_exists( 'nvx_page_module_supplemental_routes' ) ) {
	function nvx_page_module_supplemental_routes(): array {
		return array(
			'/protocolo-novias-madrid/' =>
				'inc/nvx-bridal-page.php',

			'/que-exigir-antes-de-operarte/' =>
				'inc/nvx-que-exigir-page.php',
		);
	}
}

/**
 * Return all conditional renderer modules.
 *
 * Used only by compatibility contexts such as controlled Yoast WP-CLI
 * regeneration and REST until those paths have their own explicit contracts.
 *
 * @return string[]
 */
if ( ! function_exists( 'nvx_page_module_all_files' ) ) {
	function nvx_page_module_all_files(): array {
		return array_values(
			array_unique(
				array_merge(
					array_values(
						nvx_page_module_owner_map()
					),
					array_values(
						nvx_page_module_supplemental_routes()
					)
				)
			)
		);
	}
}

/**
 * Normalize a candidate request path.
 */
if ( ! function_exists( 'nvx_page_module_normalize_path' ) ) {
	function nvx_page_module_normalize_path(
		string $path
	): string {
		$path = wp_parse_url(
			$path,
			PHP_URL_PATH
		);

		if ( ! is_string( $path ) ) {
			return '';
		}

		$path = '/' . trim( $path, '/' ) . '/';

		return '//' === $path
			? '/'
			: $path;
	}
}

/**
 * Resolve current immutable request path.
 */
if ( ! function_exists( 'nvx_page_module_request_path' ) ) {
	function nvx_page_module_request_path(): string {
		if (
			function_exists(
				'nvx_theme_request_context'
			)
		) {
			$context =
				nvx_theme_request_context();

			return nvx_page_module_normalize_path(
				(string) (
					$context['path']
					?? ''
				)
			);
		}

		return '';
	}
}

/**
 * Whether the current execution is a public frontend HTTP request.
 */
if ( ! function_exists( 'nvx_page_module_is_public_http' ) ) {
	function nvx_page_module_is_public_http(): bool {
		if (
			defined( 'WP_CLI' )
			&& WP_CLI
		) {
			return false;
		}

		if (
			function_exists(
				'wp_doing_cron'
			)
			&& wp_doing_cron()
		) {
			return false;
		}

		if (
			function_exists(
				'wp_doing_ajax'
			)
			&& wp_doing_ajax()
		) {
			return false;
		}

		if ( is_admin() ) {
			return false;
		}

		$path =
			nvx_page_module_request_path();

		if (
			'' === $path
			|| str_starts_with(
				$path,
				'/wp-json/'
			)
		) {
			return false;
		}

		return true;
	}
}

/**
 * Load one whitelisted page module.
 */
if ( ! function_exists( 'nvx_page_module_require' ) ) {
	function nvx_page_module_require(
		string $relative_file
	): bool {
		static $loaded = array();

		$allowed =
			nvx_page_module_all_files();

		if (
			! in_array(
				$relative_file,
				$allowed,
				true
			)
		) {
			error_log(
				'NVX_PAGE_MODULE_LOADER=FAIL reason=module_not_whitelisted'
			);

			return false;
		}

		if (
			isset(
				$loaded[
					$relative_file
				]
			)
		) {
			return true;
		}

		$file =
			get_template_directory()
			. '/'
			. ltrim(
				$relative_file,
				'/'
			);

		if ( ! is_readable( $file ) ) {
			error_log(
				sprintf(
					'NVX_PAGE_MODULE_LOADER=FAIL module=%s reason=unreadable',
					sanitize_key(
						basename(
							$relative_file,
							'.php'
						)
					)
				)
			);

			return false;
		}

		try {
			require_once $file;
		} catch ( Throwable $error ) {
			unset( $error );

			error_log(
				sprintf(
					'NVX_PAGE_MODULE_LOADER=FAIL module=%s reason=load_error',
					sanitize_key(
						basename(
							$relative_file,
							'.php'
						)
					)
				)
			);

			return false;
		}

		$loaded[
			$relative_file
		] = true;

		return true;
	}
}

/**
 * Load every conditional module.
 *
 * Do not use this in normal public HTTP traffic.
 */
if ( ! function_exists( 'nvx_page_modules_load_all' ) ) {
	function nvx_page_modules_load_all(): void {
		foreach (
			nvx_page_module_all_files()
			as $relative_file
		) {
			nvx_page_module_require(
				$relative_file
			);
		}
	}
}

/**
 * Resolve one renderer module from canonical registry ownership.
 */
if ( ! function_exists( 'nvx_page_module_from_registry_path' ) ) {
	function nvx_page_module_from_registry_path(
		string $path
	): string {
		if (
			''
			=== $path
			|| ! function_exists(
				'nvx_get_canonical_page_registry'
			)
		) {
			return '';
		}

		$registry =
			nvx_get_canonical_page_registry();

		if (
			! isset(
				$registry[
					$path
				]
			)
			|| ! is_array(
				$registry[
					$path
				]
			)
		) {
			return '';
		}

		$owner =
			(string) (
				$registry[
					$path
				]['owner']
				?? ''
			);

		$map =
			nvx_page_module_owner_map();

		return isset(
			$map[
				$owner
			]
		)
			? (string)
				$map[
					$owner
				]
			: '';
	}
}

/**
 * Resolve a supplemental module from exact path.
 */
if ( ! function_exists( 'nvx_page_module_from_supplemental_path' ) ) {
	function nvx_page_module_from_supplemental_path(
		string $path
	): string {
		$routes =
			nvx_page_module_supplemental_routes();

		return isset(
			$routes[
				$path
			]
		)
			? (string)
				$routes[
					$path
				]
			: '';
	}
}

/**
 * Resolve module from the immutable request path.
 */
if ( ! function_exists( 'nvx_page_module_for_request' ) ) {
	function nvx_page_module_for_request(): string {
		$path =
			nvx_page_module_request_path();

		if ( '' === $path ) {
			return '';
		}

		$file =
			nvx_page_module_from_registry_path(
				$path
			);

		if ( '' !== $file ) {
			return $file;
		}

		return nvx_page_module_from_supplemental_path(
			$path
		);
	}
}

/**
 * Primary public loader.
 *
 * parse_request is deliberately used instead of wp:
 * - query mutation modules are not placed here;
 * - renderer callbacks are registered before template_redirect/wp_head;
 * - no page renderer needs to execute on init after 4B-2B.
 *
 * @param mixed $wp WordPress environment instance.
 */
if ( ! function_exists( 'nvx_page_modules_load_for_request' ) ) {
	function nvx_page_modules_load_for_request(
		$wp
	): void {
		unset( $wp );

		if (
			! nvx_page_module_is_public_http()
		) {
			return;
		}

		$file =
			nvx_page_module_for_request();

		if ( '' !== $file ) {
			nvx_page_module_require(
				$file
			);
		}
	}
}

if (
	false === has_action(
		'parse_request',
		'nvx_page_modules_load_for_request'
	)
) {
	add_action(
		'parse_request',
		'nvx_page_modules_load_for_request',
		PHP_INT_MIN,
		1
	);
}

/**
 * Query-aware fallback.
 *
 * Protects unusual rewrite scenarios where the request path did not directly
 * identify the final canonical page.
 */
if ( ! function_exists( 'nvx_page_modules_load_query_fallback' ) ) {
	function nvx_page_modules_load_query_fallback(): void {
		if (
			! nvx_page_module_is_public_http()
			|| ! function_exists(
				'nvx_resolve_canonical_page_entry'
			)
		) {
			return;
		}

		$entry =
			nvx_resolve_canonical_page_entry();

		if ( ! is_array( $entry ) ) {
			return;
		}

		$owner =
			(string) (
				$entry['owner']
				?? ''
			);

		$map =
			nvx_page_module_owner_map();

		if (
			isset(
				$map[
					$owner
				]
			)
		) {
			nvx_page_module_require(
				(string)
					$map[
						$owner
					]
			);
		}
	}
}

if (
	false === has_action(
		'wp',
		'nvx_page_modules_load_query_fallback'
	)
) {
	add_action(
		'wp',
		'nvx_page_modules_load_query_fallback',
		PHP_INT_MIN
	);
}

/**
 * REST compatibility boundary.
 *
 * Phase 1 deliberately preserves previous symbol availability for REST and
 * Yoast/headless consumers. Once explicit REST contracts prove these renderers
 * unnecessary, this can be narrowed further.
 */
if ( ! function_exists( 'nvx_page_modules_load_for_rest' ) ) {
	function nvx_page_modules_load_for_rest(): void {
		nvx_page_modules_load_all();
	}
}

if (
	false === has_action(
		'rest_api_init',
		'nvx_page_modules_load_for_rest'
	)
) {
	add_action(
		'rest_api_init',
		'nvx_page_modules_load_for_rest',
		PHP_INT_MIN
	);
}

/**
 * Controlled Yoast staging reindex compatibility.
 *
 * CLI performance is irrelevant compared with exact schema/indexable parity,
 * so every conditional renderer is made available in this one governed mode.
 */
if (
	defined( 'WP_CLI' )
	&& WP_CLI
	&& '1'
	=== getenv(
		'NVX_ALLOW_STAGING_YOAST_INDEXABLE_REBUILD'
	)
) {
	nvx_page_modules_load_all();
}
