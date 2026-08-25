<?php
/**
 * Template Name: Soluciones médicas
 * Template Post Type: page
 *
 * Dedicated route template for /soluciones-medicas/.
 * Renders the theme-owned hub markup without the_content filters.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

// Fail closed with visible diagnostics if the canonical partial is missing.
$partial = get_template_directory() . '/template-parts/content/nvx-soluciones-medicas.php';

// Document head contract is owned by nvx-document-governance. This template only
// captures the solutions partial (local view buffer), never a second document rewrite.

ob_start();

echo "\n<!-- nvx-solutions-template-active -->\n";

$markup = function_exists( 'nvx_solutions_hub_markup' ) ? nvx_solutions_hub_markup() : '';
if ( '' === trim( $markup ) && is_readable( $partial ) ) {
	// Fallback if the helper is unavailable: load the view without require_once.
	// Pair ob_start/ob_get_clean only — do not climb/clean outer document buffers.
	ob_start();
	load_template( $partial, false );
	$captured = ob_get_clean();
	$markup   = is_string( $captured ) ? $captured : '';
}

if ( '' !== trim( $markup ) ) {
	// Partial builds HTML with escaped helpers; do not re-escape compound markup.
	echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- composed from escaped theme partial.
} elseif ( is_readable( $partial ) ) {
	echo '<div class="nvx-brand-section__inner"><h1 class="nvx-brand-title">Soluciones médicas</h1><p>Plantilla de soluciones vacía.</p></div>';
} else {
	echo '<div class="nvx-brand-section__inner"><h1 class="nvx-brand-title">Soluciones médicas</h1><p>Falta el partial versionado de soluciones.</p></div>';
}

$content = ob_get_clean();

set_query_var( 'nvx_shell_content', $content );
set_query_var( 'nvx_shell_skip_header', true );
set_query_var( 'nvx_shell_content_is_layout', true );
get_template_part( 'template-parts/content/nvx-page-shell' );
