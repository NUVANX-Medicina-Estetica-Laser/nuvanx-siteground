<?php
/**
 * Repository-owned WordPress security contract.
 *
 * Replaces the WordPress-specific security coverage that disappeared when the
 * WPCS dependency chain was removed. The contract is deliberately fail-closed:
 * it runs executable negative fixtures, scans theme PHP output sinks, and
 * requires nonce verification before mutating request handlers.
 */

declare(strict_types=1);

function nvx_wpsec_fail(string $message): never {
    fwrite(STDERR, 'WORDPRESS_SECURITY_CONTRACT=FAIL ' . $message . PHP_EOL);
    exit(1);
}

/**
 * @return array<int, array{id:int|null,text:string,line:int}>
 */
function nvx_wpsec_tokens(string $source): array {
    $raw = token_get_all($source);
    $line = 1;
    $tokens = array();

    foreach ($raw as $token) {
        if (is_array($token)) {
            $tokens[] = array(
                'id'   => $token[0],
                'text' => $token[1],
                'line' => $token[2],
            );
            $line = $token[2] + substr_count($token[1], "\n");
            continue;
        }

        $tokens[] = array(
            'id'   => null,
            'text' => $token,
            'line' => $line,
        );
        $line += substr_count($token, "\n");
    }

    return $tokens;
}

function nvx_wpsec_has_escape_ignore(array $lines, int $line): bool {
    $start = max(1, $line - 1);
    for ($index = $start; $index <= $line; $index++) {
        $text = $lines[$index - 1] ?? '';
        if (
            str_contains($text, 'phpcs:ignore WordPress.Security.EscapeOutput')
            || str_contains($text, 'phpcs:disable WordPress.Security.EscapeOutput')
        ) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<int, array{id:int|null,text:string,line:int}> $statement
 */
function nvx_wpsec_statement_text(array $statement): string {
    return implode('', array_map(
        static fn(array $token): string => $token['text'],
        $statement
    ));
}

/**
 * Return true when a superglobal token is not nested inside an approved
 * output-sanitizing/escaping function in the current output expression.
 *
 * @param array<int, array{id:int|null,text:string,line:int}> $statement
 */
function nvx_wpsec_has_raw_superglobal(array $statement): bool {
    $safeFunctions = array(
        'absint',
        'esc_attr',
        'esc_html',
        'esc_js',
        'esc_textarea',
        'esc_url',
        'esc_url_raw',
        'htmlspecialchars',
        'intval',
        'number_format_i18n',
        'rawurlencode',
        'sanitize_html_class',
        'wp_json_encode',
        'wp_kses',
        'wp_kses_post',
    );
    $stack = array();
    $pendingFunction = null;

    foreach ($statement as $index => $token) {
        $id = $token['id'];
        $text = $token['text'];

        if (T_STRING === $id) {
            $next = $index + 1;
            while (
                isset($statement[$next])
                && in_array($statement[$next]['id'], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
            ) {
                $next++;
            }
            if (isset($statement[$next]) && '(' === $statement[$next]['text']) {
                $pendingFunction = strtolower($text);
            }
        }

        if ('(' === $text) {
            $stack[] = $pendingFunction;
            $pendingFunction = null;
            continue;
        }

        if (')' === $text) {
            array_pop($stack);
            continue;
        }

        if (T_VARIABLE !== $id) {
            continue;
        }

        if (!in_array($text, array('$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES'), true)) {
            continue;
        }

        $protected = false;
        foreach ($stack as $call) {
            if (null !== $call && in_array($call, $safeFunctions, true)) {
                $protected = true;
                break;
            }
        }

        if (!$protected) {
            return true;
        }
    }

    return false;
}

function nvx_wpsec_is_direct_dynamic_output(string $statementText): bool {
    $statementText = preg_replace('/^\s*(?:echo|print)\s+/i', '', trim($statementText)) ?? '';
    $statementText = rtrim(trim($statementText), ';');
    $statementText = trim($statementText);

    return 1 === preg_match(
        '/^\(*\s*(?:(?:\(string\)|\(int\)|\(float\))\s*)?\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:(?:->|::)[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*|\[[^\]]+\])*\s*\)*$/u',
        $statementText
    );
}

/**
 * @return list<string>
 */
function nvx_wpsec_output_violations(string $source, string $label): array {
    $tokens = nvx_wpsec_tokens($source);
    $lines = preg_split('/\R/', $source) ?: array();
    $violations = array();

    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];
        $isEcho = T_ECHO === $token['id']
            || T_PRINT === $token['id']
            || (defined('T_OPEN_TAG_WITH_ECHO') && T_OPEN_TAG_WITH_ECHO === $token['id']);
        if (!$isEcho) {
            continue;
        }

        $line = $token['line'];
        $statement = array($token);
        $depth = 0;

        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $part = $tokens[$cursor];
            $statement[] = $part;

            if (in_array($part['text'], array('(', '[', '{'), true)) {
                $depth++;
            } elseif (in_array($part['text'], array(')', ']', '}'), true)) {
                $depth = max(0, $depth - 1);
            }

            if (0 === $depth && (';' === $part['text'] || T_CLOSE_TAG === $part['id'])) {
                $index = $cursor;
                break;
            }
        }

        if (nvx_wpsec_has_escape_ignore($lines, $line)) {
            continue;
        }

        $statementText = nvx_wpsec_statement_text($statement);
        if (nvx_wpsec_has_raw_superglobal($statement)) {
            $violations[] = $label . ':' . $line . ':raw_superglobal_output';
            continue;
        }

        if (nvx_wpsec_is_direct_dynamic_output($statementText)) {
            $violations[] = $label . ':' . $line . ':unescaped_dynamic_output';
        }
    }

    return $violations;
}

/**
 * @return array<string, array{body:string,line:int}>
 */
function nvx_wpsec_named_functions(string $source): array {
    $tokens = nvx_wpsec_tokens($source);
    $functions = array();
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (T_FUNCTION !== $tokens[$index]['id']) {
            continue;
        }

        $cursor = $index + 1;
        while (
            $cursor < $count
            && in_array($tokens[$cursor]['id'], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
        ) {
            $cursor++;
        }
        if ($cursor < $count && '&' === $tokens[$cursor]['text']) {
            $cursor++;
            while ($cursor < $count && T_WHITESPACE === $tokens[$cursor]['id']) {
                $cursor++;
            }
        }

        if ($cursor >= $count || T_STRING !== $tokens[$cursor]['id']) {
            continue;
        }

        $name = $tokens[$cursor]['text'];
        $line = $tokens[$cursor]['line'];

        while ($cursor < $count && '{' !== $tokens[$cursor]['text']) {
            $cursor++;
        }
        if ($cursor >= $count) {
            continue;
        }

        $depth = 1;
        $body = '';
        for ($cursor++; $cursor < $count && $depth > 0; $cursor++) {
            $part = $tokens[$cursor];
            if ('{' === $part['text']) {
                $depth++;
            } elseif ('}' === $part['text']) {
                $depth--;
                if (0 === $depth) {
                    break;
                }
            }
            $body .= $part['text'];
        }

        $functions[$name] = array('body' => $body, 'line' => $line);
    }

    return $functions;
}

/**
 * @return list<array{hook:string,callback:string,line:int}>
 */
function nvx_wpsec_action_callbacks(string $source): array {
    $matches = array();
    preg_match_all(
        "/add_action\s*\(\s*(['\"])(?<hook>(?:wp_ajax(?:_nopriv)?_|admin_post(?:_nopriv)?_)[^'\"]+|template_redirect)\\1\s*,\s*(['\"])(?<callback>[A-Za-z_][A-Za-z0-9_]*)\\3/s",
        $source,
        $matches,
        PREG_OFFSET_CAPTURE
    );

    $result = array();
    foreach ($matches[0] ?? array() as $index => $whole) {
        $offset = $whole[1];
        $result[] = array(
            'hook'     => $matches['hook'][$index][0],
            'callback' => $matches['callback'][$index][0],
            'line'     => 1 + substr_count(substr($source, 0, $offset), "\n"),
        );
    }

    return $result;
}

function nvx_wpsec_first_mutation_offset(string $body): ?int {
    $sinks = array(
        'add_option',
        'delete_option',
        'delete_post_meta',
        'delete_transient',
        'set_transient',
        'update_option',
        'update_post_meta',
        'wp_delete_post',
        'wp_insert_post',
        'wp_mail',
        'wp_redirect',
        'wp_remote_post',
        'wp_remote_request',
        'wp_safe_redirect',
        'wp_update_post',
    );

    $first = null;
    foreach ($sinks as $sink) {
        $offset = strpos($body, $sink . '(');
        if (false !== $offset && (null === $first || $offset < $first)) {
            $first = $offset;
        }
    }
    return $first;
}

function nvx_wpsec_first_capability_guard_offset(string $body): ?int {
    $matches = array();
    if ( preg_match( '/if\s*\(\s*!\s*current_user_can\s*\([^)]*\)\s*\)\s*(?:\{\s*(?:return|exit|die|wp_die)\b[^{};]*;\s*\}|(?:return|exit|die|wp_die)\b[^;]*;)/', $body, $matches, PREG_OFFSET_CAPTURE ) ) {
        return $matches[0][1];
    }
    return null;
}

function nvx_wpsec_first_nonce_offset(string $body): ?int {
    $checks = array('check_admin_referer(', 'check_ajax_referer(', 'wp_verify_nonce(');
    $first = null;
    foreach ($checks as $check) {
        $offset = strpos($body, $check);
        if (false !== $offset && (null === $first || $offset < $first)) {
            $first = $offset;
        }
    }
    return $first;
}

/**
 * @return list<string>
 */
function nvx_wpsec_auth_violations(string $source, string $label): array {
    $functions = nvx_wpsec_named_functions($source);
    $violations = array();

    foreach (nvx_wpsec_action_callbacks($source) as $action) {
        $function = $functions[$action['callback']] ?? null;
        if (null === $function) {
            continue;
        }

        $body = $function['body'];
        $readsRequestData = str_contains($body, '$_POST')
            || str_contains($body, '$_REQUEST')
            || str_contains($body, '$_GET');
        $mutationOffset = nvx_wpsec_first_mutation_offset($body);
        if (!$readsRequestData || null === $mutationOffset) {
            continue;
        }

        $isAdminAction = str_starts_with($action['hook'], 'admin_post_') || str_starts_with($action['hook'], 'wp_ajax_');
        $isNoPrivAjax  = str_starts_with($action['hook'], 'wp_ajax_nopriv_');

        if ($isAdminAction && !$isNoPrivAjax) {
            $capOffset = nvx_wpsec_first_capability_guard_offset($body);
            if (null === $capOffset) {
                $violations[] = $label . ':' . $function['line'] . ':missing_capability:' . $action['callback'];
            } elseif ($capOffset > $mutationOffset) {
                $violations[] = $label . ':' . $function['line'] . ':late_capability:' . $action['callback'];
            }
        }

        $nonceOffset = nvx_wpsec_first_nonce_offset($body);
        if (null === $nonceOffset) {
            $violations[] = $label . ':' . $function['line'] . ':missing_nonce:' . $action['callback'];
            continue;
        }

        if ($nonceOffset > $mutationOffset) {
            $violations[] = $label . ':' . $function['line'] . ':late_nonce:' . $action['callback'];
        }
    }

    return $violations;
}

/**
 * @return list<string>
 */
function nvx_wpsec_template_redirect_contract(string $source, string $label): array {
    $functions = nvx_wpsec_named_functions($source);
    $violations = array();

    foreach (nvx_wpsec_action_callbacks($source) as $action) {
        if ('template_redirect' !== $action['hook']) {
            continue;
        }
        $function = $functions[$action['callback']] ?? null;
        if (null === $function) {
            continue;
        }

        $body = $function['body'];
        if (str_contains($body, '_wp_page_template') && (str_contains($body, 'update_post_meta') || str_contains($body, 'update_metadata'))) {
            $violations[] = $label . ':' . $function['line'] . ':illegal_template_migration_in_template_redirect:' . $action['callback'];
        }
    }
    return $violations;
}

/**
 * @return list<string>
 */
function nvx_wpsec_valoracion_nonce_contract(string $source, string $label): array {
    $functions = nvx_wpsec_named_functions($source);
    $handler = $functions['nvx_valoracion_maybe_handle_direct_submit'] ?? null;
    if (null === $handler) {
        return array($label . ':missing_valoracion_handler');
    }

    $body = $handler['body'];
    if (!str_contains($body, '$_POST')) {
        return array($label . ':' . $handler['line'] . ':valoracion_post_guard_missing');
    }

    $nonceOffset = nvx_wpsec_first_nonce_offset($body);
    if (null === $nonceOffset) {
        return array($label . ':' . $handler['line'] . ':valoracion_nonce_missing');
    }

    $sensitiveOffsets = array();
    foreach (
        array(
            'set_transient(',
            'nvx_valoracion_forward_to_hubspot(',
            "wp_safe_redirect( home_url( '/gracias/' )",
        ) as $needle
    ) {
        $offset = strpos($body, $needle);
        if (false !== $offset) {
            $sensitiveOffsets[] = $offset;
        }
    }

    if (array() === $sensitiveOffsets) {
        return array($label . ':' . $handler['line'] . ':valoracion_mutation_sink_missing');
    }

    if ($nonceOffset > min($sensitiveOffsets)) {
        return array($label . ':' . $handler['line'] . ':valoracion_nonce_after_mutation');
    }

    return array();
}

function nvx_wpsec_run_self_tests(): void {
    $unsafeOutput = "<?php\n\$unsafe = 'x';\necho \$unsafe;\n";
    $escapedOutput = "<?php\n\$safe = 'x';\necho esc_html( \$safe );\n";
    $waivedOutput = "<?php\n\$html = '<strong>x</strong>';\n// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reviewed safe HTML builder.\necho \$html;\n";
    $rawRequestOutput = "<?php\necho \$_GET['x'];\n";
    $escapedRequestOutput = "<?php\necho esc_html( sanitize_text_field( wp_unslash( \$_GET['x'] ) ) );\n";

    if (array() === nvx_wpsec_output_violations($unsafeOutput, 'fixture-unsafe-output')) {
        nvx_wpsec_fail('self_test_expected_unescaped_output_failure');
    }
    if (array() !== nvx_wpsec_output_violations($escapedOutput, 'fixture-escaped-output')) {
        nvx_wpsec_fail('self_test_escaped_output_false_positive');
    }
    if (array() !== nvx_wpsec_output_violations($waivedOutput, 'fixture-waived-output')) {
        nvx_wpsec_fail('self_test_reviewed_output_waiver_false_positive');
    }
    if (array() === nvx_wpsec_output_violations($rawRequestOutput, 'fixture-raw-request-output')) {
        nvx_wpsec_fail('self_test_expected_raw_request_output_failure');
    }
    if (array() !== nvx_wpsec_output_violations($escapedRequestOutput, 'fixture-escaped-request-output')) {
        nvx_wpsec_fail('self_test_escaped_request_output_false_positive');
    }

    $missingNonce = <<<'PHP'
<?php
function save_handler(): void {
    if (!current_user_can('manage_options')) return;
    if (empty($_POST['save'])) return;
    update_option('x', $_POST['save']);
}
add_action('admin_post_nvx_save', 'save_handler');
PHP;
    $validNonce = <<<'PHP'
<?php
function save_handler(): void {
    if (!current_user_can('manage_options')) return;
    check_admin_referer('nvx_save');
    if (empty($_POST['save'])) return;
    update_option('x', sanitize_text_field(wp_unslash($_POST['save'])));
}
add_action('admin_post_nvx_save', 'save_handler');
PHP;
    $lateNonce = <<<'PHP'
<?php
function save_handler(): void {
    if (!current_user_can('manage_options')) return;
    if (empty($_POST['save'])) return;
    update_option('x', sanitize_text_field(wp_unslash($_POST['save'])));
    check_admin_referer('nvx_save');
}
add_action('admin_post_nvx_save', 'save_handler');
PHP;
    $missingCap = <<<'PHP'
<?php
function save_handler(): void {
    check_admin_referer('nvx_save');
    if (empty($_POST['save'])) return;
    update_option('x', sanitize_text_field(wp_unslash($_POST['save'])));
}
add_action('admin_post_nvx_save', 'save_handler');
PHP;
    $lateCap = <<<'PHP'
<?php
function save_handler(): void {
    check_admin_referer('nvx_save');
    if (empty($_POST['save'])) return;
    update_option('x', sanitize_text_field(wp_unslash($_POST['save'])));
    if (!current_user_can('manage_options')) return;
}
add_action('admin_post_nvx_save', 'save_handler');
PHP;

    if (array() === nvx_wpsec_auth_violations($missingNonce, 'fixture-missing-nonce')) {
        nvx_wpsec_fail('self_test_expected_missing_nonce_failure');
    }
    if (array() !== nvx_wpsec_auth_violations($validNonce, 'fixture-valid-nonce')) {
        nvx_wpsec_fail('self_test_valid_nonce_false_positive');
    }
    if (array() === nvx_wpsec_auth_violations($lateNonce, 'fixture-late-nonce')) {
        nvx_wpsec_fail('self_test_expected_late_nonce_failure');
    }
    $ignoredCap = <<<'PHP'
<?php
function save_handler(): void {
    current_user_can('manage_options');
    check_admin_referer('nvx_save');
    if (empty($_POST['save'])) return;
    update_option('x', sanitize_text_field(wp_unslash($_POST['save'])));
}
add_action('admin_post_nvx_save', 'save_handler');
PHP;

    $nestedCap = <<<'PHP'
<?php
function save_handler(): void {
    if (!current_user_can('manage_options')) {
        if (isset($_POST['confirm'])) return;
    }
    check_admin_referer('nvx_save');
    update_option('x', sanitize_text_field(wp_unslash($_POST['save'])));
}
add_action('admin_post_nvx_save', 'save_handler');
PHP;

    if (array() === nvx_wpsec_auth_violations($ignoredCap, 'fixture-ignored-cap')) {
        nvx_wpsec_fail('self_test_expected_ignored_cap_failure');
    }
    if (array() === nvx_wpsec_auth_violations($nestedCap, 'fixture-nested-cap')) {
        nvx_wpsec_fail('self_test_expected_nested_cap_failure');
    }
    if (array() === nvx_wpsec_auth_violations($missingCap, 'fixture-missing-cap')) {
        nvx_wpsec_fail('self_test_expected_missing_cap_failure');
    }
    if (array() === nvx_wpsec_auth_violations($lateCap, 'fixture-late-cap')) {
        nvx_wpsec_fail('self_test_expected_late_cap_failure');
    }

    echo 'WORDPRESS_SECURITY_SELF_TEST=PASS cases=12' . PHP_EOL;
}

nvx_wpsec_run_self_tests();

if (in_array('--self-test-only', $argv ?? array(), true)) {
    exit(0);
}

$root = dirname(__DIR__, 2);
$theme = $root . '/wp-content/themes/nuvanx-medical';
$realTheme = realpath($theme);
if (false === $realTheme || !is_dir($realTheme)) {
    nvx_wpsec_fail('theme_root_missing');
}

$violations = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($realTheme, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || 'php' !== strtolower($file->getExtension())) {
        continue;
    }

    $path = $file->getRealPath();
    if (false === $path) {
        nvx_wpsec_fail('realpath_failed');
    }
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    if (!str_starts_with($path, $realTheme . DIRECTORY_SEPARATOR)) {
        nvx_wpsec_fail('theme_path_escape');
    }

    $source = file_get_contents($path);
    if (false === $source) {
        nvx_wpsec_fail('read_failed:' . $path);
    }

    $label = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    array_push($violations, ...nvx_wpsec_output_violations($source, $label));
    array_push($violations, ...nvx_wpsec_auth_violations($source, $label));
    array_push($violations, ...nvx_wpsec_template_redirect_contract($source, $label));
}

$valoracionPath = $realTheme . '/inc/nvx-valoracion-direct-form.php';
$valoracion = file_get_contents($valoracionPath);
if (false === $valoracion) {
    nvx_wpsec_fail('valoracion_contract_source_missing');
}
array_push(
    $violations,
    ...nvx_wpsec_valoracion_nonce_contract(
        $valoracion,
        'wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php'
    )
);

if (array() !== $violations) {
    foreach (array_values(array_unique($violations)) as $violation) {
        fwrite(STDERR, 'WORDPRESS_SECURITY_VIOLATION ' . $violation . PHP_EOL);
    }
    nvx_wpsec_fail('violations=' . count(array_unique($violations)));
}

echo 'WORDPRESS_SECURITY_OUTPUT_ESCAPING=PASS' . PHP_EOL;
echo 'WORDPRESS_SECURITY_NONCE_VERIFICATION=PASS' . PHP_EOL;
echo 'WORDPRESS_SECURITY_VALORACION_POST=PASS' . PHP_EOL;
echo 'WORDPRESS_SECURITY_CONTRACT=PASS' . PHP_EOL;
