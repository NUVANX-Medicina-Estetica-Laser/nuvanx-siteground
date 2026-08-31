import { spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { EX_TEMPFAIL } from './siteground-transient-classifier.mjs';

const activeChildren = new Set();
['SIGTERM', 'SIGINT'].forEach((sig) => {
  process.once(sig, () => {
    console.error(`VALORACION_ORCHESTRATOR=TERMINATED signal=${sig} active_children=${activeChildren.size}`);
    for (const child of activeChildren) {
      if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL');
    }
    process.exit(sig === 'SIGINT' ? 130 : 143);
  });
});

function runProcess(moduleUrl) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [fileURLToPath(moduleUrl)], {
      env: process.env,
      stdio: 'inherit',
    });
    activeChildren.add(child);
    child.once('error', (err) => {
      activeChildren.delete(child);
      reject(err);
    });
    child.once('exit', (code, signal) => {
      activeChildren.delete(child);
      if (signal) {
        reject(new Error(`Terminated by signal ${signal}`));
        return;
      }
      resolve(Number.isInteger(code) ? code : 1);
    });
  });
}

const valoracionArtifactsDir = fileURLToPath(new URL('./valoracion-artifacts', import.meta.url));
const STAGE_EVIDENCE_MAP = {
  'meta-no-consent': {
    source: fileURLToPath(new URL('./meta-no-consent-artifacts/results.json', import.meta.url)),
    destinationDir: valoracionArtifactsDir,
    destination: fileURLToPath(new URL('./valoracion-artifacts/meta-no-consent-results.json', import.meta.url)),
  },
  'complianz-first-visit-mobile': {
    sourceDirectory: fileURLToPath(new URL('./complianz-first-visit-mobile-artifacts', import.meta.url)),
    destinationDirectory: fileURLToPath(new URL('./valoracion-artifacts/complianz-first-visit-mobile', import.meta.url)),
  },
  'block-a11y': {
    source: fileURLToPath(new URL('./block-a11y-artifacts/results.json', import.meta.url)),
    destinationDir: valoracionArtifactsDir,
    destination: fileURLToPath(new URL('./valoracion-artifacts/block-a11y-results.json', import.meta.url)),
  },
};

async function prepareAllStageEvidence() {
  for (const config of Object.values(STAGE_EVIDENCE_MAP)) {
    const sourceTarget = config.sourceDirectory || config.source;
    const destinationTarget = config.destinationDirectory || config.destination;
    if (sourceTarget) {
      await fs.rm(sourceTarget, { recursive: true, force: true }).catch((err) => {
        console.warn(`STAGING_ACCEPTANCE_EVIDENCE=CLEANUP_WARN path=${sourceTarget} error=${err instanceof Error ? err.message : String(err)}`);
      });
    }
    if (destinationTarget) {
      await fs.rm(destinationTarget, { recursive: true, force: true }).catch((err) => {
        console.warn(`STAGING_ACCEPTANCE_EVIDENCE=CLEANUP_WARN path=${destinationTarget} error=${err instanceof Error ? err.message : String(err)}`);
      });
    }
  }
}

async function preserveStageEvidence(component) {
  const config = STAGE_EVIDENCE_MAP[component];
  if (!config) return true;
  try {
    if (config.sourceDirectory && config.destinationDirectory) {
      await fs.access(path.join(config.sourceDirectory, 'results.json'));
      await fs.rm(config.destinationDirectory, { recursive: true, force: true });
      await fs.mkdir(path.dirname(config.destinationDirectory), { recursive: true });
      await fs.cp(config.sourceDirectory, config.destinationDirectory, { recursive: true });
      console.log(`STAGING_ACCEPTANCE_EVIDENCE=PRESERVED component=${component} path=${config.destinationDirectory} mode=directory`);
      return true;
    }
    await fs.mkdir(config.destinationDir, { recursive: true });
    await fs.copyFile(config.source, config.destination);
    console.log(`STAGING_ACCEPTANCE_EVIDENCE=PRESERVED component=${component} path=${config.destination} mode=file`);
    return true;
  } catch (error) {
    console.warn(`STAGING_ACCEPTANCE_EVIDENCE=UNAVAILABLE component=${component} error=${error instanceof Error ? error.message : String(error)}`);
    return false;
  }
}

async function writeRollbackState(value, component, reason) {
  const envFile = (process.env.GITHUB_ENV || '').trim();
  if (!envFile) return;
  try {
    await fs.appendFile(envFile, `STAGING_MUTATION_ARMED=${value}\n`, 'utf8');
    console.log(`STAGING_ACCEPTANCE_ROLLBACK=${value === '1' ? 'REARMED' : 'DISARMED'} component=${component} reason=${reason}`);
  } catch (error) {
    console.warn(`STAGING_ACCEPTANCE_ROLLBACK=WRITE_FAILED component=${component} error=${error instanceof Error ? error.message : String(error)}`);
  }
}

async function disarmRollbackAfterTransientExhaustion(component) {
  const summary = (process.env.GITHUB_STEP_SUMMARY || '').trim();
  if (!summary) return;
  try {
    await fs.appendFile(
      summary,
      `\n### Staging acceptance transient exhaustion\n\nComponent \`${component}\` remained inconclusive after all bounded retry cycles. No deterministic defect was established; this run is not eligible for Production acceptance.\n`,
      'utf8'
    );
  } catch (error) {
    console.warn(`STAGING_ACCEPTANCE_SUMMARY=WRITE_FAILED component=${component} error=${error instanceof Error ? error.message : String(error)}`);
  }
}

async function recordMissingEvidence(component) {
  await writeRollbackState('0', component, 'required-evidence-unavailable');
  const summary = (process.env.GITHUB_STEP_SUMMARY || '').trim();
  if (summary) {
    try {
      await fs.appendFile(
        summary,
        `\n### Staging acceptance evidence unavailable\n\nComponent \`${component}\` passed, but its required evidence file could not be preserved. No deterministic site defect was established, so rollback was disarmed; this run is not eligible for Production acceptance and the same immutable SHA must be retried on a fresh runner.\n`,
        'utf8'
      );
    } catch (error) {
      console.warn(`STAGING_ACCEPTANCE_SUMMARY=WRITE_FAILED component=${component} error=${error instanceof Error ? error.message : String(error)}`);
    }
  }
  console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL_TRANSIENT component=${component} reason=required_evidence_unavailable exit=${EX_TEMPFAIL}`);
}

async function runStage(name, moduleUrl, maxCycles = 1, backoffMs = 3500) {
  let lastExitCode = 1;
  let sawTransient = false;
  for (let cycle = 1; cycle <= maxCycles; cycle += 1) {
    if (maxCycles > 1) console.log(`STAGING_ACCEPTANCE_CYCLE component=${name} cycle=${cycle}/${maxCycles}`);
    let processError = null;
    let evidencePreserved = true;
    try {
      lastExitCode = await runProcess(moduleUrl);
    } catch (err) {
      processError = err;
    } finally {
      evidencePreserved = await preserveStageEvidence(name);
    }
    if (processError) {
      console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL component=${name} reason=${processError instanceof Error ? processError.message : String(processError)}`);
      return 1;
    }
    if (lastExitCode === 0) {
      if (!evidencePreserved) {
        await recordMissingEvidence(name);
        return EX_TEMPFAIL;
      }
      if (sawTransient) {
        await writeRollbackState('1', name, 'transient-recovered');
        const envFile = (process.env.GITHUB_ENV || '').trim();
        if (envFile) {
          await fs.appendFile(envFile, 'STAGING_ACCEPTANCE_TRANSIENT=0\n', 'utf8').then(() => {
            console.log(`STAGING_ACCEPTANCE_TRANSIENT=RESET component=${name} reason=transient-recovered`);
          }).catch((err) => {
            console.warn(`STAGING_ACCEPTANCE_TRANSIENT=RESET_FAILED component=${name} error=${err instanceof Error ? err.message : String(err)}`);
          });
        }
      }
      console.log(`STAGING_ACCEPTANCE_COMPONENT=PASS component=${name}${maxCycles > 1 ? ` cycle=${cycle}` : ''}`);
      return 0;
    }
    if (lastExitCode !== EX_TEMPFAIL) {
      console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL component=${name} exit=${lastExitCode}`);
      return lastExitCode;
    }
    sawTransient = true;
    if (cycle < maxCycles) {
      await writeRollbackState('1', name, 'outer-transient-retry');
      const delayMs = backoffMs * cycle;
      console.warn(`STAGING_ACCEPTANCE_COMPONENT=RETRY component=${name} cycle=${cycle} exit=${lastExitCode} delay_ms=${delayMs}`);
      await delay(delayMs);
    }
  }
  await disarmRollbackAfterTransientExhaustion(name);
  console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL component=${name} cycles=${maxCycles} exit=${lastExitCode}`);
  return lastExitCode || 1;
}

const VALORACION_PLACEMENT_CYCLES = Number.parseInt(process.env.VALORACION_PLACEMENT_CYCLES || '3', 10) || 3;
const VALORACION_A11Y_CYCLES = Number.parseInt(
  process.env.VALORACION_A11Y_CYCLES || process.env.HUBSPOT_A11Y_CYCLES || '3',
  10
) || 3;

const stages = [
  { name: 'siteground-transient-classifier', url: new URL('./test-siteground-transient-classifier.mjs', import.meta.url), maxCycles: 1 },
  { name: 'hubspot-submission-classifier', url: new URL('./test-hubspot-submission-classifier.mjs', import.meta.url), maxCycles: 1 },
  { name: 'governed-blog-head-contract', url: new URL('./governed-blog-head-resilient.mjs', import.meta.url), maxCycles: 1 },
  { name: 'governed-blog-runtime-identity', url: new URL('./governed-blog-runtime-contract.mjs', import.meta.url), maxCycles: 3 },
  { name: 'meta-no-consent', url: new URL('./meta-no-consent-contract.mjs', import.meta.url), maxCycles: 3, backoffMs: 5000 },
  { name: 'valoracion-placement', url: new URL('./valoracion-placement-resilient.mjs', import.meta.url), maxCycles: VALORACION_PLACEMENT_CYCLES },
  { name: 'valoracion-first-party-a11y', url: new URL('./first-party-valoracion-a11y.mjs', import.meta.url), maxCycles: VALORACION_A11Y_CYCLES, backoffMs: 7000 },
];

console.log('STAGING_ACCEPTANCE_SCOPE=P0 attribution_lineage=separate_phase form_owner=first-party browser_hubspot=retired');

stages.push({ name: 'complianz-first-visit-mobile', url: new URL('./complianz-first-visit-mobile.mjs', import.meta.url), maxCycles: 1 });
stages.push({ name: 'block-a11y', url: new URL('./block-a11y.mjs', import.meta.url), maxCycles: 1 });

await prepareAllStageEvidence();
for (const stage of stages) {
  const exitCode = await runStage(stage.name, stage.url, stage.maxCycles, stage.backoffMs);
  if (exitCode !== 0) process.exit(exitCode);
}
