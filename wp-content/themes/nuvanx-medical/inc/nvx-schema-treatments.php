<?php
/**
 * MedicalProcedure / MedicalService schema node builders for laser and BTL
 * treatment pages.
 *
 * Extracted from nvx-structured-data.php.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

function nvx_schema_treatment_node_laser( string $key, string $permalink, string $organization_id ): ?array {
	$label_from   = nvx_format_price_eur( nvx_endolift_price_from_eur() );
	$label_papada = nvx_format_price_eur( nvx_endolift_price_papada_eur() );
	$label_f      = function_exists( 'nvx_co2_price_facial_eur' ) ? nvx_format_price_eur( nvx_co2_price_facial_eur() ) : '';
	$label_b      = function_exists( 'nvx_co2_price_body_eur' ) ? nvx_format_price_eur( nvx_co2_price_body_eur() ) : '';

	if ( 'endolift_facial' === $key ) {
		return array(
			'@type'             => array( 'MedicalProcedure', 'Service' ),
			'@id'               => $permalink . NVX_SD_ID_MEDICAL_PROCEDURE,
			'name'              => 'Endolift® facial para papada y línea mandibular',
			'alternateName'     => array( NVX_SD_ENDOLIFT_FACIAL, 'Láser intersticial facial', 'Endolift® Chamberí Madrid', 'Endolift® Goya Madrid' ),
			'url'               => $permalink,
			'mainEntityOfPage'  => array( '@id' => $permalink ),
			'provider'          => array( '@id' => $organization_id ),
			'description'       => 'Endolift® premium con dirección médica especializada en láser intersticial. Procedimiento médico mínimamente invasivo con microfibra láser subdérmica para lipólisis selectiva y retracción térmica en papada, contorno mandibular y cuello. Valoración anatómica exhaustiva por Dr. Javier Rivera Tejeda (Máster Universitario en Medicina Estética UCM). PVP papada/marcación mandibular desde ' . $label_papada . ' €; tarifas faciales desde ' . $label_from . ' €. Tarifa premium por autoridad clínica y protocolo médico personalizado.',
			'bodyLocation'      => array( 'Papada', 'Línea mandibular', 'Cuello', 'Óvalo facial' ),
			'procedureType'     => 'https://schema.org/PercutaneousProcedure',
			'preparation'       => 'Valoración médica presencial exhaustiva de anatomía, calidad de piel, grasa submentoniana, ptosis y expectativas. Exclusión de ptosis severa con exceso cutáneo que requiera cirugía. Planificación individualizada por médico especialista.',
			'howPerformed'      => 'Tras anestesia local se inserta microfibra óptica de 200–300 micras y se aplica energía láser intersticial en patrón vectorial subdérmico adaptado a la zona. Protocolo médico personalizado según anatomía y objetivos.',
			'followup'          => 'Seguimiento clínico protocolizado por dirección médica (típicamente semanas 4 y 8 y control posterior). Reincorporación habitual en menos de 24 h; edema o inflamación pueden durar 3–7 días.',
			'indication'        => array(
				array(
					'@type' => 'MedicalIndication',
					'name'  => 'Flacidez facial leve a moderada',
				),
				array(
					'@type' => 'MedicalIndication',
					'name'  => 'Adiposidad submentoniana (papada) seleccionada',
				),
			),
			'relevantCondition' => array(
				array(
					'@type' => 'MedicalCondition',
					'name'  => 'Flacidez facial',
				),
				array(
					'@type' => 'MedicalCondition',
					'name'  => 'Adiposidad submentoniana',
				),
			),
		);
	}

	if ( 'endolaser_corporal' === $key ) {
		return array(
			'@type'             => array( 'MedicalProcedure', 'Service' ),
			'@id'               => $permalink . NVX_SD_ID_MEDICAL_PROCEDURE,
			'name'              => 'Endoláser corporal — destrucción de grasa localizada y retracción cutánea',
			'alternateName'     => array( 'Laserlipólisis corporal', 'Endoláser Madrid' ),
			'url'               => $permalink,
			'mainEntityOfPage'  => array( '@id' => $permalink ),
			'provider'          => array( '@id' => $organization_id ),
			'description'       => 'Laserlipólisis médica intervencionista: lipólisis de adipocitos y estímulo de retracción dérmica en un acto ambulatorio por zonas (abdomen, flancos, muslos, brazos, submandibular). No trata obesidad ni pérdida masiva de peso; el presupuesto se personaliza tras valoración.',
			'bodyLocation'      => array( 'Abdomen', 'Flancos', 'Cara interna de muslos', 'Rodillas', 'Brazos', 'Región submandibular' ),
			'procedureType'     => 'https://schema.org/PercutaneousProcedure',
			'preparation'       => 'Peso estable, grasa focal y flacidez leve–moderada. Exclusión de exceso cutáneo severo (derivación a cirugía excisional, p. ej. abdominoplastia).',
			'howPerformed'      => 'Bajo anestesia local se introduce fibra láser en tejido subcutáneo para lipólisis selectiva y estímulo térmico de retracción en la cuadrícula de zonas planificada.',
			'followup'          => 'Cuidados post-procedimiento y revisiones según zona y protocolo médico.',
			'indication'        => array(
				array(
					'@type' => 'MedicalIndication',
					'name'  => 'Adiposidad localizada resistente a dieta y ejercicio',
				),
				array(
					'@type' => 'MedicalIndication',
					'name'  => 'Flacidez cutánea leve a moderada asociada a pérdida de volumen local',
				),
			),
			'relevantCondition' => array(
				array(
					'@type' => 'MedicalCondition',
					'name'  => 'Adiposidad localizada',
				),
				array(
					'@type' => 'MedicalCondition',
					'name'  => 'Flacidez cutánea corporal leve-moderada',
				),
			),
		);
	}

	if ( 'laser_co2' === $key ) {
		return array(
			'@type'             => array( 'MedicalProcedure', 'Service' ),
			'@id'               => $permalink . NVX_SD_ID_MEDICAL_PROCEDURE,
			'name'              => 'Láser CO₂ fraccionado — resurfacing epidérmico y cicatrices',
			'alternateName'     => array( 'CO₂ fraccionado Madrid', 'Resurfacing láser CO₂' ),
			'url'               => $permalink,
			'mainEntityOfPage'  => array( '@id' => $permalink ),
			'provider'          => array( '@id' => $organization_id ),
			'description'       => 'Ablación fraccionada con microcolumnas de vaporización y tejido sano peri-lesional. Indicado en cicatrices atróficas de acné, poros, textura irregular y fotodaño. Downtime típico 4–7 días; remodelación colagénica 4–6 semanas. PVP sesión facial desde ' . $label_f . ' €; corporal ' . $label_b . ' € (IVA incl.).',
			'bodyLocation'      => 'Piel facial y zonas cutáneas seleccionadas',
			'procedureType'     => 'https://schema.org/PercutaneousProcedure',
			'preparation'       => 'Evaluación de fototipo, inflamación, bronceado, medicación y objetivo (cicatriz, textura, fotodaño). Compromiso con downtime y fotoprotección.',
			'howPerformed'      => 'Microhaces de CO₂ crean columnas de vaporización térmica fraccionada; el tejido circundante acelera la curación y estimula colágeno I y III.',
			'followup'          => 'Días 1–3 eritema y patrón punteado; días 4–7 descamación; desde día 7 recuperación visual habitual y remodelación progresiva 4–6 semanas.',
			'indication'        => array(
				array(
					'@type' => 'MedicalIndication',
					'name'  => 'Cicatrices atróficas de acné',
				),
				array(
					'@type' => 'MedicalIndication',
					'name'  => 'Poros dilatados y textura irregular',
				),
				array(
					'@type' => 'MedicalIndication',
					'name'  => 'Fotodaño y elastosis solar',
				),
			),
			'relevantCondition' => array(
				array(
					'@type' => 'MedicalCondition',
					'name'  => 'Cicatrices atróficas de acné',
				),
				array(
					'@type' => 'MedicalCondition',
					'name'  => 'Fotodaño cutáneo',
				),
			),
		);
	}

	return null;
}

function nvx_schema_treatment_node_btl( string $key, string $permalink, string $organization_id ): ?array {
	if ( 'exion_btl' === $key ) {
		return array(
			'@type'            => array( 'MedicalProcedure', 'Service' ),
			'@id'              => $permalink . NVX_SD_ID_SERVICE,
			'name'             => 'EXION® BTL en Madrid',
			'serviceType'      => 'Protocolos médicos con plataforma EXION® BTL',
			'url'              => $permalink,
			'mainEntityOfPage' => array( '@id' => $permalink ),
			'provider'         => array( '@id' => $organization_id ),
			'description'      => 'Plataforma médica BTL con aplicadores Fractional RF, Face y Body para protocolos de textura, firmeza y calidad cutánea según diagnóstico. El presupuesto se documenta tras la valoración médica según aplicador, zona y plan de sesiones.',
			'procedureType'    => 'https://schema.org/NoninvasiveProcedure',
			'areaServed'       => 'Madrid',
		);
	}

	$btl_detail_keys = array( 'exion_face', 'exion_body', 'exion_fractional', 'emfusion' );
	if ( in_array( $key, $btl_detail_keys, true ) && function_exists( 'nvx_btl_detail_registry' ) ) {
		$slug_map = array(
			'exion_face'       => 'exion-face',
			'exion_body'       => 'exion-body',
			'exion_fractional' => 'exion-fractional',
			'emfusion'         => 'emfusion',
		);
		$slug     = $slug_map[ $key ] ?? '';
		$reg      = nvx_btl_detail_registry();
		if ( $slug && ! empty( $reg[ $slug ] ) && is_array( $reg[ $slug ] ) ) {
			$cfg = $reg[ $slug ];
			return array(
				'@type'            => 'Service',
				'@id'              => $permalink . NVX_SD_ID_SERVICE,
				'name'             => $cfg['schema_name'],
				'serviceType'      => $cfg['schema_type'],
				'url'              => $permalink,
				'mainEntityOfPage' => array( '@id' => $permalink ),
				'provider'         => array( '@id' => $organization_id ),
				'description'      => $cfg['schema_desc'],
				'areaServed'       => 'Madrid',
			);
		}
	}

	if ( 'exilite_btl' === $key ) {
		return array(
			'@type'            => 'Service',
			'@id'              => $permalink . NVX_SD_ID_SERVICE,
			'name'             => 'BTL EXILITE™ IPL en Madrid',
			'serviceType'      => 'Protocolos médicos con plataforma BTL EXILITE™ IPL',
			'url'              => $permalink,
			'mainEntityOfPage' => array( '@id' => $permalink ),
			'provider'         => array( '@id' => $organization_id ),
			'description'      => 'Luz pulsada intensa (IPL) para manchas, rojeces y lesiones pigmentarias o vasculares superficiales seleccionadas tras diagnóstico. No es un láser.',
			'areaServed'       => 'Madrid',
		);
	}

	return null;
}

function nvx_schema_treatment_node( $page_id, $organization_id ) {
	$key = nvx_schema_resolve_treatment_key( $page_id );
	if ( null === $key ) {
		return null;
	}

	$permalink = get_permalink( $page_id );

	$laser_node = nvx_schema_treatment_node_laser( $key, $permalink, $organization_id );
	if ( null !== $laser_node ) {
		return $laser_node;
	}

	return nvx_schema_treatment_node_btl( $key, $permalink, $organization_id );
}
