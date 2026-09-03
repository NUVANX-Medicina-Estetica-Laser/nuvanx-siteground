<?php
/**
 * Canonical deterministic CSS compiler for the NUVANX medical theme.
 *
 * This compiler is intentionally shipped with the theme so CI, Staging and
 * Production materialize the exact same distribution contract from the exact
 * accepted source tree. dist/ remains generated state and is never a second
 * source of truth.
 *
 * Usage:
 *   php tools/compile-theme-css.php
 *   php tools/compile-theme-css.php --verify-only
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

const NVX_CSS_BUNDLES = array(
    'core' => array(
        'assets/css/nvx-fonts.css',
        'assets/css/nvx-tokens.css',
        'assets/css/nvx-base.css',
        'assets/css/nvx-site-layout.css',
        'assets/css/nvx-components.css',
        'assets/css/nvx-patterns-editorial.css',
        'assets/css/nvx-treatment-authority.css',
        'assets/css/nvx-header.css',
        'assets/css/nvx-footer.css',
        'assets/css/nvx-accessibility-governance.css',
    ),
);

/** Normalize text exactly before hashing or comparing. */
function nvx_css_normalize(string $raw): string {
    return trim(str_replace(array("\r\n", "\r"), "\n", $raw));
}

/** Compute the immutable 10-character SHA-256 content hash. */
function nvx_css_hash(string $content): string {
    return substr(hash('sha256', $content), 0, 10);
}

/** Read a UTF-8 CSS file or fail closed. */
function nvx_css_read(string $path): string {
    $contents = file_get_contents($path);
    if (false === $contents) {
        throw new RuntimeException('Unable to read required CSS source or artifact');
    }
    return nvx_css_normalize($contents);
}

/** Write a generated UTF-8 artifact for isolated release materialization. */
function nvx_css_write(string $path, string $contents): void {
    if (false === file_put_contents($path, $contents)) {
        throw new RuntimeException('Unable to write generated CSS artifact');
    }
}

/** Return sorted source CSS paths relative to the theme root. */
function nvx_css_source_files(string $theme_dir): array {
    $css_dir = $theme_dir . '/assets/css';
    $entries = scandir($css_dir);
    if (false === $entries) {
        throw new RuntimeException('Unable to enumerate CSS source directory');
    }

    $files = array();
    foreach ($entries as $entry) {
        if (str_ends_with($entry, '.css')) {
            $files[] = 'assets/css/' . $entry;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/** Build the deterministic generated distribution. */
function nvx_css_compile(string $theme_dir): array {
    $dist_dir = $theme_dir . '/dist';
    if (!is_dir($dist_dir) && !mkdir($dist_dir, 0775, true) && !is_dir($dist_dir)) {
        throw new RuntimeException('Unable to create CSS distribution directory');
    }

    $existing = scandir($dist_dir);
    if (false === $existing) {
        throw new RuntimeException('Unable to enumerate CSS distribution directory');
    }
    foreach ($existing as $entry) {
        if (str_ends_with($entry, '.css') || 'manifest.json' === $entry) {
            $artifact = $dist_dir . '/' . $entry;
            if (is_file($artifact) && !unlink($artifact)) {
                throw new RuntimeException('Unable to remove stale CSS distribution artifact');
            }
        }
    }

    $source_epoch = getenv('SOURCE_DATE_EPOCH');
    $epoch = (false !== $source_epoch && preg_match('/^-?\d+$/', $source_epoch)) ? (int) $source_epoch : 0;
    $manifest = array(
        'schema'    => 1,
        'generated' => gmdate('Y-m-d\\TH:i:s', $epoch) . '.000Z',
        'bundles'   => array(),
        'files'     => array(),
    );

    $bundled_sources = array();
    foreach (NVX_CSS_BUNDLES as $sources) {
        foreach ($sources as $source) {
            $bundled_sources[$source] = true;
        }
    }

    foreach (nvx_css_source_files($theme_dir) as $relative_source) {
        if (isset($bundled_sources[$relative_source])) {
            continue;
        }
        $content = nvx_css_read($theme_dir . '/' . $relative_source);
        $hash = nvx_css_hash($content);
        $base = basename($relative_source, '.css');
        $dist_name = $base . '.' . $hash . '.css';
        nvx_css_write($dist_dir . '/' . $dist_name, $content . "\n");
        $manifest['files'][$relative_source] = array(
            'file' => $dist_name,
            'hash' => $hash,
            'size' => strlen($content),
        );
    }

    foreach (NVX_CSS_BUNDLES as $bundle_name => $sources) {
        if (count($sources) < 2) {
            throw new RuntimeException('CSS bundle must aggregate at least two sources');
        }
        $parts = array();
        foreach ($sources as $relative_source) {
            $content = nvx_css_read($theme_dir . '/' . $relative_source);
            $parts[] = '/* ' . basename($relative_source) . " */\n" . $content;
        }
        $bundle_content = implode("\n\n", $parts);
        $hash = nvx_css_hash($bundle_content);
        $dist_name = 'nvx-' . $bundle_name . '.' . $hash . '.css';
        nvx_css_write($dist_dir . '/' . $dist_name, $bundle_content . "\n");
        $manifest['bundles'][$bundle_name] = array(
            'file'    => $dist_name,
            'hash'    => $hash,
            'size'    => strlen($bundle_content),
            'sources' => array_values($sources),
        );
    }

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (false === $json) {
        throw new RuntimeException('Unable to encode CSS manifest');
    }
    nvx_css_write($dist_dir . '/manifest.json', $json . "\n");

    echo 'CSS_COMPILATION=PASS bundles=' . count($manifest['bundles'])
        . ' route_files=' . count($manifest['files'])
        . ' dist=wp-content/themes/nuvanx-medical/dist' . PHP_EOL;

    return $manifest;
}

/** Verify complete source coverage and byte/hash integrity of generated dist. */
function nvx_css_verify(string $theme_dir): void {
    $dist_dir = $theme_dir . '/dist';
    $manifest_path = $dist_dir . '/manifest.json';
    $raw = file_get_contents($manifest_path);
    if (false === $raw || '' === trim($raw)) {
        throw new RuntimeException('CSS manifest is missing or unreadable');
    }
    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || 1 !== ($manifest['schema'] ?? null)) {
        throw new RuntimeException('Invalid CSS manifest schema');
    }

    $bundles = $manifest['bundles'] ?? null;
    $files = $manifest['files'] ?? null;
    if (!is_array($bundles) || array('core') !== array_keys($bundles)) {
        throw new RuntimeException('Only the canonical core bundle is permitted');
    }
    if (!is_array($files)) {
        throw new RuntimeException('CSS manifest route files map is missing');
    }

    $core = $bundles['core'];
    $core_sources = $core['sources'] ?? null;
    if (!is_array($core_sources) || count($core_sources) < 2 || count(array_unique($core_sources)) !== count($core_sources)) {
        throw new RuntimeException('Core bundle source contract is invalid');
    }

    $source_files = nvx_css_source_files($theme_dir);
    $route_sources = array_keys($files);
    sort($route_sources, SORT_STRING);
    $overlap = array_intersect($route_sources, $core_sources);
    if (!empty($overlap)) {
        throw new RuntimeException('CSS source has duplicate runtime representation');
    }
    $represented = array_merge($core_sources, $route_sources);
    sort($represented, SORT_STRING);
    if ($represented !== $source_files) {
        throw new RuntimeException('CSS manifest/source coverage mismatch');
    }

    $referenced = array();
    foreach ($bundles as $bundle_name => $info) {
        $file = (string) ($info['file'] ?? '');
        $hash = (string) ($info['hash'] ?? '');
        if ('' === $file || '' === $hash || isset($referenced[$file])) {
            throw new RuntimeException('Invalid or duplicate CSS bundle artifact reference');
        }
        $referenced[$file] = true;
        $parts = array();
        foreach ($info['sources'] as $relative_source) {
            $parts[] = '/* ' . basename($relative_source) . " */\n" . nvx_css_read($theme_dir . '/' . $relative_source);
        }
        $reconstructed = implode("\n\n", $parts);
        $dist_content = nvx_css_read($dist_dir . '/' . $file);
        if (nvx_css_hash($reconstructed) !== $hash || nvx_css_hash($dist_content) !== $hash) {
            throw new RuntimeException('CSS bundle hash mismatch');
        }
        if (!str_contains($file, $hash) || strlen($reconstructed) !== (int) ($info['size'] ?? -1) || $reconstructed !== $dist_content) {
            throw new RuntimeException('CSS bundle reconstruction mismatch');
        }
    }

    foreach ($files as $relative_source => $info) {
        $file = (string) ($info['file'] ?? '');
        $hash = (string) ($info['hash'] ?? '');
        if ('' === $file || '' === $hash || isset($referenced[$file])) {
            throw new RuntimeException('Invalid or duplicate route CSS artifact reference');
        }
        $referenced[$file] = true;
        $source_content = nvx_css_read($theme_dir . '/' . $relative_source);
        $dist_content = nvx_css_read($dist_dir . '/' . $file);
        if ($source_content !== $dist_content || nvx_css_hash($source_content) !== $hash || nvx_css_hash($dist_content) !== $hash) {
            throw new RuntimeException('Route CSS distribution mismatch');
        }
        if (!str_contains($file, $hash) || strlen($source_content) !== (int) ($info['size'] ?? -1)) {
            throw new RuntimeException('Route CSS metadata mismatch');
        }
    }

    $dist_entries = scandir($dist_dir);
    if (false === $dist_entries) {
        throw new RuntimeException('Unable to enumerate generated CSS artifacts');
    }
    $actual_css = array_values(array_filter($dist_entries, static fn(string $entry): bool => str_ends_with($entry, '.css')));
    sort($actual_css, SORT_STRING);
    $expected_css = array_keys($referenced);
    sort($expected_css, SORT_STRING);
    if ($actual_css !== $expected_css) {
        throw new RuntimeException('Generated CSS artifact set contains missing or orphan files');
    }

    echo 'CSS_DISTRIBUTION=PASS bundles=' . count($bundles)
        . ' route_files=' . count($route_sources)
        . ' sources=' . count($source_files)
        . ' runtime_artifacts=' . count($expected_css)
        . ' single_representation=verified hash_integrity=verified orphan_check=clean source_coverage=complete'
        . PHP_EOL;
}

try {
    $theme_dir = realpath(dirname(__DIR__));
    if (false === $theme_dir) {
        throw new RuntimeException('Unable to resolve theme directory');
    }
    $verify_only = in_array('--verify-only', $argv, true);
    if (!$verify_only) {
        nvx_css_compile($theme_dir);
    }
    nvx_css_verify($theme_dir);
} catch (Throwable $error) {
    fwrite(STDERR, 'CSS_DISTRIBUTION=FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
