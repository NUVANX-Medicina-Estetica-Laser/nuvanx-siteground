<?php
/**
 * Environment-specific presentation and deployment flags.
 *
 * Deploy workflows stamp the exact checked-out commit into `.nvx-deploy-sha`.
 * The public deployment identity is rendered centrally by nvx-deploy-stamp.php;
 * this module only resolves the environment and immutable SHA source.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Determine whether the authoritative request belongs to staging2. */
if ( ! function_exists( 'nvx_environment_is_staging2' ) ) {
	function nvx_environment_is_staging2(): bool {
		if ( ! function_exists( 'nvx_theme_request_context' ) ) {
			return false;
		}

		$context = nvx_theme_request_context();
		return ! empty( $context['is_staging2'] );
	}
}

/** Determine whether the authoritative request belongs to production. */
if ( ! function_exists( 'nvx_environment_is_production' ) ) {
	function nvx_environment_is_production(): bool {
		if ( ! function_exists( 'nvx_theme_request_context' ) ) {
			return false;
		}

		$context = nvx_theme_request_context();
		return true === ( $context['is_production'] ?? false );
	}
}

/**
 * Resolve the exact deployed Git commit SHA.
 *
 * Resolution order supports controlled host configuration while keeping the
 * workflow-generated marker as the normal source of truth. Rendering is owned
 * exclusively by nvx_render_deploy_stamp_meta().
 */
if ( ! function_exists( 'nvx_environment_deploy_sha' ) ) {
	function nvx_environment_deploy_sha(): string {
		static $resolved = null;

		if ( is_string( $resolved ) ) {
			return $resolved;
		}

		$candidates = array();
		if ( defined( 'NVX_DEPLOY_SHA' ) ) {
			$candidates[] = (string) NVX_DEPLOY_SHA;
		}

		$environment_sha = getenv( 'NVX_DEPLOY_SHA' );
		if ( is_string( $environment_sha ) ) {
			$candidates[] = $environment_sha;
		}

		$marker = get_template_directory() . '/.nvx-deploy-sha';
		if ( is_readable( $marker ) ) {
			$marker_sha = file_get_contents( $marker );
			if ( is_string( $marker_sha ) ) {
				$candidates[] = $marker_sha;
			}
		}

		foreach ( $candidates as $candidate ) {
			$candidate = strtolower( trim( $candidate ) );
			if ( 1 === preg_match( '/^[a-f0-9]{40}$/', $candidate ) ) {
				$resolved = $candidate;
				return $resolved;
			}
		}

		$resolved = '';
		return $resolved;
	}
}
