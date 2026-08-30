<?php
/**
 * Template Name: Equipo Médico
 * Canonical medical team page.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_relative = '/assets/css/nvx-equipo-medico.css';
$css_path     = get_template_directory() . $css_relative;
if ( is_readable( $css_path ) ) {
	$version = function_exists( 'nvx_asset_version' )
		? nvx_asset_version( $css_relative )
		: (string) filemtime( $css_path );

	if ( ! function_exists( 'nvx_theme_public_delivers_inline_styles' ) || ! nvx_theme_public_delivers_inline_styles() ) {
		wp_enqueue_style(
			'nvx-equipo-medico',
			get_template_directory_uri() . $css_relative,
			array( 'nvx-components' ),
			$version
		);
	}
}

$clinics             = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
$chamberi            = is_array( $clinics['chamberi'] ?? null ) ? $clinics['chamberi'] : array();
$goya                = is_array( $clinics['goya'] ?? null ) ? $clinics['goya'] : array();
$director_doctoralia = function_exists( 'nvx_medical_staff_doctoralia_url' ) ? nvx_medical_staff_doctoralia_url( 'director' ) : '';

get_header();
?>

<div class="nvx-shell nvx-equipo-shell">
	<!-- SNIPPET CONTENT START -->
	<div class="pg">
	  <div class="pg-head">
	    <div class="pg-kicker">NUVANX &middot; Equipo médico</div>
	    <h1 class="pg-title">Quién te valora y quién trata en NUVANX Madrid</h1>
	    <div class="pg-sub">Tres médicos colegiados con práctica hospitalaria activa, investigación publicada y certificación en las tecnologías que aplican. Dirección médica, well-aging y geriatría del envejecimiento &mdash; en dos sedes.</div>
	  </div>

	  <!-- DR. RIVERA TEJEDA -->
	  <div class="doc" itemscope itemtype="https://schema.org/Person">
	    <meta itemprop="sameAs" content="<?php echo esc_url( $director_doctoralia ); ?>" />
	    <div class="doc-hero doc-hero--with-portrait">
	      <div class="doc-portrait">
	        <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/team/nvx-dr-javier-rivera-director-medico.webp' ); ?>" alt="<?php esc_attr_e( 'Dr. José Javier Rivera Tejeda — Director Médico NUVANX Madrid', 'nuvanx-medical' ); ?>" class="doc-portrait__img" width="130" height="130" loading="eager" decoding="async" />
	      </div>
	      <div class="doc-hero__info">
	        <div class="doc-name" itemprop="name">Dr. José Javier Rivera Tejeda</div>
	        <div class="doc-title" itemprop="jobTitle">Director médico &middot; Endolift&reg; &middot; Láser CO&sub2; fraccionado &middot; Tricología</div>
	        <div class="doc-meta">
	          <span class="doc-pill accent">ICOMEM <?php echo esc_html( nvx_medical_colegiado( 'director' ) ); ?></span>
	          <span class="doc-pill">+17 años de trayectoria</span>
	          <span class="doc-pill"><a href="<?php echo esc_url( $director_doctoralia ); ?>" target="_blank" rel="noopener noreferrer">166 opiniones verificadas Doctoralia</a></span>
	          <span class="doc-pill">Idiomas: ES &middot; EN &middot; DE</span>
	        </div>
	      </div>
	    </div>

	    <div class="doc-body">

	      <div>
	        <div class="section-label">Trayectoria clínica</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">2024 &rarr; act.</div>
	            <div class="tl-content">Director médico, NUVANX Medicina Estética Láser &mdash; Chamberí y Goya, Madrid<em>Endolift&reg; facial y corporal, láser CO&sub2;, inductores de colágeno, neuromoduladores</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Anterior</div>
	            <div class="tl-content">Cirugía cosmética láser en Clínicas Londres (Ciudad Real) y Clínicas Dr. Esquivel (Madrid)<em>Dirección de área de cirugía cosmética láser</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Anterior</div>
	            <div class="tl-content">Médico estético en Centros Único, Clínica Dermalife, Centro Médico Estético Sonia Cruz<em>Medicina inyectable, tecnología láser, tricología clínica</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Anterior</div>
	            <div class="tl-content">Hospital Universitario Severo Ochoa &mdash; Consultas médicas<em>Formación hospitalaria y práctica clínica general</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Anterior</div>
	            <div class="tl-content">DomusVi &mdash; Médico geriátrico (Leganés)<em>Atención al paciente mayor en entorno residencial</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Formación y certificaciones</div>
	        <div class="timeline">
	          <div class="tl-item"><div class="tl-year">UCM</div><div class="tl-content">Máster Universitario en Medicina Estética<em>Universidad Complutense de Madrid</em></div></div>
	          <div class="tl-item"><div class="tl-year">AMIR</div><div class="tl-content">Máster en Tricología y Cirugía Capilar<em>Alopecia androgenética, PRP capilar, mesoterapia</em></div></div>
	          <div class="tl-item"><div class="tl-year">ELAM</div><div class="tl-content">Licenciatura en Medicina<em>Escuela Latinoamericana de Medicina</em></div></div>
	          <div class="tl-item"><div class="tl-year">Cert.</div><div class="tl-content">Experto Certificado en Endolift&reg; Facial, Endolift&reg; Corporal y Laserlipólisis</div></div>
	          <div class="tl-item"><div class="tl-year">Cert.</div><div class="tl-content">Experto en Plataformas de Láser Fraccionado CO&sub2; y Tecnologías Lumínicas</div></div>
	          <div class="tl-item"><div class="tl-year">Cert.</div><div class="tl-content">Experto en Thermage</div></div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Protocolos que realiza en NUVANX</div>
	        <div class="tags">
	          <a href="<?php echo esc_url( home_url( '/endolift-facial-papada-mandibula/' ) ); ?>" class="tag excl">Endolift&reg; facial &mdash; exclusivo</a>
	          <a href="<?php echo esc_url( home_url( '/endolaser-corporal-grasa-localizada/' ) ); ?>" class="tag excl">Endolift&reg; corporal / laserlipólisis &mdash; exclusivo</a>
	          <a href="<?php echo esc_url( home_url( '/resurfacing-laser-co2-fraccionado/' ) ); ?>" class="tag blue">Láser CO&sub2; fraccionado</a>
	          <a href="<?php echo esc_url( home_url( '/exion-rf-fraccionada-microneedling-madrid/' ) ); ?>" class="tag blue">EXION&reg; Fractional RF</a>
	          <a href="<?php echo esc_url( home_url( '/btl-exilite-ipl-madrid/' ) ); ?>" class="tag blue">BTL EXILITE&trade; IPL</a>
	          <a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="tag blue">Medicina inyectable</a>
	          <a href="<?php echo esc_url( home_url( '/armonizacion-facial-radiesse-madrid/' ) ); ?>" class="tag blue">Bioestimuladores de colágeno</a>
	          <a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="tag blue">Rinomodelación</a>
	          <span class="tag blue">Tricología clínica</span>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Agenda en NUVANX</div>
	        <div class="schedule">
	          <a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="sched-item"><strong>Chamberí (<?php echo esc_html( (string) ( $chamberi['reg'] ?? '' ) ); ?>)</strong>Martes &middot; Jueves</a>
	          <a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="sched-item"><strong>Goya&ndash;Salamanca (<?php echo esc_html( (string) ( $goya['reg'] ?? '' ) ); ?>)</strong>Miércoles</a>
	        </div>
	      </div>

	      <div>
	        <div class="quote">
	          El diagnóstico tisular manda sobre la tecnología, no al revés. Primero evaluamos calidad dérmica, grado de ptosis y anatomía. Después, y solo si hay una razón clínica clara, seleccionamos la energía, la profundidad y los parámetros. Si el caso indica cirugía, lo decimos.
	          <cite>&mdash; Dr. J.J. Rivera Tejeda &middot; ICOMEM <?php echo esc_html( nvx_medical_colegiado( 'director' ) ); ?><?php if ( '' !== $director_doctoralia ) : ?> &middot; <a href="<?php echo esc_url( $director_doctoralia ); ?>" target="_blank" rel="noopener noreferrer">Ver perfil Doctoralia (166 opiniones)</a><?php endif; ?></cite>
	        </div>
	      </div>

	    </div>
	  </div>

	  <!-- DRA. RIVERA DERAS -->
	  <div class="doc" itemscope itemtype="https://schema.org/Person">
	    <div class="doc-hero doc-hero--with-portrait">
	      <div class="doc-portrait">
	        <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/team/nvx-dra-paola-rivera-deras.webp' ); ?>" alt="<?php esc_attr_e( 'Dra. Ivon Yamileth Rivera Deras — Geriatría y Medicina Familiar NUVANX', 'nuvanx-medical' ); ?>" class="doc-portrait__img" width="130" height="130" loading="lazy" decoding="async" />
	      </div>
	      <div class="doc-hero__info">
	        <div class="doc-name" itemprop="name">Dra. Ivon Yamileth Rivera Deras</div>
	        <div class="doc-title" itemprop="jobTitle">Geriatría &middot; Medicina Familiar y Comunitaria &middot; Nutrición clínica &middot; Well-aging</div>
	        <div class="doc-meta">
	          <span class="doc-pill accent">ICOMEM <?php echo esc_html( nvx_medical_colegiado( 'ivon' ) ); ?></span>
	          <span class="doc-pill">Especialista MIR en Geriatría</span>
	          <span class="doc-pill">Especialista MIR en Medicina Familiar y Comunitaria</span>
	          <span class="doc-pill">Máster UCAM 2026&ndash;2027 en curso</span>
	        </div>
	      </div>
	    </div>

	    <div class="doc-body">

	      <div>
	        <div class="section-label">Actividad asistencial actual</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">10/2022 &rarr; act.</div>
	            <div class="tl-content">Hospital Universitario Vithas Arturo Soria, Madrid<em>Servicio de Geriatría &middot; Urgencias y consulta de alta resolución</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">09/2025 &rarr; act.</div>
	            <div class="tl-content">OXON Epidemiology<em>Medical Advisor y Medical Monitor &middot; asesoramiento científico-clínico, seguridad del paciente y supervisión médica de estudios</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">11/2025 &rarr; act.</div>
	            <div class="tl-content">Hospital Universitario de Getafe, Madrid<em>Servicio de Geriatría &middot; interconsulta hospitalaria y valoración integral de pacientes complejos</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">2022 &rarr; 09/2025</div>
	            <div class="tl-content">Hospital Universitario La Paz&ndash;Cantoblanco, Madrid<em>Responsable de la Unidad de Memoria y de Seguridad del Paciente en el Servicio de Geriatría</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Formación médica y académica</div>
	        <div class="timeline">
	          <div class="tl-item"><div class="tl-year">MIR</div><div class="tl-content">Especialista en Geriatría<em>Hospital Central de la Cruz Roja San José y Santa Adela, Madrid &middot; 2016&ndash;2021</em></div></div>
	          <div class="tl-item"><div class="tl-year">MIR</div><div class="tl-content">Especialista en Medicina Familiar y Comunitaria<em>Hospital Universitario de Guadalajara &middot; 2010&ndash;2014</em></div></div>
	          <div class="tl-item"><div class="tl-year">UCAM &middot; 2026&ndash;2027</div><div class="tl-content">Máster de Formación Permanente Internacional en Medicina Estética, Antienvejecimiento y Nutrición &mdash; en curso<em>90 ECTS &middot; procedimientos mínimamente invasivos, medicina antienvejecimiento, nutrición aplicada y gestión de clínicas médico-estéticas</em></div></div>
	          <div class="tl-item"><div class="tl-year">2024</div><div class="tl-content">Máster de Formación Permanente en Enfermedades Neurodegenerativas<em>TECH Universidad Tecnológica &middot; biomarcadores y terapias emergentes</em></div></div>
	          <div class="tl-item"><div class="tl-year">2025</div><div class="tl-content">Experta en Psicogeriatría y en Enfermedad de Alzheimer<em>Universidad Católica de Murcia y Universidad Francisco de Vitoria</em></div></div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Investigación, docencia y responsabilidad clínica</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">UEM</div>
	            <div class="tl-content">Profesora Asociada de Medicina en la Universidad Europea de Madrid<em>Responsable de la asignatura de Geriatría en el Grado en Medicina</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">SEMEG</div>
	            <div class="tl-content">Coordinadora científica de las Jornadas de Deterioro Cognitivo<em>Colaboradora del Grupo de Trabajo en Deterioro Cognitivo de la Sociedad Española de Medicina Geriátrica</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Investigación</div>
	            <div class="tl-content">Investigadora principal y colaboradora en estudios de fragilidad, nutrición y deterioro cognitivo<em>Incluye NUTRIFRAIL, ASPECT, AB21004-ALZ, DEMPAZ, PROBIOMIND y ESTRAGENIAL</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Calidad</div>
	            <div class="tl-content">Experiencia en seguridad del paciente y mejora asistencial<em>Responsable de Seguridad del Paciente en Geriatría de Cantoblanco &middot; certificaciones AENOR 2022&ndash;2026</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Publicaciones y obra reciente</div>
	        <div class="pub"><strong>J. Ageing Longev. (2026) &mdash; primer autor</strong> «Oral Nutritional Supplementation in Routine Clinical Practice to Improve Physical Performance and Nutrition in Frail Adults at Risk of Falls: Preliminary Evidence».</div>
	        <div class="pub"><strong>Neurama (2025) &mdash; primer autor</strong> «Integración de biomarcadores y valoración geriátrica integral en el diagnóstico de deterioro cognitivo en adultos mayores».</div>
	        <div class="pub"><strong>2026</strong> Coautora de «Síndrome de Titono» y «Síndrome del Tántalo»; coordinadora científica del protocolo SEMEG «Consulta de deterioro cognitivo».</div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Protocolos que realiza en NUVANX</div>
	        <div class="tags">
	          <a href="<?php echo esc_url( home_url( '/btl-exilite-ipl-madrid/' ) ); ?>" class="tag blue">BTL EXILITE&trade; IPL</a>
	          <a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="tag blue">Medicina inyectable</a>
	          <a href="<?php echo esc_url( home_url( '/armonizacion-facial-radiesse-madrid/' ) ); ?>" class="tag blue">Bioestimuladores de colágeno</a>
	        </div>
	      </div>

	    </div>
	  </div>

	  <!-- DR. QUIÑÓNEZ -->
	  <div class="doc" itemscope itemtype="https://schema.org/Person">
	    <meta itemprop="sameAs" content="https://orcid.org/0000-0002-8390-7366" />
	    <div itemprop="affiliation" itemscope itemtype="https://schema.org/MedicalOrganization">
	      <meta itemprop="name" content="NUVANX Medicina Estética Láser" />
	    </div>
	    <div class="doc-hero doc-hero--with-portrait">
	      <div class="doc-portrait">
	        <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/team/nvx-dr-quinonez.webp' ); ?>" alt="<?php esc_attr_e( 'Dr. Fabio Augusto Quiñónez Bareiro — Geriatría y Paciente Complejo NUVANX', 'nuvanx-medical' ); ?>" class="doc-portrait__img" width="130" height="130" loading="lazy" decoding="async" />
	      </div>
	      <div class="doc-hero__info">
	        <div class="doc-name" itemprop="name">Dr. Fabio Augusto Quiñónez Bareiro</div>
	        <div class="doc-title" itemprop="jobTitle">Geriatría &middot; Gerontología &middot; Fisiología del envejecimiento &middot; Paciente complejo</div>
	        <div class="doc-meta">
	          <span class="doc-pill accent">ICOMEM <?php echo esc_html( nvx_medical_colegiado( 'fabio' ) ); ?></span>
	          <span class="doc-pill">Ph.D. UAM &mdash; 11 jun. 2024</span>
	          <span class="doc-pill">CIBERFES (ISCIII)</span>
	          <span class="doc-pill">SEMEG</span>
	        </div>
	      </div>
	    </div>

	    <div class="doc-body">

	      <div>
	        <div class="section-label">Trayectoria clínica</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">Actual</div>
	            <div class="tl-content">FEA Geriatría, Hospital Virgen del Valle &mdash; Complejo Hospitalario Universitario de Toledo<em>Paciente complejo: pluripatología, fragilidad, demencia, riesgo cardiovascular</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Anterior</div>
	            <div class="tl-content">Hospital de Emergencias Enfermera Isabel Zendal (Madrid) &mdash; Urgencias y medicina interna<em>Durante pandemia COVID-19</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Anterior</div>
	            <div class="tl-content">Hospital Quirónsalud Tres Culturas</div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Anterior</div>
	            <div class="tl-content">Hospital Virgen de la Salud, Toledo &mdash; Urgencias<em>Paciente crítico</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Formación académica</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">UAM &middot; 2024</div>
	            <div class="tl-content">Doctor en Medicina (Ph.D.) &mdash; Tesis leída el 11 de junio de 2024<em>«Disfunción vascular sub-clínica, declinar cognitivo y fragilidad» &middot; Dir: F.J. García García y J.A. Carnicero &middot; Dpto. de Medicina Preventiva y Salud Pública</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">UAB</div>
	            <div class="tl-content">Máster en Psicogeriatría<em>Universitat Autònoma de Barcelona</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">ELAM</div>
	            <div class="tl-content">Licenciatura en Medicina<em>Escuela Latinoamericana de Medicina</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Publicaciones científicas indexadas (selección verificada)</div>
	        <div class="pub">
	          <strong>GeroScience (2023) &mdash; Primer autor</strong>
	          «How cognitive performance changes according to the ankle-brachial index score in an elderly cohort? Results from the Toledo Study of Healthy Ageing» &middot; Estudio prospectivo con 1.147 participantes &ge;65 años &middot; <a href="https://link.springer.com/article/10.1007/s11357-023-00966-4" target="_blank" rel="noopener noreferrer">DOI 10.1007/s11357-023-00966-4</a>
	        </div>
	        <div class="pub">
	          <strong>The Journals of Gerontology: Series A (2026) &mdash; Coautor</strong>
	          «Interplay between osteosarcopenia and intrinsic capacity: insights and associations with all-cause mortality in the Toledo Study for Healthy Aging» &middot; Affil: CIBERFES, ISCIII, Madrid &middot; <a href="https://academic.oup.com/biomedgerontology/article/81/6/glag090/8626955" target="_blank" rel="noopener noreferrer">DOI 10.1093/gerona/glag090</a>
	        </div>
	        <div class="pub">
	          <strong>American Journal of Geriatric Psychiatry (2025) &mdash; Coautor</strong>
	          «Frailty and Depression: A Comprehensive Perspective on Their Role in Adverse Health Outcomes» &middot; Affil: Hospital Virgen del Valle + CIBERFES
	        </div>
	        <div class="pub">
	          <strong>J. Frailty Aging (2022) &mdash; Coautor</strong>
	          «Risk of frailty according to the values of the ankle-brachial index in the Toledo Study for Healthy Aging»
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Docencia universitaria</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">TECH Univ.</div>
	            <div class="tl-content">Profesor Colaborador &mdash; Curso Universitario en Paciente Anciano Crónico Complejo<em>Pluripatología: diabetes, insuficiencia cardíaca y demencia</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">TECH Univ.</div>
	            <div class="tl-content">Diseño de contenidos del Experto en Patología Osteoarticular<em>Artrosis, osteoporosis, dolor avanzado</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Protocolos que realiza en NUVANX</div>
	        <div class="tags">
	          <a href="<?php echo esc_url( home_url( '/resurfacing-laser-co2-fraccionado/' ) ); ?>" class="tag blue">Láser CO&sub2; fraccionado</a>
	          <a href="<?php echo esc_url( home_url( '/exion-rf-fraccionada-microneedling-madrid/' ) ); ?>" class="tag blue">EXION&reg; Fractional RF</a>
	          <a href="<?php echo esc_url( home_url( '/exion-face/' ) ); ?>" class="tag blue">EXION Face</a>
	          <a href="<?php echo esc_url( home_url( '/exion-body/' ) ); ?>" class="tag blue">EXION Body</a>
	          <a href="<?php echo esc_url( home_url( '/btl-exilite-ipl-madrid/' ) ); ?>" class="tag blue">BTL EXILITE&trade; IPL</a>
	          <a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="tag blue">Medicina inyectable</a>
	          <a href="<?php echo esc_url( home_url( '/armonizacion-facial-radiesse-madrid/' ) ); ?>" class="tag blue">Bioestimuladores de colágeno</a>
	        </div>
	      </div>

	    </div>
	  </div>

	  <!-- DRA. CRISTINA MÁRQUEZ -->
	  <div class="doc" itemscope itemtype="https://schema.org/Person">
	    <?php $cristina_doc = function_exists( 'nvx_medical_staff_doctoralia_url' ) ? nvx_medical_staff_doctoralia_url( 'cristina' ) : ''; ?>
	    <?php if ( '' !== $cristina_doc ) : ?>
	      <meta itemprop="sameAs" content="<?php echo esc_url( $cristina_doc ); ?>" />
	    <?php endif; ?>
	    <div class="doc-hero doc-hero--with-portrait">
	      <div class="doc-portrait">
	        <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/team/nvx-dra-cristina-marquez.webp' ); ?>" alt="<?php esc_attr_e( 'Dra. Cristina Márquez González — Senología y Medicina Estética NUVANX', 'nuvanx-medical' ); ?>" class="doc-portrait__img" width="130" height="130" loading="lazy" decoding="async" />
	      </div>
	      <div class="doc-hero__info">
	        <div class="doc-name" itemprop="name">Dra. Cristina Márquez González</div>
	        <div class="doc-title" itemprop="jobTitle">Radiología mamaria &middot; Senología &middot; Medicina estética facial</div>
	        <div class="doc-meta">
	          <span class="doc-pill accent">ICOMEM <?php echo esc_html( nvx_medical_colegiado( 'cristina' ) ); ?></span>
	          <span class="doc-pill">HM Hospitales</span>
	          <span class="doc-pill">Sede Goya · Barrio Salamanca</span>
	          <?php if ( '' !== $cristina_doc ) : ?>
	            <span class="doc-pill"><a href="<?php echo esc_url( $cristina_doc ); ?>" target="_blank" rel="noopener noreferrer">Perfil verificado Doctoralia</a></span>
	          <?php endif; ?>
	        </div>
	      </div>
	    </div>

	    <div class="doc-body">
	      <div>
	        <div class="section-label">Ámbito asistencial y formación</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">Hospitalario</div>
	            <div class="tl-content">Facultativa Especialista en Radiología Mamaria en HM Hospitales<em>Diagnóstico mamario avanzado, ecografía intervencionista y seguimiento clínico</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">NUVANX</div>
	            <div class="tl-content">Médica estética en centro clínico NUVANX Salamanca–Goya<em>Protocolos de armonización facial, inductores de colágeno y rejuvenecimiento</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Posgrado</div>
	            <div class="tl-content">Especialización en Senología y Patología Mamaria &middot; Máster en Medicina Estética</div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Protocolos que realiza en NUVANX</div>
	        <div class="tags">
	          <a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="tag blue">Armonización facial</a>
	          <a href="<?php echo esc_url( home_url( '/armonizacion-facial-radiesse-madrid/' ) ); ?>" class="tag blue">Bioestimuladores e inductores de colágeno</a>
	          <a href="<?php echo esc_url( home_url( '/medicina-estetica/' ) ); ?>" class="tag blue">Neuromoduladores</a>
	          <a href="<?php echo esc_url( home_url( '/resurfacing-laser-co2-fraccionado/' ) ); ?>" class="tag blue">Láser CO&sub2;</a>
	        </div>
	      </div>
	    </div>
	  </div>

	  <!-- EQUIPO CLÍNICO Y COORDINACIÓN -->
	  <div class="nvx-staff-section">
	    <div class="section-label">Equipo de coordinación asistencial y experiencia de paciente</div>
	    <div class="nvx-staff-grid">
	      <div class="nvx-staff-card">
	        <div class="nvx-staff-portrait">
	          <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/team/nvx-francisco-geraldo-ceo.webp' ); ?>" alt="Francisco Geraldo Lorenzo" class="nvx-staff-img" width="72" height="72" loading="lazy" decoding="async" />
	        </div>
	        <div class="nvx-staff-info">
	          <div class="nvx-staff-name">Francisco Geraldo Lorenzo</div>
	          <div class="nvx-staff-role">Coordinación clínica · Dirección asistencial · Enfermería</div>
	        </div>
	      </div>

	      <div class="nvx-staff-card">
	        <div class="nvx-staff-portrait">
	          <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/team/nvx-yolanda-pinero-goya.webp' ); ?>" alt="Yolanda Piñero" class="nvx-staff-img" width="72" height="72" loading="lazy" decoding="async" />
	        </div>
	        <div class="nvx-staff-info">
	          <div class="nvx-staff-name">Yolanda Piñero</div>
	          <div class="nvx-staff-role">Coordinación asistencial · Sede Salamanca–Goya</div>
	        </div>
	      </div>

	      <div class="nvx-staff-card">
	        <div class="nvx-staff-portrait">
	          <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/team/gosia.webp' ); ?>" alt="Gosia" class="nvx-staff-img" width="72" height="72" loading="lazy" decoding="async" />
	        </div>
	        <div class="nvx-staff-info">
	          <div class="nvx-staff-name">Gosia</div>
	          <div class="nvx-staff-role">Atención al paciente · Coordinación de agenda</div>
	        </div>
	      </div>
	    </div>
	  </div>

	</div>
	<!-- SNIPPET CONTENT END -->
</div>

<?php
get_footer();