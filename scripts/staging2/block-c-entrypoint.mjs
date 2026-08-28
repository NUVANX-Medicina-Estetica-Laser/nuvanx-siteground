await import('./block-c-entrypoint-orchestrator.mjs');

const priorExit = Number(process.exitCode || 0);
if (priorExit !== 0) {
  console.error(`BLOCK_C_CLINICAL_EVIDENCE=SKIP prior_exit=${priorExit}`);
} else {
  await import('./clinical-evidence-runtime.mjs');
}
