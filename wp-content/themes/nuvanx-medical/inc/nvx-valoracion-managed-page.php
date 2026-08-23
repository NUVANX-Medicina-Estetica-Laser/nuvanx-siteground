<?php
/**
 * Canonical theme-owned valuation landing.
 *
 * The CMS stores a stable route marker only. This module renders the full
 * hierarchy and the canonical HubSpot mount so staging and production do not
 * depend on historical database HTML.
 *
 * SEO title/description are owned exclusively by nvx-seo-metadata.php. HubSpot
 * mount normalization is owned by nvx-runtime-governance.js; this dedicated
 * conversion route also ships a defensive bootstrap so the form cannot remain
 * blank if that runtime is delayed or dropped by an optimizer.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical clinical explanation for the managed valuation landing.
 *
 * A virtual orientation can start the process, while physical examination is
 * still required whenever it is clinically necessary before treatment.
 */
function nvx_valoracion_managed_intro_markup(): string {
	$html  = '<section class="nvx-brand-section nvx-valoracion-intro" id="nvx-valoracion-intro" aria-labelledby="nvx-valoracion-intro-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Primer paso', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-intro-title" class="nvx-heading">' . esc_html__( 'Una consulta médica para orientar tu caso', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Puedes iniciar el proceso con una orientación virtual o reservar directamente una valoración presencial en Chamberí o Salamanca–Goya. La cita suele durar entre 15 y 30 minutos. Durante ese tiempo revisamos el motivo de consulta, las opciones de tratamiento, los tiempos de recuperación y el presupuesto individualizado. Cuando la exploración física sea necesaria para confirmar la indicación, se completa de forma presencial antes de tratar.', 'nuvanx-medical' ) . '</p>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Al finalizar tendrás una orientación clara sobre los siguientes pasos. El equipo, bajo la dirección del Dr. Rivera Tejeda, sigue tres criterios:', 'nuvanx-medical' ) . '</p>';
	$html .= '<ol class="nvx-treatment-process__steps nvx-valoracion-steps">';

	$steps = function_exists( 'nvx_valoracion_process_steps' ) ? nvx_valoracion_process_steps() : array();
	foreach ( $steps as $step ) {
		$html .= '<li class="nvx-treatment-process__step">';
		$html .= '<h3 class="nvx-treatment-process__step-title">' . esc_html( $step['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $step['body'] ?? '' ) . '</p>';
		$html .= '</li>';
	}
	$html .= '</ol>';

	if ( function_exists( 'nvx_contact_privacy_disclaimer_markup' ) ) {
		$html .= nvx_contact_privacy_disclaimer_markup();
	}
	$html .= '</div></section>';

	$html .= '<section class="nvx-brand-section nvx-valoracion-locations" aria-labelledby="nvx-valoracion-loc-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Sedes', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-loc-title" class="nvx-heading">' . esc_html__( 'Ubicaciones autorizadas por Sanidad', 'nuvanx-medical' ) . '</h2>';
	if ( function_exists( 'nvx_contact_clinics_markup' ) ) {
		$html .= nvx_contact_clinics_markup();
	}
	$html .= '</div></section>';

	$wa_url = function_exists( 'nvx_cta_whatsapp_url' ) ? nvx_cta_whatsapp_url() : 'https://wa.me/34689317399';
	$html  .= '<section class="nvx-home-closure" aria-labelledby="nvx-valoracion-closure-title">';
	$html  .= '<div class="nvx-container">';
	$html  .= '<h2 id="nvx-valoracion-closure-title" class="nvx-home-closure__title">' . esc_html__( '¿Dudas sobre tu caso o la indicación?', 'nuvanx-medical' ) . '</h2>';
	$html  .= '<p class="nvx-home-closure__desc">' . esc_html__( 'Nuestro equipo médico revisará tus consultas previas sin compromiso comercial.', 'nuvanx-medical' ) . '</p>';
	$html  .= '<div class="nvx-home-closure__actions">';
	$html  .= '<a href="#nvx-hubspot-form" class="nvx-brand-btn nvx-btn--primary">' . esc_html__( 'Solicitar valoración médica', 'nuvanx-medical' ) . '</a>';
	$html  .= '<a href="' . esc_url( $wa_url ) . '" class="nvx-brand-btn nvx-btn--secondary-on-dark" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Contactar por WhatsApp', 'nuvanx-medical' ) . '</a>';
	$html  .= '</div></div></section>';

	return $html;
}

/**
 * Deterministic loader for the dedicated valoración conversion page.
 *
 * The normal runtime remains the primary owner. This bootstrap first triggers
 * that runtime synchronously and only injects the same declarative HubSpot
 * portal loader when no owner has created it. It also repairs a missing frame
 * and removes extra frames inside the canonical host, making the page resilient
 * to optimizer timing without introducing a second form instance.
 */
function nvx_valoracion_hubspot_bootstrap_markup(): string {
	$portal_id = defined( 'NVX_VALORACION_HS_FRAME_PORTAL_ID' ) ? (string) NVX_VALORACION_HS_FRAME_PORTAL_ID : '147416356';
	$form_id   = defined( 'NVX_VALORACION_HS_FRAME_FORM_ID' ) ? (string) NVX_VALORACION_HS_FRAME_FORM_ID : '5042522a-0bc5-4381-ac3e-5aee8649b69c';
	$region    = defined( 'NVX_VALORACION_HS_FRAME_REGION' ) ? (string) NVX_VALORACION_HS_FRAME_REGION : 'eu1';

	$portal_id = trim( $portal_id );
	$region    = strtolower( trim( $region ) );
	if ( 1 !== preg_match( '/^\d{1,20}$/', $portal_id ) ) {
		$portal_id = '147416356';
	}
	if ( 1 !== preg_match( '/^[a-z]{2,4}\d{1,2}$/', $region ) ) {
		$region = 'eu1';
	}

	$config = wp_json_encode(
		array(
			'portalId' => $portal_id,
			'formId'   => $form_id,
			'region'   => $region,
		),
		JSON_UNESCAPED_SLASHES
	);
	if ( ! is_string( $config ) ) {
		$config = '{"portalId":"147416356","formId":"5042522a-0bc5-4381-ac3e-5aee8649b69c","region":"eu1"}';
	}

		// The valuation form is a functional lead-request channel. Its HubSpot embed
		// must remain available when marketing cookies are declined; attribution and
		// other marketing scripts continue to require Complianz marketing consent.
		return '<script id="nvx-valoracion-form-eager">(function(){"use strict";var cfg=' . $config . ';var recoveryTimer=0;var formHost=null;'
			. 'function hostMatches(hostname,domain){hostname=String(hostname||"").toLowerCase();return hostname===domain||hostname.slice(-(domain.length+1))==="."+domain;}'
			. 'function isAllowedHubSpotHost(hostname){return hostMatches(hostname,"hsforms.net")||hostMatches(hostname,"hsforms.com")||hostMatches(hostname,"hubspot.com");}'
			. 'function getFormHost(){if(formHost&&document.documentElement.contains(formHost)){return formHost;}formHost=document.getElementById("nvx-hubspot-native-form");return formHost;}'
			. 'function hasMarketingConsent(){if(typeof window.cmplz_has_consent!=="function"){return false;}try{return window.cmplz_has_consent("marketing")===true;}catch(e){return false;}}' . 'function hasFormAccess(){var host=getFormHost();if(host&&host.getAttribute("data-nvx-consent")==="functional"){return true;}return hasMarketingConsent();}'
		. 'function iframeIsHubSpot(iframe){if(!iframe){return false;}var src=(iframe.getAttribute("src")||"").trim();if(!src||src==="about:blank"){return false;}try{return isAllowedHubSpotHost(new URL(src,window.location.href).hostname);}catch(e){return false;}}'
		. 'function hasUsableHubSpotIframe(root){if(!root||!hasFormAccess()){return false;}var iframes=root.querySelectorAll("iframe");for(var i=0;i<iframes.length;i++){if(iframeIsHubSpot(iframes[i])){return true;}}return false;}'
		. 'function isRenderable(root){if(!root||!hasFormAccess()){return false;}if(root.querySelector(".hbspt-form input,.hbspt-form textarea,.hs-form input")){return true;}return hasUsableHubSpotIframe(root);}'
		. 'function formIsDirty(form){if(!form){return false;}var els=form.querySelectorAll("input:not([type=hidden]):not([type=submit]):not([type=checkbox]),textarea");for(var i=0;i<els.length;i++){if(String(els[i].value||"").trim()){return true;}}return false;}'
		. 'function sync(host,frame){if(!host){return;}var live=isRenderable(host)||isRenderable(frame);var form=host.querySelector("[data-nvx-direct-form]");if(live&&!formIsDirty(form)){host.classList.add("nvx-hubspot-is-live");}else{host.classList.remove("nvx-hubspot-is-live");}}'
		. 'function hasActiveEmbed(){var scripts=document.querySelectorAll("#nvx-hubspot-forms-runtime,script[data-nvx-hubspot-canonical=\\"1\\"],script[src*=\\"/forms/embed/\\"]");for(var i=0;i<scripts.length;i++){var type=(scripts[i].getAttribute("type")||"text/javascript").toLowerCase();if(type==="text/plain"){continue;}return true;}return false;}'
		. 'function boot(){var host=getFormHost();if(!host){return;}'
		. 'var frames=host.querySelectorAll(".hs-form-frame");var frame=null;var i;'
		. 'for(i=0;i<frames.length;i++){if(hasUsableHubSpotIframe(frames[i])||isRenderable(frames[i])){frame=frames[i];break;}}'
		. 'if(!frame){frame=frames[0]||null;}'
		. 'if(!frame){frame=document.createElement("div");frame.className="hs-form-frame";host.insertBefore(frame,host.firstChild);}'
		. 'for(i=0;i<frames.length;i++){if(frames[i]!==frame){frames[i].remove();}}'
		. 'frame.dataset.region=cfg.region;frame.dataset.portalId=cfg.portalId;frame.dataset.formId=cfg.formId;frame.dataset.nvxHubspotLazy="1";sync(host,frame);'
		. 'if(typeof MutationObserver==="function"&&!host.dataset.nvxHubspotObserver){host.dataset.nvxHubspotObserver="1";new MutationObserver(function(){sync(host,frame);}).observe(host,{childList:true,subtree:true,attributes:true,attributeFilter:["src","data-category"]});}'
		. 'if(!hasFormAccess()){return;}'
		. 'if(isRenderable(host)||isRenderable(frame)){return;}'
		. 'if(recoveryTimer){return;}'
		. 'try{host.dispatchEvent(new Event("focusin",{bubbles:true}));}catch(e){}'
		. 'recoveryTimer=window.setTimeout(function(){recoveryTimer=0;sync(host,frame);if(!hasFormAccess()){return;}if(isRenderable(host)||isRenderable(frame)){return;}'
		. 'if(hasActiveEmbed()){return;}'
		. 'var script=document.createElement("script");script.id="nvx-hubspot-forms-runtime";script.dataset.nvxHubspotCanonical="1";'
		. 'script.src="https://js-"+cfg.region+".hs"+"forms.net/forms/embed/"+cfg.portalId+".js";script.async=true;'
		. 'script.addEventListener("load",function(){script.dataset.nvxLoaded="1";sync(host,frame);},{once:true});document.head.appendChild(script);},0);}'
		. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot,{once:true});}else{boot();}'
		. 'window.addEventListener("load",boot,{once:true});'
		. 'document.addEventListener("cmplz_enable_category",function(){window.setTimeout(boot,0);});document.addEventListener("cmplz_status_change",function(){window.setTimeout(boot,0);});'
		. 'window.addEventListener("hs-form-event:on-ready",function(){boot();});'
		. 'window.addEventListener("message",function(event){var data=event&&event.data;if(typeof data==="string"){try{data=JSON.parse(data);}catch(e){data=null;}}if(!data||data.type!=="hsFormCallback"){return;}var name=String(data.eventName||"").toLowerCase();if(name==="onformready"||name==="onformsubmitted"){boot();}});})();</script>';
}

/**
 * Build the canonical valuation page before form-order and HubSpot MU filters.
 */
function nvx_valoracion_managed_page_markup(): string {
	// Hero image-free by design: block the featured-image injection performed by
	// nvx_ensure_hero_featured_media (the_content prio 12). The managed renderer
	// runs earlier (prio 10), so setting the flag here short-circuits that filter.
	global $nvx_page_shell_has_hero;
	$nvx_page_shell_has_hero = true;

	$valuation_url  = home_url( '/madrid/valoracion/' );
	$doctoralia_url = 'https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser';
	$wa_url         = function_exists( 'nvx_cta_whatsapp_url' ) ? nvx_cta_whatsapp_url() : 'https://wa.me/34689317399';

	$html = '<div class="nvx-brand-page nvx-valoracion-page" id="nvx-valoracion-main" aria-labelledby="nvx-valoracion-h1">';

	// Conversion-first page header: site header/menu -> concise page heading -> form.
	$html .= '<section class="nvx-brand-hero nvx-valoracion-hero" aria-labelledby="nvx-valoracion-h1">';
	$html .= '<div class="nvx-brand-hero__inner">';
	$html .= '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'VALORACIÓN MÉDICA · MADRID', 'nuvanx-medical' ) . '</p>';
	$html .= '<h1 id="nvx-valoracion-h1" class="nvx-brand-hero__title">' . esc_html__( 'Valoración médica estética en Madrid', 'nuvanx-medical' ) . '</h1>';
	$html .= function_exists( 'nvx_clinical_authority_byline_markup' )
		? nvx_clinical_authority_byline_markup()
		: '';
	$html .= '<p class="nvx-brand-hero__lead" id="nvx-valoracion-lead">' . esc_html__( 'Valoración médica en Madrid de 15 a 30 minutos: diagnóstico, indicación y presupuesto documentado antes de tratar. Sin Anestesia General. Recuperación en 48h como reincorporación habitual al trabajo o a la vida social, según el protocolo indicado; el edema y el ejercicio de impacto pueden requerir más días. Sin obligación de procedimiento.', 'nuvanx-medical' ) . '</p>';
	$html .= '<ul class="nvx-valoracion-hero__proof" aria-label="' . esc_attr__( 'Condiciones clínicas del protocolo', 'nuvanx-medical' ) . '">';
	$html .= '<li>' . esc_html__( 'Sin Anestesia General', 'nuvanx-medical' ) . '</li>';
	$html .= '<li>' . esc_html__( 'Recuperación en 48h (reincorporación habitual, según protocolo)', 'nuvanx-medical' ) . '</li>';
	$html .= '</ul>';
	$html .= '</div></div></section>';

	// The form is deliberately the first content block after the heading.
	$html .= '<section class="nvx-brand-section nvx-hubspot-form-section nvx-form-stage" id="nvx-hubspot-form" aria-labelledby="nvx-valoracion-form-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'SOLICITUD DE VALORACIÓN', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-form-title" class="nvx-brand-title">' . esc_html__( 'Cuéntanos qué quieres valorar', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-brand-body">' . esc_html__( 'Completa tus datos e indica la zona o tratamiento de interés. El equipo de NUVANX te contactará para coordinar una orientación virtual o una valoración presencial. La cita suele durar entre 15 y 30 minutos y permite revisar tu caso, las opciones de tratamiento y el presupuesto individualizado.', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-valoracion-direct-contact">';
	$html .= '<p class="nvx-valoracion-direct-contact__label">' . esc_html__( '¿Prefieres coordinar directamente o tienes alguna duda?', 'nuvanx-medical' ) . '</p>';
	$html .= '<div class="nvx-valoracion-direct-contact__actions">';
	$html .= '<a href="' . esc_url( $wa_url ) . '" class="nvx-brand-btn nvx-btn--secondary" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WhatsApp directo', 'nuvanx-medical' ) . '</a>';
	$html .= '<a href="tel:+34689317399" class="nvx-brand-btn nvx-btn--secondary">' . esc_html__( 'Llamar: 689 31 73 99', 'nuvanx-medical' ) . '</a>';
	$html .= '</div></div>';
	$html .= '<div class="nvx-form nvx-hs-native-section" aria-label="' . esc_attr__( 'Formulario de valoración médica NUVANX', 'nuvanx-medical' ) . '">';
	$html .= '<div class="nvx-hs-native-box">';
	// This node is a presentation host only. The output-governance layer inserts
	// the single canonical .hs-form-frame child with the HubSpot identity. Repeating
	// data-form-id/data-portal-id here makes the portal embed initialize a second form.
	$html .= '<div id="nvx-hubspot-native-form" class="nvx-hubspot-native-form-v2" data-nvx-hubspot-native="1" data-nvx-hubspot-eager="1" data-nvx-consent="functional" data-page-origin="' . esc_attr__( 'Valoración médica estética en Madrid', 'nuvanx-medical' ) . '" data-page-url="' . esc_url( $valuation_url ) . '"></div>';
	$html .= '<p class="nvx-copy nvx-form-note">' . esc_html__( 'La información enviada se utiliza para gestionar tu solicitud. La indicación final depende de valoración médica y los resultados pueden variar según cada paciente.', 'nuvanx-medical' ) . '</p>';
	$html .= '<p class="nvx-copy nvx-form-note nvx-doctoralia-proof">' . esc_html__( 'Más de 100 opiniones verificadas en Doctoralia.', 'nuvanx-medical' ) . ' <a class="nvx-brand-inline-link" href="' . esc_url( $doctoralia_url ) . '" target="_blank" rel="noopener noreferrer external">' . esc_html__( 'Consultar opiniones verificadas', 'nuvanx-medical' ) . '</a></p>';
	$html .= '</div></div></div></section>';

	// Keep clinical explanation and locations after the conversion block.
	$html .= nvx_valoracion_managed_intro_markup();

	// Dedicated conversion route: trigger the normal runtime and recover if an
	// optimizer delayed or removed it. The bootstrap never creates a second frame
	// or a second portal loader when the canonical owner is already present.
	$html .= nvx_valoracion_hubspot_bootstrap_markup();
	$html .= '</div>';

	return $html;
}

/**
 * Replace any prior body or CMS marker with the canonical managed landing.
 *
 * @param string $content Original page content.
 */
function nvx_render_managed_valoracion_page( $content ): string {
	$content = is_string( $content ) ? $content : '';
	if ( is_admin() || ! function_exists( 'nvx_is_valoracion_page_request' ) || ! nvx_is_valoracion_page_request() ) {
		return $content;
	}

	return nvx_valoracion_managed_page_markup();
}
add_filter( 'the_content', 'nvx_render_managed_valoracion_page', NVX_HOOK_PRIO_VALORACION_MANAGED );

/**
 * Register valoración page as page owner to prevent shell hero duplication.
 *
 * When the shell evaluates $has_managed_editorial in nvx-page-shell.php,
 * this filter ensures valoración pages are recognized as managed,
 * preventing the shell from rendering its own hero in addition to
 * the renderer's hero.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) || is_admin() ) {
			return $owner;
		}
		if ( function_exists( 'nvx_is_valoracion_page_request' ) && nvx_is_valoracion_page_request() ) {
			return 'nvx_valoracion_managed';
		}
		return $owner;
	},
	10
);

/**
 * Page-level MedicalWebPage + ReserveAction for /madrid/valoracion/.
 *
 * Organization already exposes ReserveAction. This pass types the landing
 * itself so the H1 and initial answer are speakable and reviewed.
 *
 * @param mixed $graph Yoast schema graph.
 * @return mixed
 */
function nvx_valoracion_schema_graph( $graph ) {
	if ( ! is_array( $graph ) || is_admin() || is_feed() ) {
		return $graph;
	}
	if ( ! function_exists( 'nvx_is_valoracion_page_request' ) || ! nvx_is_valoracion_page_request() ) {
		return $graph;
	}

	$url         = home_url( '/madrid/valoracion/' );
	$colegiado   = defined( 'NVX_DIRECTOR_COLEGIADO' ) ? (string) NVX_DIRECTOR_COLEGIADO : '282864786';
	$description = __( 'Valoración médica en Madrid de 15 a 30 minutos: diagnóstico, indicación y presupuesto documentado antes de tratar. Sin Anestesia General. Recuperación en 48h como reincorporación habitual, según el protocolo indicado. Sin obligación de procedimiento.', 'nuvanx-medical' );
	$action      = array(
		'@type'  => 'ReserveAction',
		'name'   => __( 'Solicitar valoración médica', 'nuvanx-medical' ),
		'target' => array(
			'@type'          => 'EntryPoint',
			'urlTemplate'    => $url . '#nvx-hubspot-form',
			'inLanguage'     => 'es',
			'actionPlatform' => array(
				'https://schema.org/DesktopWebPlatform',
				'https://schema.org/MobileWebPlatform',
			),
		),
		'result' => array(
			'@type' => 'Reservation',
			'name'  => __( 'Cita de valoración médica', 'nuvanx-medical' ),
		),
	);
	$reviewer    = array(
		'@type'      => 'Physician',
		'name'       => 'Dr. José Javier Rivera Tejeda',
		'url'        => home_url( '/equipo-medico/#physician-rivera-tejeda' ),
		'identifier' => array(
			'@type' => 'PropertyValue',
			'name'  => defined( 'NVX_SD_LABEL_NUM_COLEGIADO' ) ? NVX_SD_LABEL_NUM_COLEGIADO : 'Número de colegiado ICOMEM',
			'value' => $colegiado,
		),
	);

	foreach ( $graph as $index => $node ) {
		if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
			continue;
		}
		$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
		if ( ! in_array( 'WebPage', $types, true ) && ! in_array( 'MedicalWebPage', $types, true ) ) {
			continue;
		}

		if ( function_exists( 'nvx_schema_add_type' ) ) {
			$graph[ $index ]['@type'] = nvx_schema_add_type( $node['@type'], 'MedicalWebPage' );
		} else {
			$types[]                  = 'MedicalWebPage';
			$graph[ $index ]['@type'] = array_values( array_unique( $types ) );
		}

		$graph[ $index ]['name']            = __( 'Valoración médica estética en Madrid', 'nuvanx-medical' );
		$graph[ $index ]['description']     = $description;
		$graph[ $index ]['url']             = $url;
		$graph[ $index ]['inLanguage']      = 'es-ES';
		$graph[ $index ]['lastReviewed']    = '2026-08-01';
		$graph[ $index ]['reviewedBy']      = $reviewer;
		$graph[ $index ]['speakable']       = array(
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => array( '#nvx-valoracion-h1', '#nvx-valoracion-lead' ),
		);
		$graph[ $index ]['potentialAction'] = $action;
		$graph[ $index ]['mainEntity']      = $action;
		break;
	}

	return $graph;
}
nvx_add_filter_with_priority( 'wpseo_schema_graph', 'nvx_valoracion_schema_graph' );