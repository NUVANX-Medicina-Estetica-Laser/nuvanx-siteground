<?php
declare(strict_types=1);

const PROSE_WRAPPER = 'nvx-page__content nvx-prose';

/**
 * Contract: priority Ads landings expose H1, authority, tariff and recovery.
 *
 * @package nuvanx-medical
 */


$root = dirname( __DIR__, 2 );
$fail = static function ( string $message ): void {
	fwrite( STDERR, 'PRIORITY_LANDING_CONTRACT=FAIL ' . $message . PHP_EOL );
	exit( 1 );
};

$endolift_php = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-endolift-page.php' );
$endolift_json = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/endolift-page.json' );
$helpers       = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-page-render-helpers.php' );
$sede          = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/templates/page-sede.php' );
$neuro         = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/aesthetic-treatment-pages.json' );

if ( ! str_contains( $endolift_php, 'endolift-facial' ) || str_contains( $endolift_php, "strpos( \$content, 'nvx-endolift-editorial' )" ) ) {
	$fail( 'endolift detector must claim the path and ignore CMS HTML comments' );
}

if ( ! str_contains( $endolift_json, 'papada y mandíbula sin cirugía' ) ) {
	$fail( 'endolift H1 must state Madrid + papada/mandible + without surgery' );
}

if ( ! str_contains( $helpers, 'function nvx_clinical_authority_byline_markup' )
	|| ! str_contains( $helpers, 'function nvx_recovery_table_markup' )
	|| ! str_contains( $helpers, 'function nvx_candidacy_markup' )
	|| ! str_contains( $helpers, 'function nvx_tariff_price_label' ) ) {
	$fail( 'shared competitive helpers are missing' );
}

if ( ! str_contains( $sede, 'Medicina estética en Chamberí, Madrid' )
	|| ( ! str_contains( $sede, 'nvx_clinic_landing_photos' ) && ! str_contains( $sede, 'nvx_chamberi_landing_photos' ) ) ) {
	$fail( 'chamberi landing must have local-intent H1 and photo gallery' );
}

if ( ! str_contains( $neuro, 'arrugas de expresión del tercio superior' ) ) {
	$fail( 'neuromodulators H1 must include Madrid indication' );
}

$exilite_json = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/btl-detail-pages.json' );
$exilite_php  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-btl-detail-pages.php' );
$blog_meta    = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/seo-blog-post-metadata.json' );
$blog_php     = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-blog-system.php' );
$blog_runtime = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-governed-blog-runtime.php' );

if ( ! str_contains( $exilite_json, 'manchas y rojeces' )
	|| ! str_contains( $exilite_php, 'nvx_btl_detail_reservation_markup' )
	|| ! str_contains( $exilite_php, 'nvx_btl_detail_hydrate_tariffs' ) ) {
	$fail( 'EXILITE transactional page must expose candidacy/reservation/tariff hydration' );
}

if ( str_contains( $blog_meta, '"canonical_path": "/btl-exilite-ipl-madrid/"' )
	|| ! str_contains( $blog_php, 'tratamiento IPL médico en Madrid' )
	|| ! str_contains( $blog_php, 'guía completa del Endolift® facial en Madrid' )
	|| ! str_contains( $blog_php, 'ficha completa del tratamiento' )
	|| ! str_contains( $blog_php, 'function nvx_theme_wrap_top1_commercial_mentions' )
	|| ! str_contains( $blog_runtime, 'function nvx_governed_blog_html_canonical_url' ) ) {
	$fail( 'IPL Journal article must remain self-canonical and Top-1 articles must link their commercial fichas' );
}

$valoracion_php = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-valoracion-managed-page.php' );
$valoracion_css = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/assets/css/nvx-components.css' );
$seo_catalog    = json_decode( (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/seo-metadata.json' ), true );
$valoracion_desc = is_array( $seo_catalog ) ? (string) ( $seo_catalog['valoracion']['description'] ?? '' ) : '';

// Extraer el lead renderizado para que las frases RSA no se satisfagan solo con la lista de prueba.
$valoracion_lead = '';
if ( preg_match( '/id="nvx-valoracion-lead"[^>]*>(.*?)<\/p>/s', $valoracion_php, $lead_m ) ) {
	$valoracion_lead = $lead_m[1];
}
// Extraer la descripción del schema MedicalWebPage.
$valoracion_schema_desc = '';
if ( preg_match( "/\\\$description\\s*=\\s*__\\(\\s*'(.*?)'\\s*,\\s*'nuvanx-medical'\\s*\\)/s", $valoracion_php, $desc_m ) ) {
	$valoracion_schema_desc = $desc_m[1];
}

if ( ! str_contains( $valoracion_php, 'Valoración médica estética en Madrid' )
	|| ! str_contains( $valoracion_lead, 'Valoración médica en Madrid de 15 a 30 minutos' )
	|| ! str_contains( $valoracion_lead, 'Sin Anestesia General' )
	|| ! str_contains( $valoracion_lead, 'Recuperación en 48h' )
	|| ! str_contains( $valoracion_lead, 'reincorporación habitual' )
	|| ! str_contains( $valoracion_lead, 'según el protocolo indicado' )
	|| ! str_contains( $valoracion_schema_desc, 'Sin Anestesia General' )
	|| ! str_contains( $valoracion_schema_desc, 'Recuperación en 48h' )
	|| ! str_contains( $valoracion_schema_desc, 'reincorporación habitual' )
	|| ! str_contains( $valoracion_php, 'function nvx_valoracion_schema_graph' )
	|| ! str_contains( $valoracion_php, 'MedicalWebPage' )
	|| ! str_contains( $valoracion_php, 'id="nvx-valoracion-lead"' )
	|| ! str_contains( $valoracion_php, 'nvx_clinical_authority_byline_markup' ) ) {
	$fail( 'valoracion landing must expose H1, RSA message match, Rivera byline and MedicalWebPage schema' );
}
if ( '' === $valoracion_desc
	|| ! str_contains( $valoracion_desc, 'Sin Anestesia General' )
	|| ! str_contains( $valoracion_desc, 'Recuperación en 48h' )
	|| ! str_contains( $valoracion_desc, 'reincorporación habitual' )
	|| ! str_contains( $valoracion_desc, 'según protocolo' ) ) {
	$fail( 'valoracion SEO metadata must keep RSA phrases with the 48h reincorporation caveat' );
}

// Aislar el bloque @media (max-width: 48rem) con conteo de llaves balanceadas.
$mobile_css = '';
$anchor = strpos( $valoracion_css, '@media (max-width: 48rem)' );
if ( false !== $anchor ) {
	$brace_open = strpos( $valoracion_css, '{', $anchor );
	if ( false !== $brace_open ) {
		$depth = 0;
		$len   = strlen( $valoracion_css );
		for ( $i = $brace_open; $i < $len; $i++ ) {
			$ch = $valoracion_css[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					$mobile_css = substr( $valoracion_css, $brace_open + 1, $i - $brace_open - 1 );
					break;
				}
			}
		}
	}
}
if ( '' === $mobile_css ) {
	$fail( 'valoracion CSS must contain @media (max-width: 48rem) block' );
}
if ( ! str_contains( $mobile_css, '.nvx-valoracion-hero .nvx-brand-hero__copy' )
	|| ! str_contains( $mobile_css, '.nvx-valoracion-hero__proof' )
	|| ! str_contains( $mobile_css, '.nvx-valoracion-page .nvx-hs-native-section' ) ) {
	$fail( 'valoracion mobile ATF rules must compact the hero and surface the form' );
}

if ( ! str_contains( $helpers, 'function nvx_inject_clinical_authority_byline' )
	|| ! str_contains( $helpers, 'endolaser-corporal' )
	|| ! str_contains( $sede, 'nvx_clinical_authority_byline_markup' )
	|| ! str_contains( $endolift_php, 'nvx_clinical_authority_byline_markup' ) ) {
	$fail( 'Endolift, Endoláser and Chamberí must carry the Rivera clinical signature' );
}

$papada_json = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/nvx-signature-phase-catalog.json' );
$papada_php  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-signature-phase-pages.php' );
$journal_lipo = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/journal-laserlipolisis-vs-liposuccion.json' );
$journal_php  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-journal-laserlipolisis-vs-lipo.php' );
$laser_json   = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/laser-medicine-page.json' );
if ( ! str_contains( $journal_lipo, 'Laserlipólisis DEKA' )
	|| ! str_contains( $journal_lipo, 'Endolift®' )
	|| ! str_contains( $journal_lipo, 'EXION®' )
	|| str_contains( $journal_lipo, 'Smartlipo' )
	|| ! str_contains( $journal_php, 'NVX_JOURNAL_LASERLIPO_VS_LIPO_SLUG' )
	|| ! str_contains( $blog_php, 'laserlipolisis-vs-liposuccion' )
	|| ! str_contains( $blog_meta, '"laserlipolisis-vs-liposuccion"' )
	|| ! str_contains( $laser_json, 'Laserlipólisis DEKA' ) ) {
	$fail( 'laserlipolysis journal must name DEKA/Endolift/EXION, skip Smartlipo, and link the Endoláser ficha' );
}

$smartlipo_json = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/data/journal-smartlipo-laserlipolisis-endolift.json' );
if ( ! str_contains( $smartlipo_json, 'Nd:YAG' )
	|| ! str_contains( $smartlipo_json, '1064' )
	|| ! str_contains( $smartlipo_json, '1470' )
	|| ! str_contains( $smartlipo_json, 'Endolift®' )
	|| ! str_contains( $smartlipo_json, 'Kim KH, Geronemus RG' )
	|| ! str_contains( $journal_php, 'NVX_JOURNAL_SMARTLIPO_ENDOLIFT_SLUG' )
	|| ! str_contains( $blog_meta, '"smartlipo-laserlipolisis-endolift"' )
	|| ! str_contains( $endolift_php, '/smartlipo-laserlipolisis-endolift/' ) ) {
	$fail( 'Smartlipo journal must compare 1064 vs Endolift 1470, cite the literature and link both fichas' );
}

if ( ! str_contains( $papada_json, 'diagnóstico médico antes de indicar' )
	|| ! str_contains( $papada_json, '/endolift-facial-papada-mandibula/' )
	|| ! str_contains( $papada_json, 'ficha del Endolift® facial en Madrid' )
	|| ! str_contains( $papada_php, 'nvx_clinical_authority_byline_markup' )
	|| ! str_contains( $papada_php, 'function nvx_papada_hub_schema_graph' )
	|| ! str_contains( $papada_php, 'DiagnosticProcedure' )
	|| ! str_contains( $blog_php, 'papada-sin-cirugia-madrid-opciones-endolift' ) ) {
	$fail( 'papada hub must stay a decision page, sign Rivera, schema without Endolift procedure, and link the ficha' );
}

$governance = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-native-style-governance.php' );
$aesthetic  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-aesthetic-medicine-page.php' );
$signature  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/inc/nvx-signature-phase-pages.php' );
$solutions  = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/template-parts/content/nvx-soluciones-medicas.php' );
$valoracion = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/templates/page-landing-valoracion.php' );
$shell      = (string) file_get_contents( $root . '/wp-content/themes/nuvanx-medical/template-parts/content/nvx-page-shell.php' );

if ( ! str_contains( $endolift_php, 'must not block the theme renderer' ) ) {
	$fail( 'endolift detector must ignore CMS editorial comments' );
}

if ( str_contains( $shell, '$is_exion_btl' ) ) {
	$fail( 'page shell must not force a prose wrapper on EXION BTL' );
}

if ( ! str_contains( $aesthetic, "nvx_schema_path_matches( \$path, '/medicina-estetica/' )" )
	|| str_contains( $aesthetic, "entry-content " . PROSE_WRAPPER ) ) {
	$fail( 'aesthetic hub must be path-owned and emit no outer nvx-prose' );
}

if ( ! str_contains( $governance, 'nvx-aes-section' ) ) {
	$fail( 'prose normalizer must accept aes-section component pages' );
}

if ( ! str_contains( $signature, "return 'nvx_signature_phase_pages'" )
	|| str_contains( $signature, PROSE_WRAPPER ) ) {
	$fail( 'signature pages must declare owner and drop the prose wrapper' );
}

if ( str_contains( $solutions, PROSE_WRAPPER )
	|| str_contains( $valoracion, PROSE_WRAPPER ) ) {
	$fail( 'soluciones and valoracion templates must not emit the conflicting wrapper' );
}

$photos = array(
	'chamberi/01-interior.jpg',
	'chamberi/02-sala.jpg',
	'chamberi/03-consulta-rivera.jpg',
);
foreach ( $photos as $photo ) {
	$path = $root . '/wp-content/themes/nuvanx-medical/assets/images/clinics/' . $photo;
	if ( ! is_readable( $path ) || filesize( $path ) < 10000 ) {
		$fail( 'missing clinic photo ' . $photo );
	}
}

echo 'PRIORITY_LANDING_CONTRACT=PASS' . PHP_EOL;
echo 'EXILITE_CANNIBALIZATION_CONTRACT=PASS' . PHP_EOL;
