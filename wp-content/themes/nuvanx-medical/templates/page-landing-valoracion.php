<?php
/**
 * Template Name: Landing Valoración
 *
 * Thin adapter for the canonical managed valoración page.
 *
 * The WordPress page keeps this template assignment for routing continuity,
 * while `nvx_render_managed_valoracion_page()` owns the actual page hierarchy
 * through `the_content`. This file deliberately contains no second hero, form,
 * NAP block, or conversion copy so runtime ownership cannot drift between the
 * template and `inc/nvx-valoracion-managed-page.php`.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

// The managed renderer owns the hero. Prevent the shared shell from injecting
// a second featured-media hero around the delegated content.
global $nvx_page_shell_has_hero;
$nvx_page_shell_has_hero = true;

ob_start();
?>
<div class="entry-content nvx-page__content">
	<?php the_content(); ?>
</div>
<?php
$content = ob_get_clean();

set_query_var( 'nvx_shell_content', $content );
set_query_var( 'nvx_shell_skip_header', true );
get_template_part( 'template-parts/content/nvx-page-shell' );
