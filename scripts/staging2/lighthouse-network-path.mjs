import net from 'node:net';
import { spawn, spawnSync } from 'node:child_process';

const CANONICAL_STAGING_HOSTNAME = 'staging2.nuvanx.com';
const CANONICAL_SSH_ALIAS = 'nvx-staging2';
const DEFAULT_SOCKS_PORT = 41873;

function assertPort(value) {
  const port = Number.parseInt(String(value || ''), 10);
  if (!Number.isInteger(port) || port < 1024 || port > 65535) {
    throw new Error('Invalid Lighthouse SOCKS port');
  }
  return port;
}

export function shouldUseAuthenticatedStagingEgress(env = process.env) {
  if (String(env.GITHUB_ACTIONS || '').toLowerCase() !== 'true') return false;
  const raw = String(env.BASE_URL || '').trim();
  if (!raw) return false;
  try {
    const parsed = new URL(raw);
    return parsed.protocol === 'https:' && parsed.hostname === CANONICAL_STAGING_HOSTNAME;
  } catch {
    return false;
  }
}

export function buildLighthouseChromeFlags(proxyServer = '') {
  const flags = ['--headless', '--disable-dev-shm-usage'];
  const value = String(proxyServer || '').trim();
  if (value) {
    const parsed = new URL(value);
    if (parsed.protocol !== 'socks5:' || parsed.hostname !== '127.0.0.1' || !parsed.port) {
      throw new Error('Lighthouse proxy must be a loopback SOCKS5 endpoint');
    }
    assertPort(parsed.port);
    flags.push(`--proxy-server=${parsed.toString().replace(/\/$/, '')}`);
  }
  return flags.join(' ');
}

function waitForListeningPort(port, child, timeoutMs = 12000) {
  return new Promise((resolve, reject) => {
    const started = Date.now();
    let settled = false;
    let lastError = '';

    const finish = (error) => {
      if (settled) return;
      settled = true;
      clearInterval(timer);
      child.off('exit', onExit);
      child.off('error', onError);
      if (error) reject(error);
      else resolve();
    };

    const onExit = (code, signal) => finish(new Error(`SSH SOCKS tunnel exited before readiness code=${code ?? 'null'} signal=${signal || 'none'}`));
    const onError = (error) => finish(error);
    child.once('exit', onExit);
    child.once('error', onError);

    const probe = () => {
      const socket = net.createConnection({ host: '127.0.0.1', port });
      socket.setTimeout(1000);
      socket.once('connect', () => {
        socket.destroy();
        finish();
      });
      const record = (error) => {
        lastError = String(error?.message || error || 'not-listening');
        socket.destroy();
        if (Date.now() - started >= timeoutMs) {
          finish(new Error(`SSH SOCKS tunnel did not become ready: ${lastError}`));
        }
      };
      socket.once('error', record);
      socket.once('timeout', () => record(new Error('socket timeout')));
    };

    const timer = setInterval(probe, 250);
    probe();
  });
}

function verifyCanonicalStagingPath(proxyServer, baseUrl) {
  const result = spawnSync(
    'curl',
    [
      '--silent',
      '--show-error',
      '--location',
      '--max-time',
      '20',
      '--socks5-hostname',
      proxyServer.replace(/^socks5:\/\//, ''),
      '--output',
      '/dev/null',
      '--write-out',
      '%{http_code}\t%{url_effective}',
      `${baseUrl.replace(/\/$/, '')}/`,
    ],
    { encoding: 'utf8', timeout: 25000, maxBuffer: 1024 * 1024 },
  );
  if (result.error || result.status !== 0) {
    throw new Error(`Authenticated Staging egress probe failed: ${result.error?.message || result.stderr || `exit=${result.status}`}`);
  }

  const [statusRaw, effectiveRaw] = String(result.stdout || '').trim().split('\t');
  const status = Number.parseInt(statusRaw, 10);
  let effective;
  try {
    effective = new URL(effectiveRaw);
  } catch {
    throw new Error('Authenticated Staging egress probe returned an invalid effective URL');
  }
  if (
    !Number.isInteger(status)
    || status < 200
    || status >= 400
    || status === 202
    || status === 429
    || status === 503
    || effective.hostname !== CANONICAL_STAGING_HOSTNAME
    || effective.pathname.startsWith('/.well-known/sgcaptcha/')
  ) {
    throw new Error(`Authenticated Staging egress was intercepted status=${statusRaw || 'missing'} final_path=${effective.pathname}`);
  }
  return { status, effectivePath: effective.pathname };
}

export async function startAuthenticatedStagingEgress({
  baseUrl = process.env.BASE_URL || 'https://staging2.nuvanx.com',
  sshAlias = CANONICAL_SSH_ALIAS,
  port = process.env.PERFORMANCE_SOCKS_PORT || DEFAULT_SOCKS_PORT,
} = {}) {
  const resolvedPort = assertPort(port);
  const proxyServer = `socks5://127.0.0.1:${resolvedPort}`;
  const stderrChunks = [];
  const child = spawn(
    'ssh',
    [
      '-n',
      '-N',
      '-T',
      '-o', 'ExitOnForwardFailure=yes',
      '-o', 'ClearAllForwardings=yes',
      '-D', `127.0.0.1:${resolvedPort}`,
      sshAlias,
    ],
    { stdio: ['ignore', 'ignore', 'pipe'] },
  );
  child.stderr?.on('data', (chunk) => {
    if (stderrChunks.join('').length < 4096) stderrChunks.push(String(chunk));
  });

  try {
    await waitForListeningPort(resolvedPort, child);
    const probe = verifyCanonicalStagingPath(proxyServer, baseUrl);
    return {
      proxyServer,
      probe,
      close() {
        if (child.exitCode === null && !child.killed) child.kill('SIGTERM');
      },
      diagnostics() {
        return stderrChunks.join('').slice(0, 4096);
      },
    };
  } catch (error) {
    if (child.exitCode === null && !child.killed) child.kill('SIGTERM');
    const diagnostic = stderrChunks.join('').trim();
    throw new Error(`${String(error?.message || error)}${diagnostic ? ` ssh=${diagnostic.slice(0, 1000)}` : ''}`);
  }
}
