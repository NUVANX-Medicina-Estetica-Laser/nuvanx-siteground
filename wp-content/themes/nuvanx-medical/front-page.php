<?php
/**
 * Canonical front page template.
 *
 * Complete theme-owned markup: no block-content dependency and no nested main
 * landmark. Media URLs use the active WordPress content origin; clinic and
 * clinician identity data come from their canonical registries.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

$hero_video_url  = content_url( '/uploads/2026/07/nvx-home-video-portada-hero-12s-720p.mp4' );
$hero_poster_url = function_exists( 'nvx_resolve_home_hero_poster_url' ) ? nvx_resolve_home_hero_poster_url() : content_url( '/uploads/2026/07/nvx-home-video-portada-poster.webp' );
$evidence_image  = content_url( '/uploads/2026/07/consulta-medica-personalizada-nuvanx-madrid.webp' );
$clinics         = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();

ob_start();
?>
<div id="nvx-home-v3" class="nvx-home-v3">
	<section class="nvx-home-hero" aria-labelledby="nvx-home-hero-title">
		<picture class="nvx-home-hero__poster" aria-hidden="true">
			<?php if ( '' !== $hero_poster_mobile_url ) : ?>
				<source media="(max-width: 768px)" srcset="<?php echo esc_url( $hero_poster_mobile_url ); ?>" type="image/webp">
			<?php endif; ?>
			<img class="nvx-home-hero__poster-img" src="<?php echo esc_url( $hero_poster_url ); ?>" alt="" fetchpriority="high" loading="eager" decoding="async">
		</picture>
		<video id="nvx-home-hero-video" class="nvx-home-hero__video nvx-home-hero-video" autoplay muted loop playsinline preload="none" aria-label="Experiencia NUVANX Medicina Estética Láser en Madrid">
			<source src="<?php echo esc_url( $hero_video_url ); ?>" type="video/mp4">
		</video>
		<div class="nvx-home-hero__content nvx-home-hero__copy">
			<p class="nvx-home-hero__kicker">Endolift® · Láser CO₂ · Medicina Regenerativa · Madrid</p>
			<h1 id="nvx-home-hero-title" class="nvx-home-hero__title">Primero el diagnóstico médico. Luego, el tratamiento adecuado.<span class="nvx-hero-title-location"> Madrid.</span></h1>
			<p class="nvx-home-hero__lead">Antes de recomendar nada, escuchamos qué te preocupa y entendemos qué tendría sentido mejorar en tu caso.</p>
			<div class="nvx-brand-actions">
				<a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="nvx-brand-btn nvx-btn--light">Valoración gratuita — sin compromiso</a>
			</div>
		</div>
		<a href="#nvx-home-philosophy-title" class="nvx-home-hero__scroll-cue" aria-label="<?php esc_attr_e( 'Desplazarse al contenido', 'nuvanx-medical' ); ?>">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
		</a>
		<button type="button" id="nvx-hero-video-toggle" class="nvx-home-hero__video-toggle" aria-label="<?php esc_attr_e( 'Pausar vídeo de fondo', 'nuvanx-medical' ); ?>" aria-pressed="false">
			<span class="nvx-video-toggle__icon" aria-hidden="true">⏸</span>
		</button>
	</section>

	<section class="nvx-home-philosophy" aria-labelledby="nvx-home-philosophy-title">
		<div class="nvx-home-philosophy__inner">
			<p class="nvx-home-philosophy__kicker">Filosofía médica</p>
			<h2 class="nvx-home-philosophy__lead" id="nvx-home-philosophy-title">No tratamos una imagen aislada. Tratamos a una persona, con su historia y sus prioridades.</h2>
			<p class="nvx-home-philosophy__text">Cada protocolo comienza con una valoración médica individual. Si no está indicado para ti, te lo diremos con la misma claridad.</p>
		</div>
	</section>

	<section class="nvx-home-standard" aria-labelledby="nvx-home-standard-title">
		<header class="nvx-home-standard__header">
			<h2 id="nvx-home-standard-title" class="nvx-home-standard__title">Intervención mínima. Planificación estructural.</h2>
			<p class="nvx-home-standard__subtitle">Protocolos médicos definidos según anatomía, calidad del tejido y objetivos clínicos realistas.</p>
		</header>
		<div class="nvx-home-standard__grid">
			<div class="nvx-home-feature">
				<span class="nvx-home-feature__number" aria-hidden="true">01</span>
				<h3 class="nvx-home-feature__title">Abordajes sin incisiones quirúrgicas amplias</h3>
				<p class="nvx-home-feature__desc">Determinadas indicaciones pueden abordarse mediante microcánulas o fibra óptica, siempre tras exploración médica.</p>
			</div>
			<div class="nvx-home-feature">
				<span class="nvx-home-feature__number" aria-hidden="true">02</span>
				<h3 class="nvx-home-feature__title">Recuperación según el procedimiento</h3>
				<p class="nvx-home-feature__desc">El tiempo de reincorporación depende del tratamiento, la zona, los parámetros utilizados y la respuesta individual.</p>
			</div>
			<div class="nvx-home-feature">
				<span class="nvx-home-feature__number" aria-hidden="true">03</span>
				<h3 class="nvx-home-feature__title">Anestesia adaptada a la indicación</h3>
				<p class="nvx-home-feature__desc">Cuando procede, los tratamientos se realizan con anestesia local y seguimiento médico personalizado.</p>
			</div>
			<div class="nvx-home-feature">
				<span class="nvx-home-feature__number" aria-hidden="true">04</span>
				<h3 class="nvx-home-feature__title">Tratamiento combinado del contorno</h3>
				<p class="nvx-home-feature__desc">La reducción adiposa y la mejora de la firmeza pueden integrarse en un mismo plan cuando existe indicación.</p>
			</div>
			<div class="nvx-home-feature">
				<span class="nvx-home-feature__number" aria-hidden="true">05</span>
				<h3 class="nvx-home-feature__title">Evolución progresiva y seguimiento</h3>
				<p class="nvx-home-feature__desc">La evolución se revisa en consulta y varía según el tratamiento, el tejido y los hábitos de cada paciente.</p>
			</div>
		</div>
	</section>

	<section class="nvx-home-portfolio" aria-labelledby="nvx-home-portfolio-title">
		<header class="nvx-home-portfolio__header">
			<h2 id="nvx-home-portfolio-title" class="nvx-home-portfolio__title">Protocolos principales y tecnologías de referencia</h2>
		</header>
		<div class="nvx-home-portfolio__list">
			<article class="nvx-home-portfolio__item">
				<a href="<?php echo esc_url( home_url( '/endolift-facial-papada-mandibula/' ) ); ?>" class="nvx-home-portfolio__link">
					<span class="nvx-home-portfolio__number" aria-hidden="true">01</span>
					<h3 class="nvx-home-portfolio__name">Endolift® Facial</h3>
					<p class="nvx-home-portfolio__desc">Retracción tisular y definición del contorno mandibular mediante láser subdérmico.</p>
				</a>
			</article>
			<article class="nvx-home-portfolio__item">
				<a href="<?php echo esc_url( home_url( '/endolaser-corporal-grasa-localizada/' ) ); ?>" class="nvx-home-portfolio__link">
					<span class="nvx-home-portfolio__number" aria-hidden="true">02</span>
					<h3 class="nvx-home-portfolio__name">Endoláser Corporal</h3>
					<p class="nvx-home-portfolio__desc">Lipólisis láser focalizada para depósitos adiposos y flacidez.</p>
				</a>
			</article>
			<article class="nvx-home-portfolio__item">
				<a href="<?php echo esc_url( home_url( '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/' ) ); ?>" class="nvx-home-portfolio__link">
					<span class="nvx-home-portfolio__number" aria-hidden="true">03</span>
					<h3 class="nvx-home-portfolio__name">Láser CO₂ Fraccionado</h3>
					<p class="nvx-home-portfolio__desc">Renovación fraccionada para abordar fotodaño, cicatrices y textura según parámetros médicos.</p>
				</a>
			</article>
			<article class="nvx-home-portfolio__item">
				<a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="nvx-home-portfolio__link">
					<span class="nvx-home-portfolio__number" aria-hidden="true">04</span>
					<h3 class="nvx-home-portfolio__name">Medicina Estética Facial</h3>
					<p class="nvx-home-portfolio__desc">Planificación conservadora que respeta la identidad y las proporciones naturales.</p>
				</a>
			</article>
			<article class="nvx-home-portfolio__item">
				<a href="<?php echo esc_url( home_url( '/exion-btl/' ) ); ?>" class="nvx-home-portfolio__link">
					<span class="nvx-home-portfolio__number" aria-hidden="true">05</span>
					<h3 class="nvx-home-portfolio__name">EXION® y tecnologías BTL</h3>
					<p class="nvx-home-portfolio__desc">Protocolos para calidad cutánea, firmeza y tratamiento facial o corporal tras diagnóstico.</p>
				</a>
			</article>
		</div>
		<div class="nvx-home-portfolio__action">
			<a href="<?php echo esc_url( home_url( '/tratamientos/' ) ); ?>" class="nvx-brand-btn nvx-btn--secondary">Ver portafolio completo</a>
		</div>
	</section>

	<section class="nvx-home-evidence" aria-labelledby="nvx-home-evidence-title">
		<div class="nvx-home-evidence__grid">
			<div class="nvx-home-evidence__image-col">
				<?php
				$evidence_w = 1672;
				$evidence_h = 941;
				echo nvx_responsive_img_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
					$evidence_image,
					'Consulta médica personalizada en NUVANX Madrid',
					'class="nvx-home-evidence__image" width="' . (int) $evidence_w . '" height="' . (int) $evidence_h . '" loading="lazy" decoding="async"'
				);
				?>
			</div>
			<div class="nvx-home-evidence__text-col">
				<h2 id="nvx-home-evidence-title" class="nvx-home-evidence__title">El seguimiento clínico forma parte del protocolo, no es un extra.</h2>
				<p class="nvx-home-evidence__desc">Las revisiones se programan en semanas 4 y 8 para los protocolos de láser intersticial, y según la respuesta individual en los de radiofrecuencia y CO₂. La responsabilidad médica no termina al salir de consulta.</p>
				<?php
				$cases_id       = function_exists( 'nvx_page_id_by_slug' ) ? nvx_page_id_by_slug( 'casos-de-pacientes' ) : 0;
				$cases_ready    = ( $cases_id > 0 && '1' === (string) get_post_meta( $cases_id, '_nvx_cases_publication_ready', true ) ) || file_exists( get_template_directory() . '/inc/data/patient-cases.json' );
				$evidence_url   = $cases_ready ? home_url( '/casos-de-pacientes/' ) : home_url( '/por-que-nuvanx/' );
				$evidence_label = $cases_ready ? __( 'Ver casos clínicos de seguimiento', 'nuvanx-medical' ) : __( 'Conocer el método NUVANX', 'nuvanx-medical' );
				?>
				<a href="<?php echo esc_url( $evidence_url ); ?>" class="nvx-brand-btn nvx-btn--secondary-on-dark"><?php echo esc_html( $evidence_label ); ?></a>
			</div>
		</div>
	</section>

	<section class="nvx-home-team" aria-labelledby="nvx-home-team-title">
		<div class="nvx-home-team__inner">
			<div class="nvx-home-team__header">
				<h2 id="nvx-home-team-title" class="nvx-home-team__title">Dirección y criterio médico</h2>
				<a href="<?php echo esc_url( home_url( '/equipo-medico/' ) ); ?>" class="nvx-brand-btn nvx-btn--secondary">Conocer al equipo médico</a>
			</div>
			<div class="nvx-home-team__content">
				<p class="nvx-home-team__desc">El equipo integra experiencia clínica, valoración individual y seguimiento para seleccionar la tecnología adecuada en cada caso.</p>
				<ul class="nvx-home-team__list">
					<li><strong><?php echo esc_html( nvx_medical_staff_name( 'director' ) ); ?></strong> <span>Dirección médica. Endolift® y láser CO₂.</span></li>
					<li><strong><?php echo esc_html( nvx_medical_staff_name( 'ivon' ) ); ?></strong> <span>Medicina y well-aging.</span></li>
					<li><strong><?php echo esc_html( nvx_medical_staff_name( 'fabio' ) ); ?></strong> <span>Medicina e investigación en fisiología del envejecimiento.</span></li>
				</ul>
			</div>
		</div>
	</section>

	<section class="nvx-home-seo" aria-labelledby="nvx-home-seo-title">
		<div class="nvx-home-seo__inner">
			<p id="nvx-home-seo-title" class="nvx-home-seo__title">Áreas de valoración y tratamiento</p>
			<div class="nvx-home-seo__grid">
				<div class="nvx-home-seo__col">
					<h3 class="nvx-home-seo__col-title">Corporal</h3>
					<ul class="nvx-home-seo__list">
						<li><strong><a href="<?php echo esc_url( home_url( '/grasa-localizada-abdomen-flancos-madrid/' ) ); ?>" class="nvx-text-link">Abdomen y flancos:</a></strong> valoración de grasa localizada y firmeza.</li>
						<li><strong><a href="<?php echo esc_url( home_url( '/flacidez-muslos-internos-subgluteo-madrid/' ) ); ?>" class="nvx-text-link">Caderas y muslos:</a></strong> planificación del contorno según anatomía.</li>
						<li><strong><a href="<?php echo esc_url( home_url( '/flacidez-grasa-localizada-brazos-madrid/' ) ); ?>" class="nvx-text-link">Brazos, rodillas y espalda:</a></strong> protocolos ajustados al tejido.</li>
						<li><strong><a href="<?php echo esc_url( home_url( '/remodelacion-corporal-laser-madrid/' ) ); ?>" class="nvx-text-link">Calidad cutánea corporal:</a></strong> selección de tecnología según diagnóstico.</li>
					</ul>
				</div>
				<div class="nvx-home-seo__col">
					<h3 class="nvx-home-seo__col-title">Facial</h3>
					<ul class="nvx-home-seo__list">
						<li><strong><a href="<?php echo esc_url( home_url( '/papada-definicion-mandibular-madrid/' ) ); ?>" class="nvx-text-link">Tercio inferior:</a></strong> mandíbula, cuello y papada.</li>
						<li><strong><a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="nvx-text-link">Armonización:</a></strong> planificación conservadora de proporciones y soporte.</li>
						<li><strong><a href="<?php echo esc_url( home_url( '/calidad-piel-firmeza-luminosidad-madrid/' ) ); ?>" class="nvx-text-link">Calidad de piel:</a></strong> textura, poros, cicatrices y fotodaño.</li>
					</ul>
				</div>
			</div>
		</div>
	</section>

	<section class="nvx-home-locations" aria-labelledby="nvx-home-locations-title">
		<h2 id="nvx-home-locations-title" class="nvx-home-locations__title">Madrid. Dos sedes. Un único criterio médico.</h2>
		<div class="nvx-home-locations__grid">
			<?php foreach ( array( 'chamberi', 'goya' ) as $clinic_key ) : ?>
				<?php if ( isset( $clinics[ $clinic_key ] ) && is_array( $clinics[ $clinic_key ] ) ) : ?>
					<?php $clinic = $clinics[ $clinic_key ]; ?>
					<div class="nvx-home-location">
						<div class="nvx-home-location__map">
							<?php
							echo nvx_lazy_map_embed_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
								nvx_clinic_map_embed_url( $clinic ),
								sprintf( __( 'Ubicación en Google Maps de NUVANX %s', 'nuvanx-medical' ), (string) $clinic['short_name'] ),
								'nvx-map-embed--home'
							);
							?>
						</div>
						<h3 class="nvx-home-location__name"><?php echo esc_html( (string) $clinic['short_name'] ); ?></h3>
						<p class="nvx-home-location__address"><?php echo esc_html( (string) $clinic['address'] ); ?></p>
						<p class="nvx-home-location__desc"><?php echo esc_html( (string) ( $clinic['descriptor'] ?? '' ) ); ?></p>
						<span class="nvx-home-location__code nvx-reg-copy"><?php echo esc_html( 'Reg. Sanitario ' . (string) $clinic['reg'] ); ?></span>
						<a href="<?php echo esc_url( home_url( (string) $clinic['landing_path'] ) ); ?>" class="nvx-home-location__link">Ver sede y ubicación →</a>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="nvx-home-closure" aria-labelledby="nvx-home-closure-title">
		<h2 id="nvx-home-closure-title" class="nvx-home-closure__title">Primero el diagnóstico médico. Luego, el tratamiento adecuado</h2>
		<p class="nvx-home-closure__desc">Presupuesto y plan documentado por escrito en la primera visita. Tiempos de recuperación informados según el protocolo.</p>
		<div class="nvx-home-closure__actions">
			<a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="nvx-brand-btn nvx-btn--light nvx-open-valoracion-modal" data-nvx-valoracion-modal="1" aria-haspopup="dialog" data-gtag="click-reserve">Definir mi plan clínico</a>
			<a href="<?php echo esc_url( nvx_cta_whatsapp_url() ); ?>" class="nvx-brand-btn nvx-btn--secondary-on-dark" target="_blank" rel="noopener noreferrer" data-gtag="click-whatsapp">Contactar por WhatsApp</a>
		</div>
	</section>
</div>
<?php
$content = ob_get_clean();

set_query_var( 'nvx_shell_content', $content );
set_query_var( 'nvx_shell_skip_header', true );
set_query_var( 'nvx_shell_with_wrapper', true );
get_template_part( 'template-parts/content/nvx-page-shell' );
