<?php
/**
 * PHPStan runtime model for dynamically defined public medical identity constants.
 *
 * Production values continue to be loaded from inc/data/medical-staff.json through
 * nvx-config-helpers.php. These placeholders exist only while PHPStan boots so the
 * analyser can discover the public constants without duplicating governed values.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

foreach (
	array(
		'NVX_DIRECTOR_COLEGIADO',
		'NVX_IVON_COLEGIADO',
		'NVX_FABIO_COLEGIADO',
		'NVX_CRISTINA_COLEGIADO',
	) as $constant
) {
	if ( ! defined( $constant ) ) {
		define( $constant, '' );
	}
}
