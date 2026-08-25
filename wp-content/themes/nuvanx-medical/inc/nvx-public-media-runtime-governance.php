<?php
/**
 * Runtime filesystem guard for governed public editorial media.
 *
 * WordPress attachment metadata can retain derivative URLs after the physical
 * derivative has disappeared from uploads. Public markup must never advertise
 * one of those stale files as `src` or `srcset`.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Whether a public uploads URL resolves to a readable file in this runtime. */
function nvx_public_media_upload_url_is_readable( string $url ): bool {
	$uploads = wp_get_upload_dir();
	$baseurl = isset( $uploads['baseurl'] ) ? rtrim( (string) $uploads['baseurl'], '/' ) : '';
	$basedir = isset( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/\\' ) : '';

	if ( '' === $url || '' === $baseurl || '' === $basedir ) {
		return false;
	}

	$url_path    = (string) wp_parse_url( $url, PHP_URL_PATH );
	$base_path   = rtrim( (string) wp_parse_url( $baseurl, PHP_URL_PATH ), '/' );
	$url_host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$base_host   = strtolower( (string) wp_parse_url( $baseurl, PHP_URL_HOST ) );
	$url_scheme  = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	$base_scheme = strtolower( (string) wp_parse_url( $baseurl, PHP_URL_SCHEME ) );
	if ( '' === $url_path || '' === $base_path || '' === $url_host || '' === $base_host || '' === $url_scheme || '' === $base_scheme || $url_host !== $base_host || $url_scheme !== $base_scheme || 0 !== strpos( $url_path, $base_path . '/' ) ) {
		// This guard owns local WordPress uploads only. Leave external/CDN URLs to
		// their own delivery boundary rather than deleting them speculatively.
		return true;
	}

	$relative = ltrim( rawurldecode( substr( $url_path, strlen( $base_path ) ) ), '/' );
	if ( '' === $relative || false !== strpos( $relative, '../' ) ) {
		return false;
	}

	return is_readable( $basedir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
}

/** Build one local derivative URL from attachment metadata. */
function nvx_public_media_metadata_variant( int $attachment_id, array $meta, array $variant ): ?array {
	$file = isset( $variant['file'] ) ? basename( (string) $variant['file'] ) : '';
	if ( '' === $file ) {
		return null;
	}

	$original_path = get_attached_file( $attachment_id );
	$original_url  = wp_get_attachment_url( $attachment_id );
	if ( ! is_string( $original_path ) || ! is_readable( $original_path ) || ! is_string( $original_url ) || '' === $original_url ) {
		return null;
	}

	$path = dirname( $original_path ) . DIRECTORY_SEPARATOR . $file;
	if ( ! is_readable( $path ) ) {
		return null;
	}

	$url = trailingslashit( dirname( $original_url ) ) . rawurlencode( $file );
	return array(
		$url,
		(int) ( $variant['width'] ?? 0 ),
		(int) ( $variant['height'] ?? 0 ),
		true,
	);
}

/**
 * Replace a stale governed `src` with the best physically available variant.
 *
 * Existing image_downsize owners run first. If their chosen URL is readable we
 * leave it untouched. Otherwise choose the widest readable governed derivative
 * within the public cap, falling back to the verified original only as a last
 * resort. This avoids both HTTP 404 and silent selection of another stale size.
 *
 * @param mixed        $downsize Existing short-circuit value.
 * @param int          $attachment_id Attachment ID.
 * @param string|int[] $size Requested size.
 * @return mixed
 */
function nvx_public_media_runtime_downsize( $downsize, int $attachment_id, $size ) {
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! function_exists( 'nvx_governed_public_image_ids' ) || ! in_array( $attachment_id, nvx_governed_public_image_ids(), true ) ) {
		return $downsize;
	}

	if ( is_array( $downsize ) && isset( $downsize[0] ) && nvx_public_media_upload_url_is_readable( (string) $downsize[0] ) ) {
		return $downsize;
	}

	$resolved_id = 2892 === $attachment_id ? 2877 : $attachment_id;
	$meta        = wp_get_attachment_metadata( $resolved_id );
	$meta        = is_array( $meta ) ? $meta : array();

	// Let core keep an explicitly requested derivative when the file really is
	// present. We intervene only when metadata points at an absent file.
	if ( is_string( $size ) && isset( $meta['sizes'][ $size ] ) && is_array( $meta['sizes'][ $size ] ) ) {
		$exact = nvx_public_media_metadata_variant( $resolved_id, $meta, $meta['sizes'][ $size ] );
		if ( is_array( $exact ) ) {
			return false === $downsize ? $downsize : $exact;
		}
	}

	$cap        = function_exists( 'nvx_governed_public_srcset_cap' ) ? nvx_governed_public_srcset_cap( $attachment_id ) : PHP_INT_MAX;
	$candidates = array();
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $variant ) {
		if ( ! is_array( $variant ) ) {
			continue;
		}
		$width = (int) ( $variant['width'] ?? 0 );
		if ( $width <= 0 || $width > $cap ) {
			continue;
		}
		$existing = nvx_public_media_metadata_variant( $resolved_id, $meta, $variant );
		if ( is_array( $existing ) ) {
			$candidates[ $width ] = $existing;
		}
	}

	if ( array() !== $candidates ) {
		krsort( $candidates, SORT_NUMERIC );
		return reset( $candidates );
	}

	$original_path = get_attached_file( $resolved_id );
	$original_url  = wp_get_attachment_url( $resolved_id );
	if ( is_string( $original_path ) && is_readable( $original_path ) && is_string( $original_url ) && '' !== $original_url ) {
		return array(
			$original_url,
			(int) ( $meta['width'] ?? 0 ),
			(int) ( $meta['height'] ?? 0 ),
			false,
		);
	}

	return $downsize;
}
add_filter( 'image_downsize', 'nvx_public_media_runtime_downsize', 20, 3 );

/** Remove every governed srcset candidate whose uploads file is absent. */
function nvx_public_media_runtime_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
	unset( $size_array, $image_src, $image_meta );
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! function_exists( 'nvx_governed_public_image_ids' ) || ! in_array( $attachment_id, nvx_governed_public_image_ids(), true ) ) {
		return $sources;
	}

	foreach ( array_keys( $sources ) as $width ) {
		$url = isset( $sources[ $width ]['url'] ) ? (string) $sources[ $width ]['url'] : '';
		if ( '' === $url || ! nvx_public_media_upload_url_is_readable( $url ) ) {
			unset( $sources[ $width ] );
		}
	}

	// Empty is deliberate: WordPress then emits no srcset instead of restoring
	// stale metadata candidates and sending the browser to a known 404.
	return $sources;
}
add_filter( 'wp_calculate_image_srcset', 'nvx_public_media_runtime_srcset', 20, 5 );
