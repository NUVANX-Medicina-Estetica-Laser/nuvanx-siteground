<?php
/**
 * Template Name: Sede Local
 *
 * Uses unified nvx-brand-hero pattern with banner for consistency.
 * Content and clinic details are managed by nvx-clinics-hub functions.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

// The clinics hub owns its complete hero/content through nvx-clinics-hub.php.
// Route only that hub through the canonical shell so page-sede.php does not
// print a second H1 before the managed the_content renderer runs.
if ( function_exists( 'nvxIsClinicsHub' ) && nvxIsClinicsHub() ) {
	get_template_part( 'template-parts/content/nvx-page-shell' );
	return;
}

// Get clinic-specific data for individual clinic pages.
$clinics = function_exists( 'nvx_schema_clinics' ) ? nvx_schema_clinics() : array();
$config  = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

// Determine which clinic this page represents based on URL/slug.
$current_slug = get_post_field( 'post_name', get_the_ID() );
$clinic_key   = 'chamberi';

if ( strpos( $current_slug, 'goya' ) !== false || strpos( $current_slug, 'salamanca' ) !== false ) {
	$clinic_key = 'goya';
}

$clinic_data   = isset( $clinics[ $clinic_key ] ) && is_array( $clinics[ $clinic_key ] ) ? $clinics[ $clinic_key ] : array();
$clinic_config = isset( $config[ $clinic_key ] ) && is_array( $config[ $clinic_key ] ) ? $config[ $clinic_key ] : array();

$clinic_name    = ! empty( $clinic_data['name'] ) ? (string) $clinic_data['name'] : '';
$clinic_address = ! empty( $clinic_config['address'] )
	? sprintf(
		'%s, %s %s',
		(string) $clinic_config['address'],
		(string) ( $clinic_config['postal_code'] ?? '' ),
		(string) ( $clinic_config['locality'] ?? '' )
	)
	: '';
$clinic_phone   = (string) ( $clinic_config['phone_href'] ?? '' );
$phone_display  = (string) ( $clinic_config['phone'] ?? '' );
$clinic_reg     = (string) ( $clinic_config['reg'] ?? '' );
$clinic_hours   = (string) ( $clinic_config['hours'] ?? '' );
$clinic_maps    = ! empty( $clinic_data['hasMap'] ) ? (string) $clinic_data['hasMap'] : '';
$whatsapp_url   = (string) ( $clinic_config['whatsapp_href'] ?? '' );
$valoracion_url = home_url( '/madrid/valoracion/' );

ob_start();
?>

<!-- Content goes inside .nvx-brand-page wrapper from header.php -->
<section class="nvx-brand-hero" aria-labelledby="nvx-sede-hero-title">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'Clínicas NUVANX · Madrid', 'nuvanx-medical' ); ?></p>
				<h1 id="nvx-sede-hero-title" class="nvx-brand-hero__title">
					<?php
					if ( 'chamberi' === $clinic_key ) {
						esc_html_e( 'Medicina estética en Chamberí, Madrid — clínica NUVANX', 'nuvanx-medical' );
					} else {
						esc_html_e( 'Medicina estética en Goya y Barrio de Salamanca — clínica NUVANX', 'nuvanx-medical' );
					}
					?>
				</h1>
				<?php
				if ( function_exists( 'nvx_clinical_authority_byline_markup' ) ) {
					echo nvx_clinical_authority_byline_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
				}
				?>
				<?php if ( '' !== $clinic_address && '' !== $phone_display && '' !== $clinic_reg ) : ?>
					<p class="nvx-brand-hero__lead">
						<?php
						$lead_format = 'chamberi' === $clinic_key
							? __( 'Clínica de medicina estética en Chamberí: %1$s. Teléfono %2$s. Centro sanitario %3$s. Valoración médica presencial antes de cualquier tratamiento.', 'nuvanx-medical' )
							: __( 'Clínica de medicina estética láser en Goya, Barrio de Salamanca: %1$s. Teléfono %2$s. Centro sanitario %3$s. Valoración médica presencial antes de cualquier tratamiento.', 'nuvanx-medical' );
						echo esc_html( sprintf( $lead_format, $clinic_address, $phone_display, $clinic_reg ) );
						?>
					</p>
				<?php endif; ?>
				<div class="nvx-brand-actions">
					<a href="<?php echo esc_url( $valoracion_url ); ?>" class="nvx-brand-btn nvx-brand-btn--primary">
						<?php esc_html_e( 'Solicitar valoración médica', 'nuvanx-medical' ); ?>
					</a>
					<?php if ( '' !== $whatsapp_url ) : ?>
						<a href="<?php echo esc_url( $whatsapp_url ); ?>" class="nvx-brand-btn nvx-brand-btn--secondary" rel="noopener noreferrer" target="_blank">
							<?php esc_html_e( 'Contactar por WhatsApp', 'nuvanx-medical' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $clinic_reg ) : ?>
					<p class="nvx-brand-meta nvx-reg-copy">
						<?php echo esc_html( sprintf( __( 'Registro sanitario: %1$s · %2$s, Madrid', 'nuvanx-medical' ), $clinic_reg, 'chamberi' === $clinic_key ? 'Chamberí' : 'Salamanca–Goya' ) ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</section>

		<section class="nvx-brand-section" aria-label="<?php esc_attr_e( 'Información de la sede', 'nuvanx-medical' ); ?>">
			<div class="nvx-brand-section__inner">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'Datos de contacto', 'nuvanx-medical' ); ?></p>
				<h2 class="nvx-brand-title"><?php esc_html_e( 'Ubicación y horarios', 'nuvanx-medical' ); ?></h2>

				<div class="nvx-brand-grid nvx-brand-grid--2">
					<div class="nvx-brand-card">
						<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Dirección', 'nuvanx-medical' ); ?></h3>
						<p class="nvx-body">
							<svg class="nvx-icon" aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
							<?php echo esc_html( $clinic_address ); ?>
						</p>
					</div>

					<div class="nvx-brand-card">
						<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Teléfono', 'nuvanx-medical' ); ?></h3>
						<p class="nvx-body">
							<svg class="nvx-icon" aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 1 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
							<?php if ( '' !== $phone_display && '' !== $clinic_phone ) : ?>
								<a href="<?php echo esc_url( 'tel:' . $clinic_phone ); ?>"><?php echo esc_html( $phone_display ); ?></a>
							<?php endif; ?>
						</p>
					</div>

					<div class="nvx-brand-card">
						<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Horario', 'nuvanx-medical' ); ?></h3>
						<p class="nvx-body">
							<svg class="nvx-icon" aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
							<?php echo esc_html( $clinic_hours ); ?>
						</p>
					</div>

					<?php if ( '' !== $clinic_maps ) : ?>
						<div class="nvx-brand-card">
							<h3 class="nvx-brand-subtitle"><?php esc_html_e( 'Cómo llegar', 'nuvanx-medical' ); ?></h3>
							<p class="nvx-body">
								<a href="<?php echo esc_url( $clinic_maps ); ?>" class="nvx-brand-btn nvx-brand-btn--primary" rel="noopener noreferrer" target="_blank">
									<?php esc_html_e( 'Ver en Google Maps', 'nuvanx-medical' ); ?>
								</a>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<?php
		$clinic_photos = function_exists( 'nvx_clinic_landing_photos' )
			? nvx_clinic_landing_photos( $clinic_key )
			: array();
		$clinic_gallery_expected = function_exists( 'nvx_clinic_landing_gallery_expected_count' )
			? nvx_clinic_landing_gallery_expected_count( $clinic_key )
			: ( 'goya' === $clinic_key ? 2 : 4 );
		$clinic_gallery_complete = function_exists( 'nvx_clinic_landing_gallery_is_complete' )
			? nvx_clinic_landing_gallery_is_complete( $clinic_photos, $clinic_key )
			: $clinic_gallery_expected === count( $clinic_photos );
		if ( $clinic_gallery_complete ) :
			?>
		<section class="nvx-brand-section nvx-clinic-gallery" aria-labelledby="nvx-clinic-gallery-title">
			<div class="nvx-brand-section__inner">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'La sede', 'nuvanx-medical' ); ?></p>
				<h2 id="nvx-clinic-gallery-title" class="nvx-brand-title"><?php esc_html_e( 'Fachada, salas y consulta', 'nuvanx-medical' ); ?></h2>
				<div class="nvx-clinic-gallery__grid">
					<?php foreach ( $clinic_photos as $photo ) : ?>
						<?php
						$attachment_id = (int) ( $photo['id'] ?? 0 );
						$alt           = (string) ( $photo['alt'] ?? '' );
						$sizes         = '(min-width: 1024px) 50vw, (min-width: 641px) 50vw, 100vw';
						if ( $attachment_id > 0 ) {
							$image = wp_get_attachment_image(
								$attachment_id,
								'full',
								false,
								array(
									'class'    => 'nvx-clinic-gallery__image',
									'alt'      => $alt,
									'loading'  => 'lazy',
									'decoding' => 'async',
									'sizes'    => $sizes,
								)
							);
						} else {
							$src    = isset( $photo['file'] ) ? (string) $photo['file'] : '';
							$srcset = isset( $photo['srcset'] ) ? (string) $photo['srcset'] : '';
							$width  = isset( $photo['width'] ) ? (int) $photo['width'] : 0;
							$height = isset( $photo['height'] ) ? (int) $photo['height'] : 0;
							$image  = '';
							if ( '' !== $src && '' !== $srcset && $width > 0 && $height > 0 ) {
								$image = sprintf(
									'<img class="nvx-clinic-gallery__image" src="%1$s" srcset="%2$s" sizes="%3$s" width="%4$d" height="%5$d" alt="%6$s" loading="lazy" decoding="async">',
									esc_url( $src ),
									esc_attr( $srcset ),
									esc_attr( $sizes ),
									$width,
									$height,
									esc_attr( $alt )
								);
							}
						}
						if ( '' === $image ) {
							continue;
						}
						?>
						<figure class="nvx-clinic-gallery__item">
							<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image escapes. ?>
							<figcaption><?php echo esc_html( (string) ( $photo['caption'] ?? '' ) ); ?></figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
				<?php
				$gbp_review = function_exists( 'nvx_gbp_review_url' ) ? nvx_gbp_review_url( $clinic_key ) : '';
				if ( '' !== $gbp_review ) :
					?>
					<p class="nvx-body nvx-body--measure">
						<a class="nvx-brand-inline-link" href="<?php echo esc_url( $gbp_review ); ?>" rel="noopener noreferrer" target="_blank">
							<?php esc_html_e( 'Ver o dejar una opinión en Google', 'nuvanx-medical' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</section>
			<?php
		endif;
		if ( ! $clinic_gallery_complete ) :
			?>
			<section class="nvx-brand-section nvx-clinic-gallery" data-nvx-gallery-contract="incomplete" aria-labelledby="nvx-clinic-gallery-title">
				<div class="nvx-brand-section__inner">
					<h2 id="nvx-clinic-gallery-title" class="nvx-brand-title"><?php esc_html_e( 'Galería de la sede temporalmente no disponible', 'nuvanx-medical' ); ?></h2>
					<p class="nvx-brand-lead"><?php esc_html_e( 'Estamos actualizando las imágenes verificadas de esta sede. La galería se publicará de nuevo cuando estén disponibles las fotografías editoriales aprobadas para esta sede.', 'nuvanx-medical' ); ?></p>
				</div>
			</section>
			<?php
		endif;

		if ( 'goya' === $clinic_key && function_exists( 'nvx_goya_clinical_team_markup' ) ) {
			echo nvx_goya_clinical_team_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
		}
		?>

		<?php
		$embed_map_query = ( 'chamberi' === $clinic_key )
			? 'Calle+de+Fernandez+de+la+Hoz+4+28010+Madrid'
			: 'Calle+de+Fernan+Gonzalez+26+28009+Madrid';
		?>
		<section class="nvx-brand-section nvx-brand-section--map" aria-label="<?php echo esc_attr( sprintf( __( 'Mapa de ubicación de %s', 'nuvanx-medical' ), $clinic_name ) ); ?>">
			<div class="nvx-brand-section__inner">
				<div class="nvx-map-container">
					<?php
					echo nvx_lazy_map_embed_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
						'https://maps.google.com/maps?q=' . $embed_map_query . '&t=&z=16&ie=UTF8&iwloc=&output=embed',
						sprintf( __( 'Ubicación en Google Maps de %s', 'nuvanx-medical' ), $clinic_name )
					);
					?>
				</div>
			</div>
		</section>

	<div class="entry-content nvx-page__content">
		<?php the_content(); ?>
	</div>

<?php
$content = ob_get_clean();

set_query_var( 'nvx_shell_content', $content );
set_query_var( 'nvx_shell_skip_header', true );
get_template_part( 'template-parts/content/nvx-page-shell' ); ?>
