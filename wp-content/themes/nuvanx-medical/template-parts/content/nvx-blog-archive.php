<?php
/**
 * Shared journal archive for /blog/, taxonomies, dates, authors and search.
 *
 * @package nuvanx-medical
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve Journal card media fail-closed.
 *
 * A post may use its own valid featured image or one positively matched clinical
 * theme asset. Bridal campaign imagery is deliberately excluded from Journal
 * fallback media. Unmatched articles render as editorial text cards rather than
 * borrowing an unrelated photograph.
 *
 * @param array{priority?:bool,sizes?:string,reset_used?:bool} $args Image flags.
 */
function nvx_blog_archive_semantic_image( array $args = array() ): string {
	static $used = array();

	if ( ! empty( $args['reset_used'] ) ) {
		$used = array();
		return '';
	}

	$priority = ! empty( $args['priority'] );
	$sizes    = isset( $args['sizes'] ) ? (string) $args['sizes'] : '(min-width: 1024px) 33vw, (min-width: 641px) 50vw, 100vw';

	if ( has_post_thumbnail() ) {
		$thumb_id = (int) get_post_thumbnail_id();
		$alt      = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
		$attr     = array(
			'class'    => 'nvx-blog-card__image',
			'loading'  => $priority ? 'eager' : 'lazy',
			'decoding' => 'async',
			'alt'      => $alt,
			'sizes'    => $sizes,
		);
		if ( $priority ) {
			$attr['fetchpriority'] = 'high';
		}

		$html = get_the_post_thumbnail( null, 'large', $attr );
		if ( is_string( $html ) && '' !== $html
			&& 1 !== preg_match( '/logo-nuvanx|nuvanx-web\.webp|\/logo[-_]|nvx-logo|site-logo|custom-logo/iu', $html )
			&& ( ! function_exists( 'nvx_public_html_is_vendor_image' ) || ! nvx_public_html_is_vendor_image( $html ) )
		) {
			return $html;
		}
	}

	if ( ! function_exists( 'nvx_blog_named_image_catalog' ) || ! function_exists( 'nvx_blog_named_image_html' ) ) {
		return '';
	}

	$parts = array( (string) get_the_title(), (string) get_post_field( 'post_name', get_the_ID() ) );
	foreach ( get_the_category() as $category ) {
		if ( $category instanceof WP_Term ) {
			$parts[] = $category->name;
			$parts[] = $category->slug;
		}
	}

	$lower = static function ( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	};
	$haystack   = strtr( $lower( implode( ' ', $parts ) ), array( '-' => ' ', '_' => ' ', '/' => ' ' ) );
	$best       = null;
	$best_score = 0;

	foreach ( nvx_blog_named_image_catalog() as $asset ) {
		if ( ! is_array( $asset ) ) {
			continue;
		}
		$id = (string) ( $asset['id'] ?? '' );
		if ( '' === $id || 0 === strpos( $id, 'novias-' ) || isset( $used[ $id ] ) ) {
			continue;
		}

		$score = 0;
		foreach ( (array) ( $asset['keys'] ?? array() ) as $key ) {
			$key = trim( (string) $key );
			if ( '' !== $key && false !== strpos( $haystack, $lower( $key ) ) ) {
				++$score;
			}
		}

		if ( $score > $best_score ) {
			$best       = $asset;
			$best_score = $score;
		}
	}

	if ( ! is_array( $best ) || $best_score < 1 ) {
		return '';
	}

	$used[ (string) $best['id'] ] = true;
	return nvx_blog_named_image_html(
		$best,
		array(
			'priority' => $priority,
			'sizes'    => $sizes,
		)
	);
}

$eyebrow = __( 'NUVANX Journal', 'nuvanx-medical' );
$title   = __( 'Medicina estética con criterio', 'nuvanx-medical' );
$lead    = __( 'Análisis médicos sobre tecnología láser, calidad de piel, well-aging, seguridad y decisiones terapéuticas en Madrid.', 'nuvanx-medical' );

if ( is_search() ) {
	$title = sprintf(
		/* translators: %s: search term. */
		__( 'Resultados para “%s”', 'nuvanx-medical' ),
		get_search_query()
	);
	$lead = __( 'Artículos y guías relacionadas con tu búsqueda.', 'nuvanx-medical' );
} elseif ( is_category() ) {
	$title       = single_cat_title( '', false );
	$description = category_description();
	$lead        = $description ? wp_strip_all_tags( $description ) : __( 'Artículos de esta especialidad dentro del Journal médico NUVANX.', 'nuvanx-medical' );
} elseif ( is_tag() ) {
	$title       = single_tag_title( '', false );
	$description = tag_description();
	$lead        = $description ? wp_strip_all_tags( $description ) : __( 'Artículos relacionados con este tema médico-estético.', 'nuvanx-medical' );
} elseif ( is_author() ) {
	$author = get_queried_object();
	$title  = $author instanceof WP_User ? $author->display_name : __( 'Autor', 'nuvanx-medical' );
	$lead   = __( 'Publicaciones y revisiones editoriales de este autor.', 'nuvanx-medical' );
} elseif ( is_date() ) {
	$title = wp_strip_all_tags( get_the_archive_title() );
	$lead  = __( 'Archivo cronológico del Journal médico NUVANX.', 'nuvanx-medical' );
}

$topics = get_categories(
	array(
		'hide_empty' => true,
		'number'     => 12,
		'orderby'    => 'count',
		'order'      => 'DESC',
	)
);
?>
<div class="nvx-brand-page ">
	<section class="nvx-brand-hero" aria-labelledby="nvx-blog-archive-title">
		<div class="nvx-brand-hero__inner">
			<div class="nvx-brand-hero__copy">
				<p class="nvx-brand-kicker"><?php echo esc_html( $eyebrow ); ?></p>
				<h1 id="nvx-blog-archive-title" class="nvx-brand-hero__title"><?php echo esc_html( $title ); ?></h1>
				<p class="nvx-brand-hero__lead"><?php echo esc_html( $lead ); ?></p>
			</div>
		</div>
	</section>

	<div class="nvx-blog-archive__body">
		<div class="nvx-brand-section__inner">
			<?php if ( have_posts() ) : ?>
				<div class="nvx-blog-grid">
					<?php
					nvx_blog_archive_semantic_image( array( 'reset_used' => true ) );
					$nvx_editorial_index = 0;
					while ( have_posts() ) :
						the_post();
						$categories = get_the_category();
						$primary    = ! empty( $categories ) ? $categories[0] : null;
						$slot       = $nvx_editorial_index % 6;
						$sizes      = 5 === $slot
							? '(min-width: 768px) 40vw, 100vw'
							: '(min-width: 1024px) 33vw, (min-width: 641px) 50vw, 100vw';
						$image      = 4 !== $slot
							? nvx_blog_archive_semantic_image(
								array(
									'priority' => 0 === $nvx_editorial_index,
									'sizes'    => $sizes,
								)
							)
							: '';
						$has_media  = '' !== $image;
						$format     = function_exists( 'nvx_blog_archive_card_format' )
							? nvx_blog_archive_card_format( $nvx_editorial_index, $has_media )
							: ( $has_media ? 'vertical' : 'text' );
						$classes    = array(
							'nvx-blog-card',
							'nvx-blog-card--' . $format,
						);
						if ( ! $has_media ) {
							$classes[] = 'nvx-blog-card--no-media';
						}
						$permalink   = get_permalink();
						$title_attr  = the_title_attribute( array( 'echo' => false ) );
						$excerpt     = wp_trim_words( get_the_excerpt(), 'horizontal' === $format ? 30 : 22, '…' );
						$reading     = function_exists( 'nvx_reading_time' ) ? nvx_reading_time() : '';
						$index_label = str_pad( (string) ( $nvx_editorial_index + 1 ), 2, '0', STR_PAD_LEFT );
						?>
						<article id="post-<?php the_ID(); ?>" <?php post_class( $classes ); ?>>
							<?php if ( in_array( $format, array( 'hero', 'horizontal' ), true ) && $has_media ) : ?>
								<div class="nvx-blog-card__media" aria-hidden="true"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP thumbnail/theme image HTML. ?></div>
								<div class="nvx-blog-card__content">
									<?php if ( $primary instanceof WP_Term ) : ?>
										<span class="nvx-blog-card__category"><a href="<?php echo esc_url( get_category_link( $primary->term_id ) ); ?>"><?php echo esc_html( $primary->name ); ?></a></span>
									<?php endif; ?>
									<h2 class="nvx-blog-card__title"><a href="<?php echo esc_url( $permalink ); ?>"><?php the_title(); ?></a></h2>
									<div class="nvx-blog-card__excerpt"><p><?php echo esc_html( $excerpt ); ?></p></div>
									<div class="nvx-blog-card__meta">
										<time class="nvx-blog-card__date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
										<?php if ( '' !== $reading ) : ?>
											<span class="nvx-blog-card__reading"><?php echo esc_html( $reading ); ?></span>
										<?php endif; ?>
										<a class="nvx-blog-card__link" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Leer artículo: %s', 'nuvanx-medical' ), $title_attr ) ); ?>"><?php esc_html_e( 'Leer artículo', 'nuvanx-medical' ); ?> <span aria-hidden="true">→</span></a>
									</div>
								</div>
							<?php else : ?>
								<?php if ( $has_media && 'vertical' === $format ) : ?>
									<div class="nvx-blog-card__media" aria-hidden="true"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP thumbnail/theme image HTML. ?></div>
								<?php endif; ?>
								<div class="nvx-blog-card__content">
									<?php if ( 'text' === $format ) : ?>
										<span class="nvx-blog-card__index" aria-hidden="true"><?php echo esc_html( $index_label ); ?></span>
									<?php endif; ?>
									<div class="nvx-blog-card__meta">
										<?php if ( $primary instanceof WP_Term ) : ?>
											<span class="nvx-blog-card__category"><a href="<?php echo esc_url( get_category_link( $primary->term_id ) ); ?>"><?php echo esc_html( $primary->name ); ?></a></span>
										<?php endif; ?>
										<?php if ( 'vertical' === $format && '' !== $reading ) : ?>
											<span class="nvx-blog-card__reading"><?php echo esc_html( $reading ); ?></span>
										<?php endif; ?>
									</div>
									<h2 class="nvx-blog-card__title"><a href="<?php echo esc_url( $permalink ); ?>"><?php the_title(); ?></a></h2>
									<div class="nvx-blog-card__excerpt"><p><?php echo esc_html( $excerpt ); ?></p></div>
									<?php if ( 'text' === $format ) : ?>
										<div class="nvx-blog-card__meta">
											<time class="nvx-blog-card__date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
											<a class="nvx-blog-card__link" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Leer artículo: %s', 'nuvanx-medical' ), $title_attr ) ); ?>"><?php esc_html_e( 'Leer', 'nuvanx-medical' ); ?> <span aria-hidden="true">→</span></a>
										</div>
									<?php else : ?>
										<a class="nvx-blog-card__link" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Leer artículo: %s', 'nuvanx-medical' ), $title_attr ) ); ?>"><?php esc_html_e( 'Leer artículo', 'nuvanx-medical' ); ?> <span aria-hidden="true">→</span></a>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</article>
						<?php
						++$nvx_editorial_index;
					endwhile;
					?>
				</div>

				<nav class="nvx-blog-pagination" aria-label="<?php esc_attr_e( 'Paginación del Journal', 'nuvanx-medical' ); ?>">
					<?php
					the_posts_pagination(
						array(
							'mid_size'  => 2,
							'prev_text' => __( 'Anterior', 'nuvanx-medical' ),
							'next_text' => __( 'Siguiente', 'nuvanx-medical' ),
						)
					);
					?>
				</nav>
			<?php else : ?>
				<div class="nvx-blog-empty">
					<h2 class="nvx-brand-title"><?php esc_html_e( 'No se encontraron artículos', 'nuvanx-medical' ); ?></h2>
					<p class="nvx-copy"><?php esc_html_e( 'Prueba con otro tema o vuelve al Journal completo.', 'nuvanx-medical' ); ?></p>
					<a class="nvx-brand-btn nvx-brand-btn--primary" href="<?php echo esc_url( home_url( '/blog/' ) ); ?>"><?php esc_html_e( 'Ver todos los artículos', 'nuvanx-medical' ); ?></a>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $topics ) ) : ?>
				<nav class="nvx-blog-topics" aria-labelledby="nvx-blog-topics-title">
					<h2 id="nvx-blog-topics-title" class="nvx-blog-topics__title"><?php esc_html_e( 'Explorar por tema', 'nuvanx-medical' ); ?></h2>
					<ul class="nvx-blog-topics__list">
						<?php foreach ( $topics as $topic ) : ?>
							<li><a href="<?php echo esc_url( get_category_link( $topic->term_id ) ); ?>"><?php echo esc_html( $topic->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
		</div>
	</div>
</div>
