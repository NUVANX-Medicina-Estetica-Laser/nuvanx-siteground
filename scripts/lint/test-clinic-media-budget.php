<?php
/**
 * Clinic media transfer-budget regression gate.
 *
 * Every theme-owned file below the clinic-media directory must stay at or below
 * 500 KiB. The three oversized files already present on the 2026-08-23 baseline
 * are explicit, capped legacy exceptions so they cannot grow and must be removed
 * from this allowlist when they are optimized or retired.
 */

declare(strict_types=1);

$root       = dirname(__DIR__, 2);
$asset_root = $root . '/wp-content/themes/nuvanx-medical/assets/images/clinics';
$budget     = 500 * 1024;

$legacy_exceptions = array(
	'chamberi/05-laser-detalle.jpg'  => 573113,
	'chamberi/06-retrato-rivera.jpg' => 627173,
	'goya/01-fachada.jpg'            => 1053292,
);

$fail = static function ( string $reason ): never {
	fwrite(STDERR, "CLINIC_MEDIA_BUDGET=FAIL reason={$reason}\n");
	exit(1);
};

if (is_link($asset_root)) {
	$fail('asset_root_symlink_forbidden');
}
if (!is_dir($asset_root)) {
	$fail('asset_root_missing');
}

$seen      = array();
$oversized = array();
$scanned   = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($asset_root, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
	$pathname = $item->getPathname();
	$relative = ltrim(str_replace('\\', '/', substr($pathname, strlen($asset_root))), '/');

	if ($item->isLink()) {
		$fail('symlink_forbidden:' . $relative);
	}
	if (!$item->isFile()) {
		continue;
	}

	// The directory is media-only. Govern every regular file rather than an
	// extension allowlist so SVG, future formats, or renamed payloads cannot
	// bypass the transfer budget.
	++$scanned;
	$size            = $item->getSize();
	$seen[$relative] = $size;

	if ($size <= $budget) {
		if (array_key_exists($relative, $legacy_exceptions)) {
			$fail('stale_legacy_exception_below_budget:' . $relative . ':bytes=' . $size);
		}
		continue;
	}

	if (!array_key_exists($relative, $legacy_exceptions)) {
		$fail('new_oversized_asset:' . $relative . ':bytes=' . $size . ':budget=' . $budget);
	}

	$legacy_cap = $legacy_exceptions[$relative];
	if ($size > $legacy_cap) {
		$fail('legacy_asset_grew:' . $relative . ':bytes=' . $size . ':cap=' . $legacy_cap);
	}

	$oversized[$relative] = $size;
}

if (0 === $scanned) {
	$fail('no_clinic_media_scanned');
}

foreach ($legacy_exceptions as $relative => $legacy_cap) {
	if (!array_key_exists($relative, $seen)) {
		$fail('legacy_exception_missing:' . $relative);
	}
	if ($seen[$relative] <= $budget) {
		$fail('stale_legacy_exception_below_budget:' . $relative . ':bytes=' . $seen[$relative]);
	}
	if (!array_key_exists($relative, $oversized)) {
		$fail('legacy_exception_not_accounted:' . $relative);
	}
}

ksort($oversized, SORT_STRING);
foreach ($oversized as $relative => $size) {
	echo 'CLINIC_MEDIA_BUDGET_LEGACY path=' . $relative . ' bytes=' . $size . ' cap=' . $legacy_exceptions[$relative] . PHP_EOL;
}

echo 'CLINIC_MEDIA_BUDGET=PASS budget_bytes=' . $budget
	. ' scanned=' . $scanned
	. ' legacy_oversized=' . count($oversized)
	. PHP_EOL;
