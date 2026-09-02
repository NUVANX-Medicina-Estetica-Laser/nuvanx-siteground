<?php
/**
 * Integration bootstrap regression contract.
 *
 * PHP syntax validation cannot detect executable code accidentally swallowed by
 * a valid block/doc comment. This contract removes comments from the token
 * stream and then verifies the public Goya/canonical guards remain executable.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-integrations.php';

$source = file_get_contents($file);
if (!is_string($source) || '' === $source) {
    fwrite(STDERR, "INTEGRATIONS_RUNTIME_CONTRACT=FAIL reason=source_unreadable\n");
    exit(1);
}

$executable = '';
foreach (token_get_all($source) as $token) {
    if (is_array($token)) {
        if (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        $executable .= $token[1];
        continue;
    }
    $executable .= $token;
}

$normalized = preg_replace('/\s+/', ' ', $executable);
if (!is_string($normalized)) {
    fwrite(STDERR, "INTEGRATIONS_RUNTIME_CONTRACT=FAIL reason=normalization_failed\n");
    exit(1);
}

$required = array(
    'goya_function' => '/function\s+nvx_theme_is_goya_page\s*\(/',
    'language_filter' => '/add_filter\s*\(\s*[\'\"]language_attributes[\'\"]/',
    'canonical_filter' => '/add_filter\s*\(\s*[\'\"]redirect_canonical[\'\"]/',
    'canonical_remove' => '/remove_action\s*\(\s*[\'\"]template_redirect[\'\"]\s*,\s*[\'\"]redirect_canonical[\'\"]/',
);

$missing = array();
foreach ($required as $name => $pattern) {
    if (1 !== preg_match($pattern, $normalized)) {
        $missing[] = $name;
    }
}

if (1 === preg_match('/function\s+nvx_theme_request_path\s*\(/', $normalized)) {
    fwrite(STDERR, "INTEGRATIONS_RUNTIME_CONTRACT=FAIL reason=request_path_duplicate_owner\n");
    exit(1);
}

if (!empty($missing)) {
    fwrite(STDERR, 'INTEGRATIONS_RUNTIME_CONTRACT=FAIL missing=' . implode(',', $missing) . PHP_EOL);
    exit(1);
}

echo 'INTEGRATIONS_RUNTIME_CONTRACT=PASS goya=executable request_path_owner=theme-request' . PHP_EOL;
