<?php
/**
 * Clinical governance for BTL detail pages.
 *
 * Keeps manufacturer-supported device information visible while preventing
 * those data from being presented as universal patient outcomes.
 *
 * Public claim copy is a single governed string per id (no dual source/rewrite
 * catalogue). Theme JSON and registry builders resolve claims through
 * nvx_btl_claim().
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the supported BTL detail slugs.
 *
 * @return string[]
 */
function nvx_btl_governed_slugs(): array {
	return array( 'exion-face', 'exion-body', 'exion-fractional', 'emfusion' );
}

/**
 * Whether the current request is a governed BTL detail page.
 */
function nvx_btl_is_governed_request(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	if ( ! is_page() ) {
		return false;
	}

	$slug = function_exists( 'nvx_theme_current_page_slug' )
		? nvx_theme_current_page_slug()
		: (string) get_post_field( 'post_name', get_queried_object_id() );

	return in_array( $slug, nvx_btl_governed_slugs(), true );
}

/**
 * Public claim library: one governed string per key.
 *
 * @return array<string, string>
 */
function nvx_btl_claim_library(): array {
	static $library = null;
	if ( null !== $library ) {
		return $library;
	}

	$btl_disclaimer = 'El aplicador incorpora refrigeración de superficie. Los efectos y riesgos se explican antes de decidir, según el protocolo y la respuesta individual.';

	$library = array(
		'exion_face_mech_intro' => 'EXION® Face combina radiofrecuencia monopolar y ultrasonido terapéutico orientados a estimular fibroblastos y matriz extracelular. La comparación con plataformas de mayor pico térmico depende del aplicador, los parámetros y la indicación.',
		'exion_face_ha_224'     => 'La documentación del fabricante describe cambios en marcadores de matriz cutánea en modelos evaluados. La evidencia aplicable, la indicación y la respuesta clínica deben valorarse de forma individual; no se comunica como porcentaje ni como resultado garantizado.',
		'exion_face_compare'    => 'Las tecnologías energéticas se seleccionan por mecanismo, zona, fototipo, antecedentes, objetivo y período de recuperación aceptable. La indicación no se establece por una comparación comercial entre marcas.',
		'exion_body_btl_22'     => 'La documentación técnica describe cambios en adiposidad en series evaluadas. No se publica un porcentaje porque depende de población, zona, protocolo y evaluación clínica, y no constituye un resultado individual garantizado.',
		'exion_body_compare'    => 'Los procedimientos para contorno corporal tienen mecanismos, límites y períodos de recuperación distintos. La elección se realiza tras explorar grasa localizada, calidad cutánea, exceso de piel y expectativas; una tecnología no sustituye una cirugía cuando ésta está indicada.',
		'exion_body_cooling'    => $btl_disclaimer,
		'frac_tolerate_title'   => 'Pacientes con antecedentes de baja tolerancia a protocolos multipasada',
		'frac_single_pass'      => 'El diseño single-pass y el feedback de impedancia pueden reducir pasadas adicionales en protocolos seleccionados. El profesional decide el número de pases según zona, respuesta y objetivo.',
	);

	return $library;
}

/**
 * Translate a claim library string.
 *
 * phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- msgids are centralized claim literals.
 */
function nvx_btl_claim_translate( string $text ): string {
	if ( '' === $text ) {
		return '';
	}
	return __( $text, 'nuvanx-medical' );
}

/** Return approved public wording for a governed claim. */
function nvx_btl_claim_governed( string $id ): string {
	$library = nvx_btl_claim_library();
	$raw     = isset( $library[ $id ] ) ? (string) $library[ $id ] : '';
	return nvx_btl_claim_translate( $raw );
}

/**
 * Build the canonical clinical note shell wrapper.
 *
 * @param string $notice The clinical notice content.
 * @return string The wrapped HTML.
 */
function nvx_btl_build_clinical_note_shell( string $notice ): string {
	return '<div class="nvx-shell nvx-clinical-evidence-note" data-nvx-btl-clinical-note="1" role="note" aria-label="Nota clínica">'
		 . '<div class="nvx-clinical-evidence-note__inner">' . $notice . '</div>'
		 . '</div>';
}

/**
 * Safe claim lookup for registry builders (empty when id missing).
 */
function nvx_btl_claim( string $id ): string {
	return nvx_btl_claim_governed( $id );
}

/**
 * Qualify comparative competitor blocks and append the clinical note.
 *
 * @param string $content Rendered page content.
 * @return string
 */
function nvx_btl_govern_rendered_content( string $content ): string {
	if ( ! nvx_btl_is_governed_request() || '' === $content ) {
		return $content;
	}

	$governed = preg_replace_callback(
		'/<details\b[^>]*>[\s\S]*?<\/details>/iu',
		static function ( array $matches ): string {
			return preg_match( '/\b(?:Morpheus8|Potenza|CoolSculpting|HIFU|Thermage|Ultherapy|Hydrafacial|Dermapen)\b/iu', $matches[0] ) ? '' : $matches[0];
		},
		$content
	) ?? $content;

	$notice_content = '<h2 class="nvx-clinical-note__title">Datos técnicos y variabilidad clínica</h2><p class="nvx-clinical-note__text">Los datos técnicos requieren contexto clínico y no equivalen a un resultado individual. La indicación, los parámetros y la respuesta dependen del equipo, el aplicador, la zona y el paciente.</p>';

	if ( false === strpos( $governed, 'data-nvx-btl-clinical-note="1"' ) ) {
		$notice_shell = nvx_btl_build_clinical_note_shell( $notice_content );

		if ( false !== strpos( $governed, '<!-- nvx:clinical-note-anchor -->' ) ) {
			$governed = str_replace(
				'<!-- nvx:clinical-note-anchor -->',
				$notice_shell,
				$governed
			);
		} else {
			$governed = preg_replace(
				'/(<section[^>]+nvx-closing-cta[^>]*>)/i',
				$notice_shell . '$1',
				$governed,
				1
			) ?? $governed;
		}

		if ( false === strpos( $governed, 'data-nvx-btl-clinical-note="1"' ) ) {
			$governed .= $notice_shell;
		}
	}

	return $governed;
}
add_filter( 'the_content', 'nvx_btl_govern_rendered_content', NVX_HOOK_PRIO_BTL_GOVERNANCE );

/**
 * Keep search snippets precise on BTL detail routes.
 *
 * @param string $description Existing Yoast description.
 * @return string
 */
function nvx_btl_govern_metadescription( string $description ): string {
	if ( ! nvx_btl_is_governed_request() ) {
		return $description;
	}

	$slug = function_exists( 'nvx_theme_current_page_slug' )
		? nvx_theme_current_page_slug()
		: (string) get_post_field( 'post_name', get_queried_object_id() );

	$descriptions = array(
		'exion-face'       => 'EXION® Face en NUVANX Madrid: RF y ultrasonido a microtemperaturas controladas para calidad cutánea. Valoración médica en Chamberí y Goya.',
		'exion-body'       => 'EXION® Body en NUVANX Madrid: radiofrecuencia con refrigeración activa para grasa localizada y calidad cutánea, según valoración médica.',
		'exion-fractional' => 'EXION® Fractional RF en Madrid: microagujas con control de impedancia para textura, poro y cicatrices según diagnóstico y fototipo.',
		'emfusion'         => 'EMFUSION® en NUVANX Madrid: microcanales acústicos DYNAMiQ™ para hidratación y apoyo a la barrera cutánea, sin sistemas de succión.',
	);

	return $descriptions[ $slug ] ?? $description;
}
add_filter( 'wpseo_metadesc', 'nvx_btl_govern_metadescription', 101 );
add_filter( 'wpseo_opengraph_desc', 'nvx_btl_govern_metadescription', 101 );
add_filter( 'wpseo_twitter_description', 'nvx_btl_govern_metadescription', 101 );
