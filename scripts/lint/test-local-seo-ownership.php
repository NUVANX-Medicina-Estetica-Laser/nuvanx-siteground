<?php
/**
 * Blocking contract for local-intent landing ownership and governed clinic hours.
 *
 * The public clinic configuration, Schema fallbacks and SEO metadata must agree
 * with the API/business-owner truth in gbp-profiles.json, and each clinic landing
 * must explicitly own its local search intent instead of relying on the home page.
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$fail = static function ( string $message ): void {
	fwrite( STDERR, 'LOCAL_SEO_OWNERSHIP_TEST=FAIL ' . $message . PHP_EOL );
	exit( 1 );
};

$data_path     = $root . '/wp-content/themes/nuvanx-medical/inc/data/gbp-profiles.json';
$seo_path      = $root . '/wp-content/themes/nuvanx-medical/inc/data/seo-metadata.json';
$config_path   = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-business-config.php';
$schema_path   = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-schema-foundation.php';
$landing_path  = $root . '/wp-content/themes/nuvanx-medical/templates/page-sede.php';
$profiles_raw  = file_get_contents( $data_path );
$seo_raw       = file_get_contents( $seo_path );
$config_source = file_get_contents( $config_path );
$schema_source = file_get_contents( $schema_path );
$landing       = file_get_contents( $landing_path );

if (
	false === $profiles_raw
	|| false === $seo_raw
	|| false === $config_source
	|| false === $schema_source
	|| false === $landing
) {
	$fail( 'unreadable local SEO contract source' );
}

$profiles = json_decode( $profiles_raw, true );
$seo      = json_decode( $seo_raw, true );
if ( ! is_array( $profiles ) || ! is_array( $seo ) ) {
	$fail( 'governed local SEO JSON is invalid' );
}

if ( 'business_owner_confirmed_2026-08-24' !== ( $profiles['source_of_truth']['business_hours_status'] ?? null ) ) {
	$fail( 'GBP regular-hours truth is not owner-confirmed' );
}

/** Extract one clinic's source block so another branch cannot satisfy its assertions. */
$clinic_source_block = static function ( string $source, string $clinic, ?string $next_clinic ) use ( $fail ): string {
	$start = strpos( $source, "'{$clinic}'" );
	if ( false === $start ) {
		$fail( 'missing public clinic config block: ' . $clinic );
	}
	if ( null !== $next_clinic ) {
		$end = strpos( $source, "'{$next_clinic}'", $start + 1 );
	} else {
		$end = strpos( $source, "\n\t);", $start );
	}
	if ( false === $end || $end <= $start ) {
		$fail( 'unable to isolate public clinic config block: ' . $clinic );
	}
	return substr( $source, $start, $end - $start );
};

$expected = array(
	'chamberi' => array(
		'display' => 'lunes a sábado, 10:00–20:00',
		'opens'   => '10:00',
		'closes'  => '20:00',
		'next'    => 'goya',
	),
	'goya' => array(
		'display' => 'lunes a sábado, 11:00–20:00',
		'opens'   => '11:00',
		'closes'  => '20:00',
		'next'    => null,
	),
);

$weekdays = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
foreach ( $expected as $clinic => $hours ) {
	$profile = $profiles['clinics'][ $clinic ] ?? null;
	if ( ! is_array( $profile ) ) {
		$fail( 'missing GBP clinic profile: ' . $clinic );
	}
	if ( 'business_owner_confirmed_2026-08-24' !== ( $profile['regular_hours_status'] ?? null ) ) {
		$fail( 'clinic regular hours are not owner-confirmed: ' . $clinic );
	}
	foreach ( $weekdays as $day ) {
		$expected_value = $hours['opens'] . '-' . $hours['closes'];
		if ( $expected_value !== ( $profile['regular_hours'][ $day ] ?? null ) ) {
			$fail( sprintf( 'GBP hours mismatch clinic=%s day=%s', $clinic, $day ) );
		}
	}
	if ( 'closed' !== ( $profile['regular_hours']['sunday'] ?? null ) ) {
		$fail( 'Sunday must remain closed for ' . $clinic );
	}

	$block = $clinic_source_block( $config_source, $clinic, $hours['next'] );
	if ( ! str_contains( $block, "'hours'         => '" . $hours['display'] . "'" ) ) {
		$fail( 'public display hours drift for ' . $clinic );
	}
	$days_line = "array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' )";
	if ( ! str_contains( $block, $days_line )
		|| ! str_contains( $block, "'opens'  => '" . $hours['opens'] . "'" )
		|| ! str_contains( $block, "'closes' => '" . $hours['closes'] . "'" ) ) {
		$fail( 'OpeningHoursSpecification source drift for ' . $clinic );
	}
}

$schema_start = strpos( $schema_source, 'function nvx_schema_clinics()' );
$schema_end   = strpos( $schema_source, 'function nvx_schema_organization_id()', false === $schema_start ? 0 : $schema_start );
if ( false === $schema_start || false === $schema_end || $schema_end <= $schema_start ) {
	$fail( 'unable to isolate nvx_schema_clinics fallbacks' );
}
$schema_clinics = substr( $schema_source, $schema_start, $schema_end - $schema_start );
$days_line      = "array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' )";

if ( str_contains( $schema_clinics, "'opens'     => '12:00'" )
	|| str_contains( $schema_clinics, "'closes'    => '18:00'" ) ) {
	$fail( 'legacy clinic hours remain reachable through Schema fallback' );
}
if ( substr_count( $schema_clinics, $days_line ) < 2 ) {
	$fail( 'Schema fallbacks must cover Monday through Saturday for both clinics' );
}
if ( ! str_contains( $schema_clinics, "'opens'     => '10:00'" )
	|| ! str_contains( $schema_clinics, "'opens'     => '11:00'" )
	|| substr_count( $schema_clinics, "'closes'    => '20:00'" ) < 2 ) {
	$fail( 'Schema fallback hours drift from governed clinic truth' );
}

$chamberi_meta = $seo['chamberi']['description'] ?? '';
if ( ! is_string( $chamberi_meta )
	|| ! str_contains( $chamberi_meta, 'Lunes a sábado 10:00–20:00' )
	|| str_contains( $chamberi_meta, '12:00–20:00' )
	|| str_contains( $chamberi_meta, '10:00–18:00' ) ) {
	$fail( 'Chamberí SEO metadata hours drift from governed truth' );
}

if ( str_contains( $config_source, 'lunes a viernes, 12:00–20:00; sábados, 10:00–18:00' )
	|| str_contains( $config_source, 'lunes a viernes, 11:00–20:00' ) ) {
	$fail( 'legacy clinic hours reintroduced' );
}

if ( ! str_contains( $landing, 'Medicina estética en Chamberí, Madrid — clínica NUVANX' ) ) {
	$fail( 'Chamberí landing lost explicit local-intent H1' );
}
if ( ! str_contains( $landing, 'Medicina estética en Goya y Barrio de Salamanca — clínica NUVANX' ) ) {
	$fail( 'Goya landing must explicitly own Goya + Barrio de Salamanca intent' );
}
if ( ! str_contains( $landing, 'Clínica de medicina estética láser en Goya, Barrio de Salamanca:' ) ) {
	$fail( 'Goya hero lead must state local medical-aesthetic intent' );
}
if ( ! str_contains( $landing, 'Centro sanitario CS20073.' ) ) {
	$fail( 'Goya landing must retain its sanitary-registration context' );
}

echo 'LOCAL_SEO_OWNERSHIP_TEST=PASS clinics=2 hours=gbp-governed metadata=aligned schema_fallbacks=aligned goya_intent=explicit' . PHP_EOL;
