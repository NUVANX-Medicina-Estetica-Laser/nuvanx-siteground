export function resolvePerformanceGateMode({ eventName = '', requestedMode = '' } = {}) {
  const event = String(eventName || '').trim().toLowerCase();
  const requested = String(requestedMode || '').trim().toLowerCase();

  // Every normal push is production-eligible acceptance and therefore must
  // enforce the approved regression baseline. A stale workflow default may
  // never downgrade a push to capture-only behavior.
  if (event === 'push') return 'enforce';

  // Baseline capture remains an explicit calibration tool for manual runs.
  if (requested === 'baseline') return requested;

  // Missing mode fails safe to enforcement. Invalid non-empty modes are passed
  // through so the core's configuration validator rejects them with EX_CONFIG.
  return requested === '' ? 'enforce' : requested;
}
