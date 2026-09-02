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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether the current request belongs to the staging2 environment.
 *
 * @return bool `true` if the normalized host is `staging2.nuvanx.com`, `false` otherwise.
 */
function nvx_environment_is_staging2(): bool {
	if ( function_exists( 'nvx_theme_request_context' ) ) {
		$context = nvx_theme_request_context();
		return ! empty( $context['is_staging'] );
	}
	return false;
}

/**
 * Resolve the exact deployed Git commit SHA.
 *
 * Resolution order supports controlled host configuration while keeping the
 * workflow-generated marker as the normal source of truth. Rendering is owned
 * exclusively by nvx_render_deploy_stamp_meta().
 */
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

require_once __DIR__ . '/nvx-meta-browser-governance.php';
