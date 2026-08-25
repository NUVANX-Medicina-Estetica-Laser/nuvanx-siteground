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

/**
 * Resolve a public uploads URL to the active local uploads filesystem.
 *
 * During governed Staging publication, attachment metadata may still contain
 * the production uploads host while the rendered page uses the current home
 * host. Both are local representations of the same synced uploads tree and
 * must be checked against disk before they are advertised to the browser.
 *
 * @return string|null Local file path, or null for a genuinely external URL.
 */
function nvx_public_media_local_file_from_url( string $url ): ?string {
	$uploads = wp_get_upload_dir();
	$baseurl = isset( $uploads['baseurl'] ) ? rtrim( (string) $uploads['baseurl'], '/' ) : '';
	$basedir = isset( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/\\' ) : '';

	if ( '' === $url || '' === $baseurl || '' === $basedir ) {
		return null;
	}

	$url_path  = (string) wp_parse_url( $url, PHP_URL_PATH );
	$base_path = rtrim( (string) wp_parse_url( $baseurl, PHP_URL_PATH ), '/' );
	$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$base_host = strtolower( (string) wp_parse_url( $baseurl, PHP_URL_HOST ) );
	$home_host = function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) : '';
	$local_hosts = array_values( array_unique( array_filter( array( $base_host, $home_host ) ) ) );

	if ( '' === $url_path || '' === $base_path || '' === $url_host || array() === $local_hosts || ! in_array( $url_host, $local_hosts, true ) ) {
		return null;
	}
	if ( $url_path !== $base_path && 0 !== strpos( $url_path, $base_path . '/' ) ) {
		return null;
	}

	$relative = ltrim( rawurldecode( substr( $url_path, strlen( $base_path ) ) ), '/' );
	if ( '' === $relative || false !== strpos( $relative, '../' ) ) {
		return null;
	}

	return $basedir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
}

/** Whether a public uploads URL resolves to a readable file in this runtime. */
function nvx_public_media_upload_url_is_readable( string $url ): bool {
	$local_file = nvx_public_media_local_file_from_url( $url );
	if ( null === $local_file ) {
		// This guard owns the active NUVANX uploads hosts only. Leave genuinely
		// external/CDN URLs to their own delivery boundary.
		return true;
	}

	return is_readable( $local_file );
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

/**
 * Remove every governed srcset candidate whose uploads file is absent.
 *
 * Another earlier runtime guard is allowed to return `false` when it removes
 * every local candidate. WordPress explicitly permits that contract, so this
 * callback must preserve `false` instead of requiring an array and triggering
 * a PHP TypeError on routes whose responsive candidates are all stale.
 *
 * @param array|false $sources       Responsive image candidates keyed by width.
 * @param mixed       $size_array    Requested image dimensions.
 * @param mixed       $image_src     Primary image URL.
 * @param mixed       $image_meta    Attachment metadata.
 * @param mixed       $attachment_id Attachment ID.
 * @return array|false
 */
function nvx_public_media_runtime_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	unset( $size_array, $image_src, $image_meta );
	if ( ! is_array( $sources ) || array() === $sources ) {
		return $sources;
	}

	$attachment_id = (int) $attachment_id;
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! function_exists( 'nvx_governed_public_image_ids' ) || ! in_array( $attachment_id, nvx_governed_public_image_ids(), true ) ) {
		return $sources;
	}

	foreach ( array_keys( $sources ) as $width ) {
		$url = isset( $sources[ $width ]['url'] ) ? (string) $sources[ $width ]['url'] : '';
		if ( '' === $url || ! nvx_public_media_upload_url_is_readable( $url ) ) {
			unset( $sources[ $width ] );
		}
	}

	return array() === $sources ? false : $sources;
}
add_filter( 'wp_calculate_image_srcset', 'nvx_public_media_runtime_srcset', 20, 5 );

/** Remove absent local candidates from one rendered srcset attribute. */
function nvx_public_media_runtime_filter_srcset_attribute( string $srcset ): string {
	if ( '' === trim( $srcset ) ) {
		return '';
	}

	$kept = array();
	foreach ( preg_split( '/\s*,\s*/', trim( $srcset ) ) ?: array() as $candidate ) {
		$candidate = trim( (string) $candidate );
		if ( '' === $candidate ) {
			continue;
		}
		$parts = preg_split( '/\s+/', $candidate, 2 );
		$url   = isset( $parts[0] ) ? html_entity_decode( (string) $parts[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		if ( '' !== $url && nvx_public_media_upload_url_is_readable( $url ) ) {
			$kept[] = $candidate;
		}
	}

	return implode( ', ', $kept );
}

/**
 * Final fail-closed boundary for WordPress-generated governed image attributes.
 *
 * `image_downsize` and `wp_calculate_image_srcset` are intentionally retained
 * because they prevent stale candidates upstream. This final filter protects
 * against later callbacks, host rewriting and lazy-load attributes that can
 * otherwise re-advertise stale attachment metadata after those earlier gates.
 *
 * @param array<string,mixed> $attr       Final image attributes.
 * @param mixed               $attachment Attachment object.
 * @param mixed               $size       Requested WordPress image size.
 * @return array<string,mixed>
 */
function nvx_public_media_runtime_attributes( array $attr, $attachment, $size ): array {
	$attachment_id = isset( $attachment->ID ) ? (int) $attachment->ID : 0;
	if ( $attachment_id < 1 || ( function_exists( 'is_admin' ) && is_admin() ) || ! function_exists( 'nvx_governed_public_image_ids' ) || ! in_array( $attachment_id, nvx_governed_public_image_ids(), true ) ) {
		return $attr;
	}

	$replacement = null;
	foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original' ) as $name ) {
		if ( ! isset( $attr[ $name ] ) || ! is_string( $attr[ $name ] ) || '' === trim( $attr[ $name ] ) || nvx_public_media_upload_url_is_readable( $attr[ $name ] ) ) {
			continue;
		}
		if ( null === $replacement ) {
			$candidate   = nvx_public_media_runtime_downsize( array(), $attachment_id, $size );
			$replacement = is_array( $candidate ) && isset( $candidate[0] ) && nvx_public_media_upload_url_is_readable( (string) $candidate[0] ) ? $candidate : false;
		}
		if ( is_array( $replacement ) ) {
			$attr[ $name ] = (string) $replacement[0];
			if ( 'src' === $name ) {
				if ( isset( $replacement[1] ) && (int) $replacement[1] > 0 ) {
					$attr['width'] = (int) $replacement[1];
				}
				if ( isset( $replacement[2] ) && (int) $replacement[2] > 0 ) {
					$attr['height'] = (int) $replacement[2];
				}
			}
		} else {
			unset( $attr[ $name ] );
		}
	}

	foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $name ) {
		if ( ! isset( $attr[ $name ] ) || ! is_string( $attr[ $name ] ) ) {
			continue;
		}
		$filtered = nvx_public_media_runtime_filter_srcset_attribute( $attr[ $name ] );
		if ( '' === $filtered ) {
			unset( $attr[ $name ] );
		} else {
			$attr[ $name ] = $filtered;
		}
	}

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'nvx_public_media_runtime_attributes', PHP_INT_MAX, 3 );
