<?php
/**
 * Canonical facial aesthetic treatment pages.
 *
 * One versioned catalogue drives visible content, metadata, FAQ schema and the
 * staging-only page seeder. A later medical-review publish reclaims the slug.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical catalogue for facial injectable/regenerative treatment pages.
 *
 * Orientative reference tariffs, session counts, durations and clinical parameters
 * are published here, subject to individual medical assessment in consultation.
 *
 * @return array<string, array<string, mixed>>
 */

/**
 * Normalize aesthetic catalog entry clinical fields from nested sources.
 *
 * @param array<string,mixed> $entry Catalog record reference.
 */
function nvx_aesthetic_catalog_normalize_entry( array &$entry ): void {
	$sources = array(
		$entry['protocol'] ?? array(),
		$entry['schema'] ?? array(),
	);
	foreach ( $sources as $src ) {
		if ( ! is_array( $src ) ) {
			continue;
		}
		if ( empty( $entry['price_range'] ) && ! empty( $src['price_range'] ) ) {
			$entry['price_range'] = $src['price_range'];
		}
		if ( empty( $entry['session_time'] ) && ! empty( $src['session_time'] ) ) {
			$entry['session_time'] = $src['session_time'];
		}
		if ( empty( $entry['duration'] ) ) {
			if ( ! empty( $src['duration_result'] ) ) {
				$entry['duration'] = $src['duration_result'];
			} elseif ( ! empty( $src['duration'] ) ) {
				$entry['duration'] = $src['duration'];
			}
		}
		if ( empty( $entry['anesthesia'] ) && ! empty( $src['anesthesia'] ) ) {
			$entry['anesthesia'] = $src['anesthesia'];
		}
		if ( empty( $entry['brands'] ) ) {
			if ( ! empty( $src['brands'] ) ) {
				$entry['brands'] = (array) $src['brands'];
			} elseif ( ! empty( $src['products_used'] ) ) {
				$entry['brands'] = (array) $src['products_used'];
			}
		}
		if ( empty( $entry['sessions'] ) && ! empty( $src['sessions'] ) ) {
			$entry['sessions'] = $src['sessions'];
		}
		if ( empty( $entry['downtime'] ) && ! empty( $src['downtime'] ) ) {
			$entry['downtime'] = $src['downtime'];
		}
	}
}

function nvx_aesthetic_treatment_catalog(): array {
	static $catalog = null;

	if ( null === $catalog ) {
		require_once __DIR__ . '/nvx-catalog-json.php';
		$raw_catalog = nvx_catalog_json_resolved( 'aesthetic-treatment-pages.json' );

		if ( is_array( $raw_catalog ) ) {
			foreach ( $raw_catalog as &$entry ) {
				if ( is_array( $entry ) ) {
					nvx_aesthetic_catalog_normalize_entry( $entry );
				}
			}
			unset( $entry );
		}

		$catalog = nvx_catalog_filter_records(
			$raw_catalog,
			array(
				'slug',
				'kicker',
				'h1',
				'lead',
				'description',
				'seo_title',
				'diagnosis',
				'indications',
				'precautions',
				'mechanism',
				'process',
				'evolution',
				'risks',
				'combinations',
				'faqs',
				'schema',
				'protocol',
				'brands',
				'duration',
				'session_time',
				'anesthesia',
				'techniques',
				'price_range',
				'sessions',
				'downtime',
			),
			'aesthetic-treatment-pages.json'
		);
	}

	return $catalog;
}

/** Resolve a treatment key from slug or current singular page. */
function nvx_aesthetic_treatment_key_from_slug( string $slug ): ?string {
	$slug = trim( $slug, '/' );
	foreach ( nvx_aesthetic_treatment_catalog() as $key => $entry ) {
		if ( $slug === $entry['slug'] ) {
			return $key;
		}
	}
	return null;
}

function nvx_aesthetic_treatment_current_key(): ?string {
	if ( is_admin() || ! is_singular( 'page' ) ) {
		return null;
	}

	$post_id = get_queried_object_id();
	$slug    = (string) get_post_field( 'post_name', $post_id );
	$key     = nvx_aesthetic_treatment_key_from_slug( $slug );
	if ( null !== $key ) {
		return $key;
	}

	// Staging seed records can survive historical slug migrations. Resolve the
	// canonical treatment from the explicit seed metadata before falling back to
	// the inert CMS marker, so the versioned catalogue remains the only source of
	// visible clinical content and a stale post_name cannot collapse the page to
	// an empty marker plus shell title.
	$catalog  = nvx_aesthetic_treatment_catalog();
	$meta_key = get_post_meta( $post_id, '_nvx_aesthetic_treatment_key', true );
	if ( is_string( $meta_key ) && isset( $catalog[ $meta_key ] ) ) {
		return $meta_key;
	}

	$content = (string) get_post_field( 'post_content', $post_id );
	if ( preg_match( '/data-nvx-treatment=["\']([a-z0-9_-]+)["\']/i', $content, $matches ) ) {
		$marker_key = (string) $matches[1];
		if ( isset( $catalog[ $marker_key ] ) ) {
			return $marker_key;
		}
	}

	return null;
}

/** @return array<string, array<int, array{q:string,a:string}>> */
function nvx_aesthetic_treatment_faq_catalog(): array {
	$result = array();
	foreach ( nvx_aesthetic_treatment_catalog() as $key => $entry ) {
		$result[ $key ] = $entry['faqs'];
	}
	return $result;
}

/** @return array<string, array<string, mixed>> */
function nvx_aesthetic_treatment_schema_catalog(): array {
	$result = array();
	foreach ( nvx_aesthetic_treatment_catalog() as $key => $entry ) {
		$result[ $key ] = $entry['schema'];
	}
	return $result;
}

/** Current page data or null. */
function nvx_aesthetic_treatment_current(): ?array {
	$key = nvx_aesthetic_treatment_current_key();
	if ( null === $key ) {
		return null;
	}
	$catalog = nvx_aesthetic_treatment_catalog();
	return $catalog[ $key ] ?? null;
}

/**
 * Render one treatment data row.
 *
 * @param string $label Row label.
 * @param string $value Row value.
 */
function nvx_aesthetic_treatment_data_row( string $label, string $value ): string {
	if ( '' === trim( $value ) ) {
		return '';
	}
	return '<li><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $value ) . '</span></li>';
}

/**
 * Render plain bullet list.
 *
 * @param array<int,string> $items List values.
 */
function nvx_aesthetic_treatment_list( array $items ): string {
	if ( empty( $items ) ) {
		return '';
	}
	$html = '<ul class="nvx-brand-list" role="list">';
	foreach ( $items as $item ) {
		$html .= '<li>' . esc_html( $item ) . '</li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Open a brand section with a canonical H2.
 */
function nvx_aesthetic_treatment_section_open( string $heading_id, string $title ): string {
	$heading = '<h2 id="' . esc_attr( $heading_id ) . '" class="nvx-brand-title">' . esc_html( $title ) . '</h2>';
	if ( function_exists( 'nvx_page_brand_section_open_markup' ) ) {
		return nvx_page_brand_section_open_markup( '', $heading_id ) . $heading;
	}

	return '<section class="nvx-brand-section" aria-labelledby="' . esc_attr( $heading_id ) . '"><div class="nvx-brand-section__inner">' . $heading;
}

/** Close a brand section opened by nvx_aesthetic_treatment_section_open(). */
function nvx_aesthetic_treatment_section_close(): string {
	return '</div></section>';
}

/**
 * Render optional page section.
 *
 * @param string $title Section title.
 * @param string $copy Section copy.
 */
function nvx_aesthetic_treatment_copy_section( string $title, string $copy ): string {
	if ( '' === trim( $copy ) ) {
		return '';
	}
	$id = 'nvx-treatment-' . sanitize_title( $title );
	return nvx_aesthetic_treatment_section_open( $id, $title )
		. '<p class="nvx-body">' . esc_html( $copy ) . '</p>'
		. nvx_aesthetic_treatment_section_close();
}

/**
 * Render treatment page content.
 *
 * @param array<string,mixed> $page Current treatment data.
 */
function nvx_aesthetic_treatment_render( array $page ): void {
	$schema     = is_array( $page['schema'] ?? null ) ? $page['schema'] : array();
	$protocol   = is_array( $page['protocol'] ?? null ) ? $page['protocol'] : array();
	$brands     = array_values( array_filter( array_map( 'strval', (array) ( $page['brands'] ?? array() ) ) ) );
	$techniques = array_values( array_filter( array_map( 'strval', (array) ( $page['techniques'] ?? array() ) ) ) );
	$price      = (string) ( $page['price_range'] ?? $protocol['price_range'] ?? $schema['price_range'] ?? '' );
	$clinics    = function_exists( 'nvx_get_clinics_config' ) ? nvx_get_clinics_config() : array();
	$chamberi_reg = (string) ( $clinics['chamberi']['reg'] ?? '' );
	$goya_reg = (string) ( $clinics['goya']['reg'] ?? '' );
	$chamberi_name = (string) ( $clinics['chamberi']['short_name'] ?? '' );
	$goya_name = (string) ( $clinics['goya']['short_name'] ?? '' );
	$clinic_meta = $chamberi_name . ' (' . $chamberi_reg . ') · ' . $goya_name . ' (' . $goya_reg . ')';
	if ( 'neuromoduladores-faciales-madrid' === (string) ( $page['slug'] ?? '' ) && function_exists( 'nvx_tariff_price_label' ) ) {
		$from = nvx_tariff_price_label( 'neuromoduladores', 'entrecejo' );
		if ( '' !== $from ) {
			$price = sprintf(
				/* translators: %s: canonical tariff */
				__( 'Desde %s por zona. El presupuesto final depende de zonas y dosis.', 'nuvanx-medical' ),
				$from
			);
		}
	}
	$duration   = (string) ( $page['duration'] ?? $protocol['duration_result'] ?? $schema['duration'] ?? '' );
	$session    = (string) ( $page['session_time'] ?? $protocol['session_time'] ?? $schema['session_time'] ?? '' );
	$anesthesia = (string) ( $page['anesthesia'] ?? $protocol['anesthesia'] ?? $schema['anesthesia'] ?? '' );
	$sessions   = (string) ( $page['sessions'] ?? $protocol['sessions'] ?? $schema['sessions'] ?? '' );
	$downtime   = (string) ( $page['downtime'] ?? $protocol['downtime'] ?? $schema['downtime'] ?? '' );
	?>
	<div class="nvx-treatment-editorial" data-nvx-treatment-page>
		<section class="nvx-brand-hero" aria-labelledby="nvx-treatment-h1">
			<div class="nvx-brand-hero__inner">
				<?php
				echo nvx_brand_hero_copy_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
					array(
						'kicker'             => (string) $page['kicker'],
						'h1_id'              => 'nvx-treatment-h1',
						'h1'                 => (string) $page['h1'],
						'byline'             => true,
						'lead'               => (string) $page['lead'],
						'cta_fallback_label' => __( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ),
						'meta'               => $clinic_meta,
					)
				);
				?>
			</div>
		</section>

		<?php echo wp_kses_post( nvx_aesthetic_treatment_copy_section( __( 'Diagnóstico médico', 'nuvanx-medical' ), (string) $page['diagnosis'] ) ); ?>
		<?php
		echo wp_kses_post(
			nvx_aesthetic_treatment_section_open( 'nvx-treatment-indications', __( 'Cuándo puede estar indicado', 'nuvanx-medical' ) )
			. nvx_aesthetic_treatment_list( (array) $page['indications'] )
			. nvx_aesthetic_treatment_section_close()
		);
		$yes = array_values( array_filter( array_map( 'strval', (array) ( $page['indications'] ?? array() ) ) ) );
		$no  = array_values( array_filter( array_map( 'strval', (array) ( $page['precautions'] ?? array() ) ) ) );
		if ( function_exists( 'nvx_candidacy_markup' ) && ( array() !== $yes || array() !== $no ) ) {
			echo wp_kses_post( nvx_aesthetic_treatment_section_open( 'nvx-treatment-candidacy', __( 'Quién es candidato y quién no', 'nuvanx-medical' ) ) );
			echo nvx_candidacy_markup( $yes, $no ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
			echo wp_kses_post( nvx_aesthetic_treatment_section_close() );
		}
		echo wp_kses_post( nvx_aesthetic_treatment_copy_section( __( 'Cómo actúa', 'nuvanx-medical' ), (string) $page['mechanism'] ) );
		echo wp_kses_post(
			nvx_aesthetic_treatment_section_open( 'nvx-treatment-process', __( 'Cómo es el tratamiento', 'nuvanx-medical' ) )
			. nvx_aesthetic_treatment_list( (array) $page['process'] )
			. nvx_aesthetic_treatment_section_close()
		);
		echo wp_kses_post( nvx_aesthetic_treatment_copy_section( __( 'Evolución esperable', 'nuvanx-medical' ), (string) $page['evolution'] ) );
		if ( 'neuromoduladores-faciales-madrid' === (string) ( $page['slug'] ?? '' ) && function_exists( 'nvx_recovery_table_markup' ) ) {
			echo wp_kses_post( nvx_aesthetic_treatment_section_open( 'nvx-neuro-recovery', __( 'Recuperación y vuelta a la actividad', 'nuvanx-medical' ) ) );
			echo nvx_recovery_table_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
				array(
					array(
						'when'     => __( 'Primeras 4 horas', 'nuvanx-medical' ),
						'expect'   => __( 'Posibles microhematomas puntiformes. No frotar la zona.', 'nuvanx-medical' ),
						'activity' => __( 'No tumbarse ni hacer ejercicio intenso.', 'nuvanx-medical' ),
					),
					array(
						'when'     => __( 'Día 1', 'nuvanx-medical' ),
						'expect'   => __( 'Sin downtime. Maquillaje habitual si no hay hematoma.', 'nuvanx-medical' ),
						'activity' => __( 'Vuelta inmediata al trabajo.', 'nuvanx-medical' ),
					),
					array(
						'when'     => __( 'Días 3–5', 'nuvanx-medical' ),
						'expect'   => __( 'Empieza la relajación muscular.', 'nuvanx-medical' ),
						'activity' => __( 'Actividad normal.', 'nuvanx-medical' ),
					),
					array(
						'when'     => __( 'Días 14–21', 'nuvanx-medical' ),
						'expect'   => __( 'Efecto máximo. Revisión médica de simetría.', 'nuvanx-medical' ),
						'activity' => __( 'Vida habitual. Ajuste solo si está indicado.', 'nuvanx-medical' ),
					),
				),
				__( 'Recuperación orientativa de neuromoduladores', 'nuvanx-medical' )
			);
			echo wp_kses_post( nvx_aesthetic_treatment_section_close() );
		}

		if ( $price || $duration || $session || $anesthesia || $sessions || $downtime || $brands || $techniques ) {
			echo wp_kses_post( nvx_aesthetic_treatment_section_open( 'nvx-treatment-facts', __( 'Datos orientativos', 'nuvanx-medical' ) ) );
			echo '<ul class="nvx-brand-list" role="list">';
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Precio orientativo', 'nuvanx-medical' ), $price ) );
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Duración del resultado', 'nuvanx-medical' ), $duration ) );
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Tiempo de sesión', 'nuvanx-medical' ), $session ) );
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Anestesia', 'nuvanx-medical' ), $anesthesia ) );
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Sesiones', 'nuvanx-medical' ), $sessions ) );
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Recuperación', 'nuvanx-medical' ), $downtime ) );
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Marcas / productos', 'nuvanx-medical' ), implode( ', ', $brands ) ) );
			echo wp_kses_post( nvx_aesthetic_treatment_data_row( __( 'Técnicas', 'nuvanx-medical' ), implode( ', ', $techniques ) ) );
			echo '</ul>';
			echo wp_kses_post( nvx_aesthetic_treatment_section_close() );
		}

		echo wp_kses_post(
			nvx_aesthetic_treatment_section_open( 'nvx-treatment-safety', __( 'Seguridad y valoración', 'nuvanx-medical' ) )
			. nvx_aesthetic_treatment_list( (array) $page['precautions'] )
			. nvx_aesthetic_treatment_section_close()
		);
		echo wp_kses_post(
			nvx_aesthetic_treatment_section_open( 'nvx-treatment-risks', __( 'Riesgos y efectos adversos', 'nuvanx-medical' ) )
			. nvx_aesthetic_treatment_list( (array) $page['risks'] )
			. nvx_aesthetic_treatment_section_close()
		);

		if ( ! empty( $page['combinations'] ) ) {
			echo wp_kses_post(
				nvx_aesthetic_treatment_section_open( 'nvx-treatment-combinations', __( 'Combinaciones posibles', 'nuvanx-medical' ) )
				. nvx_aesthetic_treatment_list( (array) $page['combinations'] )
				. nvx_aesthetic_treatment_section_close()
			);
		}

		if ( ! empty( $page['faqs'] ) ) {
			echo wp_kses_post( nvx_aesthetic_treatment_section_open( 'nvx-treatment-faqs', __( 'Preguntas frecuentes', 'nuvanx-medical' ) ) );
			if ( function_exists( 'nvx_faq_direct_answer_markup' ) ) {
				echo nvx_faq_direct_answer_markup( (array) $page['faqs'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
			} else {
				foreach ( (array) $page['faqs'] as $faq ) {
					echo '<details><summary>' . esc_html( (string) ( $faq['q'] ?? '' ) ) . '</summary><p class="nvx-body">' . esc_html( (string) ( $faq['a'] ?? '' ) ) . '</p></details>';
				}
			}
			echo wp_kses_post( nvx_aesthetic_treatment_section_close() );
		}
		?>
	</div>
	<?php
}

/** Declare theme ownership so the shared page shell never emits a second H1. */
function nvx_aesthetic_treatment_page_owner( $owner ) {
	if ( ! empty( $owner ) ) {
		return $owner;
	}
	return null !== nvx_aesthetic_treatment_current_key() ? 'nvx_aesthetic_treatment_pages' : $owner;
}
add_filter( 'nvx_page_owner', 'nvx_aesthetic_treatment_page_owner' );

/** Render complete treatment page for governed slugs. */
function nvx_aesthetic_treatment_page_content( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( 'nvx_aesthetic_treatment_pages' !== $owner || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$page = nvx_aesthetic_treatment_current();
	if ( null === $page ) {
		return $content;
	}
	ob_start();
	nvx_aesthetic_treatment_render( $page );
	return (string) ob_get_clean();
}
add_filter( 'the_content', 'nvx_aesthetic_treatment_page_content', NVX_HOOK_PRIO_AESTHETIC_TREATMENT );

/** Seed governed pages on staging only; existing editorial records are never overwritten. */
