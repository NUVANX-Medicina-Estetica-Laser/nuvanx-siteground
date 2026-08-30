<?php
/**
 * Physician entity schema nodes (E-E-A-T), publications, offer catalog,
 * and emission-condition guards.
 *
 * Extracted from nvx-structured-data.php.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

/**
 * Director médico as Physician (E-E-A-T entity for GEO specialist queries).
 *
 * @param string $organization_id Organization @id.
 * @return array
 */
function nvx_schema_physician_director( $organization_id ) {
	$equipo      = home_url( NVX_SD_PATH_EQUIPO_MEDICO );
	$director_id = home_url( NVX_SD_PATH_EQUIPO_MEDICO . '#physician-rivera-tejeda' );
	$colegiado   = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'director' ) : '';
	$doctoralia  = function_exists( 'nvx_medical_staff_doctoralia_url' ) ? nvx_medical_staff_doctoralia_url( 'director' ) : '';

	return array(
		'@type'            => array( 'Person', 'Physician' ),
		'@id'              => $director_id,
		'name'             => 'José Javier Rivera Tejeda',
		'honorificPrefix'  => 'Dr.',
		'jobTitle'         => 'Director médico e investigador clínico aplicado · NUVANX Madrid',
		'description'      => 'Dirección médica de NUVANX. Láser intersticial (Endolift®, laserlipólisis), CO₂ fraccionado, geometría facial con inductores y tricología. ' . NVX_SD_LABEL_COLEGIADO_PREFIX . $colegiado . '. Perfil público en Doctoralia.',
		'url'              => $equipo . '#physician-rivera-tejeda',
		'image'            => get_template_directory_uri() . '/assets/images/team/nvx-dr-javier-rivera-director-medico.webp',
		'knowsAbout'       => array(
			'Medicina estética',
			'Medicina estética láser',
			'Endolift®',
			'Láser CO₂',
			'Tricología',
			NVX_SD_ENDOLIFT_FACIAL,
			'Laserlipólisis',
			NVX_SD_ENDOLASER_CORPORAL,
			NVX_SD_LASER_CO2_FRACCIONADO,
			'Marcación mandibular con láser',
			'Inductores de colágeno',
			'Tricología médica',
			NVX_SD_MEDICINA_REGENERATIVA,
		),
		'worksFor'         => array( '@id' => $organization_id ),
		'hasCredential'    => array(
			array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => NVX_SD_LABEL_NUM_COLEGIADO,
				'identifier'         => $colegiado,
				'name'               => NVX_SD_LABEL_COLEGIADO_PREFIX . $colegiado,
			),
			array(
				'@type' => 'EducationalOccupationalCredential',
				'name'  => 'Máster Universitario en Medicina Estética — Universidad Complutense de Madrid',
			),
			array(
				'@type' => 'EducationalOccupationalCredential',
				'name'  => 'Máster en Tricología y Cirugía Capilar — AMIR',
			),
		),
		'alumniOf'         => array(
			array(
				'@type' => 'CollegeOrUniversity',
				'name'  => 'Universidad Complutense de Madrid',
			),
			array(
				'@type' => 'EducationalOrganization',
				'name'  => 'AMIR',
			),
		),
		'sameAs'           => array(
			$doctoralia,
		),
	);
}

/**
 * Dra. Ivon Yamileth Rivera Deras — Physician + Researcher (E-E-A-T / GEO).
 *
 * @param string $organization_id Organization @id.
 * @return array
 */
function nvx_schema_physician_ivon( $organization_id ) {
	$equipo    = home_url( NVX_SD_PATH_EQUIPO_MEDICO );
	$ivon_id   = home_url( NVX_SD_PATH_EQUIPO_MEDICO . '#physician-rivera-deras' );
	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'ivon' ) : '';

	return array(
		'@type'            => array( 'Person', 'Physician' ),
		'@id'              => $ivon_id,
		'name'             => 'Ivon Yamileth Rivera Deras',
		'honorificPrefix'  => 'Dra.',
		'jobTitle'         => 'Especialista en Geriatría y Medicina Familiar y Comunitaria · NUVANX',
		'description'      => NVX_SD_LABEL_COLEGIADO_PREFIX . $colegiado . '. Especialista vía MIR en Geriatría y en Medicina Familiar y Comunitaria. Actividad asistencial actual en Hospital Universitario Vithas Arturo Soria y Hospital Universitario de Getafe; Medical Advisor y Medical Monitor en OXON Epidemiology; profesora asociada de Medicina en la Universidad Europea de Madrid. Coordinación científica y colaboración en deterioro cognitivo con SEMEG. Cursa el Máster de Formación Permanente Internacional en Medicina Estética, Antienvejecimiento y Nutrición de la UCAM (2026–2027).',
		'url'              => $equipo . '#physician-rivera-deras',
		'image'            => get_template_directory_uri() . '/assets/images/team/nvx-dra-paola-rivera-deras.webp',
		'medicalSpecialty' => array(
			'https://schema.org/Geriatric',
			'https://schema.org/PrimaryCare',
		),
		'worksFor'         => array(
			array( '@id' => $organization_id ),
			array(
				'@type' => 'Hospital',
				'name'  => 'Hospital Universitario Vithas Arturo Soria',
			),
			array(
				'@type' => 'Hospital',
				'name'  => 'Hospital Universitario de Getafe',
			),
			array(
				'@type' => 'Organization',
				'name'  => 'OXON Epidemiology',
			),
			array(
				'@type' => 'CollegeOrUniversity',
				'name'  => 'Universidad Europea de Madrid',
			),
		),
		'hasCredential'    => array(
			array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => NVX_SD_LABEL_NUM_COLEGIADO,
				'identifier'         => $colegiado,
				'name'               => 'Colegiada ICOMEM ' . $colegiado,
			),
			array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => 'Especialidad médica vía MIR',
				'name'               => 'Especialista en Geriatría — Hospital Central de la Cruz Roja San José y Santa Adela',
			),
			array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => 'Especialidad médica vía MIR',
				'name'               => 'Especialista en Medicina Familiar y Comunitaria — Hospital Universitario de Guadalajara',
			),
		),
		'affiliation'       => array(
			array(
				'@type' => 'MedicalOrganization',
				'name'  => NVX_SD_SOCIEDAD_SEMEG,
			),
		),
		'knowsAbout'       => array(
			'Geriatría',
			'Medicina Familiar y Comunitaria',
			'Nutrición clínica',
			'Well-aging',
			'Deterioro cognitivo',
			'Enfermedad de Alzheimer',
			'Fragilidad',
			'Seguridad del paciente',
			'Real-World Evidence',
			'Medicina estética, antienvejecimiento y nutrición — formación de posgrado en curso',
		),
	);
}

/**
 * Dr. Fabio Quiñónez Bareiro — Physician + Researcher (E-E-A-T / GEO).
 *
 * @param string $organization_id Organization @id.
 * @return array
 */
function nvx_schema_physician_fabio( $organization_id ) {
	$equipo    = home_url( NVX_SD_PATH_EQUIPO_MEDICO );
	$fabio_id  = home_url( NVX_SD_PATH_EQUIPO_MEDICO . '#physician-quinonez-bareiro' );
	$colegiado = function_exists( 'nvx_medical_colegiado' ) ? nvx_medical_colegiado( 'fabio' ) : '';

	return array(
		'@type'            => array( 'Person', 'Physician' ),
		'@id'              => $fabio_id,
		'name'             => 'Fabio Augusto Quiñónez Bareiro',
		'honorificPrefix'  => 'Dr.',
		'jobTitle'         => 'Especialista en geriatría, gerontología y paciente complejo · NUVANX',
		'description'      => NVX_SD_LABEL_COLEGIADO_PREFIX . $colegiado . '. Doctor por la UAM e investigador en el CIBERFES. FEA en Geriatría (Hospital Virgen del Valle, Toledo). Experto en fisiología del envejecimiento y paciente complejo. Integra longevidad y medicina regenerativa en NUVANX.',
		'url'              => $equipo . '#physician-quinonez-bareiro',
		'image'            => get_template_directory_uri() . '/assets/images/team/nvx-dr-quinonez.webp',
		'medicalSpecialty' => 'https://schema.org/Geriatric',
		'worksFor'         => array(
			array( '@id' => $organization_id ),
			array(
				'@type' => 'Hospital',
				'name'  => 'Hospital Virgen del Valle (Toledo)',
			),
		),
		'hasCredential'    => array(
			array(
				'@type'              => 'EducationalOccupationalCredential',
				'credentialCategory' => NVX_SD_LABEL_NUM_COLEGIADO,
				'identifier'         => $colegiado,
				'name'               => 'Colegiado ICOMEM ' . $colegiado,
			),
		),
		'memberOf'         => array(
			array(
				'@type' => 'MedicalOrganization',
				'name'  => 'CIBER de Fragilidad y Envejecimiento Saludable (CIBERFES)',
			),
			array(
				'@type' => 'MedicalOrganization',
				'name'  => NVX_SD_SOCIEDAD_SEMEG,
			),
		),
		'alumniOf'         => array(
			array(
				'@type' => 'CollegeOrUniversity',
				'name'  => 'Universidad Autónoma de Madrid',
			),
		),
		'knowsAbout'       => array(
			'Geriatría',
			'Gerontología',
			'Paciente complejo',
			'Fragilidad',
			'Deterioro cognitivo',
			'Longevidad',
			'Fisiología del envejecimiento',
		),
	);
}

/**
 * Creative works authored by Dra. Ivon (equipo page graph density).
 *
 * @param string $author_id Physician @id.
 * @return array<int, array>
 */
function nvx_schema_ivon_publications( $author_id ) {
	return array(
		array(
			'@type'  => 'ScholarlyArticle',
			'@id'    => home_url( '/equipo-medico/#work-ivon-j-ageing-longev-2026' ),
			'name'   => 'Oral Nutritional Supplementation in Routine Clinical Practice to Improve Physical Performance and Nutrition in Frail Adults at Risk of Falls: Preliminary Evidence',
			'author' => array( '@id' => $author_id ),
			'doi'    => '10.3390/jal6010015',
			'datePublished' => '2026',
		),
		array(
			'@type'  => 'ScholarlyArticle',
			'@id'    => home_url( '/equipo-medico/#work-ivon-neurama-2025' ),
			'name'   => 'Integración de biomarcadores y valoración geriátrica integral en el diagnóstico de deterioro cognitivo en adultos mayores',
			'author' => array( '@id' => $author_id ),
			'datePublished' => '2025',
		),
		array(
			'@type'  => 'Book',
			'@id'    => home_url( '/equipo-medico/#work-ivon-sindrome-titono' ),
			'name'   => 'Síndrome de Titono',
			'author' => array( '@id' => $author_id ),
			'datePublished' => '2026',
		),
		array(
			'@type'  => 'Book',
			'@id'    => home_url( '/equipo-medico/#work-ivon-sindrome-tantalo' ),
			'name'   => 'Síndrome del Tántalo',
			'author' => array( '@id' => $author_id ),
			'datePublished' => '2026',
		),
		array(
			'@type'  => 'CreativeWork',
			'@id'    => home_url( '/equipo-medico/#work-ivon-protocolo-deterioro-cognitivo' ),
			'name'   => 'Protocolo - Consulta de deterioro cognitivo',
			'author' => array( '@id' => $author_id ),
			'datePublished' => '2026',
			'publisher' => array(
				'@type' => 'MedicalOrganization',
				'name'  => NVX_SD_SOCIEDAD_SEMEG,
			),
		),
	);
}

/**
 * Creative works / thesis associated with Dr. Fabio (equipo page graph density).
 *
 * @param string $author_id Physician @id.
 * @return array<int, array>
 */
function nvx_schema_fabio_publications( $author_id ) {
	return array(
		array(
			'@type'              => 'Thesis',
			'@id'                => home_url( '/equipo-medico/#work-fabio-tesis-uam' ),
			'name'               => 'Disfunción vascular sub-clínica, declinar cognitivo y fragilidad',
			'author'             => array( '@id' => $author_id ),
			'inSupportOf'        => 'Ph.D.',
			'sourceOrganization' => array(
				'@type' => 'CollegeOrUniversity',
				'name'  => 'Universidad Autónoma de Madrid',
			),
		),
		array(
			'@type'       => 'ScholarlyArticle',
			'@id'         => home_url( '/equipo-medico/#work-fabio-itu-delirium' ),
			'name'        => '¿Será una infección del tracto urinario?',
			'author'      => array( '@id' => $author_id ),
			'description' => 'Diagnósticos diferenciales entre delírium e infección en el anciano.',
		),
	);
}

/**
 * Service catalog for home graph — cite-able list of protocols (with starting price when known).
 * No retail InStock spam; offers are informational reference tariffs.
 *
 * @param string $organization_id Organization @id.
 * @return array
 */
function nvx_schema_offer_catalog( $organization_id ) {
	$registry = nvx_schema_page_registry();
	$items    = array();
	$co2_from = function_exists( 'nvx_co2_price_facial_eur' ) ? nvx_co2_price_facial_eur() : null;

	$catalog_defs = array(
		'endolift_facial'    => array(
			'label' => NVX_SD_ENDOLIFT_FACIAL,
			'price' => nvx_endolift_price_from_eur(),
		),
		'endolaser_corporal' => array(
			'label' => NVX_SD_ENDOLASER_CORPORAL,
			'price' => null,
		),
		'laser_co2'          => array(
			'label' => NVX_SD_LASER_CO2_FRACCIONADO,
			'price' => $co2_from,
		),
		'exion_btl'          => array(
			'label' => 'EXION® BTL',
			'price' => null,
		),
		'exion_face'         => array(
			'label' => 'EXION® Face',
			'price' => null,
		),
		'exion_body'         => array(
			'label' => 'EXION® Body',
			'price' => null,
		),
		'exion_fractional'   => array(
			'label' => 'EXION® Fractional RF',
			'price' => null,
		),
		'emfusion'           => array(
			'label' => 'EMFUSION®',
			'price' => null,
		),
		'exilite_btl'        => array(
			'label' => 'BTL EXILITE™ IPL',
			'price' => null,
		),
	);

	foreach ( $catalog_defs as $key => $def ) {
		if ( empty( $registry['treatments'][ $key ]['path'] ) ) {
			continue;
		}
		$url   = home_url( $registry['treatments'][ $key ]['path'] );
		$offer = array(
			'@type'       => 'Offer',
			'itemOffered' => array(
				'@type' => 'Service',
				'name'  => $def['label'],
				'url'   => $url,
			),
			'url'         => $url,
			'areaServed'  => 'Madrid',
			'seller'      => array( '@id' => $organization_id ),
		);
		if ( null !== $def['price'] && $def['price'] > 0 ) {
			$offer['priceCurrency'] = 'EUR';
			$offer['price']         = nvx_schema_price_string( $def['price'] );
			$offer['description']   = 'Tarifa de referencia desde ' . nvx_format_price_eur( $def['price'] ) . ' € (presupuesto tras valoración).';
		}
		$items[] = $offer;
	}

	return array(
		'@type'           => 'OfferCatalog',
		'@id'             => home_url( '/#/schema/offer-catalog' ),
		'name'            => 'Protocolos médicos láser NUVANX',
		'itemListElement' => $items,
		'provider'        => array( '@id' => $organization_id ),
	);
}

/**
 * Whether director Physician should appear (home, equipo, treatment).
 *
 * @param int $page_id Current page ID.
 * @return bool
 */
function nvx_schema_should_emit_physician( $page_id ) {
	if ( is_front_page() || is_singular( 'post' ) || null !== nvx_schema_resolve_treatment_key( $page_id ) ) {
		return true;
	}

	$path = nvx_schema_current_path( $page_id );

	return nvx_schema_path_matches( $path, NVX_SD_PATH_EQUIPO_MEDICO ) || nvx_schema_path_matches( $path, '/dr-javier-rivera-tejeda/' );
}

/**
 * Whether Dra. Ivon Physician should appear (equipo + home for org trust; not every treatment).
 *
 * @param int $page_id Current page ID.
 * @return bool
 */
function nvx_schema_should_emit_physician_ivon( $page_id ) {
	if ( is_front_page() ) {
		return true;
	}

	$path = nvx_schema_current_path( $page_id );

	return nvx_schema_path_matches( $path, NVX_SD_PATH_EQUIPO_MEDICO );
}

/**
 * Whether Dr. Fabio Physician should appear (equipo + home for org trust).
 *
 * @param int $page_id Current page ID.
 * @return bool
 */
function nvx_schema_should_emit_physician_fabio( $page_id ) {
	if ( is_front_page() ) {
		return true;
	}

	$path = nvx_schema_current_path( $page_id );

	return nvx_schema_path_matches( $path, NVX_SD_PATH_EQUIPO_MEDICO ) || nvx_schema_path_matches( $path, '/dr-fabio-quinonez-bareiro/' );
}

/**
 * Builds array of physician nodes to emit for the current page.
 */
function nvx_schema_build_physicians( int $page_id, string $org_id ): array {
	$physicians = array();
	if ( nvx_schema_should_emit_physician( $page_id ) ) {
		$physicians[] = nvx_schema_physician_director( $org_id );
	}
	if ( nvx_schema_should_emit_physician_ivon( $page_id ) ) {
		$physicians[] = nvx_schema_physician_ivon( $org_id );
	}
	if ( nvx_schema_should_emit_physician_fabio( $page_id ) ) {
		$physicians[] = nvx_schema_physician_fabio( $org_id );
	}
	return $physicians;
}

/**
 * Enriches the main Organization node in Yoast schema graph.
 */