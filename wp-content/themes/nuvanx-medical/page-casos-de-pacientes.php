<?php
/**
 * Canonical patient-cases page.
 *
 * While real evidence is pending explicit editorial approval, this slug-specific
 * template renders a responsible holding state owned by the theme. Once
 * `_nvx_cases_publication_ready=1`, control returns to the ordinary page shell.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cases_data = function_exists( 'nvx_catalog_json_load' ) ? nvx_catalog_json_load( 'patient-cases.json' ) : array();
$cases_list = $cases_data['cases'] ?? array();
$disclaimer = $cases_data['disclaimer'] ?? __( 'Los resultados y evoluciones mostrados corresponden a casos individuales documentados en consulta médica con consentimiento expreso del paciente. La respuesta biológica, calidad tisular y tiempos de recuperación varían según cada persona. La documentación gráfica no constituye una garantía de resultado idéntico. Todo tratamiento médico requiere valoración anatómica presencial previa.', 'nuvanx-medical' );

$css_relative = '/assets/css/nvx-cases-holding.css';
$css_path     = get_template_directory() . $css_relative;
if ( is_readable( $css_path ) ) {
	$version = function_exists( 'nvx_asset_version' )
		? nvx_asset_version( $css_relative )
		: (string) filemtime( $css_path );

	if ( ! function_exists( 'nvx_theme_public_delivers_inline_styles' ) || ! nvx_theme_public_delivers_inline_styles() ) {
		wp_enqueue_style(
			'nvx-cases-holding',
			get_template_directory_uri() . $css_relative,
			array( 'nvx-components', 'nvx-patterns' ),
			$version
		);
	}
}

get_header();
?>
<div class="nvx-page nvx-brand-page nvx-cases-holding" aria-labelledby="nvx-cases-h1">
	<section class="nvx-brand-hero nvx-cases-holding__hero" aria-labelledby="nvx-cases-h1">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker"><?php esc_html_e( 'EVIDENCIA CLÍNICA · MADRID', 'nuvanx-medical' ); ?></p>
				<h1 id="nvx-cases-h1" class="nvx-brand-hero__title"><?php esc_html_e( 'Casos clínicos de pacientes', 'nuvanx-medical' ); ?></h1>
				<p class="nvx-brand-lead"><?php esc_html_e( 'Evolución documentada en consulta médica con consentimiento expreso del paciente. Contexto anatómico, técnica aplicada, número de sesiones e intervalos de seguimiento.', 'nuvanx-medical' ); ?></p>
			</div>
		</div>
	</section>

	<section class="nvx-brand-section nvx-cases-holding__intro" aria-labelledby="nvx-cases-intro-title">
		<div class="nvx-shell nvx-brand-section__inner">
			<p class="nvx-brand-kicker"><?php esc_html_e( 'PUBLICACIÓN RESPONSABLE', 'nuvanx-medical' ); ?></p>
			<h2 id="nvx-cases-intro-title" class="nvx-brand-title"><?php esc_html_e( 'Criterios de registro y transparencia clínica', 'nuvanx-medical' ); ?></h2>
			<p class="nvx-brand-body nvx-cases-holding__lead"><?php esc_html_e( 'Cada caso clínico documentado en NUVANX cumple con criterios rigurosos de consentimiento, estandarización de tomas y contexto técnico para interpretar la respuesta tisular sin falsas expectativas ni promesas de resultado garantizado.', 'nuvanx-medical' ); ?></p>

			<ul class="nvx-cases-holding__grid">
				<li class="nvx-brand-card nvx-cases-holding__card">
					<h3 class="nvx-brand-card__title"><?php esc_html_e( 'Consentimiento y confidencialidad', 'nuvanx-medical' ); ?></h3>
					<p class="nvx-brand-card__body"><?php esc_html_e( 'Firma previa de consentimiento informado específico para registro clínico fotográfico y divulgación médica disociada.', 'nuvanx-medical' ); ?></p>
				</li>
				<li class="nvx-brand-card nvx-cases-holding__card">
					<h3 class="nvx-brand-card__title"><?php esc_html_e( 'Fotografía estandarizada', 'nuvanx-medical' ); ?></h3>
					<p class="nvx-brand-card__body"><?php esc_html_e( 'Mismo plano anatómico, posición y encuadre comparable, registrando cualquier variación en condiciones lumínicas o posturales.', 'nuvanx-medical' ); ?></p>
				</li>
				<li class="nvx-brand-card nvx-cases-holding__card">
					<h3 class="nvx-brand-card__title"><?php esc_html_e( 'Tiempos biológicos reales', 'nuvanx-medical' ); ?></h3>
					<p class="nvx-brand-card__body"><?php esc_html_e( 'Seguimiento a 3 y 6 meses en consulta, respetando el intervalo necesario para valorar la maduración de neocolagénesis y retracción dérmica.', 'nuvanx-medical' ); ?></p>
				</li>
			</ul>
		</div>
	</section>

	<section class="nvx-brand-section nvx-cases-holding__scope" aria-labelledby="nvx-cases-scope-title">
		<div class="nvx-shell nvx-brand-section__inner">
			<p class="nvx-brand-kicker"><?php esc_html_e( 'EVIDENCIA DOCUMENTADA', 'nuvanx-medical' ); ?></p>
			<h2 id="nvx-cases-scope-title" class="nvx-brand-title"><?php esc_html_e( 'Casos clínicos documentados', 'nuvanx-medical' ); ?></h2>

			<?php if ( ! empty( $cases_list ) ) : ?>
				<ul class="nvx-cases-list">
					<?php foreach ( $cases_list as $case ) : ?>
						<li class="nvx-case-card">
							<div class="nvx-case-card__header">
								<span class="nvx-case-card__badge"><?php echo esc_html( $case['category_label'] ?? '' ); ?></span>
								<h3 class="nvx-case-card__title"><?php echo esc_html( $case['title'] ?? '' ); ?></h3>
							</div>

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

	<section class="nvx-brand-section nvx-cases-holding__criteria" aria-labelledby="nvx-cases-criteria-title">
		<div class="nvx-shell nvx-brand-section__inner">
			<p class="nvx-brand-kicker"><?php esc_html_e( 'VALORACIÓN MÉDICA', 'nuvanx-medical' ); ?></p>
			<h2 id="nvx-cases-criteria-title" class="nvx-brand-title"><?php esc_html_e( 'Cada caso requiere diagnóstico anatómico individual', 'nuvanx-medical' ); ?></h2>
			<div class="nvx-cases-holding__criteria-grid">
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
