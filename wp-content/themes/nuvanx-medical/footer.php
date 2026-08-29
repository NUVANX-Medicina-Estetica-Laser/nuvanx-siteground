<?php
/**
 * Footer principal de NUVANX.
 *
 * @package NUVANX_Medical
 */

defined( 'ABSPATH' ) || exit;

// Close nvx-brand-page wrapper only if it was opened in header.php, and do so
// before </main> so the div nests correctly inside the main landmark.
?>

<?php if ( ! function_exists( 'nvx_page_has_standard_wrapper' ) || ! nvx_page_has_standard_wrapper() ) : ?>
		</div><!-- .nvx-brand-page -->
<?php endif; ?>

</main>

<?php
// Single site-wide closing CTA (same on home, tratamientos, equipo, blogs…).
if ( function_exists( 'nvx_theme_show_cta_banner' ) && nvx_theme_show_cta_banner() && function_exists( 'nvx_site_closing_cta_markup' ) ) {
	echo nvx_site_closing_cta_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup helper escapes.
}

// Detail treatments are listed only when a corresponding WordPress page is public.
$nvx_footer_published_treatments = function_exists( 'nvx_navigation_published_treatments' )
	? nvx_navigation_published_treatments()
	: array();
$nvx_cases_id                    = function_exists( 'nvx_page_id_by_slug' ) ? nvx_page_id_by_slug( 'casos-de-pacientes' ) : 0;
$nvx_cases_public                = $nvx_cases_id > 0
	&& ( ! function_exists( 'nvx_noindex_page_ids' )
		|| ! in_array( $nvx_cases_id, nvx_noindex_page_ids(), true ) );
$nvx_why_nuvanx_url              = function_exists( 'nvx_strategy_published_url' ) ? nvx_strategy_published_url( 'why_nuvanx' ) : '';
$nvx_investment_url              = function_exists( 'nvx_strategy_published_url' ) ? nvx_strategy_published_url( 'investment' ) : '';
$nvx_footer_clinics              = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

$nvx_footer_treatments = array(
	array(
		'label' => 'Endolift® facial',
		'url'   => home_url( '/endolift-facial-papada-mandibula/' ),
	),
	array(
		'label' => 'Endoláser corporal',
		'url'   => home_url( '/endolaser-corporal-grasa-localizada/' ),
	),
	array(
		'label' => 'Láser CO₂ fraccionado',
		'url'   => home_url( '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/' ),
	),
	array(
		'label' => 'EXION® BTL',
		'url'   => home_url( '/exion-btl/' ),
	),
);
$nvx_footer_seen_urls = array();
foreach ( $nvx_footer_treatments as $nvx_treatment ) {
	$nvx_footer_seen_urls[ (string) $nvx_treatment['url'] ] = true;
}
if ( is_array( $nvx_footer_published_treatments ) ) {
	foreach ( $nvx_footer_published_treatments as $nvx_treatment ) {
		$nvx_url = isset( $nvx_treatment['url'] ) ? (string) $nvx_treatment['url'] : '';
		if ( '' === $nvx_url || isset( $nvx_footer_seen_urls[ $nvx_url ] ) ) {
			continue;
		}
		$nvx_footer_treatments[]          = $nvx_treatment;
		$nvx_footer_seen_urls[ $nvx_url ] = true;
	}
}
$nvx_split_at = (int) ceil( count( $nvx_footer_treatments ) / 2 );
$nvx_col_one  = array_slice( $nvx_footer_treatments, 0, $nvx_split_at );
$nvx_col_two  = array_slice( $nvx_footer_treatments, $nvx_split_at );

?>

<footer class="nvx-footer" role="contentinfo">
	<div class="nvx-footer__inner">

		<div class="nvx-footer__brand">
			<a
				href="<?php echo esc_url( home_url( '/' ) ); ?>"
				class="nvx-logo"
				aria-label="<?php esc_attr_e( 'NUVANX MEDICINA ESTÉTICA LÁSER — Inicio', 'nuvanx-medical' ); ?>"
			>
				<span class="nvx-logo__wordmark">NUVANX</span>
				<span class="nvx-logo__tagline"><?php esc_html_e( 'Medicina Estética Láser', 'nuvanx-medical' ); ?></span>
			</a>
			<p class="nvx-footer__cities">Madrid · Chamberí<br>Madrid · Salamanca</p>
			<div class="nvx-footer__social">
				<a href="https://www.instagram.com/nuvanx/" class="nvx-footer__social-link" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Síguenos en Instagram', 'nuvanx-medical' ); ?>">
					<svg class="nvx-footer__social-icon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
				</a>
				<a href="https://www.facebook.com/profile.php?id=61593612745090" class="nvx-footer__social-link" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Síguenos en Facebook', 'nuvanx-medical' ); ?>">
					<svg class="nvx-footer__social-icon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
				</a>
			</div>
		</div>

		<div class="nvx-footer__main">
			<details class="nvx-footer__section nvx-footer__section--treatments">
				<summary class="nvx-footer__section-title"><?php esc_html_e( 'Tratamientos', 'nuvanx-medical' ); ?></summary>
				<div class="nvx-footer__treatments">
					<div class="nvx-footer__links">
						<?php foreach ( $nvx_col_one as $nvx_treatment ) : ?>
							<a href="<?php echo esc_url( (string) $nvx_treatment['url'] ); ?>"><?php echo esc_html( (string) $nvx_treatment['label'] ); ?></a>
						<?php endforeach; ?>
					</div>
					<div class="nvx-footer__links">
						<?php foreach ( $nvx_col_two as $nvx_treatment ) : ?>
							<a href="<?php echo esc_url( (string) $nvx_treatment['url'] ); ?>"><?php echo esc_html( (string) $nvx_treatment['label'] ); ?></a>
						<?php endforeach; ?>
						<a href="<?php echo esc_url( home_url( '/tratamientos/' ) ); ?>" aria-label="<?php esc_attr_e( 'Ver todos los tratamientos', 'nuvanx-medical' ); ?>"><?php esc_html_e( 'Ver todos →', 'nuvanx-medical' ); ?></a>
					</div>
				</div>
			</details>

			<details class="nvx-footer__section nvx-footer__section--clinics">
				<summary class="nvx-footer__section-title"><?php esc_html_e( 'Clínicas', 'nuvanx-medical' ); ?></summary>
				<div class="nvx-footer__clinics">
					<?php foreach ( array( 'chamberi', 'goya' ) as $nvx_clinic_key ) : ?>
						<?php
						$nvx_clinic = isset( $nvx_footer_clinics[ $nvx_clinic_key ] ) && is_array( $nvx_footer_clinics[ $nvx_clinic_key ] )
							? $nvx_footer_clinics[ $nvx_clinic_key ]
							: array();
						$nvx_clinic_path    = (string) ( $nvx_clinic['landing_path'] ?? '' );
						$nvx_clinic_phone   = (string) ( $nvx_clinic['phone'] ?? '' );
						$nvx_clinic_phone_h = (string) ( $nvx_clinic['phone_href'] ?? '' );
						$nvx_clinic_address = (string) ( $nvx_clinic['address'] ?? '' );
						$nvx_clinic_postal  = (string) ( $nvx_clinic['postal_code'] ?? '' );
						$nvx_clinic_locality = (string) ( $nvx_clinic['locality'] ?? '' );
						$nvx_clinic_label   = (string) ( $nvx_clinic['short_name'] ?? '' );
						?>
						<?php if ( '' !== $nvx_clinic_path && '' !== $nvx_clinic_label ) : ?>
							<div class="nvx-footer__clinic">
								<a href="<?php echo esc_url( home_url( $nvx_clinic_path ) ); ?>" class="nvx-footer__clinic-name"><?php echo esc_html( $nvx_clinic_label ); ?></a>
								<?php if ( '' !== $nvx_clinic_phone_h && '' !== $nvx_clinic_phone ) : ?>
									<a href="<?php echo esc_url( 'tel:' . $nvx_clinic_phone_h ); ?>" class="nvx-footer__clinic-phone"><?php echo esc_html( $nvx_clinic_phone ); ?></a>
								<?php endif; ?>
								<?php if ( '' !== $nvx_clinic_address ) : ?>
									<address class="nvx-footer__address">
										<?php echo esc_html( $nvx_clinic_address ); ?><br>
										<?php echo esc_html( trim( $nvx_clinic_postal . ' ' . $nvx_clinic_locality ) ); ?>
									</address>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
					<a href="<?php echo esc_url( home_url( '/clinicas-de-medicina-estetica-nuvanx/' ) ); ?>" class="nvx-footer__clinics-all">Nuestras clínicas</a>
				</div>
			</details>

			<details class="nvx-footer__section nvx-footer__section--nuvanx">
				<summary class="nvx-footer__section-title"><?php esc_html_e( 'NUVANX', 'nuvanx-medical' ); ?></summary>
				<div class="nvx-footer__links">
					<a href="<?php echo esc_url( home_url( '/nosotros/' ) ); ?>">Nosotros</a>
					<?php if ( '' !== $nvx_why_nuvanx_url ) : ?>
						<a href="<?php echo esc_url( $nvx_why_nuvanx_url ); ?>">Por qué NUVANX</a>
					<?php endif; ?>
					<?php if ( '' !== $nvx_investment_url ) : ?>
						<a href="<?php echo esc_url( $nvx_investment_url ); ?>">Inversión</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( home_url( '/equipo-medico/' ) ); ?>">Equipo médico</a>
					<?php if ( $nvx_cases_public ) : ?>
						<a href="<?php echo esc_url( home_url( '/casos-de-pacientes/' ) ); ?>">Casos de pacientes</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">Blog</a>
					<a href="<?php echo esc_url( home_url( '/contacto/' ) ); ?>">Contacto</a>
					<a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>">Valoración médica</a>
				</div>
			</details>
		</div>

	</div>

	<div class="nvx-footer__bottom">
		<div class="nvx-footer__bottom-inner">
			<p class="nvx-footer__copyright">
				&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> NUVANX Medicina Estética Láser en Madrid
			</p>
			<nav class="nvx-footer__legal-nav" aria-label="<?php esc_attr_e( 'Información legal', 'nuvanx-medical' ); ?>">
				<a href="<?php echo esc_url( home_url( '/aviso-legal/' ) ); ?>">Aviso legal</a>
				<span aria-hidden="true"> · </span>
				<a href="<?php echo esc_url( home_url( '/politica-privacidad/' ) ); ?>">Política de privacidad</a>
				<span aria-hidden="true"> · </span>
				<a href="<?php echo esc_url( home_url( '/politica-de-cookies-ue/' ) ); ?>">Política de cookies</a>
			</nav>
			<?php
			$nvx_footer_reg_parts = array();
			foreach ( array( 'chamberi', 'goya' ) as $nvx_clinic_key ) {
				$nvx_clinic = isset( $nvx_footer_clinics[ $nvx_clinic_key ] ) && is_array( $nvx_footer_clinics[ $nvx_clinic_key ] )
					? $nvx_footer_clinics[ $nvx_clinic_key ]
					: array();
				$nvx_label = (string) ( $nvx_clinic['short_name'] ?? '' );
				$nvx_reg   = (string) ( $nvx_clinic['reg'] ?? '' );
				if ( '' !== $nvx_label && '' !== $nvx_reg ) {
					$nvx_footer_reg_parts[] = sprintf( '%s · Centro sanitario autorizado %s', $nvx_label, $nvx_reg );
				}
			}
			?>
			<?php if ( ! empty( $nvx_footer_reg_parts ) ) : ?>
				<p class="nvx-footer__registrations nvx-reg-copy"><?php echo esc_html( implode( ' · ', $nvx_footer_reg_parts ) ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</footer>

<?php if ( function_exists( 'nvx_valoracion_modal_enabled' ) && nvx_valoracion_modal_enabled() ) : ?>
<div class="nvx-sticky-mobile-cta" aria-label="<?php esc_attr_e( 'Acción rápida de reserva', 'nuvanx-medical' ); ?>">
	<a
		href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>"
		class="nvx-sticky-mobile-cta__btn nvx-open-valoracion-modal"
		data-nvx-valoracion-modal="1"
		aria-haspopup="dialog"
		data-gtag="click-reserve"
	>
		<?php esc_html_e( 'Solicitar Valoración Médica', 'nuvanx-medical' ); ?>
	</a>
</div>
<?php endif; ?>

<?php wp_footer(); ?>

</body>
</html>
