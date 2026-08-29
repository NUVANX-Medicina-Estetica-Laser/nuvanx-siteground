<?php
/**
 * Clinical Governance & Medical Content Contract
 *
 * Centralizes the retrieval of clinical treatments data (SSOT).
 * Extracted from inc/data/clinical-matrix.json to enforce consistency
 * and valid medical claims across the UI, pricing, and Schema JSON-LD.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/nvx-endolift-authority-graph.php';

/**
 * Load and validate the Clinical Matrix catalog.
 *
 * @return array<string,array>
 * @throws RuntimeException If the JSON cannot be parsed.
 */
function nvx_get_clinical_matrix(): array {
	static $matrix = null;

	if ( null !== $matrix ) {
		return $matrix;
	}

	$file = __DIR__ . '/data/clinical-matrix.json';
	if ( ! is_readable( $file ) ) {
		$matrix = array();
		return $matrix;
	}

	$data = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $data ) || empty( $data['treatments'] ) ) {
		$matrix = array();
		return $matrix;
	}

	$matrix = $data['treatments'];
	return $matrix;
}

/**
 * Retrieve a specific clinical treatment profile by its matrix ID.
 *
 * @param string $treatment_id The key in the clinical matrix.
 * @return array|null The treatment array, or null if missing.
 */
function nvx_get_clinical_treatment( string $treatment_id ): ?array {
	$matrix = nvx_get_clinical_matrix();
	return $matrix[ $treatment_id ] ?? null;
}

/**
 * Generate E-E-A-T MedicalProcedure schema for a given treatment ID.
 *
 * @param string $treatment_id Matrix identifier.
 * @param string $url Page canonical URL.
 * @return array|null
 */
function nvx_clinical_generate_schema( string $treatment_id, string $url ): ?array {
	$data = nvx_get_clinical_treatment( $treatment_id );
	if ( ! $data ) {
		return null;
	}

	$schema = array(
		'@type'       => array( 'MedicalProcedure', 'MedicalTherapy' ),
		'@id'         => trailingslashit( $url ) . '#medical-procedure',
		'name'        => $data['name'],
		'description' => $data['mechanism'],
		'url'         => $url,
	);

	if ( ! empty( $data['anesthesia'] ) ) {
		$schema['preparation'] = array(
			'@type' => 'MedicalEntity',
			'name'  => $data['anesthesia'],
		);
	}

	if ( ! empty( $data['risks'] ) ) {
		$schema['complication'] = array();
		foreach ( $data['risks'] as $risk ) {
			$schema['complication'][] = array(
				'@type' => 'MedicalEntity',
				'name'  => $risk,
			);
		}
	}

	if ( ! empty( $data['follow_up'] ) ) {
		$schema['followup'] = $data['follow_up'];
	}

	// Resolve medical responsibility from the canonical staff registry.
	$medical_id = trim( (string) ( $data['medical_responsible_id'] ?? '' ) );
	if ( '' !== $medical_id && function_exists( 'nvx_medical_staff_name' ) && function_exists( 'nvx_medical_colegiado' ) ) {
		$medical_name      = trim( (string) nvx_medical_staff_name( $medical_id ) );
		$medical_colegiado = trim( (string) nvx_medical_colegiado( $medical_id ) );
		if ( '' !== $medical_name && '' !== $medical_colegiado ) {
			$schema['provider'] = array(
				'@type'      => 'Physician',
				'name'       => $medical_name,
				'identifier' => $medical_colegiado,
			);
		}
	}

	return $schema;
}

/**
 * Resolve the governed evidence record for the current priority treatment page.
 */
function nvx_clinical_evidence_current_treatment_id(): string {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_page() ) {
		return '';
	}

	$slug = function_exists( 'nvx_theme_current_page_slug' )
		? nvx_theme_current_page_slug()
		: (string) get_post_field( 'post_name', get_queried_object_id() );

	$routes = array(
		'endolift-facial-papada-mandibula'                     => 'endolift_facial',
		'laser-co2-fraccionado-madrid-textura-cicatrices-poro' => 'laser_co2',
		'exion-face'                                           => 'exion_face',
	);

	return $routes[ $slug ] ?? '';
}

/**
 * Render source-traceable evidence without translating study endpoints into
 * guaranteed patient outcomes.
 */
function nvx_clinical_evidence_markup( string $treatment_id ): string {
	$treatment = nvx_get_clinical_treatment( $treatment_id );
	if ( ! is_array( $treatment ) ) {
		return '';
	}

	$evidence = is_array( $treatment['evidence'] ?? null ) ? $treatment['evidence'] : array();
	if ( array() === $evidence ) {
		return '';
	}

	$title_id = 'nvx-clinical-evidence-' . sanitize_html_class( $treatment_id );
	$html     = '<section class="nvx-brand-section nvx-clinical-evidence" aria-labelledby="' . esc_attr( $title_id ) . '" data-nvx-clinical-evidence="' . esc_attr( $treatment_id ) . '">';
	$html    .= '<div class="nvx-shell nvx-brand-section__inner">';
	$html    .= '<p class="nvx-brand-kicker">' . esc_html__( 'Evidencia clínica', 'nuvanx-medical' ) . '</p>';
	$html    .= '<h2 id="' . esc_attr( $title_id ) . '" class="nvx-brand-title">' . esc_html__( 'Qué dice la evidencia publicada', 'nuvanx-medical' ) . '</h2>';
	$html    .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Los datos siguientes describen estudios publicados y su contexto. No equivalen a una promesa de resultado individual ni sustituyen la valoración médica.', 'nuvanx-medical' ) . '</p>';

	foreach ( $evidence as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$study_type   = trim( (string) ( $row['study_type'] ?? '' ) );
		$sample_size  = trim( (string) ( $row['sample_size'] ?? '' ) );
		$title        = trim( (string) ( $row['title'] ?? '' ) );
		$summary      = trim( (string) ( $row['summary'] ?? '' ) );
		$limitation   = trim( (string) ( $row['limitation'] ?? '' ) );
		$source_label = trim( (string) ( $row['source_label'] ?? '' ) );
		$source_url   = trim( (string) ( $row['source_url'] ?? '' ) );

		$html .= '<article class="nvx-clinical-note">';
		if ( '' !== $title ) {
			$html .= '<h3 class="nvx-clinical-note__title">' . esc_html( $title ) . '</h3>';
		}
		if ( '' !== $study_type || '' !== $sample_size ) {
			$meta  = array_filter( array( $study_type, $sample_size ) );
			$html .= '<p class="nvx-brand-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		}
		if ( '' !== $summary ) {
			$html .= '<p class="nvx-clinical-note__text">' . esc_html( $summary ) . '</p>';
		}
		if ( '' !== $limitation ) {
			$html .= '<p class="nvx-body"><strong>' . esc_html__( 'Límite de la evidencia:', 'nuvanx-medical' ) . '</strong> ' . esc_html( $limitation ) . '</p>';
		}
		if ( '' !== $source_url && '' !== $source_label ) {
			$html .= '<p class="nvx-body"><a class="nvx-brand-inline-link" href="' . esc_url( $source_url ) . '" rel="noopener">' . esc_html( $source_label ) . '</a></p>';
		}
		$html .= '</article>';
	}

	$html .= '</div></section>';
	return $html;
}

/**
 * Insert a source block after a clinical review line when the page renderer
 * exposes one, or after the hero as a controlled fallback.
 */
function nvx_clinical_evidence_inject( string $content ): string {
	$treatment_id = nvx_clinical_evidence_current_treatment_id();
	if ( '' === $treatment_id || false !== strpos( $content, 'data-nvx-clinical-evidence=' ) ) {
		return $content;
	}

	$block = nvx_clinical_evidence_markup( $treatment_id );
	if ( '' === $block ) {
		return $content;
	}

	$review_class = '';
	if ( 'endolift_facial' === $treatment_id ) {
		$review_class = 'nvx-endolift-reviewed';
	} elseif ( 'laser_co2' === $treatment_id ) {
		$review_class = 'nvx-co2-reviewed';
	}

	if ( '' !== $review_class ) {
		$pattern = '/(<p class="' . preg_quote( $review_class, '/' ) . '">[\s\S]*?<\/p>)/u';
		$updated = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $block ): string {
				return $matches[1] . $block;
			},
			$content,
			1
		);
		if ( is_string( $updated ) && $updated !== $content ) {
			return $updated;
		}
	}

	$updated = preg_replace_callback(
		'/<section class="nvx-brand-hero[^\"]*"[\s\S]*?<\/section>/u',
		static function ( array $matches ) use ( $block ): string {
			return $matches[0] . $block;
		},
		$content,
		1
	);

	return is_string( $updated ) ? $updated : $content;
}
add_filter( 'the_content', 'nvx_clinical_evidence_inject', NVX_HOOK_PRIO_CLINICAL_EVIDENCE );
