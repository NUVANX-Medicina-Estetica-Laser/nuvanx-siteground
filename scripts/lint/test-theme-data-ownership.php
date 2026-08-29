<?php
/**
 * Blocking ownership contract for canonical theme data.
 *
 * Product/business truth belongs to the governed JSON registries. PHP may
 * consume those values, but must not redeclare them as literal fallbacks or
 * alternate sources. Inline media error handlers are forbidden as a second
 * presentation/behavior path.
 */

declare(strict_types=1);

$root       = dirname( __DIR__, 2 );
$theme_root = $root . '/wp-content/themes/nuvanx-medical';
$failures   = array();

$read_json = static function ( string $path, string $label ) use ( &$failures ): array {
	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		$failures[] = 'unreadable_registry ' . $label;
		return array();
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		$failures[] = 'invalid_registry_json ' . $label;
		return array();
	}
	return $data;
};

$staff   = $read_json( $theme_root . '/inc/data/medical-staff.json', 'medical-staff' );
$clinics = $read_json( $theme_root . '/inc/data/clinics.json', 'clinics' );
$assets  = $read_json( $theme_root . '/inc/data/clinic-asset-registry.json', 'clinic-assets' );

$string_literals  = array();
$numeric_literals = array();
foreach ( $staff['staff'] ?? array() as $record ) {
	if ( ! is_array( $record ) ) {
		continue;
	}
	foreach ( array( 'colegiado', 'doctoralia_url' ) as $field ) {
		$value = trim( (string) ( $record[ $field ] ?? '' ) );
		if ( '' !== $value ) {
			$string_literals[ $value ] = 'medical_staff_' . $field;
		}
	}
	$profile_media_id = (int) ( $record['profile_media_attachment_id'] ?? 0 );
	if ( $profile_media_id > 0 ) {
		$numeric_literals[ $profile_media_id ] = 'medical_staff_profile_media_attachment_id';
	}
}

$contact_email = trim( (string) ( $clinics['contact_email'] ?? '' ) );
if ( '' !== $contact_email ) {
	$string_literals[ $contact_email ] = 'business_contact_email';
}
foreach ( $clinics['clinics'] ?? array() as $clinic ) {
	if ( ! is_array( $clinic ) ) {
		continue;
	}
	foreach ( array( 'phone', 'phone_href', 'reg', 'address', 'landing_path' ) as $field ) {
		$value = trim( (string) ( $clinic[ $field ] ?? '' ) );
		if ( '' !== $value ) {
			$string_literals[ $value ] = 'clinic_' . $field;
		}
	}
}

$gallery_paths = array();
foreach ( $assets['approved_editorial_overrides']['clinic_landing_galleries'] ?? array() as $gallery ) {
	if ( ! is_array( $gallery ) ) {
		continue;
	}
	foreach ( $gallery as $item ) {
		$path = is_array( $item ) ? trim( (string) ( $item['uploads_path'] ?? '' ) ) : '';
		if ( '' !== $path ) {
			$gallery_paths[] = $path;
		}
	}
}
foreach ( $assets['approved_editorial_overrides']['authorized_partner_marks'] ?? array() as $mark ) {
	$id = is_array( $mark ) ? (int) ( $mark['attachment_id'] ?? 0 ) : 0;
	if ( $id > 0 ) {
		$numeric_literals[ $id ] = 'authorized_partner_attachment_id';
	}
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $theme_root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$path   = $file->getPathname();
	$source = file_get_contents( $path );
	if ( false === $source ) {
		$failures[] = 'unreadable_php ' . str_replace( $root . '/', '', $path );
		continue;
	}
	$relative = str_replace( $root . '/', '', $path );

	// Third-party vendor code is outside theme ownership governance.
	if ( str_contains( $relative, 'vendor/' ) ) {
		continue;
	}

	if ( str_contains( $source, 'Salamanca–Salamanca' ) || str_contains( $source, 'Salamanca-Salamanca' ) || str_contains( $source, "Salamanca–' . \$goya_name" ) ) {
		$failures[] = 'duplicated_clinic_name_prefix file=' . $relative;
	}
	if ( str_contains( $source, 'config.json' ) ) {
		$failures[] = 'deleted_config_reference file=' . $relative;
	}
	if ( preg_match( '/\bonerror\s*=/i', $source ) ) {
		$failures[] = 'inline_onerror_forbidden file=' . $relative;
	}
	foreach ( $string_literals as $literal => $owner ) {
		$needle = (string) $literal;
		if ( '' !== $needle && str_contains( $source, $needle ) ) {
			$failures[] = 'canonical_literal_duplicated owner=' . $owner . ' file=' . $relative;
		}
	}

	// Numeric registry identifiers are ownership values only when PHP declares
	// the exact integer token. Substring matching is invalid here: e.g. partner
	// ID 898 must not flag SVG path decimal 2.898 or unrelated prose.
	if ( array() !== $numeric_literals ) {
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || T_LNUMBER !== $token[0] ) {
				continue;
			}
			$number = (int) $token[1];
			if ( isset( $numeric_literals[ $number ] ) ) {
				$failures[] = 'canonical_literal_duplicated owner=' . $numeric_literals[ $number ] . ' file=' . $relative;
			}
		}
	}

	foreach ( $gallery_paths as $gallery_path ) {
		if ( str_contains( $source, $gallery_path ) ) {
			$failures[] = 'gallery_path_duplicated file=' . $relative;
		}
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, "THEME_DATA_OWNERSHIP_TEST=FAIL\n" . implode( "\n", array_values( array_unique( $failures ) ) ) . "\n" );
	exit( 1 );
}

echo 'THEME_DATA_OWNERSHIP_TEST=PASS registries=3 php_literals=canonical-only media_ids=canonical-only inline_onerror=absent deleted_config=absent' . PHP_EOL;
