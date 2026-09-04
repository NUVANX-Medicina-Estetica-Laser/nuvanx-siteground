<?php
/**
 * Canonical patient-cases page.
 *
 * Renders repository-governed clinical cases whose publication consent and
 * editorial indexing policy are owned by the canonical data/manifest layer.
 * This template owns presentation only and does not maintain a parallel
 * publication-readiness switch.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cases_data    = function_exists( 'nvx_catalog_json_load' ) ? nvx_catalog_json_load( 'patient-cases.json' ) : array();
$consent_states = $cases_data['consent_states'] ?? array();
$cases_list     = $cases_data['cases'] ?? array();

// Case publication requires affirmative consent. Media publication is governed
// independently below so a valid clinical record can remain public while a
// disputed or incomplete photographic pair fails closed.
$cases_list = array_filter(
	$cases_list,
	static function ( $case ): bool {
		return is_array( $case ) && 'approved' === ( $case['consent_status'] ?? '' );
	}
);

$disclaimer = $cases_data['disclaimer'] ?? __( 'Los resultados y evoluciones mostrados corresponden a casos individuales documentados en consulta médica con consentimiento expreso del paciente. La respuesta biológica, calidad tisular y tiempos de recuperación varían según cada persona. La documentación gráfica no constituye una garantía de resultado idéntico. Todo tratamiento médico requiere valoración anatómica presencial previa.', 'nuvanx-medical' );

$css_relative = '/assets/css/nvx-cases.css';
$css_path     = get_template_directory() . $css_relative;
if ( is_readable( $css_path ) ) {
	$version = function_exists( 'nvx_asset_version' )
		? nvx_asset_version( $css_relative )
		: (string) filemtime( $css_path );

	wp_enqueue_style(
		'nvx-cases',
		get_template_directory_uri() . $css_relative,
		array( 'nvx-components', 'nvx-patterns' ),
		$version
	);
}

get_header();
?>
<div class="nvx-page nvx-brand-page nvx-cases" aria-labelledby="nvx-cases-h1">
	<section class="nvx-brand-hero nvx-cases__hero" aria-labelledby="nvx-cases-h1">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'EVIDENCIA CLÍNICA · MADRID', 'nuvanx-medical' ); ?></p>
				<h1 id="nvx-cases-h1" class="nvx-brand-hero__title"><?php esc_html_e( 'Casos clínicos de pacientes', 'nuvanx-medical' ); ?></h1>
				<p class="nvx-brand-lead"><?php esc_html_e( 'Evolución documentada en consulta médica con consentimiento expreso del paciente. Contexto anatómico, técnica aplicada, número de sesiones e intervalos de seguimiento.', 'nuvanx-medical' ); ?></p>
			</div>
		</div>
	</section>

	<section class="nvx-brand-section nvx-cases__intro" aria-labelledby="nvx-cases-intro-title">
		<div class="nvx-shell nvx-brand-section__inner">
			<p class="nvx-brand-kicker"><?php esc_html_e( 'PUBLICACIÓN RESPONSABLE', 'nuvanx-medical' ); ?></p>
			<h2 id="nvx-cases-intro-title" class="nvx-brand-title"><?php esc_html_e( 'Criterios de registro y transparencia clínica', 'nuvanx-medical' ); ?></h2>
			<p class="nvx-brand-body nvx-cases__lead"><?php esc_html_e( 'Cada caso clínico documentado en NUVANX cumple con criterios rigurosos de consentimiento, estandarización de tomas y contexto técnico para interpretar la respuesta tisular sin falsas expectativas ni promesas de resultado garantizado.', 'nuvanx-medical' ); ?></p>

			<ul class="nvx-cases__grid">
				<li class="nvx-brand-card nvx-cases__card">
					<h3 class="nvx-brand-card__title"><?php esc_html_e( 'Consentimiento y confidencialidad', 'nuvanx-medical' ); ?></h3>
					<p class="nvx-brand-card__body"><?php esc_html_e( 'Firma previa de consentimiento informado específico para registro clínico fotográfico y divulgación médica disociada.', 'nuvanx-medical' ); ?></p>
				</li>
				<li class="nvx-brand-card nvx-cases__card">
					<h3 class="nvx-brand-card__title"><?php esc_html_e( 'Fotografía estandarizada', 'nuvanx-medical' ); ?></h3>
					<p class="nvx-brand-card__body"><?php esc_html_e( 'Mismo plano anatómico, posición y encuadre comparable, registrando cualquier variación en condiciones lumínicas o posturales.', 'nuvanx-medical' ); ?></p>
				</li>
				<li class="nvx-brand-card nvx-cases__card">
					<h3 class="nvx-brand-card__title"><?php esc_html_e( 'Tiempos biológicos reales', 'nuvanx-medical' ); ?></h3>
					<p class="nvx-brand-card__body"><?php esc_html_e( 'Seguimiento a 3 y 6 meses en consulta, respetando el intervalo necesario para valorar la maduración de neocolagénesis y retracción dérmica.', 'nuvanx-medical' ); ?></p>
				</li>
			</ul>
		</div>
	</section>

	<section class="nvx-brand-section nvx-cases__scope" aria-labelledby="nvx-cases-scope-title">
		<div class="nvx-shell nvx-brand-section__inner">
			<p class="nvx-brand-kicker"><?php esc_html_e( 'EVIDENCIA DOCUMENTADA', 'nuvanx-medical' ); ?></p>
			<h2 id="nvx-cases-scope-title" class="nvx-brand-title"><?php esc_html_e( 'Casos clínicos documentados', 'nuvanx-medical' ); ?></h2>

			<?php if ( ! empty( $cases_list ) ) : ?>
				<ul class="nvx-cases-list">
					<?php foreach ( $cases_list as $case ) : ?>
						<?php
						$media_is_approved = 'clinical_case' === ( $case['media_scope'] ?? '' )
							&& 'before_after' === ( $case['media_kind'] ?? '' )
							&& 'approved' === ( $case['media_status'] ?? '' );
						?>
						<li class="nvx-case-card" id="<?php echo esc_attr( $case['id'] ?? '' ); ?>">
							<div class="nvx-case-card__header">
								<span class="nvx-case-card__badge"><?php echo esc_html( $case['category_label'] ?? '' ); ?></span>
								<h3 class="nvx-case-card__title"><?php echo esc_html( $case['title'] ?? '' ); ?></h3>
							</div>

							<?php if ( $media_is_approved && ! empty( $case['image_before'] ) && ! empty( $case['image_after'] ) ) : ?>
								<?php
								$before_relative = '/' . ltrim( (string) $case['image_before'], '/' );
								$after_relative  = '/' . ltrim( (string) $case['image_after'], '/' );
								$before_src      = get_template_directory_uri() . $before_relative;
								$after_src       = get_template_directory_uri() . $after_relative;
								$title           = $case['title'] ?? 'Caso clínico';
								?>
								<div class="nvx-case-card__visual">
									<div class="nvx-case-card__gallery">
										<figure class="nvx-case-card__gallery-item">
											<span class="nvx-case-card__gallery-label"><?php esc_html_e( 'Antes', 'nuvanx-medical' ); ?></span>
											<img src="<?php echo esc_url( $before_src ); ?>" alt="<?php echo esc_attr( $title . ' — Antes del tratamiento' ); ?>" class="nvx-case-card__img" loading="lazy" decoding="async">
										</figure>
										<figure class="nvx-case-card__gallery-item">
											<span class="nvx-case-card__gallery-label"><?php esc_html_e( 'Después', 'nuvanx-medical' ); ?></span>
											<img src="<?php echo esc_url( $after_src ); ?>" alt="<?php echo esc_attr( $title . ' — Resultado y evolución' ); ?>" class="nvx-case-card__img" loading="lazy" decoding="async">
										</figure>
									</div>
									<div class="nvx-case-card__visual-caption">
										<span class="nvx-case-card__visual-badge"><?php esc_html_e( 'Registro clínico estandarizado', 'nuvanx-medical' ); ?></span>
										<span class="nvx-case-card__visual-consent"><?php echo esc_html( $consent_states[ $case['consent_status'] ] ?? $case['consent_status'] ?? '' ); ?></span>
									</div>
								</div>
							<?php endif; ?>

							<dl class="nvx-case-card__meta-grid">
								<div class="nvx-case-card__meta-item">
									<dt class="nvx-case-card__meta-label"><?php esc_html_e( 'Zona anatómica:', 'nuvanx-medical' ); ?></dt>
									<dd class="nvx-case-card__meta-value"><?php echo esc_html( $case['area'] ?? '' ); ?></dd>
								</div>
								<div class="nvx-case-card__meta-item">
									<dt class="nvx-case-card__meta-label"><?php esc_html_e( 'Indicación médica:', 'nuvanx-medical' ); ?></dt>
									<dd class="nvx-case-card__meta-value"><?php echo esc_html( $case['indication'] ?? '' ); ?></dd>
								</div>
								<div class="nvx-case-card__meta-item">
									<dt class="nvx-case-card__meta-label"><?php esc_html_e( 'Técnica aplicada:', 'nuvanx-medical' ); ?></dt>
									<dd class="nvx-case-card__meta-value"><?php echo esc_html( $case['technique'] ?? '' ); ?></dd>
								</div>
								<div class="nvx-case-card__meta-item">
									<dt class="nvx-case-card__meta-label"><?php esc_html_e( 'Pauta de sesiones:', 'nuvanx-medical' ); ?></dt>
									<dd class="nvx-case-card__meta-value"><?php echo esc_html( $case['sessions'] ?? '' ); ?></dd>
								</div>
								<div class="nvx-case-card__meta-item">
									<dt class="nvx-case-card__meta-label"><?php esc_html_e( 'Seguimiento clínico:', 'nuvanx-medical' ); ?></dt>
									<dd class="nvx-case-card__meta-value"><?php echo esc_html( $case['followup'] ?? '' ); ?></dd>
								</div>
								<div class="nvx-case-card__meta-item">
									<dt class="nvx-case-card__meta-label"><?php esc_html_e( 'Anestesia:', 'nuvanx-medical' ); ?></dt>
									<dd class="nvx-case-card__meta-value"><?php echo esc_html( $case['anesthesia'] ?? '' ); ?></dd>
								</div>
							</dl>

							<div class="nvx-case-card__notes-box">
								<h4 class="nvx-case-card__notes-title"><?php esc_html_e( 'Evolución y hallazgos clínicos', 'nuvanx-medical' ); ?></h4>
								<p class="nvx-case-card__notes-body"><?php echo esc_html( $case['clinical_notes'] ?? '' ); ?></p>
								<p class="nvx-case-card__photo-note"><?php echo esc_html( $case['photo_conditions'] ?? '' ); ?></p>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="nvx-cases-disclaimer-box">
				<p class="nvx-cases-disclaimer-text"><?php echo esc_html( $disclaimer ); ?></p>
			</div>
		</div>
	</section>

	<section class="nvx-brand-section nvx-cases__criteria" aria-labelledby="nvx-cases-criteria-title">
		<div class="nvx-shell nvx-brand-section__inner">
			<p class="nvx-brand-kicker"><?php esc_html_e( 'VALORACIÓN MÉDICA', 'nuvanx-medical' ); ?></p>
			<h2 id="nvx-cases-criteria-title" class="nvx-brand-title"><?php esc_html_e( 'Cada caso requiere diagnóstico anatómico individual', 'nuvanx-medical' ); ?></h2>
			<div class="nvx-cases__criteria-grid">
				<div>
					<p class="nvx-brand-body"><?php esc_html_e( 'Una evolución clínica documentada ilustra una respuesta tisular concreta ante una indicación precisa. La idoneidad de cada procedimiento y el plan de tratamiento se determinan tras la exploración anatómica y ecográfica en consulta.', 'nuvanx-medical' ); ?></p>
				</div>
				<div>
					<p class="nvx-brand-body"><?php esc_html_e( 'Disponemos de consulta presencial de valoración médica en nuestras clínicas de Chamberí y Salamanca–Goya, Madrid.', 'nuvanx-medical' ); ?></p>
				</div>
			</div>
		</div>
	</section>
</div>
<?php
get_footer();
