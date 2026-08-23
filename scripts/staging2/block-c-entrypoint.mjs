import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { EX_CONFIG, EX_NOT_APPLICABLE, EX_TEMPFAIL } from './siteground-transient-classifier.mjs';
import { BLOCK_C_RECOVERY_TARGETS } from './block-c-browser-config.mjs';
import { createSiteGroundOriginVerifier } from './siteground-origin-verifier.mjs';
import { renderBlockCEvidence, writeEvidenceBundle } from './block-c-evidence.mjs';

const coreScript = fileURLToPath(new URL('./block-c-entrypoint-core.mjs', import.meta.url));
const clinicMediaRuntimeScript = fileURLToPath(new URL('./clinic-media-runtime.mjs', import.meta.url));
const targetedVisualRecoveryScript = fileURLToPath(new URL('./block-c-home-mobile-recovery.mjs', import.meta.url));
const targetedVisualRecoveryTargets = Object.freeze(Object.keys(BLOCK_C_RECOVERY_TARGETS));
const artifactsDir = fileURLToPath(new URL('./block-c-artifacts/', import.meta.url));
const resultsPath = path.join(artifactsDir, 'block-c-results.json');
const matrixPath = path.join(artifactsDir, 'block-c-matrix.md');
const summaryPath = path.join(artifactsDir, 'block-c-summary.md');
const csvPath = path.join(artifactsDir, 'block-c-results.csv');
const runnerTemp = process.env.RUNNER_TEMP || '/tmp';
const realGithubEnv = process.env.GITHUB_ENV || '';
const realStepSummary = process.env.GITHUB_STEP_SUMMARY || '';
const shadowGithubEnv = path.join(runnerTemp, `nvx-block-c-core-env-${process.pid}.txt`);
const shadowStepSummary = path.join(runnerTemp, `nvx-block-c-core-summary-${process.pid}.md`);
const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedHost = new URL(baseUrl).hostname;
const expectedSha = (process.env.EXPECTED_SHA || '').trim();

function positiveIntegerEnv(name, fallback) {
  const value = Number.parseInt(process.env[name] || '', 10);
  return Number.isInteger(value) && value > 0 ? value : fallback;
}

const parsedLegacyTimeoutMs = Number.parseInt(process.env.BLOCK_C_SUBPROCESS_TIMEOUT_MS || '', 10);
const hasLegacyTimeoutOverride = Number.isInteger(parsedLegacyTimeoutMs) && parsedLegacyTimeoutMs > 0;
const legacyTimeoutMs = hasLegacyTimeoutOverride ? parsedLegacyTimeoutMs : null;
const DEFAULT_CORE_TIMEOUT_MS = 30 * 60 * 1000;
const DEFAULT_RECOVERY_TIMEOUT_MS = 10 * 60 * 1000;
const DEFAULT_CLINIC_MEDIA_TIMEOUT_MS = 5 * 60 * 1000;
// BLOCK_C_SUBPROCESS_TIMEOUT_MS is the historical global subprocess budget. For
// compatibility it remains the core fallback when explicitly set, and recovery
// must never exceed that global ceiling unless BLOCK_C_RECOVERY_TIMEOUT_MS is
// explicitly supplied by the caller.
const coreFallbackTimeoutMs = hasLegacyTimeoutOverride ? legacyTimeoutMs : DEFAULT_CORE_TIMEOUT_MS;
const recoveryFallbackTimeoutMs = hasLegacyTimeoutOverride
  ? Math.min(DEFAULT_RECOVERY_TIMEOUT_MS, legacyTimeoutMs)
  : DEFAULT_RECOVERY_TIMEOUT_MS;
const clinicMediaFallbackTimeoutMs = hasLegacyTimeoutOverride
  ? Math.min(DEFAULT_CLINIC_MEDIA_TIMEOUT_MS, legacyTimeoutMs)
  : DEFAULT_CLINIC_MEDIA_TIMEOUT_MS;
const SUBPROCESS_CONFIG = Object.freeze({
  coreTimeoutMs: positiveIntegerEnv('BLOCK_C_CORE_TIMEOUT_MS', coreFallbackTimeoutMs),
  recoveryTimeoutMs: positiveIntegerEnv('BLOCK_C_RECOVERY_TIMEOUT_MS', recoveryFallbackTimeoutMs),
  clinicMediaTimeoutMs: positiveIntegerEnv('CLINIC_MEDIA_RUNTIME_TIMEOUT_MS', clinicMediaFallbackTimeoutMs),
  hardKillGraceMs: positiveIntegerEnv('BLOCK_C_SUBPROCESS_KILL_GRACE_MS', 5000),
});

class ProcessSignalError extends Error {
  constructor(script, signal) {
    super(`${path.basename(script)} terminated by signal ${signal}`);
    this.name = 'ProcessSignalError';
    this.signal = signal;
    this.script = script;
  }
}

function runProcess(script, env = process.env, timeoutMs = SUBPROCESS_CONFIG.coreTimeoutMs) {
  if (!Number.isInteger(timeoutMs) || timeoutMs <= 0) {
    return Promise.reject(new RangeError(`Invalid Block C subprocess timeout for ${path.basename(script)}: ${timeoutMs}`));
  }

  return new Promise((resolve, reject) => {
    let timedOut = false;
    let hardKillTimer = null;
    const child = spawn(process.execPath, [script], { env, stdio: 'inherit' });

    const timeoutTimer = setTimeout(() => {
      timedOut = true;
      console.error(`BLOCK_C_SUBPROCESS=TIMEOUT script=${path.basename(script)} timeout_ms=${timeoutMs}`);
      child.kill('SIGTERM');
      hardKillTimer = setTimeout(() => {
        if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL');
      }, SUBPROCESS_CONFIG.hardKillGraceMs);
      hardKillTimer.unref?.();
    }, timeoutMs);
    timeoutTimer.unref?.();

    const cleanupTimers = () => {
      clearTimeout(timeoutTimer);
      if (hardKillTimer) clearTimeout(hardKillTimer);
    };

    child.once('error', (error) => {
      cleanupTimers();
      reject(error);
    });
    child.once('exit', (code, signal) => {
      cleanupTimers();
      if (timedOut) {
        resolve(EX_TEMPFAIL);
        return;
      }
      if (signal) {
        reject(new ProcessSignalError(script, signal));
        return;
      }
      resolve(Number.isInteger(code) ? code : 1);
    });
  });
}

async function runCore() {
  try {
    return await runProcess(coreScript, {
      ...process.env,
      GITHUB_ENV: shadowGithubEnv,
      GITHUB_STEP_SUMMARY: shadowStepSummary,
    }, SUBPROCESS_CONFIG.coreTimeoutMs);
  } catch (error) {
    if (error instanceof ProcessSignalError) {
      console.error(`BLOCK_C_CORE=TRANSIENT_SIGNAL signal=${error.signal}`);
      return EX_TEMPFAIL;
    }
    throw error;
  }
}

async function runClinicMediaRuntime() {
  try {
    return await runProcess(clinicMediaRuntimeScript, process.env, SUBPROCESS_CONFIG.clinicMediaTimeoutMs);
  } catch (error) {
    if (error instanceof ProcessSignalError) {
      console.error(`CLINIC_MEDIA_RUNTIME=TRANSIENT_SIGNAL signal=${error.signal}`);
      return EX_TEMPFAIL;
    }
    throw error;
  }
}

async function tryTargetedVisualRecovery(recoveryTarget) {
  let code;
  try {
    code = await runProcess(targetedVisualRecoveryScript, {
      ...process.env,
      BLOCK_C_RECOVERY_TARGET: recoveryTarget,
    }, SUBPROCESS_CONFIG.recoveryTimeoutMs);
  } catch (error) {
    if (error instanceof ProcessSignalError) {
      console.error(`BLOCK_C_TARGETED_VISUAL_RECOVERY=TRANSIENT_SIGNAL target=${recoveryTarget} signal=${error.signal}`);
      return { recovered: false, applicable: true, transient: true, recoveryTarget };
    }
    throw error;
  }

  if (code === 0) return { recovered: true, applicable: true, transient: false, recoveryTarget };
  if (code === EX_NOT_APPLICABLE) {
    console.error(`BLOCK_C_TARGETED_VISUAL_RECOVERY=NOT_APPLICABLE target=${recoveryTarget} wrapper=continue`);
    return { recovered: false, applicable: false, transient: false, recoveryTarget };
  }
  if (code === EX_TEMPFAIL) {
    console.error(`BLOCK_C_TARGETED_VISUAL_RECOVERY=TRANSIENT target=${recoveryTarget} wrapper=continue`);
    return { recovered: false, applicable: true, transient: true, recoveryTarget };
  }
  if (code === EX_CONFIG) {
    console.error(`BLOCK_C_TARGETED_VISUAL_RECOVERY=FAIL_CONFIG target=${recoveryTarget} wrapper_exit=78`);
    return { recovered: false, applicable: true, transient: false, realFailure: true, configFailure: true, code, recoveryTarget };
  }
  console.error(`BLOCK_C_TARGETED_VISUAL_RECOVERY=FAIL_REAL target=${recoveryTarget} wrapper_exit=${code}`);
  return { recovered: false, applicable: true, transient: false, realFailure: true, code, recoveryTarget };
}

function isRecoverableCompletedVisualTransient(result) {
  if (!result || result.status !== 'FIX') return false;
  if (result.geometry == null || Number(result.httpStatus || 0) !== 200) return false;
  if (result.externalInconclusive === true) return false;
  const blockers = Array.isArray(result.blockers) ? result.blockers.map(String) : [];
  const issues = Array.isArray(result.issues) ? result.issues.map(String) : [];
  const networkErrors = Array.isArray(result.networkErrors) ? result.networkErrors.map(String) : [];
  return blockers.length === 0
    && issues.length > 0
    && issues.every((message) => /^\d+ same-origin network error\(s\)$/i.test(message))
    && networkErrors.length > 0;
}

async function tryExactOriginNetworkRecovery() {
  try {
    let results;
    try {
      results = JSON.parse(await fs.readFile(resultsPath, 'utf8'));
    } catch (error) {
      console.error(`BLOCK_C_ORIGIN_NETWORK_RECOVERY=UNAVAILABLE reason=results_unreadable error=${error instanceof Error ? error.message : String(error)}`);
      return false;
    }
    if (!Array.isArray(results) || results.length === 0) return false;

    const failed = results.filter((result) => result?.status !== 'PASS');
    if (failed.length === 0 || !failed.every(isRecoverableCompletedVisualTransient)) {
      console.error(`BLOCK_C_ORIGIN_NETWORK_RECOVERY=NOT_APPLICABLE failed=${failed.length}`);
      return false;
    }
    if (expectedHost !== 'staging2.nuvanx.com' || !/^[0-9a-f]{40}$/.test(expectedSha)) {
      console.error(`BLOCK_C_ORIGIN_NETWORK_RECOVERY=REFUSED host=${expectedHost} sha=${expectedSha || 'missing'}`);
      return false;
    }

    const verifier = createSiteGroundOriginVerifier({ expectedHost, expectedSha });
    if (!verifier.isAvailable()) {
      console.error('BLOCK_C_ORIGIN_NETWORK_RECOVERY=UNAVAILABLE reason=origin_ssh');
      return false;
    }

    const verificationByRoute = new Map();
    try {
      for (const result of failed) {
        const route = String(result.route || '');
        if (!verificationByRoute.has(route)) verificationByRoute.set(route, verifier.verify(route));
        const verification = verificationByRoute.get(route);
        if (!verification?.pass || verification.originStatus !== 200 || verification.originDeploySha !== expectedSha) {
          console.error(`BLOCK_C_ORIGIN_NETWORK_RECOVERY=FAIL route=${route} origin_http=${verification?.originStatus ?? 0} origin_sha=${verification?.originDeploySha || 'missing'}`);
          return false;
        }
      }
    } catch (error) {
      console.error(`BLOCK_C_ORIGIN_NETWORK_RECOVERY=UNAVAILABLE reason=verifier_error error=${String(error.message).replace(/\s+/g, '_')}`);
      return false;
    }

    const recovered = results.map((result) => {
      if (result?.status === 'PASS') return result;
      const verification = verificationByRoute.get(String(result.route || ''));
      return {
        ...result,
        status: 'PASS',
        recoveredIssues: Array.isArray(result.issues) ? [...result.issues] : [],
        issues: [],
        originVerified: true,
        originStatus: verification.originStatus,
        originDeploySha: verification.originDeploySha,
        validationTransport: 'public-browser+siteground-origin-network-verification',
        transientNetworkEvidencePreserved: true,
        recoveredByExactOriginNetworkVerification: true,
        notes: [
          ...(Array.isArray(result.notes) ? result.notes : []),
          `Exact-SHA origin verification recovered transient same-origin network errors for ${result.route}.`,
        ],
      };
    });

    const recoverySummary = failed.map((result) => {
      const verification = verificationByRoute.get(String(result.route || ''));
      return `- \`${result.route}\` · ${result.viewport?.label || 'unknown'}: exact-origin HTTP ${verification.originStatus}, deploy SHA \`${verification.originDeploySha}\`; prior browser network evidence preserved.`;
    });
    const derived = renderBlockCEvidence(recovered, {
      expectedSha,
      recoverySummary,
      recoverySectionTitle: 'Exact-origin network recovery',
    });
    await writeEvidenceBundle([
      [matrixPath, derived.matrix],
      [summaryPath, derived.summary],
      [csvPath, derived.csv],
      [resultsPath, `${JSON.stringify(recovered, null, 2)}\n`],
    ]);
    console.log(`BLOCK_C_ORIGIN_NETWORK_RECOVERY=PASS cases=${failed.length} sha=${expectedSha}`);
    return true;
  } catch (error) {
    console.error(`BLOCK_C_ORIGIN_NETWORK_RECOVERY=UNAVAILABLE reason=unexpected_error error=${String(error.message).replace(/\s+/g, '_')}`);
    return false;
  }
}

async function propagateTransientFailureState() {
  if (realGithubEnv) {
    await fs.appendFile(realGithubEnv, 'STAGING_MUTATION_ARMED=0\n', 'utf8');
    console.error('BLOCK_C_STAGING_ROLLBACK=DISARMED reason=transient-exhausted-after-origin-verification');
  }
  if (realStepSummary) {
    await fs.appendFile(
      realStepSummary,
      '\n### Block C transient exhaustion\n\nThe public browser could not complete the visual contract and exact-SHA origin verification could not safely recover the case. No production-eligible completion marker is allowed.\n',
      'utf8'
    );
  }
}

async function cleanupShadowFiles() {
  await fs.rm(shadowGithubEnv, { force: true }).catch(() => {});
  await fs.rm(shadowStepSummary, { force: true }).catch(() => {});
}

let coreCode = 1;
try {
  coreCode = await runCore();
  if (coreCode !== EX_TEMPFAIL) {
    process.exitCode = coreCode;
  } else {
    let visualRecovery = { recovered: false, applicable: false, transient: false, recoveryTarget: '' };
    for (const recoveryTarget of targetedVisualRecoveryTargets) {
      visualRecovery = await tryTargetedVisualRecovery(recoveryTarget);
      if (visualRecovery.recovered || visualRecovery.realFailure) break;
    }
    if (visualRecovery.recovered) {
      console.log(`BLOCK_C_RESILIENT=PASS_PUBLIC_BROWSER_RECOVERY target=${visualRecovery.recoveryTarget} visual_contract=complete`);
      process.exitCode = 0;
    } else if (visualRecovery.realFailure) {
      console.error(`BLOCK_C_RESILIENT=${visualRecovery.configFailure ? 'FAIL_CONFIG' : 'FAIL_REAL'} fallback=public-browser-recovery target=${visualRecovery.recoveryTarget}`);
      process.exitCode = visualRecovery.code || 1;
    } else {
      const recovered = await tryExactOriginNetworkRecovery();
      if (recovered) {
        console.log('BLOCK_C_RESILIENT=PASS_EXACT_ORIGIN_NETWORK_RECOVERY visual_contract=complete');
        process.exitCode = 0;
      } else {
        await propagateTransientFailureState();
        console.error('BLOCK_C_RESILIENT=FAIL_TRANSIENT_EXHAUSTED fallback=public-browser-and-origin-verification-unavailable-or-inapplicable');
        process.exitCode = EX_TEMPFAIL;
      }
    }
  }

  if (process.exitCode === 0) {
    const clinicMediaCode = await runClinicMediaRuntime();
    if (clinicMediaCode === 0) {
      console.log('BLOCK_C_CLINIC_MEDIA_RUNTIME=PASS');
    } else if (clinicMediaCode === EX_TEMPFAIL) {
      console.error('BLOCK_C_CLINIC_MEDIA_RUNTIME=TRANSIENT wrapper_exit=75');
      process.exitCode = EX_TEMPFAIL;
    } else {
      console.error(`BLOCK_C_CLINIC_MEDIA_RUNTIME=FAIL_REAL wrapper_exit=${clinicMediaCode}`);
      process.exitCode = clinicMediaCode;
    }
  }
} catch (error) {
  console.error(`BLOCK_C_WRAPPER=FAIL_REAL reason=${error instanceof Error ? error.message : String(error)}`);
  process.exitCode = 1;
} finally {
  await cleanupShadowFiles();
}
