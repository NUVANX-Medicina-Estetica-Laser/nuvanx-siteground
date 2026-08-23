<?php
/**
 * Google Business Profile contract, clinic galleries and T+7 review requests.
 *
 * Live GBP category/photos cannot be mutated from the theme. This module owns
 * the website-side gallery, the canonical profile copy, and the post-visit
 * review email. No incentives, no star coaching.
 *
 * Historical image-hygiene regressions are guarded by blocking CI:
 * - vendor URL detection is filename-scoped so treatment directories such as
 *   /exion-face/ and /endolift-facial/ are not false positives;
 * - unresolved route intent fails safe and never deletes an approved asset;
 * - vendor figures containing picture/figcaption markup are removed whole.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NVX_GBP_VISIT_CPT   = 'nvx_gbp_visit';
const NVX_GBP_CRON_HOOK   = 'nvx_gbp_send_due_review_requests';
const NVX_GBP_DELAY_DAYS  = 7;

/** @return array<string,mixed> */
function nvx_gbp_profiles_catalog(): array {
	if ( ! function_exists( 'nvx_catalog_json_load' ) ) {
		return array();
	}
	$catalog = nvx_catalog_json_load( 'gbp-profiles.json' );
	return is_array( $catalog ) && empty( $catalog['_error'] ) ? $catalog : array();
}

/** @return array<string,mixed> */
function nvx_gbp_clinic_profile( string $clinic_key ): array {
	$catalog = nvx_gbp_profiles_catalog();
	$clinic  = $catalog['clinics'][ $clinic_key ] ?? null;
	return is_array( $clinic ) ? $clinic : array();
}

function nvx_gbp_primary_category(): string {
	$catalog = nvx_gbp_profiles_catalog();
	$category = trim( (string) ( $catalog['primary_category'] ?? '' ) );
	return '' !== $category ? $category : 'Clínica de medicina estética';
}

function nvx_gbp_review_url( string $clinic_key ): string {
	$profile  = nvx_gbp_clinic_profile( $clinic_key );
	$place_id = trim( (string) ( $profile['place_id'] ?? '' ) );
	if ( '' !== $place_id ) {
		return 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place_id );
	}
	$query = trim( (string) ( $profile['maps_query'] ?? '' ) );
	if ( '' === $query ) {
		return '';
	}
	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
}

/**
 * Approved editorial photographs for a clinic landing (max 4).
 *
 * Missing files are skipped rather than replaced with vendor packshots or
 * unverified theme JPEGs.
 *
 * @return array<int,array{id:int,alt:string,caption:string}>
 */
function nvx_clinic_editorial_photo_map( string $clinic_key ): array {
	$is_goya = 'goya' === $clinic_key;
	$facade  = $is_goya ? array( 2071, __( 'Fachada de NUVANX Salamanca–Goya, Madrid', 'nuvanx-medical' ) ) : array( 2796, __( 'Fachada de NUVANX Chamberí, Madrid', 'nuvanx-medical' ) );
	$waiting = $is_goya ? array( 1077, __( 'Sala clínica NUVANX — Salamanca–Goya, Madrid', 'nuvanx-medical' ), __( 'Sala clínica', 'nuvanx-medical' ) ) : array( 1632, __( 'Sala de espera de NUVANX Chamberí', 'nuvanx-medical' ), __( 'Sala', 'nuvanx-medical' ) );
	$box     = $is_goya ? array( 1078, __( 'Box clínico de NUVANX Salamanca–Goya', 'nuvanx-medical' ) ) : array( 1630, __( 'Sala clínica NUVANX — Chamberí, Madrid', 'nuvanx-medical' ) );

	return array(
		array( 'id' => $facade[0], 'alt' => $facade[1], 'caption' => __( 'Fachada', 'nuvanx-medical' ) ),
		array( 'id' => $waiting[0], 'alt' => $waiting[1], 'caption' => $waiting[2] ),
		array( 'id' => $box[0], 'alt' => $box[1], 'caption' => __( 'Box clínico', 'nuvanx-medical' ) ),
		array( 'id' => 2892, 'alt' => __( 'Consulta médica y valoración en NUVANX', 'nuvanx-medical' ), 'caption' => __( 'Valoración médica', 'nuvanx-medical' ) ),
	);
}

/**
 * Theme-owned gallery for a clinic landing. Only readable attachments.
 *
 * @return array<int,array{id:int,file:string,alt:string,caption:string}>
 */
function nvx_clinic_landing_photos( string $clinic_key ): array {
	$clinic_key = 'goya' === $clinic_key ? 'goya' : 'chamberi';
	$photos     = array();

	foreach ( nvx_clinic_editorial_photo_map( $clinic_key ) as $item ) {
		$attachment_id = (int) $item['id'];
		$source_path   = get_attached_file( $attachment_id );
		if ( ! is_string( $source_path ) || '' === $source_path || ! is_readable( $source_path ) ) {
			continue;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			continue;
		}

		$photos[] = array(
			'id'      => $attachment_id,
			'file'    => $url,
			'alt'     => (string) $item['alt'],
			'caption' => (string) $item['caption'],
		);

		if ( count( $photos ) >= 4 ) {
			break;
		}
	}

	return $photos;
}

/**
 * Vendor packshot stems that must not appear on sede landings.
 *
 * Tech copy and Chamberí YouTube links stay; only product-shot files go.
 *
 * @return string
 */
function nvx_clinic_vendor_packshot_regex(): string {
	return '/SmartLipo-for-Laserlipolysis-DEKA|Endolift-ISO9001-Laser|BTL-Exion-Mobile-Version|endolift-lasemar-1500-eufoton/i';
}

function nvx_clinic_html_contains_vendor_packshot( string $html ): bool {
	return (bool) preg_match( nvx_clinic_vendor_packshot_regex(), $html );
}

/**
 * Vendor product/logo stems in image URLs and lazy-load attributes.
 * Technological copy outside img/source attributes is not a block.
 */
function nvx_public_vendor_image_url_regex(): string {
	return '~(?:^|/)[^/?#,\s]*(?:deka|btl[_-]|btl-exilite|btl-exion|eufoton|endolift-lasemar|endolift-iso9001|lasemar-1500|smartlipo®?|exion|exilite)[^/?#,\s]*\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?#][^,\s]*)?(?=\s*(?:\d+(?:\.\d+)?[wx])?(?:,|$))~iu';
}

/** Vendor brand tokens in image alt text (logo, equipment or packshot). */
function nvx_public_vendor_image_alt_regex(): string {
	return '/\b(?:deka|btl|exion|eufoton|Endolift®|lasemar|Smartlipo®|exilite)\b/iu';
}

/**
 * True when a single image attribute is a vendor packshot/logo signal.
 */
function nvx_public_html_vendor_attr_is_blocked( string $attr, string $value ): bool {
	if ( 'alt' === $attr ) {
		return (bool) preg_match( nvx_public_vendor_image_alt_regex(), $value );
	}

	return (bool) preg_match( nvx_public_vendor_image_url_regex(), $value );
}

/**
 * True when an img/source attribute string carries a vendor image signal.
 */
function nvx_public_html_tag_attrs_are_vendor( string $attrs ): bool {
	if ( '' === $attrs ) {
		return false;
	}
	if ( ! preg_match_all( '/\b(src|srcset|data-src|data-srcset|data-lazy-src|data-lazy-srcset|data-original|alt)\s*=\s*(["\'])(.*?)\2/iu', $attrs, $hits, PREG_SET_ORDER ) ) {
		return false;
	}

	foreach ( $hits as $hit ) {
		if ( nvx_public_html_vendor_attr_is_blocked( strtolower( (string) $hit[1] ), (string) $hit[3] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * True when an img/source node (or wrapping markup) carries a vendor image signal.
 */
function nvx_public_html_is_vendor_image( string $html ): bool {
	if ( '' === $html || ! preg_match_all( '/<(?:img|source)\b([^>]*)>/iu', $html, $tags ) ) {
		return false;
	}

	foreach ( $tags[1] as $attrs ) {
		if ( is_string( $attrs ) && nvx_public_html_tag_attrs_are_vendor( $attrs ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Drop vendor logos, packshots and lazy clones from public HTML.
 * Original Media Library files are not deleted.
 */
function nvx_public_strip_vendor_images( string $content ): string {
	if ( is_admin() || '' === $content ) {
		return $content;
	}

	$omit = static function ( string $html ): bool {
		return nvx_public_html_is_vendor_image( $html ) || nvx_public_html_is_abdomen_asset_off_intent( $html );
	};

	$updated = preg_replace_callback(
		'/<figure\b[^>]*>[\s\S]*?<\/figure>/iu',
		static function ( array $match ) use ( $omit ): string {
			return $omit( $match[0] ) ? '' : $match[0];
		},
		$content
	);
	if ( ! is_string( $updated ) ) {
		$updated = $content;
	}

	$updated = preg_replace_callback(
		'/<picture\b[^>]*>[\s\S]*?<\/picture>/iu',
		static function ( array $match ) use ( $omit ): string {
			return $omit( $match[0] ) ? '' : $match[0];
		},
		$updated
	);
	if ( ! is_string( $updated ) ) {
		return $content;
	}

	$updated = preg_replace_callback(
		'/<(?:img|source)\b[^>]*>/iu',
		static function ( array $match ) use ( $omit ): string {
			return $omit( $match[0] ) ? '' : $match[0];
		},
		$updated
	);

	return is_string( $updated ) ? $updated : $content;
}
add_filter( 'the_content', 'nvx_public_strip_vendor_images', 198 );

/**
 * Featured images inherit the post title as alt in several templates.
 * If that title or the file name is a vendor signal, omit the image.
 */
function nvx_public_html_is_abdomen_asset_off_intent( string $html ): bool {
	if ( ! preg_match( '/laser-medico-nuvanx-madrid/i', $html ) ) {
		return false;
	}

	$path = '';
	if ( function_exists( 'nvx_schema_current_path' ) ) {
		$path = (string) nvx_schema_current_path( (int) get_queried_object_id() );
	}
	if ( '' === $path ) {
		return false;
	}

	return 1 !== preg_match( '/endolaser|remodelacion-corporal|grasa-localizada|laserlipolisis|lipolisis/i', $path );
}

function nvx_public_filter_vendor_post_thumbnail( string $html ): string {
	if ( is_admin() || '' === $html ) {
		return $html;
	}

	if ( nvx_public_html_is_vendor_image( $html ) || nvx_public_html_is_abdomen_asset_off_intent( $html ) ) {
		return '';
	}

	return $html;
}
add_filter( 'post_thumbnail_html', 'nvx_public_filter_vendor_post_thumbnail', 20 );

/**
 * Drop vendor packshot figures from Chamberí/Goya rendered content.
 */
function nvx_clinic_strip_vendor_packshots( string $content ): string {
	if ( is_admin() || '' === $content ) {
		return $content;
	}
	if ( ! function_exists( 'nvxIsSedeTemplate' ) || ! nvxIsSedeTemplate() ) {
		return $content;
	}

	// Sede photo set is the theme gallery (max 4). CMS figures are vendor
	// packshots or duplicate clinic shots and must not add to the count.
	$updated = preg_replace( '/<figure\b[^>]*>[\s\S]*?<\/figure>/iu', '', $content );
	if ( ! is_string( $updated ) ) {
		$updated = $content;
	}

	$updated = preg_replace_callback(
		'/<img\b[^>]*>/iu',
		static function ( array $match ): string {
			return nvx_clinic_html_contains_vendor_packshot( $match[0] ) ? '' : $match[0];
		},
		$updated
	);
	if ( ! is_string( $updated ) ) {
		return $content;
	}

	$updated = preg_replace( '/<div class="nvx-brand-grid[^"]*">\s*<\/div>/iu', '', $updated );

	return is_string( $updated ) ? $updated : $content;
}
add_filter( 'the_content', 'nvx_clinic_strip_vendor_packshots', 199 );

/**
 * Goya-only clinical portraits. Never used on Equipo Médico or Chamberí.
 *
 * @return array<int,array{id:int,name:string,role:string,alt:string}>
 */
function nvx_goya_clinical_team_map(): array {
	return array(
		array(
			'id'   => 3101,
			'name' => __( 'Gosia', 'nuvanx-medical' ),
			'role' => __( 'Equipo clínico · Salamanca–Goya', 'nuvanx-medical' ),
			'alt'  => __( 'Gosia — equipo clínico NUVANX en Salamanca–Goya', 'nuvanx-medical' ),
		),
		array(
			'id'   => 3100,
			'name' => __( 'Eva', 'nuvanx-medical' ),
			'role' => __( 'Equipo clínico · Salamanca–Goya', 'nuvanx-medical' ),
			'alt'  => __( 'Eva — equipo clínico NUVANX en Salamanca–Goya', 'nuvanx-medical' ),
		),
	);
}

/** Readable Goya team cards, or empty when both attachments are missing. */
function nvx_goya_clinical_team_markup(): string {
	$cards = '';

	foreach ( nvx_goya_clinical_team_map() as $member ) {
		$attachment_id = (int) $member['id'];
		$source_path   = get_attached_file( $attachment_id );
		if ( ! is_string( $source_path ) || '' === $source_path || ! is_readable( $source_path ) ) {
			continue;
		}

		$image = wp_get_attachment_image(
			$attachment_id,
			'full',
			false,
			array(
				'class'    => 'nvx-media nvx-media--doctor',
				'alt'      => (string) $member['alt'],
				'loading'  => 'lazy',
				'decoding' => 'async',
				'sizes'    => '(min-width: 981px) 28vw, (min-width: 641px) 45vw, 100vw',
			)
		);
		if ( ! is_string( $image ) || '' === $image ) {
			continue;
		}

		$cards .= '<article class="nvx-brand-card nvx-brand-card--team">';
		$cards .= '<figure class="nvx-brand-card__media nvx-brand-card__media--portrait">' . $image . '</figure>';
		$cards .= '<h3 class="nvx-brand-card__title">' . esc_html( (string) $member['name'] ) . '</h3>';
		$cards .= '<p class="nvx-brand-card__body">' . esc_html( (string) $member['role'] ) . '</p>';
		$cards .= '</article>';
	}

	if ( '' === $cards ) {
		return '';
	}

	$html  = '<section class="nvx-brand-section nvx-clinic-team" aria-labelledby="nvx-goya-team-title">';
	$html .= '<div class="nvx-brand-section__inner">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html__( 'Equipo en Goya', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 id="nvx-goya-team-title" class="nvx-brand-title">' . esc_html__( 'Quién te recibe en Salamanca–Goya', 'nuvanx-medical' ) . '</h2>';
	$html .= '<div class="nvx-equipo-staff-grid">' . $cards . '</div>';
	$html .= '</div></section>';

	return $html;
}

/** Backward-compatible Chamberí helper used by schema. */
function nvx_chamberi_landing_photos(): array {
	return nvx_clinic_landing_photos( 'chamberi' );
}

/**
 * Absolute URLs of existing clinic photos for Schema.org image.
 *
 * @return string[]
 */
function nvx_clinic_schema_image_urls( string $clinic_key ): array {
	$urls = array();
	foreach ( nvx_clinic_landing_photos( $clinic_key ) as $photo ) {
		$url = isset( $photo['id'] ) ? wp_get_attachment_url( (int) $photo['id'] ) : '';
		if ( is_string( $url ) && '' !== $url ) {
			$urls[] = $url;
		}
	}
	return $urls;
}

function nvx_chamberi_schema_image_url(): string {
	$urls = nvx_clinic_schema_image_urls( 'chamberi' );
	return $urls[0] ?? '';
}

function nvx_gbp_review_email_subject( string $clinic_key ): string {
	$profile = nvx_gbp_clinic_profile( $clinic_key );
	$name    = (string) ( $profile['name'] ?? 'NUVANX' );
	return sprintf(
		/* translators: %s: clinic name */
		__( 'Tu visita a %s', 'nuvanx-medical' ),
		$name
	);
}

function nvx_gbp_review_email_body( string $name, string $clinic_key ): string {
	$profile = nvx_gbp_clinic_profile( $clinic_key );
	$clinic  = (string) ( $profile['name'] ?? 'NUVANX' );
	$url     = nvx_gbp_review_url( $clinic_key );
	$first   = trim( $name );
	$hello   = '' !== $first
		? sprintf( /* translators: %s: first name */ __( 'Hola %s,', 'nuvanx-medical' ), $first )
		: __( 'Hola,', 'nuvanx-medical' );

	$lines   = array();
	$lines[] = $hello;
	$lines[] = '';
	$lines[] = sprintf(
		/* translators: %s: clinic name */
		__( 'Han pasado unos días desde tu visita a %s. Si quieres dejar tu opinión en Google, este es el enlace directo al perfil de la sede:', 'nuvanx-medical' ),
		$clinic
	);
	$lines[] = $url;
	$lines[] = '';
	$lines[] = __( 'No es obligatorio. No hay contraprestación ni condición asociada a esta solicitud.', 'nuvanx-medical' );
	$lines[] = '';
	$lines[] = 'NUVANX';

	return implode( "\n", $lines );
}

function nvx_gbp_register_visit_cpt(): void {
	register_post_type(
		NVX_GBP_VISIT_CPT,
		array(
			'labels'              => array(
				'name'          => __( 'Solicitudes GBP', 'nuvanx-medical' ),
				'singular_name' => __( 'Solicitud GBP', 'nuvanx-medical' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'supports'            => array( 'title' ),
			'menu_icon'           => 'dashicons-star-filled',
		)
	);
}
add_action( 'init', 'nvx_gbp_register_visit_cpt' );

function nvx_gbp_schedule_cron(): void {
	if ( ! wp_next_scheduled( NVX_GBP_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NVX_GBP_CRON_HOOK );
	}
}
add_action( 'init', 'nvx_gbp_schedule_cron' );

function nvx_gbp_unschedule_cron(): void {
	$timestamp = wp_next_scheduled( NVX_GBP_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( NVX_GBP_CRON_HOOK, $timestamp );
	}
}
add_action( 'switch_theme', 'nvx_gbp_unschedule_cron' );

function nvx_gbp_visit_send_on( string $visit_date ): string {
	$time = strtotime( $visit_date . ' 09:00:00' );
	if ( false === $time ) {
		return '';
	}
	return gmdate( 'Y-m-d', $time + ( NVX_GBP_DELAY_DAYS * DAY_IN_SECONDS ) );
}

/**
 * @return int|\WP_Error
 */
function nvx_gbp_register_visit( string $name, string $email, string $clinic_key, string $visit_date ) {
	$email      = sanitize_email( $email );
	$clinic_key = 'goya' === $clinic_key ? 'goya' : 'chamberi';
	$name       = sanitize_text_field( $name );
	if ( ! is_email( $email ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $visit_date ) ) {
		return new WP_Error( 'nvx_gbp_invalid_visit', __( 'Email o fecha de visita no válidos.', 'nuvanx-medical' ) );
	}

	$send_on = nvx_gbp_visit_send_on( $visit_date );
	if ( '' === $send_on ) {
		return new WP_Error( 'nvx_gbp_invalid_date', __( 'No se pudo calcular la fecha de envío.', 'nuvanx-medical' ) );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => NVX_GBP_VISIT_CPT,
			'post_status' => 'private',
			'post_title'  => $name . ' · ' . $clinic_key . ' · ' . $visit_date,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	update_post_meta( $post_id, '_nvx_gbp_email', $email );
	update_post_meta( $post_id, '_nvx_gbp_clinic', $clinic_key );
	update_post_meta( $post_id, '_nvx_gbp_visit_date', $visit_date );
	update_post_meta( $post_id, '_nvx_gbp_send_on', $send_on );
	update_post_meta( $post_id, '_nvx_gbp_status', 'scheduled' );

	return (int) $post_id;
}

function nvx_gbp_send_review_email( int $post_id ): bool {
	$status = (string) get_post_meta( $post_id, '_nvx_gbp_status', true );
	if ( 'sent' === $status ) {
		return true;
	}

	$email  = sanitize_email( (string) get_post_meta( $post_id, '_nvx_gbp_email', true ) );
	$clinic = (string) get_post_meta( $post_id, '_nvx_gbp_clinic', true );
	$title  = (string) get_the_title( $post_id );
	$name   = trim( (string) explode( '·', $title )[0] );

	if ( ! is_email( $email ) || '' === nvx_gbp_review_url( $clinic ) ) {
		return false;
	}

	$sent = wp_mail(
		$email,
		nvx_gbp_review_email_subject( $clinic ),
		nvx_gbp_review_email_body( $name, $clinic )
	);
	if ( ! $sent ) {
		return false;
	}

	update_post_meta( $post_id, '_nvx_gbp_status', 'sent' );
	update_post_meta( $post_id, '_nvx_gbp_sent_at', gmdate( 'c' ) );
	return true;
}

function nvx_gbp_send_due_review_requests(): void {
	$today = current_time( 'Y-m-d' );
	$query = new WP_Query(
		array(
			'post_type'      => NVX_GBP_VISIT_CPT,
			'post_status'    => 'private',
			'posts_per_page' => 50,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_nvx_gbp_status',
					'value'   => 'scheduled',
					'compare' => '=',
				),
				array(
					'key'     => '_nvx_gbp_send_on',
					'value'   => $today,
					'compare' => '<=',
					'type'    => 'DATE',
				),
			),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $query->posts as $post_id ) {
		nvx_gbp_send_review_email( (int) $post_id );
	}
}
add_action( NVX_GBP_CRON_HOOK, 'nvx_gbp_send_due_review_requests' );

function nvx_gbp_handle_admin_register(): void {
	if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	if ( empty( $_POST['nvx_gbp_register_visit'] ) ) {
		return;
	}
	check_admin_referer( 'nvx_gbp_register_visit' );

	$result = nvx_gbp_register_visit(
		isset( $_POST['nvx_gbp_name'] ) ? (string) wp_unslash( $_POST['nvx_gbp_name'] ) : '',
		isset( $_POST['nvx_gbp_email'] ) ? (string) wp_unslash( $_POST['nvx_gbp_email'] ) : '',
		isset( $_POST['nvx_gbp_clinic'] ) ? (string) wp_unslash( $_POST['nvx_gbp_clinic'] ) : 'chamberi',
		isset( $_POST['nvx_gbp_visit_date'] ) ? (string) wp_unslash( $_POST['nvx_gbp_visit_date'] ) : ''
	);

	$redirect = admin_url( 'edit.php?post_type=' . NVX_GBP_VISIT_CPT );
	$redirect = add_query_arg( 'nvx_gbp', is_wp_error( $result ) ? 'error' : 'scheduled', $redirect );
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_init', 'nvx_gbp_handle_admin_register' );

function nvx_gbp_admin_register_notice(): void {
	if ( empty( $_GET['nvx_gbp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$status = sanitize_key( (string) wp_unslash( $_GET['nvx_gbp'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'scheduled' === $status ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Visita registrada. El email de reseña se enviará a los 7 días.', 'nuvanx-medical' ) . '</p></div>';
	}
	if ( 'error' === $status ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'No se pudo registrar la visita. Revisa email y fecha.', 'nuvanx-medical' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'nvx_gbp_admin_register_notice' );

function nvx_gbp_admin_register_form(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || NVX_GBP_VISIT_CPT !== $screen->post_type ) {
		return;
	}
	?>
	<div class="notice notice-info">
		<p><strong><?php esc_html_e( 'Solicitud de reseña GBP a T+7', 'nuvanx-medical' ); ?></strong></p>
		<p><?php esc_html_e( 'Sin incentivos ni petición de estrellas. Solo el enlace directo al perfil de la sede.', 'nuvanx-medical' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'nvx_gbp_register_visit' ); ?>
			<input type="hidden" name="nvx_gbp_register_visit" value="1" />
			<p>
				<label><?php esc_html_e( 'Nombre', 'nuvanx-medical' ); ?> <input type="text" name="nvx_gbp_name" required /></label>
				<label><?php esc_html_e( 'Email', 'nuvanx-medical' ); ?> <input type="email" name="nvx_gbp_email" required /></label>
				<label><?php esc_html_e( 'Sede', 'nuvanx-medical' ); ?>
					<select name="nvx_gbp_clinic">
						<option value="chamberi"><?php esc_html_e( 'Chamberí', 'nuvanx-medical' ); ?></option>
						<option value="goya"><?php esc_html_e( 'Salamanca–Goya', 'nuvanx-medical' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Fecha de visita', 'nuvanx-medical' ); ?> <input type="date" name="nvx_gbp_visit_date" required value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" /></label>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Programar email T+7', 'nuvanx-medical' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}
add_action( 'all_admin_notices', 'nvx_gbp_admin_register_form' );

// Clean up cron hook on theme deactivation (PHP-1)
add_action( 'switch_theme', function() {
  wp_clear_scheduled_hook( NVX_GBP_CRON_HOOK );
} );
