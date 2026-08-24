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
	  <div class="doc">
	    <div class="doc-hero">
	      <div class="doc-name">Dr. José Javier Rivera Tejeda</div>
	      <div class="doc-title">Director médico &middot; Endolift&reg; &middot; Láser CO&sub2; fraccionado &middot; Tricología</div>
	      <div class="doc-meta">
	        <span class="doc-pill accent">ICOMEM 282864786</span>
	        <span class="doc-pill">+17 años de trayectoria</span>
	        <span class="doc-pill">166 opiniones verificadas Doctoralia</span>
	        <span class="doc-pill">Idiomas: ES &middot; EN &middot; DE</span>
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
	          <a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="sched-item" style="text-decoration:none;"><strong>Chamberí (CS20144)</strong>Martes &middot; Jueves</a>
	          <a href="<?php echo esc_url( home_url( '/madrid/valoracion/' ) ); ?>" class="sched-item" style="text-decoration:none;"><strong>Goya&ndash;Salamanca (CS20073)</strong>Miércoles</a>
	        </div>
	      </div>

	      <div>
	        <div class="quote">
	          El diagnóstico tisular manda sobre la tecnología, no al revés. Primero evaluamos calidad dérmica, grado de ptosis y anatomía. Después, y solo si hay una razón clínica clara, seleccionamos la energía, la profundidad y los parámetros. Si el caso indica cirugía, lo decimos.
	          <cite>&mdash; Dr. J.J. Rivera Tejeda &middot; ICOMEM 282864786 &middot; <a href="https://www.doctoralia.es/jose-javier-rivera-tejeda/medico-estetico/madrid" target="_blank" rel="noopener noreferrer">Ver perfil Doctoralia (166 opiniones)</a></cite>
	        </div>
	      </div>

	    </div>
	  </div>

	  <!-- DRA. RIVERA DERAS -->
	  <div class="doc">
	    <div class="doc-hero">
	      <div class="doc-name">Dra. Ivon Yamileth Rivera Deras</div>
	      <div class="doc-title">Well-aging &middot; Geriatría preventiva &middot; Longevidad &middot; Medicina funcional</div>
	      <div class="doc-meta">
	        <span class="doc-pill accent">ICOMEM 284621525</span>
	        <span class="doc-pill">FEA Hospital Universitario La Paz</span>
	        <span class="doc-pill">SEMEG &middot; EuGMS</span>
	        <span class="doc-pill">OXON Epidemiology</span>
	      </div>
	    </div>

	    <div class="doc-body">

	      <div>
	        <div class="section-label">Actividad asistencial hospitalaria</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">Actual</div>
	            <div class="tl-content">Médico adjunto (FEA), Hospital Universitario La Paz&ndash;Cantoblanco, Madrid<em>Unidad de Recuperación Funcional y Hospital de Día Geriátrico &middot; Concurso selectivo SERMAS</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Actual</div>
	            <div class="tl-content">Cuadro médico, Hospital Central de la Cruz Roja San José y Santa Adela<em>Centro de referencia en neurorrehabilitación y atención al adulto mayor</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">Actual</div>
	            <div class="tl-content">Profesora e investigadora, Universidad Europea de Madrid<em>Vinculada al Hospital Vithas Madrid Arturo Soria &middot; Formación de médicos, enfermería y TCAE del SERMAS</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Investigación y sociedades científicas</div>
	        <div class="timeline">
	          <div class="tl-item">
	            <div class="tl-year">SEMEG</div>
	            <div class="tl-content">Coordinadora científica de la Jornada de Deterioro Cognitivo<em>Ponente verificada en la IV edición (Geriatricarea, feb. 2025): «Entre fragilidad y memoria. Desnutrición y caídas en el contexto cognitivo» &middot; Hospital La Paz&ndash;Cantoblanco</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">EuGMS</div>
	            <div class="tl-content">Colaboración activa con la European Geriatric Medicine Society<em>Red europea de geriatría clínica e investigación</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">OXON</div>
	            <div class="tl-content">Investigadora clínica externa y consultora, OXON Epidemiology<em>Real-World Evidence &middot; estudios observacionales y farmacoepidemiología</em></div>
	          </div>
	          <div class="tl-item">
	            <div class="tl-year">SEMEG</div>
	            <div class="tl-content">Publicación SEMEG: «Piuria no significa necesariamente infección»<em>Diagnóstico diferencial en el anciano &middot; Difusión científica verificada (@semeg_es)</em></div>
	          </div>
	        </div>
	      </div>

	      <hr class="divider">

	      <div>
	        <div class="section-label">Obra escrita</div>
	        <div class="pub">«El tormento de la inmortalidad sin juventud» &mdash; coautora</div>
	        <div class="pub">«Manual de manejo de personas mayores que sufren caídas» (SEMEG) &mdash; coautora</div>
	        <div class="pub">Trabajos sobre cribado cognitivo temprano y deterioro cognitivo</div>
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

	  <!-- DR. QUIÑÓNEZ -->
	  <div class="doc" itemscope itemtype="https://schema.org/Person">
	    <meta itemprop="sameAs" content="https://orcid.org/0000-0002-8390-7366" />
	    <div itemprop="affiliation" itemscope itemtype="https://schema.org/MedicalOrganization">
	      <meta itemprop="name" content="NUVANX Medicina Estética Láser" />
	    </div>
	    <div class="doc-hero">
	      <div class="doc-name" itemprop="name">Dr. Fabio Augusto Quiñónez Bareiro</div>
	      <div class="doc-title" itemprop="jobTitle">Geriatría &middot; Gerontología &middot; Fisiología del envejecimiento &middot; Paciente complejo</div>
	      <div class="doc-meta">
	        <span class="doc-pill accent">ICOMEM 282877543</span>
	        <span class="doc-pill">Ph.D. UAM &mdash; 11 jun. 2024</span>
	        <span class="doc-pill">CIBERFES (ISCIII)</span>
	        <span class="doc-pill">SEMEG</span>
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

	</div>
	<!-- SNIPPET CONTENT END -->
</div>

<?php
get_footer();
