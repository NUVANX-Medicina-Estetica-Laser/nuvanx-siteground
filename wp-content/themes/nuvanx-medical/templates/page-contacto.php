<?php
/**
 * Template Name: Contacto NUVANX
 * Template Post Type: page
 *
 * /contacto/ — NAP, rutas de contacto y sedes. Sin formularios.
 *
 * SEO ownership:
 * - Titles / meta / social → inc/nvx-contacto-valoracion-page.php
 * - MedicalClinic graph → same module + nvx_schema_clinics()
 * - Canonical HTML → nvx-document-governance.php
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

ob_start();

$clinics = function_exists( 'nvx_schema_clinics' ) ? nvx_schema_clinics() : array();
$config  = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

$chamberi = isset( $config['chamberi'] ) && is_array( $config['chamberi'] ) ? $config['chamberi'] : array();
$goya     = isset( $config['goya'] ) && is_array( $config['goya'] ) ? $config['goya'] : array();

$chamberi_phone       = (string) ( $chamberi['phone_href'] ?? '' );
$goya_phone           = (string) ( $goya['phone_href'] ?? '' );
$chamberi_tel_display = (string) ( $chamberi['phone'] ?? '' );
$goya_tel_display     = (string) ( $goya['phone'] ?? '' );
$chamberi_reg         = (string) ( $chamberi['reg'] ?? '' );
$goya_reg             = (string) ( $goya['reg'] ?? '' );
$chamberi_address     = (string) ( $chamberi['address'] ?? '' );
$goya_address         = (string) ( $goya['address'] ?? '' );
$chamberi_postal      = (string) ( $chamberi['postal_code'] ?? '' );
$goya_postal          = (string) ( $goya['postal_code'] ?? '' );
$chamberi_locality    = (string) ( $chamberi['locality'] ?? '' );
$goya_locality        = (string) ( $goya['locality'] ?? '' );
$chamberi_hours       = (string) ( $chamberi['hours'] ?? '' );
$goya_hours           = (string) ( $goya['hours'] ?? '' );
$chamberi_days        = strtolower( (string) ( $chamberi['days'] ?? '' ) );
$goya_days            = strtolower( (string) ( $goya['days'] ?? '' ) );

$chamberi_wa = function_exists( 'nvx_phone_digits' ) ? nvx_phone_digits( $chamberi_phone ) : ltrim( $chamberi_phone, '+' );
$goya_wa     = function_exists( 'nvx_phone_digits' ) ? nvx_phone_digits( $goya_phone ) : ltrim( $goya_phone, '+' );

$chamberi_maps = ! empty( $clinics['chamberi']['hasMap'] ) ? (string) $clinics['chamberi']['hasMap'] : '';
$goya_maps     = ! empty( $clinics['goya']['hasMap'] ) ? (string) $clinics['goya']['hasMap'] : '';
?>

<div class="nvx-brand-page">
	<section class="nvx-brand-hero nvx-brand-hero--surface-ink" aria-labelledby="nvx-contact-h1" aria-label="<?php esc_attr_e( 'Contacto NUVANX', 'nuvanx-medical' ); ?>">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'Clínicas NUVANX · Madrid', 'nuvanx-medical' ); ?></p>
				<h1 id="nvx-contact-h1" class="nvx-brand-hero__title">
					<?php esc_html_e( 'Clínicas NUVANX en Madrid: Chamberí y Salamanca–Goya', 'nuvanx-medical' ); ?>
				</h1>
				<p class="nvx-brand-hero__lead">
					<?php esc_html_e( 'Consulta direcciones, teléfonos, WhatsApp, horarios y cómo llegar. Para estudiar tu caso, solicita una valoración médica.', 'nuvanx-medical' ); ?>
				</p>
				<div class="nvx-brand-actions">
					<a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="nvx-brand-btn nvx-brand-btn--primary">
						<?php esc_html_e( 'Solicitar valoración médica', 'nuvanx-medical' ); ?>
					</a>
					<?php if ( '' !== $chamberi_wa ) : ?>
						<a href="<?php echo esc_url( 'https://wa.me/' . $chamberi_wa ); ?>"
							class="nvx-brand-btn nvx-brand-btn--secondary"
							rel="noopener noreferrer"
							target="_blank"
							aria-label="<?php esc_attr_e( 'Contactar por WhatsApp con NUVANX', 'nuvanx-medical' ); ?>">
							<?php esc_html_e( 'Contactar por WhatsApp', 'nuvanx-medical' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $chamberi_reg && '' !== $goya_reg ) : ?>
					<p class="nvx-brand-meta nvx-reg-copy"><?php echo esc_html( sprintf( __( 'Chamberí (%1$s) · Salamanca–Goya (%2$s) · Medicina basada en evidencia', 'nuvanx-medical' ), $chamberi_reg, $goya_reg ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</section>

		<section class="nvx-brand-section" aria-label="<?php esc_attr_e( 'Sedes y datos de contacto', 'nuvanx-medical' ); ?>">
			<div class="nvx-brand-section__inner">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'Sedes autorizadas', 'nuvanx-medical' ); ?></p>
				<h2 class="nvx-brand-title"><?php esc_html_e( 'Datos de contacto y centros sanitarios', 'nuvanx-medical' ); ?></h2>
				<p class="nvx-brand-lead"><?php esc_html_e( 'Centros de medicina estética autorizados por la Consejería de Sanidad de la Comunidad de Madrid.', 'nuvanx-medical' ); ?></p>

				<div class="nvx-brand-grid nvx-brand-grid--2">
					<article class="nvx-clinic-card nvx-brand-card" itemscope itemtype="https://schema.org/MedicalClinic">
						<?php if ( '' !== $chamberi_reg ) : ?><meta itemprop="identifier" content="<?php echo esc_attr( $chamberi_reg ); ?>"><?php endif; ?>
						<header class="nvx-clinic-card__header">
							<h3 class="nvx-clinic-card__name nvx-brand-card__title" itemprop="name"><?php esc_html_e( 'Centro Clínico NUVANX Chamberí', 'nuvanx-medical' ); ?></h3>
							<?php if ( '' !== $chamberi_reg ) : ?><span class="nvx-clinic-card__reg nvx-reg-copy"><?php esc_html_e( 'Registro sanitario:', 'nuvanx-medical' ); ?> <?php echo esc_html( $chamberi_reg ); ?></span><?php endif; ?>
						</header>
						<ul class="nvx-clinic-card__data">
							<li itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
								<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-location"></use></svg>
								<span itemprop="streetAddress"><?php echo esc_html( $chamberi_address ); ?></span>,
								<span itemprop="postalCode"><?php echo esc_html( $chamberi_postal ); ?></span> <span itemprop="addressLocality"><?php echo esc_html( $chamberi_locality ); ?></span>
								<br><small><?php esc_html_e( 'A dos minutos de la Plaza de Olavide', 'nuvanx-medical' ); ?></small>
							</li>
							<?php if ( '' !== $chamberi_phone && '' !== $chamberi_tel_display ) : ?>
								<li>
									<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-phone"></use></svg>
									<a href="<?php echo esc_url( 'tel:' . $chamberi_phone ); ?>" itemprop="telephone"><?php echo esc_html( $chamberi_tel_display ); ?></a>
									<?php if ( '' !== $chamberi_wa ) : ?> · <a href="<?php echo esc_url( 'https://wa.me/' . $chamberi_wa ); ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a><?php endif; ?>
								</li>
							<?php endif; ?>
							<?php if ( '' !== $chamberi_hours ) : ?><li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><?php echo esc_html( sprintf( __( 'Horario de clínica: %s', 'nuvanx-medical' ), $chamberi_hours ) ); ?></li><?php endif; ?>
							<?php if ( '' !== $chamberi_days ) : ?><li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php echo esc_html( sprintf( __( 'El Dr. Rivera atiende en Chamberí los %s.', 'nuvanx-medical' ), $chamberi_days ) ); ?></li><?php endif; ?>
						</ul>
						<?php if ( '' !== $chamberi_maps ) : ?>
							<a href="<?php echo esc_url( $chamberi_maps ); ?>" class="nvx-brand-btn nvx-brand-btn--primary" rel="noopener noreferrer" target="_blank">
								<?php esc_html_e( 'Cómo llegar', 'nuvanx-medical' ); ?>
							</a>
						<?php endif; ?>
					</article>

					<article class="nvx-clinic-card nvx-brand-card" itemscope itemtype="https://schema.org/MedicalClinic">
						<?php if ( '' !== $goya_reg ) : ?><meta itemprop="identifier" content="<?php echo esc_attr( $goya_reg ); ?>"><?php endif; ?>
						<header class="nvx-clinic-card__header">
							<h3 class="nvx-clinic-card__name nvx-brand-card__title" itemprop="name"><?php esc_html_e( 'Centro Clínico NUVANX Salamanca–Goya', 'nuvanx-medical' ); ?></h3>
							<?php if ( '' !== $goya_reg ) : ?><span class="nvx-clinic-card__reg nvx-reg-copy"><?php esc_html_e( 'Registro sanitario:', 'nuvanx-medical' ); ?> <?php echo esc_html( $goya_reg ); ?></span><?php endif; ?>
						</header>
						<ul class="nvx-clinic-card__data">
							<li itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
								<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-location"></use></svg>
								<span itemprop="streetAddress"><?php echo esc_html( $goya_address ); ?></span>,
								<span itemprop="postalCode"><?php echo esc_html( $goya_postal ); ?></span> <span itemprop="addressLocality"><?php echo esc_html( $goya_locality ); ?></span>
								<br><small><?php esc_html_e( 'Barrio de Salamanca, Madrid', 'nuvanx-medical' ); ?></small>
							</li>
							<?php if ( '' !== $goya_phone && '' !== $goya_tel_display ) : ?>
								<li>
									<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-phone"></use></svg>
									<a href="<?php echo esc_url( 'tel:' . $goya_phone ); ?>" itemprop="telephone"><?php echo esc_html( $goya_tel_display ); ?></a>
									<?php if ( '' !== $goya_wa ) : ?> · <a href="<?php echo esc_url( 'https://wa.me/' . $goya_wa ); ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a><?php endif; ?>
								</li>
							<?php endif; ?>
							<?php if ( '' !== $goya_hours ) : ?><li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><?php echo esc_html( sprintf( __( 'Horario de clínica: %s', 'nuvanx-medical' ), $goya_hours ) ); ?></li><?php endif; ?>
							<?php if ( '' !== $goya_days ) : ?><li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php echo esc_html( sprintf( __( 'El Dr. Rivera atiende en Salamanca–Goya los %s.', 'nuvanx-medical' ), $goya_days ) ); ?></li><?php endif; ?>
						</ul>
						<?php if ( '' !== $goya_maps ) : ?>
							<a href="<?php echo esc_url( $goya_maps ); ?>" class="nvx-brand-btn nvx-brand-btn--primary" rel="noopener noreferrer" target="_blank">
								<?php esc_html_e( 'Cómo llegar', 'nuvanx-medical' ); ?>
							</a>
						<?php endif; ?>
					</article>
				</div>
			</div>
		</section>

		<section class="nvx-brand-section nvx-section--cta-secondary" aria-label="<?php esc_attr_e( 'Reservar valoración médica', 'nuvanx-medical' ); ?>">
			<div class="nvx-brand-section__inner">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'Atención telefónica directa', 'nuvanx-medical' ); ?></p>
				<h2 class="nvx-brand-title"><?php esc_html_e( 'Llama a tu centro NUVANX más cercano', 'nuvanx-medical' ); ?></h2>
				<p class="nvx-body"><?php esc_html_e( 'Atención directa para información sobre valoraciones, citas y localización de nuestras sedes.', 'nuvanx-medical' ); ?></p>
				<div class="nvx-cta-pair nvx-cta-group--centered">
					<?php if ( '' !== $chamberi_phone && '' !== $chamberi_tel_display ) : ?>
						<a href="<?php echo esc_url( 'tel:' . $chamberi_phone ); ?>" class="nvx-brand-btn nvx-brand-btn--secondary">
							<?php echo esc_html( sprintf( __( 'Chamberí · %s', 'nuvanx-medical' ), $chamberi_tel_display ) ); ?>
						</a>
					<?php endif; ?>
					<?php if ( '' !== $goya_phone && '' !== $goya_tel_display ) : ?>
						<a href="<?php echo esc_url( 'tel:' . $goya_phone ); ?>" class="nvx-brand-btn nvx-brand-btn--secondary">
							<?php echo esc_html( sprintf( __( 'Salamanca–Goya · %s', 'nuvanx-medical' ), $goya_tel_display ) ); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="nvx-brand-btn nvx-brand-btn--primary">
						<?php esc_html_e( 'Solicitar valoración', 'nuvanx-medical' ); ?>
					</a>
				</div>
			</div>
		</section>

<?php
$content = ob_get_clean();

// Render contact maps directly in the template since the_content filters don't run for this page
// Guard against duplication using the same id marker check as nvx_contact_append_maps
if ( false === strpos( $content, 'id="nvx-contacto-maps"' ) && function_exists( 'nvx_contact_maps_markup' ) ) {
	$content .= nvx_contact_maps_markup();
}

set_query_var( 'nvx_shell_content', $content );
set_query_var( 'nvx_shell_skip_header', true );
get_template_part( 'template-parts/content/nvx-page-shell' );
