<?php
/**
 * Canonical runtime ownership for the .nvx-brand-page frame.
 *
 * header.php opens the global frame when the stored CMS body does not already
 * own the standard entry-content/nvx-prose wrapper. Managed renderers keep a
 * local .nvx-brand-page fallback for the opposite case. This final content
 * pass removes the redundant local frame token when the global frame is
 * already present while retaining the renderer root for page-specific classes.
 * The retained root receives an explicit compatibility marker consumed by the
 * layout contract, guaranteeing exactly one .nvx-brand-page ancestor without
 * losing renderer-specific semantics.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize the first renderer-owned .nvx-brand-page class under a global frame.
 *
 * The wrapper element and all page-specific classes remain intact. When the
 * stored CMS body owns the standard wrapper, header.php does not open a global
 * brand frame and the renderer's local .nvx-brand-page class is preserved.
 */
function nvx_remove_redundant_inner_brand_page_class( string $content ): string {
	if ( is_admin() || ! is_page() || '' === trim( $content ) ) {
		return $content;
	}

	if ( ! function_exists( 'nvx_page_has_standard_wrapper' ) || nvx_page_has_standard_wrapper() ) {
		return $content;
	}

	$normalized = preg_replace_callback(
		'/<(div|article)\b([^>]*\bclass=)(["\'])([^"\']*\bnvx-brand-page\b[^"\']*)\3([^>]*)>/iu',
		static function ( array $matches ): string {
			$classes = preg_split( '/\s+/u', trim( (string) $matches[4] ) );
			$classes = is_array( $classes ) ? $classes : array();
			$classes = array_values(
				array_filter(
					$classes,
					static fn ( string $class_name ): bool => 'nvx-brand-page' !== $class_name && '' !== $class_name
				)
			);

			if ( ! in_array( 'nvx-brand-page__renderer-root', $classes, true ) ) {
				$classes[] = 'nvx-brand-page__renderer-root';
			}

			$class_value = esc_attr( implode( ' ', $classes ) );

			return '<' . $matches[1] . $matches[2] . $matches[3] . $class_value . $matches[3] . $matches[5] . '>';
		},
		$content,
		1
	);

	return is_string( $normalized ) ? $normalized : $content;
}
add_filter( 'the_content', 'nvx_remove_redundant_inner_brand_page_class', NVX_HOOK_PRIO_BRAND_WRAPPER_NORMALIZE );
