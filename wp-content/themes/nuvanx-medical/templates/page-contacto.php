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

$chamberi_phone = ! empty( $clinics['chamberi']['telephone'] ) ? (string) $clinics['chamberi']['telephone'] : '+34669319836';
$goya_phone     = ! empty( $clinics['goya']['telephone'] ) ? (string) $clinics['goya']['telephone'] : '+34647505107';

$chamberi_wa = ltrim( $chamberi_phone, '+' );
$goya_wa     = ltrim( $goya_phone, '+' );

$chamberi_tel_display = ! empty( $config['chamberi']['phone'] )
	? (string) $config['chamberi']['phone']
	: '669 31 98 36';
$goya_tel_display     = ! empty( $config['goya']['phone'] )
	? (string) $config['goya']['phone']
	: '647 50 51 07';

$chamberi_maps = ! empty( $clinics['chamberi']['hasMap'] )
	? (string) $clinics['chamberi']['hasMap']
	: 'https://www.google.com/maps/search/?api=1&query=NUVANX%20C%2F%20de%20Fern%C3%A1ndez%20de%20la%20Hoz%204%2028010%20Madrid';

$goya_maps = ! empty( $clinics['goya']['hasMap'] )
	? (string) $clinics['goya']['hasMap']
	: 'https://www.google.com/maps/search/?api=1&query=NUVANX%20C%2F%20de%20Fern%C3%A1n%20Gonz%C3%A1lez%2026%2028009%20Madrid';
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
					<a href="https://wa.me/<?php echo esc_attr( $chamberi_wa ); ?>"
						class="nvx-brand-btn nvx-brand-btn--secondary"
						rel="noopener noreferrer"
						target="_blank"
						aria-label="<?php esc_attr_e( 'Contactar por WhatsApp con NUVANX', 'nuvanx-medical' ); ?>">
						<?php esc_html_e( 'Contactar por WhatsApp', 'nuvanx-medical' ); ?>
					</a>
				</div>
				<p class="nvx-brand-meta nvx-reg-copy"><?php esc_html_e( 'Chamberí (CS20144) · Salamanca–Goya (CS20073) · Medicina basada en evidencia', 'nuvanx-medical' ); ?></p>
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
						<meta itemprop="identifier" content="CS20144">
						<header class="nvx-clinic-card__header">
							<h3 class="nvx-clinic-card__name nvx-brand-card__title" itemprop="name"><?php esc_html_e( 'Centro Clínico NUVANX Chamberí', 'nuvanx-medical' ); ?></h3>
							<span class="nvx-clinic-card__reg nvx-reg-copy"><?php esc_html_e( 'Registro sanitario:', 'nuvanx-medical' ); ?> CS20144</span>
						</header>
						<ul class="nvx-clinic-card__data">
							<li itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
								<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-location"></use></svg>
								<span itemprop="streetAddress"><?php esc_html_e( 'Calle de Fernández de la Hoz, 4, Bajo Derecha', 'nuvanx-medical' ); ?></span>,
								<span itemprop="postalCode">28010</span> <span itemprop="addressLocality">Madrid</span>
								<br><small><?php esc_html_e( 'A dos minutos de la Plaza de Olavide', 'nuvanx-medical' ); ?></small>
							</li>
							<li>
								<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-phone"></use></svg>
								<a href="<?php echo esc_url( 'tel:' . $chamberi_phone ); ?>" itemprop="telephone"><?php echo esc_html( $chamberi_tel_display ); ?></a>
								· <a href="https://wa.me/<?php echo esc_attr( $chamberi_wa ); ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a>
							</li>
							<li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><?php esc_html_e( 'Horario de clínica: lunes a viernes, 12:00–20:00; sábados, 10:00–18:00', 'nuvanx-medical' ); ?></li>
							<li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php esc_html_e( 'El Dr. Rivera atiende en Chamberí los martes y jueves.', 'nuvanx-medical' ); ?></li>
						</ul>
						<a href="<?php echo esc_url( $chamberi_maps ); ?>" class="nvx-brand-btn nvx-brand-btn--primary" rel="noopener noreferrer" target="_blank">
							<?php esc_html_e( 'Cómo llegar', 'nuvanx-medical' ); ?>
						</a>
					</article>

					<article class="nvx-clinic-card nvx-brand-card" itemscope itemtype="https://schema.org/MedicalClinic">
						<meta itemprop="identifier" content="CS20073">
						<header class="nvx-clinic-card__header">
							<h3 class="nvx-clinic-card__name nvx-brand-card__title" itemprop="name"><?php esc_html_e( 'Centro Clínico NUVANX Salamanca–Goya', 'nuvanx-medical' ); ?></h3>
							<span class="nvx-clinic-card__reg nvx-reg-copy"><?php esc_html_e( 'Registro sanitario:', 'nuvanx-medical' ); ?> CS20073</span>
						</header>
						<ul class="nvx-clinic-card__data">
							<li itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
								<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-location"></use></svg>
								<span itemprop="streetAddress"><?php esc_html_e( 'Calle de Fernán González, 26', 'nuvanx-medical' ); ?></span>,
								<span itemprop="postalCode">28009</span> <span itemprop="addressLocality">Madrid</span>
								<br><small><?php esc_html_e( 'Barrio de Salamanca, Madrid', 'nuvanx-medical' ); ?></small>
							</li>
							<li>
								<svg class="nvx-icon" aria-hidden="true" width="16" height="16" ><use href="#icon-phone"></use></svg>
								<a href="<?php echo esc_url( 'tel:' . $goya_phone ); ?>" itemprop="telephone"><?php echo esc_html( $goya_tel_display ); ?></a>
								· <a href="https://wa.me/<?php echo esc_attr( $goya_wa ); ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a>
							</li>
							<li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><?php esc_html_e( 'Horario de clínica: lunes a viernes, 11:00–20:00', 'nuvanx-medical' ); ?></li>
							<li><svg class="nvx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php esc_html_e( 'El Dr. Rivera atiende en Salamanca–Goya los miércoles.', 'nuvanx-medical' ); ?></li>
						</ul>
						<a href="<?php echo esc_url( $goya_maps ); ?>" class="nvx-brand-btn nvx-brand-btn--primary" rel="noopener noreferrer" target="_blank">
							<?php esc_html_e( 'Cómo llegar', 'nuvanx-medical' ); ?>
						</a>
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
					<a href="<?php echo esc_url( 'tel:' . $chamberi_phone ); ?>" class="nvx-brand-btn nvx-brand-btn--secondary">
						<?php echo esc_html( sprintf( __( 'Chamberí · %s', 'nuvanx-medical' ), $chamberi_tel_display ) ); ?>
					</a>
					<a href="<?php echo esc_url( 'tel:' . $goya_phone ); ?>" class="nvx-brand-btn nvx-brand-btn--secondary">
						<?php echo esc_html( sprintf( __( 'Salamanca–Goya · %s', 'nuvanx-medical' ), $goya_tel_display ) ); ?>
					</a>
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