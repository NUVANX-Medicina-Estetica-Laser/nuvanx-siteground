<?php
/**
 * Environment and deployment identity helpers.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the canonical request is staging2.
 *
 * The request boundary owns the security-sensitive environment identity.
 * This compatibility filter is intended only for presentation/tests.
 */
if ( ! function_exists( 'nvx_environment_is_staging2' ) ) {
	function nvx_environment_is_staging2(): bool {
		if (
			! function_exists(
				'nvx_theme_request_context'
			)
		) {
			return false;
		}

		$context =
			nvx_theme_request_context();

		$detected =
			! empty(
				$context['is_staging2']
			);

		return (bool) apply_filters(
			'nvx_environment_is_staging2',
			$detected,
			(string) (
				$context['host']
				?? ''
			)
		);
	}
}

/**
 * Whether the authoritative request context is production.
 *
 * No compatibility filter is applied because this helper can be used by
 * mutation/indexing gates.
 */
if ( ! function_exists( 'nvx_environment_is_production' ) ) {
	function nvx_environment_is_production(): bool {
		if (
			! function_exists(
				'nvx_theme_request_context'
			)
		) {
			return false;
		}

		$context =
			nvx_theme_request_context();

		return true
			=== (
				$context[
					'is_production'
				]
				?? false
			);
	}
}

/**
 * Resolve exact deployed Git commit SHA.
 */
if ( ! function_exists( 'nvx_environment_deploy_sha' ) ) {
	function nvx_environment_deploy_sha(): string {
		static $resolved = null;

		if ( is_string( $resolved ) ) {
			return $resolved;
		}

		$candidates =
			array();

		if ( defined( 'NVX_DEPLOY_SHA' ) ) {
			$candidates[] =
				(string) NVX_DEPLOY_SHA;
		}

		$environment_sha =
			getenv(
				'NVX_DEPLOY_SHA'
			);

		if ( is_string( $environment_sha ) ) {
			$candidates[] =
				$environment_sha;
		}

		if (
			defined( 'ABSPATH' )
			&& function_exists(
				'get_template_directory'
			)
		) {
			$git_head_path =
				get_template_directory()
				. '/../../.git/HEAD';

			if (
				file_exists(
					$git_head_path
				)
				&& is_readable(
					$git_head_path
				)
			) {
				$head_content =
					file_get_contents(
						$git_head_path
					);

				if (
					is_string(
						$head_content
					)
				) {
					$head_content =
						trim(
							$head_content
						);

					if (
						0
						=== strpos(
							$head_content,
							'ref: '
						)
					) {
						$ref_path =
							get_template_directory()
							. '/../../.git/'
							. substr(
								$head_content,
								5
							);

						if (
							file_exists(
								$ref_path
							)
							&& is_readable(
								$ref_path
							)
						) {
							$ref_content =
								file_get_contents(
									$ref_path
								);

							if (
								is_string(
									$ref_content
								)
							) {
								$candidates[] =
									trim(
										$ref_content
									);
							}
						}
					} elseif (
						40
						=== strlen(
							$head_content
						)
					) {
						$candidates[] =
							$head_content;
					}
				}
			}
		}

		foreach (
			$candidates
			as $sha
		) {
			if (
				40
				=== strlen(
					$sha
				)
				&& ctype_xdigit(
					$sha
				)
			) {
				$resolved =
					$sha;

				return $resolved;
			}
		}

		$resolved =
			'unknown-commit';

		return $resolved;
	}
}

/**
 * Return a short deploy stamp format for frontend asset busting.
 */
if ( ! function_exists( 'nvx_environment_deploy_stamp' ) ) {
	function nvx_environment_deploy_stamp(): string {
		$sha = nvx_environment_deploy_sha();

		if (
			'unknown-commit'
			=== $sha
		) {
			return (string) time();
		}

		return substr(
			$sha,
			0,
			7
		);
	}
}
