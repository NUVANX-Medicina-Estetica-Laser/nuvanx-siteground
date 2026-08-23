<?php
/**
 * Contacto (NAP) + Valoración (diagnóstico + form) content layer.
 *
 * Funnel split:
 * - /madrid/valoracion/ → clinical intro + triple validation + form primary
 * - /contacto/ → canonical page template with clinics and its dedicated form
 *
 * No videoconsulta CTA (not operational as marketed). Preliminary photo
 * orientation is only mentioned under GDPR disclaimer, not as a booking product.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this is the valoración landing (form funnel).
 */
function nvx_is_valoracion_page_request(): bool {
	if ( function_exists( 'nvx_theme_is_valoracion_landing' ) && nvx_theme_is_valoracion_landing() ) {
		return true;
	}

	if ( ! is_singular( 'page' ) ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	return is_string( $path ) && (
		false !== strpos( $path, '/valoracion/' )
		|| false !== strpos( $path, 'madrid/valoracion' )
	);
}

/**
 * Whether this is the contacto / NAP page.
 */
function nvx_is_contacto_page_request(): bool {
	if ( ! is_singular( 'page' ) || is_front_page() ) {
		return false;
	}

	$tpl = (string) get_page_template_slug();
	// Canonical: page-contacto.php. Legacy template-contact.php still detected until DB meta is rewritten.
	if ( in_array( $tpl, array( 'templates/page-contacto.php', 'templates/template-contact.php' ), true ) ) {
		return true;
	}

	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	if ( 'contacto' === $slug || 'contact' === $slug ) {
		return true;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	return is_string( $path ) && (
		nvx_schema_path_matches( $path, '/contacto/' )
		|| false !== strpos( $path, '/contacto/' )
	);
}

/**
 * Clinic NAP rows (shared with schema clinics when available).
 *
 * @return array<int, array{name:string,reg:string,address:string,phone:string,phone_href:string,days:string}>
 */
function nvx_contact_clinics_nap(): array {
	if ( ! function_exists( 'nvx_get_clinics_config' ) ) {
		return array();
	}

	$clinics = nvx_get_clinics_config();
	$nap     = array();

	foreach ( array( 'chamberi', 'goya' ) as $key ) {
		if ( isset( $clinics[ $key ] ) ) {
			$c     = $clinics[ $key ];
			$nap[] = array(
				'name'       => $c['name'],
				'reg'        => $c['reg'],
				'address'    => sprintf( '%s, %s, %s', $c['address'], $c['postal_code'], $c['locality'] ),
				'phone'      => $c['phone'],
				'phone_href' => $c['phone_href'],
				'days'       => $c['days'],
			);
		}
	}

	return $nap;
}

/**
 * Visit steps for valoración (patient-facing language).
 *
 * @return array<int, array{title:string,body:string}>
 */
function nvx_valoracion_process_steps(): array {
	return array(
		array(
			'title' => __( 'Motivo y expectativas', 'nuvanx-medical' ),
			'body'  => __( 'Historial, cirugías previas y lo que quieres mejorar — con realismo, sin presión comercial.', 'nuvanx-medical' ),
		),
		array(
			'title' => __( 'Exploración y seguridad', 'nuvanx-medical' ),
			'body'  => __( 'Calidad de piel, flacidez, grasa localizada y criterios de seguridad para indicar o descartar un protocolo.', 'nuvanx-medical' ),
		),
		array(
			'title' => __( 'Plan A/B y presupuesto', 'nuvanx-medical' ),
			'body'  => __( 'Si hay indicación: plan, tiempos de recuperación y presupuesto orientativo. Puedes decidir con calma.', 'nuvanx-medical' ),
		),
	);
}

/**
 * GDPR / photo disclaimer (no definitive remote diagnosis).
 */
function nvx_contact_privacy_disclaimer_markup(): string {
	return '<p class="nvx-contact-disclaimer"><em>' . esc_html__(
		'Privacidad: si adjunta material fotográfico para una orientación preliminar, se trata bajo protocolos de confidencialidad clínica (GDPR). Ningún diagnóstico definitivo se emite solo a partir de una evaluación fotográfica; la indicación se confirma en valoración presencial.',
		'nuvanx-medical'
	) . '</em></p>';
}

/**
 * Clinics NAP cards markup.
 */
function nvx_contact_clinics_markup(): string {
	$html = '<div class="nvx-contact-clinics">';
	foreach ( nvx_contact_clinics_nap() as $clinic ) {
		$html .= '<article class="nvx-contact-clinic">';
		$html .= '<h3 class="nvx-contact-clinic__name">' . esc_html( $clinic['name'] ) . '</h3>';
		$html .= '<p class="nvx-contact-clinic__reg nvx-reg-copy">' . esc_html__( 'Registro sanitario', 'nuvanx-medical' ) . ': ' . esc_html( $clinic['reg'] ) . '</p>';
		$html .= '<p class="nvx-contact-clinic__addr"><svg class="nvx-icon" aria-hidden="true"><use href="#icon-location"></use></svg> ' . esc_html( $clinic['address'] ) . '</p>';
		$html .= '<p class="nvx-contact-clinic__phone"><svg class="nvx-icon" aria-hidden="true"><use href="#icon-phone"></use></svg> <strong>' . esc_html__( 'Teléfono / WhatsApp', 'nuvanx-medical' ) . ':</strong> ';
		$html .= '<a class="nvx-brand-inline-link" href="' . esc_url( 'tel:' . $clinic['phone_href'] ) . '">' . esc_html( $clinic['phone'] ) . '</a></p>';
		$html .= '<p class="nvx-contact-clinic__days"><strong>' . esc_html__( 'Consulta médica directa', 'nuvanx-medical' ) . ':</strong> ' . esc_html( $clinic['days'] ) . '</p>';
		$html .= '</article>';
	}
	$html .= '</div>';

	return $html;
}

/**
 * Valoración clinical intro (form stays separate / primary via form-first filter).
 */
function nvx_valoracion_intro_markup(): string {
	$html  = '<section class="nvx-brand-section nvx-valoracion-intro" id="nvx-valoracion-intro" aria-labelledby="nvx-valoracion-intro-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Primer paso', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-intro-title" class="nvx-heading">' . esc_html__( 'Una consulta médica para orientar tu caso', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Antes de proponer un láser o un protocolo, hay que confirmar si existe indicación. La consulta médica estética se realiza de forma presencial en Chamberí o Salamanca–Goya.', 'nuvanx-medical' ) . '</p>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html__( 'Saldrás con un criterio claro. El equipo, bajo la dirección del Dr. Rivera Tejeda, sigue tres pasos:', 'nuvanx-medical' ) . '</p>';
	$html .= '<ol class="nvx-treatment-process__steps nvx-valoracion-steps">';
	foreach ( nvx_valoracion_process_steps() as $step ) {
		$html .= '<li class="nvx-treatment-process__step">';
		$html .= '<h3 class="nvx-treatment-process__step-title">' . esc_html( $step['title'] ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $step['body'] ) . '</p>';
		$html .= '</li>';
	}
	$html .= '</ol>';
	$html .= nvx_contact_privacy_disclaimer_markup();
	$html .= '</div></section>';

	// Compact NAP under process (phones secondary; form is primary CTA).
	$html .= '<section class="nvx-brand-section nvx-valoracion-locations" aria-labelledby="nvx-valoracion-loc-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Sedes', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-valoracion-loc-title" class="nvx-heading">' . esc_html__( 'Ubicaciones autorizadas por Sanidad', 'nuvanx-medical' ) . '</h2>';
	$html .= nvx_contact_clinics_markup();
	$html .= '</div></section>';

	return $html;
}


/**
 * Inject valoración intro without removing the HubSpot form.
 */
function nvx_content_enhance_valoracion_page( string $content ): string {
	if ( is_admin() || ! nvx_is_valoracion_page_request() ) {
		return $content;
	}

	if ( false !== strpos( $content, 'nvx-valoracion-intro' ) || false !== strpos( $content, 'id="nvx-valoracion-intro"' ) ) {
		return $content;
	}

	$intro = nvx_valoracion_intro_markup();

	// After hero section if present.
	if ( preg_match( '/(<section\b[^>]*class=["\'][^"\']*nvx-(?:hero|page-hero|brand-hero)[^"\']*["\'][^>]*>[\s\S]*?<\/section>)/iu', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		$end = (int) $m[0][1] + strlen( $m[0][0] );
		return substr( $content, 0, $end ) . $intro . substr( $content, $end );
	}

	// Before form section.
	if ( preg_match( '/<section\b[^>]*(?:\bid=["\']nvx-hubspot-form["\']|nvx-hubspot-form-section|nvx-form-stage)[^>]*>/iu', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		$pos = (int) $m[0][1];
		return substr( $content, 0, $pos ) . $intro . substr( $content, $pos );
	}

	return $intro . $content;
}
add_filter( 'the_content', 'nvx_content_enhance_valoracion_page', NVX_HOOK_PRIO_VALORACION_ENHANCE );




/**
 * Social preview image for /contacto/.
 *
 * @param mixed $image Yoast image URL.
 * @return string
 */
function nvx_filter_contacto_social_image( $image ): string {
	if ( ! nvx_is_contacto_page_request() ) {
		return (string) $image;
	}

	return home_url( '/wp-content/uploads/2026/07/consulta-medica-personalizada-nuvanx-madrid.webp' );
}
add_filter( 'wpseo_opengraph_image', 'nvx_filter_contacto_social_image', 100 );
add_filter( 'wpseo_twitter_image', 'nvx_filter_contacto_social_image', 100 );

/**
 * Canonical contact social image metadata (Yoast image presenter + head safeguard).
 *
 * @return array{url:string,width:int,height:int,type:string,alt:string}
 */
if ( ! function_exists( 'nvx_contacto_opengraph_image_meta' ) ) {
	function nvx_contacto_opengraph_image_meta(): array {
		return array(
			'url'    => home_url( '/wp-content/uploads/2026/07/consulta-medica-personalizada-nuvanx-madrid.webp' ),
			'width'  => 1672,
			'height' => 941,
			'type'   => 'image/webp',
			'alt'    => 'Consulta médica personalizada NUVANX Madrid',
		);
	}
}

/**
 * Register contact Open Graph image through Yoast when the presenter is available.
 *
 * @param mixed $image_container Yoast Open Graph image container.
 */
if ( ! function_exists( 'nvx_contacto_add_yoast_opengraph_image' ) ) {
	function nvx_contacto_add_yoast_opengraph_image( $image_container ): void {
		if ( ! nvx_is_contacto_page_request() || ! is_object( $image_container ) ) {
			return;
		}

		$meta      = nvx_contacto_opengraph_image_meta();
		$image_url = $meta['url'];
		$image_id  = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $image_url ) : 0;

		if ( $image_id > 0 && method_exists( $image_container, 'add_image_by_id' ) ) {
			$image_container->add_image_by_id( $image_id );
			return;
		}

		if ( method_exists( $image_container, 'add_image_by_url' ) ) {
			$image_container->add_image_by_url( $image_url );
			return;
		}

		if ( method_exists( $image_container, 'add_image' ) ) {
			$image_container->add_image(
				array(
					'url'    => $image_url,
					'width'  => (int) $meta['width'],
					'height' => (int) $meta['height'],
					'type'   => $meta['type'],
					'alt'    => $meta['alt'],
					'path'   => $image_url,
				)
			);
		}
	}
	add_filter( 'wpseo_add_opengraph_images', 'nvx_contacto_add_yoast_opengraph_image', 100 );
}

/**
 * Final head-output safeguard for contact og:image when Yoast omits tags.
 */
if ( ! function_exists( 'nvx_contacto_enforce_final_og_image' ) ) {
	function nvx_contacto_enforce_final_og_image( string $html ): string {
		if (
			! nvx_is_contacto_page_request()
			|| preg_match( '/<meta\b[^>]*\bproperty\s*=\s*(["\'])og:image\1/i', $html )
		) {
			return $html;
		}

		$meta      = nvx_contacto_opengraph_image_meta();
		$image_url = esc_url( $meta['url'] );
		$tags      = '<meta property="og:image" content="' . $image_url . '" />'
			. '<meta property="og:image:secure_url" content="' . $image_url . '" />'
			. '<meta property="og:image:width" content="' . (int) $meta['width'] . '" />'
			. '<meta property="og:image:height" content="' . (int) $meta['height'] . '" />'
			. '<meta property="og:image:type" content="' . esc_attr( $meta['type'] ) . '" />'
			. '<meta property="og:image:alt" content="' . esc_attr( $meta['alt'] ) . '" />';

		$with_tags = preg_replace( '/(?=<meta\b[^>]*\bname\s*=\s*(["\'])twitter:card\1)/i', $tags, $html, 1 );

		return is_string( $with_tags ) && $with_tags !== $html ? $with_tags : $html . $tags;
	}
}

/**


/**
 * Normalize organization finder payload to a usable index/id pair.
 *
 * @param mixed $organization Finder result.
 * @return array{id:string,index:int|null}
 */
function nvx_contacto_normalize_organization( $organization ): array {
	if ( ! is_array( $organization ) ) {
		$organization = array();
	}

	$org_id    = ( isset( $organization['id'] ) && is_string( $organization['id'] ) && '' !== $organization['id'] )
		? $organization['id']
		: ( function_exists( 'nvx_schema_organization_id' ) ? nvx_schema_organization_id() : home_url( '/#organization' ) );
	$org_index = array_key_exists( 'index', $organization ) ? $organization['index'] : null;
	if ( null !== $org_index && ! is_int( $org_index ) && ! ( is_string( $org_index ) && ctype_digit( (string) $org_index ) ) ) {
		$org_index = null;
	}
	if ( is_string( $org_index ) ) {
		$org_index = (int) $org_index;
	}

	return array(
		'id'    => $org_id,
		'index' => $org_index,
	);
}

/**
 * Ensure the graph has an Organization node; append a minimal one when missing.
 *
 * @param array<int,array<string,mixed>> $graph Yoast graph.
 * @return array{0:array<int,array<string,mixed>>,1:int|null,2:string}
 */
function nvx_contacto_ensure_organization_node( array $graph, string $org_id, $org_index ): array {
	if ( null !== $org_index ) {
		return array( $graph, $org_index, $org_id );
	}

	$graph[] = array(
		'@type' => array( 'Organization', 'MedicalOrganization' ),
		'@id'   => $org_id,
		'name'  => 'NUVANX Medicina Estética Láser',
		'url'   => home_url( '/' ),
	);

	return array( $graph, array_key_last( $graph ), $org_id );
}

/**
 * Collect existing @id values from graph pieces.
 *
 * @param array<int,mixed> $graph Yoast graph.
 * @return array<int,string>
 */
function nvx_contacto_graph_ids( array $graph ): array {
	$existing_ids = array();
	foreach ( $graph as $piece ) {
		if ( is_array( $piece ) && ! empty( $piece['@id'] ) ) {
			$existing_ids[] = (string) $piece['@id'];
		}
	}
	return $existing_ids;
}

/**
 * Append missing clinic nodes and return subOrganization refs.
 *
 * @param array<int,array<string,mixed>>    $graph        Yoast graph.
 * @param array<string,array<string,mixed>> $clinics      Clinic map.
 * @param array<int,string>                 $existing_ids Existing @id values.
 * @return array{0:array<int,array<string,mixed>>,1:array<int,array{@id:string}>}
 */
function nvx_contacto_append_clinic_nodes( array $graph, array $clinics, array $existing_ids, string $org_id ): array {
	$clinic_refs = array();
	foreach ( array( 'chamberi', 'goya' ) as $key ) {
		if ( empty( $clinics[ $key ]['@id'] ) ) {
			continue;
		}

		$clinic_refs[] = array( '@id' => $clinics[ $key ]['@id'] );
		if ( in_array( $clinics[ $key ]['@id'], $existing_ids, true ) ) {
			continue;
		}

		$clinic                       = $clinics[ $key ];
		$clinic['parentOrganization'] = array( '@id' => $org_id );
		$graph[]                      = $clinic;
	}

	return array( $graph, $clinic_refs );
}

/**
 * Merge clinic refs into the organization subOrganization property.
 *
 * @param array<int,array<string,mixed>> $graph       Yoast graph.
 * @param array<int,array{@id:string}>   $clinic_refs Clinic references.
 * @return array<int,array<string,mixed>>
 */
function nvx_contacto_merge_org_clinic_refs( array $graph, $org_index, array $clinic_refs ): array {
	if ( null === $org_index || ! isset( $graph[ $org_index ] ) || ! is_array( $graph[ $org_index ] ) ) {
		return $graph;
	}

	if ( function_exists( 'nvx_schema_add_type' ) ) {
		$graph[ $org_index ]['@type'] = nvx_schema_add_type( $graph[ $org_index ]['@type'] ?? 'Organization', 'MedicalOrganization' );
	}

	$existing_refs = isset( $graph[ $org_index ]['subOrganization'] )
		? (array) $graph[ $org_index ]['subOrganization']
		: array();
	$merged_refs   = array();
	foreach ( array_merge( $existing_refs, $clinic_refs ) as $reference ) {
		if ( is_array( $reference ) && ! empty( $reference['@id'] ) ) {
			$merged_refs[ (string) $reference['@id'] ] = array( '@id' => (string) $reference['@id'] );
		}
	}
	$graph[ $org_index ]['subOrganization'] = array_values( $merged_refs );

	return $graph;
}

/**
 * Add both canonical MedicalClinic branches to the /contacto/ Yoast graph.
 *
 * @param array $graph   Yoast schema graph.
 * @param mixed $context Unused context.
 * @return array
 */
function nvx_filter_contacto_schema_graph( $graph, $context ) {
	unset( $context );

	if (
		! nvx_is_contacto_page_request()
		|| ! is_array( $graph )
		|| ! function_exists( 'nvx_schema_clinics' )
		|| ! function_exists( 'nvx_schema_find_organization' )
	) {
		return $graph;
	}

	$clinics                            = nvx_schema_clinics();
	$organization                       = nvx_contacto_normalize_organization( nvx_schema_find_organization( $graph ) );
	list( $graph, $org_index, $org_id ) = nvx_contacto_ensure_organization_node(
		$graph,
		$organization['id'],
		$organization['index']
	);

	list( $graph, $clinic_refs ) = nvx_contacto_append_clinic_nodes(
		$graph,
		$clinics,
		nvx_contacto_graph_ids( $graph ),
		$org_id
	);

	return nvx_contacto_merge_org_clinic_refs( $graph, $org_index, $clinic_refs );
}
nvx_add_filter_with_priority( 'wpseo_schema_graph', 'nvx_filter_contacto_schema_graph', 2 );

/**
 * Map retired template path to the single contact template file.
 *
 * @param string $template Resolved template path.
 * @return string
 */
function nvx_contacto_resolve_legacy_template( string $template ): string {
	if ( false === strpos( $template, 'template-contact.php' ) ) {
		return $template;
	}

	$canonical = get_template_directory() . '/templates/page-contacto.php';
	return is_readable( $canonical ) ? $canonical : $template;
}
add_filter( 'page_template', 'nvx_contacto_resolve_legacy_template', 5 );

/**
 * Set custom SEO title for contacto page.


/**
 * Rewrite legacy _wp_page_template meta to the canonical contact template slug.
 */
function nvx_contacto_migrate_legacy_template_meta(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( ! is_singular( 'page' ) ) {
		return;
	}

	$page_id = (int) get_queried_object_id();
	if ( $page_id <= 0 ) {
		return;
	}

	$slug = (string) get_page_template_slug( $page_id );
	if ( 'templates/template-contact.php' !== $slug ) {
		return;
	}

	update_post_meta( $page_id, '_wp_page_template', 'templates/page-contacto.php' );
}
add_action( 'template_redirect', 'nvx_contacto_migrate_legacy_template_meta', 0 );
