<?php
/**
 * Single Journal article.
 *
 * @package nuvanx-medical
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

while ( have_posts() ) :
	the_post();

	$categories = get_the_category();
	$categories = is_array( $categories ) ? $categories : array();
	$primary    = ! empty( $categories ) ? $categories[0] : null;
	$previous   = get_previous_post();
	$next       = get_next_post();
	$tags       = get_the_tags();
	$tags       = is_array( $tags ) ? $tags : array();
	$clinics    = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	$chamberi_reg = (string) ( $clinics['chamberi']['reg'] ?? '' );
	$goya_reg = (string) ( $clinics['goya']['reg'] ?? '' );
	$chamberi_name = (string) ( $clinics['chamberi']['short_name'] ?? '' );
	$goya_name = (string) ( $clinics['goya']['short_name'] ?? '' );
	$blog_cta_text = sprintf(
		/* translators: 1: clinic name, 2: clinic reg, 3: clinic name, 4: clinic reg */
		__( 'Cada tratamiento se pauta tras la exploración clínica de anatomía, tejido y objetivos. Sedes autorizadas: %1$s (%2$s) y Salamanca–%3$s (%4$s).', 'nuvanx-medical' ),
		$chamberi_name,
		$chamberi_reg,
		$goya_name,
		$goya_reg
	);
	$nvx_thumb_id  = (int) get_post_thumbnail_id();
	$nvx_thumb_alt = $nvx_thumb_id > 0 ? trim( (string) get_post_meta( $nvx_thumb_id, '_wp_attachment_image_alt', true ) ) : '';
	$nvx_thumb_html = ( $nvx_thumb_id > 0 && '' !== $nvx_thumb_alt )
		? get_the_post_thumbnail(
			null,
			'full',
			array(
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'alt'           => $nvx_thumb_alt,
			)
		)
		: '';
	if ( ! is_string( $nvx_thumb_html ) ) {
		$nvx_thumb_html = '';
	}
	$hero_class = '' !== $nvx_thumb_html ? 'nvx-blog-hero' : 'nvx-blog-hero nvx-blog-hero--text-only';
	?>
	<header class="<?php echo esc_attr( $hero_class ); ?>" aria-labelledby="nvx-blog-title-<?php the_ID(); ?>">
			<div class="nvx-brand-section__inner nvx-blog-hero__inner">
				<div class="nvx-blog-hero__copy">
					<?php if ( $primary instanceof WP_Term ) : ?>
						<a class="nvx-blog-hero__category" href="<?php echo esc_url( get_category_link( $primary->term_id ) ); ?>"><?php echo esc_html( $primary->name ); ?></a>
					<?php else : ?>
						<span class="nvx-blog-hero__category"><?php esc_html_e( 'NUVANX Journal', 'nuvanx-medical' ); ?></span>
					<?php endif; ?>

					<?php the_title( '<h1 id="nvx-blog-title-' . get_the_ID() . '" class="nvx-blog-hero__title">', '</h1>' ); ?>

					<?php if ( has_excerpt() ) : ?>
						<p class="nvx-blog-hero__excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
					<?php endif; ?>

					<div class="nvx-blog-hero__meta">
						<?php
						$author = function_exists( 'nvx_blog_medical_author' )
							? nvx_blog_medical_author()
							: array(
								'name' => get_the_author(),
								'url'  => '',
								'role' => '',
							);
						?>
						<span class="nvx-blog-hero__author">
							<?php esc_html_e( 'Autor', 'nuvanx-medical' ); ?>:
							<?php if ( ! empty( $author['url'] ) ) : ?>
								<a class="nvx-blog-hero__author-link" href="<?php echo esc_url( $author['url'] ); ?>"><?php echo esc_html( $author['name'] ); ?></a>
								<?php
							else :
								echo esc_html( $author['name'] );
							endif;
							if ( ! empty( $author['role'] ) ) :
								echo esc_html( ', ' . $author['role'] );
							endif;
							?>
						</span>
						<?php
						$date_display = get_the_date();
						$date_iso     = get_the_date( 'c' );
						// Fallback if theme/date filters yield empty.
						if ( '' === trim( (string) $date_display ) ) {
							$raw = get_post_field( 'post_date', get_the_ID() );
							if ( is_string( $raw ) && '' !== $raw && '0000-00-00 00:00:00' !== $raw ) {
								$ts = strtotime( $raw );
								if ( false !== $ts ) {
									$date_display = wp_date( get_option( 'date_format' ) ?: 'j/m/Y', $ts );
									$date_iso     = gmdate( 'c', $ts );
								}
							}
						}
						if ( '' !== trim( (string) $date_display ) ) :
							?>
							<time class="nvx-blog-hero__date" datetime="<?php echo esc_attr( $date_iso ); ?>"><?php echo esc_html( $date_display ); ?></time>
						<?php endif; ?>
						<?php if ( function_exists( 'nvx_reading_time' ) ) : ?>
							<span class="nvx-blog-hero__reading"><?php echo esc_html( nvx_reading_time() ); ?> <?php esc_html_e( 'de lectura', 'nuvanx-medical' ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( '' !== $nvx_thumb_html ) : ?>
					<figure class="nvx-blog-hero__media">
						<?php echo $nvx_thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP thumbnail HTML. ?>
					</figure>
				<?php endif; ?>
			</div>
		</header>

		<div class="nvx-blog-article__stage">
			<div class="nvx-blog-article__shell">
				<div class="entry-content nvx-blog-prose">
					<?php
					the_content();
					wp_link_pages(
						array(
							'before' => '<nav class="nvx-blog-pagination" aria-label="' . esc_attr__( 'Páginas del artículo', 'nuvanx-medical' ) . '">',
							'after'  => '</nav>',
						)
					);
					?>
				</div>

				<section class="nvx-blog-article__cta" aria-label="<?php esc_attr_e( 'Valoración médica personalizada en Madrid', 'nuvanx-medical' ); ?>">
					<div class="nvx-blog-article__cta-inner">
						<p class="nvx-brand-kicker"><?php esc_html_e( 'Diagnóstico médico presencial', 'nuvanx-medical' ); ?></p>
						<h2 class="nvx-blog-article__cta-title"><?php esc_html_e( 'Plan individualizado en nuestras clínicas de Madrid', 'nuvanx-medical' ); ?></h2>
						<p class="nvx-blog-article__cta-text"><?php echo esc_html( $blog_cta_text ); ?></p>
						<?php if ( function_exists( 'nvx_cta_pair_markup' ) ) : ?>
							<?php echo nvx_cta_pair_markup( 'nvx-blog-article__actions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</section>

				<footer class="nvx-blog-article__footer">
					<p class="nvx-blog-article__notice"><?php esc_html_e( 'Contenido informativo. La indicación, los parámetros y el plan de tratamiento deben confirmarse mediante valoración médica individual.', 'nuvanx-medical' ); ?></p>

					<?php if ( ! empty( $categories ) || ! empty( $tags ) ) : ?>
						<div class="nvx-blog-article__terms" aria-label="<?php esc_attr_e( 'Temas del artículo', 'nuvanx-medical' ); ?>">
							<?php foreach ( $categories as $category ) : ?>
								<a href="<?php echo esc_url( get_category_link( $category->term_id ) ); ?>"><?php echo esc_html( $category->name ); ?></a>
							<?php endforeach; ?>
							<?php foreach ( $tags as $tag ) : ?>
								<a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>">#<?php echo esc_html( $tag->name ); ?></a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</footer>

				<?php if ( $previous || $next ) : ?>
					<nav class="nvx-blog-nav" aria-label="<?php esc_attr_e( 'Navegación entre artículos', 'nuvanx-medical' ); ?>">
						<?php if ( $previous ) : ?>
							<a class="nvx-blog-nav__item nvx-blog-nav__item--previous" href="<?php echo esc_url( get_permalink( $previous ) ); ?>" rel="prev">
								<span class="nvx-blog-nav__label"><?php esc_html_e( 'Artículo anterior', 'nuvanx-medical' ); ?></span>
								<span class="nvx-blog-nav__title"><?php echo esc_html( get_the_title( $previous ) ); ?></span>
							</a>
						<?php endif; ?>
						<?php if ( $next ) : ?>
							<a class="nvx-blog-nav__item nvx-blog-nav__item--next" href="<?php echo esc_url( get_permalink( $next ) ); ?>" rel="next">
								<span class="nvx-blog-nav__label"><?php esc_html_e( 'Siguiente artículo', 'nuvanx-medical' ); ?></span>
								<span class="nvx-blog-nav__title"><?php echo esc_html( get_the_title( $next ) ); ?></span>
							</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</div>
		</div>
	<?php
endwhile;
