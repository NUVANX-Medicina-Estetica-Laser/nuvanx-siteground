import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

export const SITEGROUND_CAPTCHA_PATH = '/.well-known/sgcaptcha/';
export const SITEGROUND_TRANSIENT_HTTP_STATUSES = new Set([202, 429, 503]);
/** BSD sysexits: EX_USAGE (64) - recovery path is unavailable or not applicable. */
export const EX_NOT_APPLICABLE = 64;
/** BSD sysexits: EX_TEMPFAIL (75) - temporary failure / transient infrastructure challenge (retryable). */
export const EX_TEMPFAIL = 75;
/** BSD sysexits: EX_CONFIG (78) - invalid recovery configuration/identity. */
export const EX_CONFIG = 78;

/** GitHub event names that trigger pipeline execution. */
export const GITHUB_EVENT_NAMES = Object.freeze({
  PULL_REQUEST_TARGET: 'pull_request_target',
  PUSH: 'push',
  WORKFLOW_DISPATCH: 'workflow_dispatch',
});

/** Git ref names for protected branches. */
export const GIT_REF_NAMES = Object.freeze({
  MASTER: 'master',
  MAIN: 'main',
});

/** Execution path identifiers for GitHub Actions. */
export const EXECUTION_PATHS = Object.freeze({
  PULL_REQUEST: 'pull_request',
  ONE_SHOT_MASTER_PUSH: 'one_shot_master_push',
  TRUSTED_WORKFLOW_DISPATCH: 'trusted_workflow_dispatch',
  UNSUPPORTED_EVENT: 'unsupported_event',
});

const STAGING_FATAL_ALLOWED_HOST = 'staging2.nuvanx.com';
const STAGING_FATAL_ALLOWED_ROOT = '/home/customer/www/staging2.nuvanx.com/public_html';
const STAGING_FATAL_ALLOWED_ALIASES = new Set(['nvx-staging2', 'nvx-staging2-pr']);
let stagingFatalCaptureAttempted = false;

function captureStagingFatalDiagnostic(triggerStatus) {
  if (stagingFatalCaptureAttempted || triggerStatus !== 500) return;

  const expectedHost = String(process.env.EXPECTED_HOST || '');
  const sshAlias = String(process.env.ORIGIN_SSH_ALIAS || '');
  const stagingRoot = String(process.env.STAGING_ROOT || '');
  if (
    expectedHost !== STAGING_FATAL_ALLOWED_HOST
    || stagingRoot !== STAGING_FATAL_ALLOWED_ROOT
    || !STAGING_FATAL_ALLOWED_ALIASES.has(sshAlias)
  ) {
    return;
  }

  stagingFatalCaptureAttempted = true;
  const remoteScript = [
    'set -Eeuo pipefail',
    'cd "$STAGING_ROOT"',
    'plugin="wp-content/mu-plugins/nvx-staging-fatal-capture.php"',
    'fatal_file="wp-content/nvx-staging-fatal.json"',
    'mkdir -p wp-content/mu-plugins',
    'cleanup() { rm -f "$plugin" "$fatal_file"; }',
    'trap cleanup EXIT',
    'cat > "$plugin" <<\'PHP\'',
    '<?php',
    'register_shutdown_function(',
    '    static function () {',
    '        $error = error_get_last();',
    '        if ( ! is_array( $error ) ) {',
    '            return;',
    '        }',
    '        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );',
    '        $type = isset( $error[\'type\'] ) ? (int) $error[\'type\'] : 0;',
    '        if ( ! in_array( $type, $fatal_types, true ) ) {',
    '            return;',
    '        }',
    '        $root = defined( \'ABSPATH\' ) ? (string) ABSPATH : \'\';',
    '        $normalize = static function ( $value ) use ( $root ) {',
    '            $value = (string) $value;',
    '            return \'\' !== $root ? str_replace( $root, \'<ABSPATH>/\', $value ) : $value;',
    '        };',
    '        $payload = array(',
    '            \'type\' => $type,',
    '            \'message\' => $normalize( $error[\'message\'] ?? \'\' ),',
    '            \'file\' => $normalize( $error[\'file\'] ?? \'\' ),',
    '            \'line\' => isset( $error[\'line\'] ) ? (int) $error[\'line\'] : 0,',
    '        );',
    '        file_put_contents( WP_CONTENT_DIR . \'/nvx-staging-fatal.json\', json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL, LOCK_EX );',
    '    }',
    ');',
    'PHP',
    'rm -f "$fatal_file"',
    'set +e',
    'request_status="$(curl -4 -k -sS --connect-timeout 10 --max-time 30 --resolve "${EXPECTED_HOST}:443:127.0.0.1" --proto \'=https\' -A \'NUVANX-Staging-Fatal-Capture/1.0\' -H \'Accept: text/html,application/xhtml+xml\' -H \'Cache-Control: no-cache\' -o /dev/null -w \'%{http_code}\' "https://${EXPECTED_HOST}/")"',
    'curl_rc=$?',
    'set -e',
    'fatal_json=""',
    'if [[ -s "$fatal_file" ]]; then fatal_json="$(cat "$fatal_file")"; fi',
    'fatal_b64="$(printf \'%s\' "$fatal_json" | base64 | tr -d \'\\n\')"',
    'printf \'NVX_FATAL_CAPTURE request_status=%s curl_rc=%s fatal_b64=%s\\n\' "$request_status" "$curl_rc" "$fatal_b64"',
    '',
  ].join('\n');

  const remoteCommand = `STAGING_ROOT=${stagingRoot} EXPECTED_HOST=${expectedHost} bash -se`;
  const result = spawnSync(
    '/usr/bin/ssh',
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1', '--', sshAlias, remoteCommand],
    { input: remoteScript, encoding: 'utf8', timeout: 60000, maxBuffer: 1024 * 1024 }
  );

  const stdout = (result.stdout || '').trim();
  const stderr = (result.stderr || '').trim();
  const fatalMatch = stdout.match(/\bfatal_b64=([A-Za-z0-9+/=]*)/);
  let fatal = null;
  if (fatalMatch?.[1]) {
    try {
      fatal = JSON.parse(Buffer.from(fatalMatch[1], 'base64').toString('utf8'));
    } catch {
      fatal = { parse_error: true };
    }
  }

  const diagnostic = {
    schema: 1,
    trigger_status: triggerStatus,
    captured_at: new Date().toISOString(),
    ssh_alias: sshAlias,
    ssh_exit_status: result.status,
    ssh_signal: result.signal || '',
    ssh_error: result.error ? result.error.message : '',
    remote_stdout: stdout,
    remote_stderr: stderr,
    fatal,
  };

  try {
    const outputDir = path.resolve('scripts/staging2/artifacts');
    fs.mkdirSync(outputDir, { recursive: true });
    fs.writeFileSync(
      path.join(outputDir, 'staging-home-fatal.json'),
      `${JSON.stringify(diagnostic, null, 2)}\n`,
      'utf8'
    );
  } catch (error) {
    console.error(`STAGING_FATAL_DIAGNOSTIC_WRITE=FAIL reason=${error instanceof Error ? error.message : String(error)}`);
  }
}

/** Get the configured protected branch (master or main) from environment or default. */
export function getProtectedBranch() {
  return process.env.NUVANX_PROTECTED_BRANCH || GIT_REF_NAMES.MASTER;
}

export function isSiteGroundCaptchaInterruption(error, currentUrl = '') {
  const message = error instanceof Error ? error.message : String(error || '');
  return String(currentUrl).includes(SITEGROUND_CAPTCHA_PATH)
    || (/interrupted by another navigation/i.test(message) && message.includes(SITEGROUND_CAPTCHA_PATH));
}

export function isSiteGroundTransientResponse(status, headers = {}, currentUrl = '') {
  const normalizedStatus = Number(status || 0);
  captureStagingFatalDiagnostic(normalizedStatus);
  return SITEGROUND_TRANSIENT_HTTP_STATUSES.has(normalizedStatus)
    || Boolean(headers['sg-captcha'])
    || String(currentUrl).includes(SITEGROUND_CAPTCHA_PATH);
}

export function isOneShotMasterPush(eventName = '', refName = '') {
  return eventName === GITHUB_EVENT_NAMES.PUSH && refName === getProtectedBranch();
}

export function getGitHubEventPath(eventName = '', refName = '') {
  if (eventName === GITHUB_EVENT_NAMES.PULL_REQUEST_TARGET) {
    return EXECUTION_PATHS.PULL_REQUEST;
  }
  if (isOneShotMasterPush(eventName, refName)) {
    return EXECUTION_PATHS.ONE_SHOT_MASTER_PUSH;
  }
  if (eventName === GITHUB_EVENT_NAMES.WORKFLOW_DISPATCH && refName === getProtectedBranch()) {
    return EXECUTION_PATHS.TRUSTED_WORKFLOW_DISPATCH;
  }
  return EXECUTION_PATHS.UNSUPPORTED_EVENT;
}
