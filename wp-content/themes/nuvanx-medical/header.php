<?php
defined( 'ABSPATH' ) || exit;

// Register canonical Complianz policy routing before wp_head/wp_footer plugin
// rendering so translated hash links cannot bypass the server-side finalizer.
require_once __DIR__ . '/inc/nvx-complianz-policy-routing.php';

// Public-media runtime callbacks are registered from functions.php before any
// template can emit attachment markup. No output-buffer rewrite is used here.
// SiteGround Optimizer + Complianz own
// the front-end buffer stack. Head contract is emitted via wp_head filters.
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php
// Single document title: theme-support title-tag + document-governance normalizer.
if ( ! current_theme_supports( 'title-tag' ) ) :
	?>
	<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php
endif;
wp_head();
?>
</head>
<body <?php body_class(); ?>>
<a class="nvx-skip-link" href="#nvx-main"><?php esc_html_e( 'Saltar al contenido principal', 'nuvanx-medical' ); ?></a>
<?php wp_body_open(); ?>
<svg xmlns="http://www.w3.org/2000/svg" hidden style="display:none" aria-hidden="true">
	<symbol id="icon-location" viewBox="0 0 24 24">
	<path fill="currentColor" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
	</symbol>
	<symbol id="icon-phone" viewBox="0 0 24 24">
	<path fill="currentColor" d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
	</symbol>
</svg>
<header class="nvx-header" role="banner" id="nvx-header">
	<div class="nvx-header__inner">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="nvx-logo" aria-label="NUVANX MEDICINA ESTÉTICA LÁSER — Inicio">
		<?php
		$logo_id        = (int) get_theme_mod( 'custom_logo' );
		$logo_file      = $logo_id > 0 ? get_attached_file( $logo_id ) : '';
		$logo_url       = $logo_id > 0 ? wp_get_attachment_url( $logo_id ) : '';
		$logo_available = is_string( $logo_file ) && '' !== $logo_file && is_readable( $logo_file ) && is_string( $logo_url ) && '' !== $logo_url;
		if ( $logo_available ) :
			?>
		<img src="<?php echo esc_url( $logo_url ); ?>" class="nvx-logo__img" alt="" width="160" height="154" decoding="async">
		<?php else : ?>
		<span class="nvx-logo__wordmark" aria-hidden="true">NUVANX</span>
		<span class="nvx-logo__tagline" aria-hidden="true">MEDICINA ESTÉTICA LÁSER</span>
		<?php endif; ?>
	</a>
	<nav class="nvx-nav" aria-label="Menú principal">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'menu_class'     => 'nvx-nav__list',
				'container'      => false,
				'items_wrap'     => '<ul class="%2$s">%3$s</ul>',
				'fallback_cb'    => 'nvx_primary_menu_fallback',
				'add_li_class'   => 'nvx-nav__item',
			)
		);
		$nvx_modal_enabled = function_exists( 'nvx_valoracion_modal_enabled' ) && nvx_valoracion_modal_enabled();
		$nvx_cta_class     = 'nvx-header__cta nvx-brand-btn nvx-btn--primary' . ( $nvx_modal_enabled ? ' nvx-open-valoracion-modal' : '' );
		$nvx_mobile_class  = 'nvx-brand-btn nvx-btn--primary' . ( $nvx_modal_enabled ? ' nvx-open-valoracion-modal' : '' );
		?>
		<a href="<?php echo esc_url( home_url( '/madrid/valoracion/#nvx-hubspot-form' ) ); ?>" class="<?php echo esc_attr( $nvx_cta_class ); ?>" id="nvx-header-cta"<?php if ( $nvx_modal_enabled ) : ?>
			data-nvx-valoracion-modal="1" aria-haspopup="dialog"
		<?php endif; ?>><?php esc_html_e( 'Solicitar valoración médica', 'nuvanx-medical' ); ?></a>
	</nav>
	<button class="nvx-hamburger" id="nvx-hamburger-btn" aria-label="Abrir menú" aria-expanded="false" aria-controls="nvx-mobile-nav">
		<span></span><span></span><span></span>
	</button>
	</div>
</header>
<dialog id="nvx-mobile-nav" class="nvx-mobile-nav" aria-label="Menú móvil">
	<button class="nvx-mobile-nav__close" id="nvx-mobile-close" aria-label="Cerrar menú" type="button">&times;</button>
	<?php
	wp_nav_menu(
		array(
			'theme_location' => 'primary',
			'menu_class'     => 'nvx-mobile-nav__list',
			'container'      => false,
			'fallback_cb'    => false,
		)
	);
	?>
	<a href="<?php echo esc_url( home_url( '/madrid/valoracion/#nvx-hubspot-form' ) ); ?>" class="<?php echo esc_attr( $nvx_mobile_class ); ?>" id="nvx-mobile-cta"<?php if ( $nvx_modal_enabled ) : ?>
		data-nvx-valoracion-modal="1" aria-haspopup="dialog"
	<?php endif; ?>><?php esc_html_e( 'Solicitar valoración médica', 'nuvanx-medical' ); ?></a>
	<a href="<?php echo function_exists( 'nvx_whatsapp_url' ) ? esc_url( nvx_whatsapp_url( 'primary' ) ) : '#'; ?>" class="nvx-brand-btn nvx-btn--secondary" target="_blank" rel="noopener noreferrer" data-gtag="click-whatsapp"><?php esc_html_e( 'Contactar por WhatsApp', 'nuvanx-medical' ); ?></a>
</dialog>

<main id="nvx-main" class="nvx-main" tabindex="-1">
	<?php
	// Only add nvx-brand-page wrapper if post_content doesn't have standard wrapper
	if ( ! function_exists( 'nvx_page_has_standard_wrapper' ) || ! nvx_page_has_standard_wrapper() ) :
		?>
		<div class="nvx-brand-page">
	<?php endif; ?>
