import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';

const CANONICAL_STAGING_HOSTNAME = 'staging2.nuvanx.com';
const CANONICAL_SSH_ALIAS = 'nvx-staging2';
const LOCAL_HTTPS_PORT = 443;
const HOSTS_MARKER = '# nvx-lighthouse-ssh-egress';

function assertCanonicalBaseUrl(raw) {
  const parsed = new URL(String(raw || '').trim());
  if (
    parsed.protocol !== 'https:'
    || parsed.hostname !== CANONICAL_STAGING_HOSTNAME
    || parsed.port
    || parsed.username
    || parsed.password
  ) {
    throw new Error('Authenticated Lighthouse egress is restricted to canonical Staging2 HTTPS');
  }
  return parsed.origin;
}

export function shouldUseAuthenticatedStagingEgress(env = process.env) {
  if (String(env.GITHUB_ACTIONS || '').toLowerCase() !== 'true') return false;
  try {
    assertCanonicalBaseUrl(env.BASE_URL || '');
    return true;
  } catch {
    return false;
  }
}

function waitForListeningPort(port, child, timeoutMs = 12000) {
  return new Promise((resolve, reject) => {
    const started = Date.now();
    let settled = false;
    let lastError = '';
    let timer;

    const finish = (error) => {
      if (settled) return;
      settled = true;
      if (timer) clearInterval(timer);
      child.off('exit', onExit);
      child.off('error', onError);
      if (error) reject(error);
      else resolve();
    };

    const onExit = (code, signal) => finish(new Error(`SSH HTTPS tunnel exited before readiness code=${code ?? 'null'} signal=${signal || 'none'}`));
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
          finish(new Error(`SSH HTTPS tunnel did not become ready: ${lastError}`));
        }
      };
      socket.once('error', record);
      socket.once('timeout', () => record(new Error('socket timeout')));
    };

    timer = setInterval(probe, 250);
    probe();
  });
}

function runSudo(args, options = {}) {
  const result = spawnSync('sudo', ['-n', ...args], {
    encoding: 'utf8',
    timeout: 15000,
    maxBuffer: 1024 * 1024,
    ...options,
  });
  if (result.error || result.status !== 0) {
    throw new Error(`sudo ${args[0]} failed: ${result.error?.message || result.stderr || `exit=${result.status}`}`);
  }
  return result;
}

function installCanonicalHostsRoute() {
  runSudo([
    'sh',
    '-c',
    `sed -i '/${HOSTS_MARKER.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$/d' /etc/hosts && printf '%s\\n' '127.0.0.1 ${CANONICAL_STAGING_HOSTNAME} ${HOSTS_MARKER}' >> /etc/hosts`,
  ]);
}

function removeCanonicalHostsRoute() {
  const escaped = HOSTS_MARKER.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  spawnSync('sudo', ['-n', 'sh', '-c', `sed -i '/${escaped}$/d' /etc/hosts`], {
    encoding: 'utf8',
    timeout: 5000,
    maxBuffer: 1024 * 1024,
  });
}

function verifyCanonicalStagingPath(baseUrl) {
  const canonicalOrigin = assertCanonicalBaseUrl(baseUrl);
  const result = spawnSync(
    'curl',
    [
      '--silent',
      '--show-error',
      '--location',
      '--max-time',
      '20',
      '--resolve',
      `${CANONICAL_STAGING_HOSTNAME}:443:127.0.0.1`,
      '--output',
      '/dev/null',
      '--write-out',
      '%{http_code}\t%{url_effective}\t%{remote_ip}',
      `${canonicalOrigin}/`,
    ],
    { encoding: 'utf8', timeout: 25000, maxBuffer: 1024 * 1024 },
  );
  if (result.error || result.status !== 0) {
    throw new Error(`Authenticated Staging egress probe failed: ${result.error?.message || result.stderr || `exit=${result.status}`}`);
  }

  const [statusRaw, effectiveRaw, remoteIp] = String(result.stdout || '').trim().split('\t');
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
    || remoteIp !== '127.0.0.1'
  ) {
    throw new Error(`Authenticated Staging egress was intercepted status=${statusRaw || 'missing'} final_path=${effective.pathname} local_peer=${remoteIp || 'missing'}`);
  }
  return { status, effectivePath: effective.pathname, localPeer: remoteIp };
}

export async function startAuthenticatedStagingEgress({
  baseUrl = process.env.BASE_URL || 'https://staging2.nuvanx.com',
  sshAlias = CANONICAL_SSH_ALIAS,
  sshConfig = path.join(os.homedir(), '.ssh', 'config'),
} = {}) {
  const canonicalOrigin = assertCanonicalBaseUrl(baseUrl);
  const stderrChunks = [];

  // Binding 443 is privileged. GitHub-hosted Ubuntu runners provide passwordless
  // sudo; the SSH config still points at the pinned key and known_hosts created
  // by the preceding workflow step. The remote destination hostname is resolved
  // by the SSH server, so the connection to Staging originates from SiteGround.
  const child = spawn(
    'sudo',
    [
      '-n',
      'ssh',
      '-F', sshConfig,
      '-n',
      '-N',
      '-T',
      '-o', 'ExitOnForwardFailure=yes',
      '-L', `127.0.0.1:${LOCAL_HTTPS_PORT}:${CANONICAL_STAGING_HOSTNAME}:443`,
      sshAlias,
    ],
    { stdio: ['ignore', 'ignore', 'pipe'] },
  );
  child.stderr?.on('data', (chunk) => {
    if (stderrChunks.join('').length < 4096) stderrChunks.push(String(chunk));
  });

  try {
    await waitForListeningPort(LOCAL_HTTPS_PORT, child);
    const probe = verifyCanonicalStagingPath(canonicalOrigin);
    installCanonicalHostsRoute();
    return {
      networkPath: 'ssh_local_https_forward',
      probe,
      close() {
        removeCanonicalHostsRoute();
        if (child.exitCode === null && !child.killed) child.kill('SIGTERM');
      },
      diagnostics() {
        return stderrChunks.join('').slice(0, 4096);
      },
    };
  } catch (error) {
    removeCanonicalHostsRoute();
    if (child.exitCode === null && !child.killed) child.kill('SIGTERM');
    const diagnostic = stderrChunks.join('').trim();
    throw new Error(`${String(error?.message || error)}${diagnostic ? ` ssh=${diagnostic.slice(0, 1000)}` : ''}`);
  }
}
