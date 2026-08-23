<?php
/**
 * Authentic editorial photography for pages that already have a verified
 * NUVANX-owned clinical, consultation or clinic-space image in the media library.
 *
 * This module intentionally excludes stock-like, supplier, generic, bridal and
 * patient-case assets unless their individual rights and clinical consents have
 * been recorded. Pages without an honest candidate stay text-led.
 *
 * @package nuvanx-medical
 */

const NVX_AUTHENTIC_PHOTO_ALT_CONSULTATION = 'Consulta médica y valoración personalizada en NUVANX Madrid';
const NVX_AUTHENTIC_PHOTO_CAPTION_VALORACION = 'Valoración médica';

/**
 * @return array<string,array{eyebrow:string,title:string,images:array<int,array{id:int,alt:string,caption:string}>}>
 */
function nvx_authentic_page_photo_registry(): array {
	return array(
		'soluciones-medicas' => array(
			'eyebrow' => 'La práctica',
			'title'   => 'Tecnología aplicada con criterio médico',
			'images'  => array(
				array( 'id' => 2471, 'alt' => 'Valoración corporal personalizada en consulta NUVANX', 'caption' => 'Valoración corporal' ),
				array( 'id' => 2472, 'alt' => 'Tratamiento de energía en entorno clínico NUVANX', 'caption' => 'Tecnología en consulta' ),
			),
		),
		'tratamientos' => array(
			'eyebrow' => 'Tratamientos',
			'title'   => 'La tecnología forma parte del acto médico',
			'images'  => array(
				array( 'id' => 2472, 'alt' => 'Tecnología de energía en una sesión clínica NUVANX', 'caption' => 'Tecnología facial' ),
				array( 'id' => 2877, 'alt' => NVX_AUTHENTIC_PHOTO_ALT_CONSULTATION, 'caption' => NVX_AUTHENTIC_PHOTO_CAPTION_VALORACION ),
			),
		),
		'medicina-estetica-laser' => array(
			'eyebrow' => 'Medicina estética láser',
			'title'   => 'Tecnología real, indicada de forma individual',
			'images'  => array(
				array( 'id' => 2472, 'alt' => 'Procedimiento facial con tecnología de energía NUVANX', 'caption' => 'Láser y energía' ),
				array( 'id' => 2877, 'alt' => NVX_AUTHENTIC_PHOTO_ALT_CONSULTATION, 'caption' => NVX_AUTHENTIC_PHOTO_CAPTION_VALORACION ),
			),
		),
		'medicina-estetica' => array(
			'eyebrow' => 'Consulta NUVANX',
			'title'   => 'Una indicación empieza por escuchar y valorar',
			'images'  => array(
				array( 'id' => 2896, 'alt' => 'Dr. José Javier Rivera — dirección médica NUVANX Madrid', 'caption' => 'Dirección médica' ),
				array( 'id' => 2381, 'alt' => 'Dr. José Javier Rivera durante una valoración médica en NUVANX Madrid', 'caption' => 'Valoración personalizada' ),
				array( 'id' => 1630, 'alt' => 'Box de tratamiento real de NUVANX', 'caption' => 'Entorno clínico' ),
			),
		),
		'por-que-nuvanx' => array(
			'eyebrow' => 'NUVANX',
			'title'   => 'Equipo, método y lugar',
			'images'  => array(
				array( 'id' => 2896, 'alt' => 'Dr. José Javier Rivera — dirección médica NUVANX Madrid', 'caption' => 'Dirección médica' ),
				array( 'id' => 1632, 'alt' => 'Recepción real de NUVANX', 'caption' => 'La clínica' ),
				array( 'id' => 2796, 'alt' => 'Fachada de NUVANX Chamberí', 'caption' => 'Chamberí' ),
			),
		),
		'protocolos-signature' => array(
			'eyebrow' => 'Protocolos',
			'title'   => 'La secuencia se decide en consulta',
			'images'  => array(
				array( 'id' => 2877, 'alt' => NVX_AUTHENTIC_PHOTO_ALT_CONSULTATION, 'caption' => 'Consulta' ),
				array( 'id' => 2471, 'alt' => 'Valoración corporal individualizada en NUVANX', 'caption' => 'Criterio corporal' ),
			),
		),
		'endolift-facial-papada-mandibula' => array(
			'eyebrow' => 'Planificación',
			'title'   => 'La planificación es tan importante como la tecnología',
			'images'  => array(
				array( 'id' => 2877, 'alt' => NVX_AUTHENTIC_PHOTO_ALT_CONSULTATION, 'caption' => 'Valoración previa' ),
			),
		),
		'papada-definicion-mandibular-madrid' => array(
			'eyebrow' => 'Diagnóstico facial',
			'title'   => 'Papada y línea mandibular: la zona correcta, en contexto',
			'images'  => array(
				array( 'id' => 3362, 'alt' => 'Papada y línea mandibular como zona de valoración para definición del tercio inferior facial en NUVANX Madrid', 'caption' => 'Papada y línea mandibular' ),
				array( 'id' => 3303, 'alt' => 'Caso clínico consentido de papada y contorno mandibular tratado con Endolift en NUVANX Madrid', 'caption' => 'Caso clínico consentido · Papada y contorno mandibular' ),
			),
		),
		'calidad-piel-firmeza-luminosidad-madrid' => array(
			'eyebrow' => 'Calidad cutánea',
			'title'   => 'Firmeza y luminosidad con una imagen coherente con el objetivo',
			'images'  => array(
				array( 'id' => 3361, 'alt' => 'Calidad, firmeza y luminosidad de la piel como objetivos de un protocolo facial personalizado en NUVANX Madrid', 'caption' => 'Calidad, firmeza y luminosidad' ),
				array( 'id' => 3355, 'alt' => 'Protocolo Skin Architecture para mejorar calidad, firmeza y luminosidad de la piel en NUVANX Madrid', 'caption' => 'Skin Architecture™' ),
			),
		),
		'cicatrices-acne-poros-textura-madrid' => array(
			'eyebrow' => 'Renovación cutánea',
			'title'   => 'Textura, poros y cicatrices en el mismo contexto clínico',
			'images'  => array(
				array( 'id' => 3360, 'alt' => 'Cicatrices de acné, poros y textura irregular de la piel dentro de un protocolo de renovación cutánea en NUVANX Madrid', 'caption' => 'Textura, poros y cicatrices' ),
				array( 'id' => 3354, 'alt' => 'Protocolo Surface Renewal para mejorar cicatrices de acné, poros y textura cutánea en NUVANX Madrid', 'caption' => 'Surface Renewal™' ),
			),
		),
		'manchas-rojeces-fotorejuvenecimiento-ipl-madrid' => array(
			'eyebrow' => 'Tono y fotodaño',
			'title'   => 'IPL para manchas, rojeces y tono irregular',
			'images'  => array(
				array( 'id' => 3611, 'alt' => 'Fotorejuvenecimiento con IPL para manchas, rojeces y tono irregular de la piel en NUVANX Madrid', 'caption' => 'Fotorejuvenecimiento IPL' ),
				array( 'id' => 3353, 'alt' => 'Protocolo Tone Correction para manchas, rojeces y tono irregular de la piel en NUVANX Madrid', 'caption' => 'Tone Correction™' ),
			),
		),
		'endolaser-corporal-grasa-localizada' => array(
			'eyebrow' => 'Endoláser corporal',
			'title'   => 'Tratamiento corporal con indicación médica',
			'images'  => array(
				array( 'id' => 2115, 'alt' => 'Procedimiento corporal láser realizado en NUVANX', 'caption' => 'Procedimiento corporal' ),
				array( 'id' => 2109, 'alt' => 'Tecnología corporal utilizada en una sesión NUVANX', 'caption' => 'Tecnología en uso' ),
				array( 'id' => 2471, 'alt' => 'Valoración corporal personalizada previa a un tratamiento', 'caption' => NVX_AUTHENTIC_PHOTO_CAPTION_VALORACION ),
			),
		),
		'remodelacion-corporal-laser-madrid' => array(
			'eyebrow' => 'Remodelación corporal',
			'title'   => 'Tratamos una indicación, no una imagen genérica',
			'images'  => array(
				array( 'id' => 2115, 'alt' => 'Tratamiento corporal con láser en NUVANX', 'caption' => 'Procedimiento corporal' ),
				array( 'id' => 2109, 'alt' => 'Tecnología corporal NUVANX durante una sesión', 'caption' => 'Tecnología aplicada' ),
			),
		),
		'tratamiento-postparto-abdomen-contorno-corporal-madrid' => array(
			'eyebrow' => 'Post-Maternity Contour™',
			'title'   => 'Abdomen y contorno después del embarazo, sin imágenes genéricas',
			'images'  => array(
				array( 'id' => 3359, 'alt' => 'Mujer embarazada como imagen contextual de planificación del contorno corporal tras el embarazo en NUVANX Madrid', 'caption' => 'Planificación posgestacional' ),
			),
		),
		'grasa-localizada-abdomen-flancos-madrid' => array(
			'eyebrow' => 'Valoración corporal',
			'title'   => 'Abdomen y flancos: la zona se muestra antes de hablar de tecnología',
			'images'  => array(
				array( 'id' => 3358, 'alt' => 'Grasa localizada en abdomen y flancos como zona de valoración para remodelación corporal en NUVANX Madrid', 'caption' => 'Abdomen y flancos' ),
				array( 'id' => 3298, 'alt' => 'Caso clínico consentido de abdomen tratado con Endolift en NUVANX Madrid', 'caption' => 'Caso clínico consentido · Abdomen' ),
			),
		),
		'flacidez-grasa-localizada-brazos-madrid' => array(
			'eyebrow' => 'Contorno de brazos',
			'title'   => 'Brazos: firmeza y grasa localizada en su propia zona anatómica',
			'images'  => array(
				array( 'id' => 3357, 'alt' => 'Flacidez y grasa localizada en brazos como zona de valoración para remodelación corporal en NUVANX Madrid', 'caption' => 'Brazos' ),
				array( 'id' => 3301, 'alt' => 'Caso clínico consentido de brazos tratado con Endolift en NUVANX Madrid', 'caption' => 'Caso clínico consentido · Brazos' ),
				array( 'id' => 3351, 'alt' => 'Secuencia visual de evolución del contorno de brazos dentro de un protocolo corporal en NUVANX Madrid', 'caption' => 'Evolución del contorno de brazos' ),
			),
		),
		'grasa-espalda-zona-sujetador-madrid' => array(
			'eyebrow' => 'Contorno de espalda',
			'title'   => 'Espalda y zona del sujetador con una imagen específica de la región',
			'images'  => array(
				array( 'id' => 3296, 'alt' => 'Caso clínico consentido de espalda y zona del sujetador tratado con Endolift en NUVANX Madrid', 'caption' => 'Caso clínico consentido · Espalda y zona del sujetador' ),
				array( 'id' => 3348, 'alt' => 'Secuencia visual de evolución de espalda y zona del sujetador dentro de un protocolo corporal en NUVANX Madrid', 'caption' => 'Espalda y zona del sujetador' ),
			),
		),
		'contacto' => array(
			'eyebrow' => 'Dos sedes, un mismo criterio',
			'title'   => 'Conoce dónde te recibimos',
			'images'  => array(
				array( 'id' => 2796, 'alt' => 'Fachada de la clínica NUVANX Chamberí', 'caption' => 'Chamberí' ),
				array( 'id' => 2071, 'alt' => 'Fachada de la clínica NUVANX Goya', 'caption' => 'Salamanca–Goya' ),
				array( 'id' => 1632, 'alt' => 'Recepción de la clínica NUVANX', 'caption' => 'Recepción NUVANX' ),
			),
		),
		'medicina-estetica-chamberi' => array(
			'eyebrow' => 'Chamberí',
			'title'   => 'Una clínica real en el centro de Madrid',
			'images'  => function_exists( 'nvx_clinic_editorial_photo_map' ) ? nvx_clinic_editorial_photo_map( 'chamberi' ) : array(),
		),
		'medicina-estetica-goya-barrio-salamanca' => array(
			'eyebrow' => 'Salamanca–Goya',
			'title'   => 'La misma práctica, en nuestra sede de Goya',
			'images'  => function_exists( 'nvx_clinic_editorial_photo_map' ) ? nvx_clinic_editorial_photo_map( 'goya' ) : array(),
		),
		'clinicas-de-medicina-estetica-nuvanx' => array(
			'eyebrow' => 'Nuestras clínicas',
			'title'   => 'Espacios creados para la consulta y el seguimiento',
			'images'  => array(
				array( 'id' => 2796, 'alt' => 'Fachada de NUVANX Chamberí', 'caption' => 'Chamberí' ),
				array( 'id' => 2071, 'alt' => 'Fachada de NUVANX Salamanca–Goya', 'caption' => 'Salamanca–Goya' ),
				array( 'id' => 1632, 'alt' => 'Recepción de NUVANX Chamberí', 'caption' => 'Recepción' ),
				array( 'id' => 1630, 'alt' => 'Box clínico de NUVANX Chamberí', 'caption' => 'Entorno clínico' ),
			),
		),
		'equipo-medico' => array(
			'eyebrow' => 'Equipo médico',
			'title'   => 'Profesionales que acompañan cada decisión',
			'images'  => array(
				array( 'id' => 2381, 'alt' => 'Dr. José Javier Rivera durante una valoración médica en NUVANX Madrid', 'caption' => 'Dirección médica' ),
				array( 'id' => 1840, 'alt' => 'Dra. Ivon Rivera Deras — equipo médico NUVANX Madrid', 'caption' => 'Medicina preventiva' ),
				array( 'id' => 2897, 'alt' => 'Francisco Geraldo — coordinación NUVANX Madrid', 'caption' => 'Coordinación NUVANX' ),
			),
		),
		'nosotros' => array(
			'eyebrow' => 'Nosotros',
			'title'   => 'Una práctica médica de personas y espacios reales',
			'images'  => array(
				array( 'id' => 2896, 'alt' => 'Dr. José Javier Rivera — dirección médica NUVANX Madrid', 'caption' => 'Equipo médico' ),
				array( 'id' => 1632, 'alt' => 'Interior de NUVANX Chamberí, Madrid', 'caption' => 'La clínica' ),
				array( 'id' => 2796, 'alt' => 'Fachada de NUVANX Chamberí, Madrid', 'caption' => 'Chamberí' ),
			),
		),
	);
}

/**
 * @param array{eyebrow:string,title:string,images:array<int,array{id:int,alt:string,caption:string}>} $data Gallery data.
 */
function nvx_authentic_page_photo_markup( array $data ): string {
	$items = '';

	foreach ( $data['images'] as $image ) {
		$attachment_id = (int) $image['id'];
		$source_path   = get_attached_file( $attachment_id );

		// Historic media metadata can survive after a file is removed. Do not emit
		// a derivative or fallback for an unavailable asset: the page stays
		// editorially complete with the remaining approved photographs.
		if ( ! is_string( $source_path ) || '' === $source_path || ! is_readable( $source_path ) ) {
			continue;
		}

		// Request WordPress' governed large derivative instead of forcing the
		// multi-megabyte source. Runtime acceptance measures currentSrc so a
		// missing historic derivative cannot silently reintroduce transfer debt.
		$markup = wp_get_attachment_image(
			$attachment_id,
			'large',
			false,
			array(
				'class'    => 'nvx-authentic-photo-grid__image',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'alt'      => $image['alt'],
				'sizes'    => '(min-width: 1320px) 400px, (min-width: 1024px) 30vw, (min-width: 641px) 46vw, 92vw',
			)
		);

		if ( ! is_string( $markup ) || '' === $markup ) {
			continue;
		}
		if ( function_exists( 'nvx_public_html_is_vendor_image' ) && nvx_public_html_is_vendor_image( $markup ) ) {
			continue;
		}

		$items .= '<figure class="nvx-authentic-photo-grid__item">';
		$items .= $markup;
		$items .= '<figcaption class="nvx-authentic-photo-grid__caption">' . esc_html( $image['caption'] ) . '</figcaption>';
		$items .= '</figure>';
	}

	if ( '' === $items ) {
		return '';
	}

	$html  = '<section class="nvx-authentic-photo-grid" aria-label="' . esc_attr( $data['title'] ) . '">';
	$html .= '<div class="nvx-authentic-photo-grid__inner">';
	$html .= '<header class="nvx-authentic-photo-grid__header">';
	$html .= '<p class="nvx-authentic-photo-grid__eyebrow">' . esc_html( $data['eyebrow'] ) . '</p>';
	$html .= '<h2 class="nvx-authentic-photo-grid__title">' . esc_html( $data['title'] ) . '</h2>';
	$html .= '</header>';
	$html .= '<div class="nvx-authentic-photo-grid__grid">' . $items . '</div>';
	$html .= '</div></section>';

	return $html;
}

/** Insert only the mapped, non-generic page photographs after the managed content. */
function nvx_append_authentic_page_photography( string $content ): string {
	if ( is_admin() || ! is_singular( 'page' ) || '' === $content || false !== strpos( $content, 'nvx-authentic-photo-grid' ) ) {
		return $content;
	}

	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	// Sede landings own a single editorial gallery in page-sede.php.
	if ( in_array( $slug, array( 'medicina-estetica-chamberi', 'medicina-estetica-goya-barrio-salamanca' ), true ) ) {
		return $content;
	}
	$data = nvx_authentic_page_photo_registry()[ $slug ] ?? null;
	if ( ! is_array( $data ) ) {
		return $content;
	}

	return $content . nvx_authentic_page_photo_markup( $data );
}
add_filter( 'the_content', 'nvx_append_authentic_page_photography', 175 );
