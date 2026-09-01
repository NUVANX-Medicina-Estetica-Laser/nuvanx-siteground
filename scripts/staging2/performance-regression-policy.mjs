/**
 * Pure performance-regression policy for the exact-SHA Lighthouse gate.
 *
 * The runtime gate owns measurement. This module owns only validation of the
 * approved empirical baseline and deterministic regression evaluation.
 */

export const PERFORMANCE_POLICY_METRICS = [
  'lcp_ms',
  'tbt_ms',
  'cls',
  'ttfb_ms',
  'performance_score',
];

function isFiniteNumber(value) {
  return typeof value === 'number' && Number.isFinite(value);
}

function cellKey(page, mode) {
  return `${page}/${mode}`;
}

export function validatePerformanceBaselineContract(
  contract,
  {
    lighthouseVersion,
    requiredCells = [],
    requireApproved = true,
  } = {},
) {
  if (!contract || typeof contract !== 'object' || Array.isArray(contract)) {
    return { ok: false, reason: 'baseline_not_object' };
  }
  if (contract.schema !== 1) {
    return { ok: false, reason: 'unsupported_schema' };
  }
  if (requireApproved && contract.status !== 'approved') {
    return { ok: false, reason: 'baseline_not_approved' };
  }
  if (lighthouseVersion && contract.lighthouse_version !== lighthouseVersion) {
    return { ok: false, reason: 'lighthouse_version_mismatch' };
  }
  if (!contract.policy || typeof contract.policy !== 'object') {
    return { ok: false, reason: 'missing_policy' };
  }
  if (!contract.cells || typeof contract.cells !== 'object') {
    return { ok: false, reason: 'missing_cells' };
  }

  const p = contract.policy;
  const checks = [
    ['lcp_ms.relative_increase', p.lcp_ms?.relative_increase],
    ['lcp_ms.absolute_delta', p.lcp_ms?.absolute_delta],
    ['lcp_ms.absolute_max', p.lcp_ms?.absolute_max],
    ['tbt_ms.absolute_delta', p.tbt_ms?.absolute_delta],
    ['tbt_ms.absolute_max', p.tbt_ms?.absolute_max],
    ['cls.absolute_delta', p.cls?.absolute_delta],
    ['cls.absolute_max', p.cls?.absolute_max],
    ['ttfb_ms.relative_increase', p.ttfb_ms?.relative_increase],
    ['ttfb_ms.absolute_delta', p.ttfb_ms?.absolute_delta],
    ['ttfb_ms.absolute_max', p.ttfb_ms?.absolute_max],
    ['performance_score.drop_points', p.performance_score?.drop_points],
    ['performance_score.absolute_min', p.performance_score?.absolute_min],
  ];
  for (const [name, value] of checks) {
    if (!isFiniteNumber(value) || value < 0) {
      return { ok: false, reason: `invalid_policy_${name}` };
    }
  }

  for (const key of requiredCells) {
    const baselineCell = contract.cells[key];
    if (!baselineCell || typeof baselineCell !== 'object' || !baselineCell.reference) {
      return { ok: false, reason: `missing_cell_${key}` };
    }
    for (const metric of PERFORMANCE_POLICY_METRICS) {
      if (!isFiniteNumber(baselineCell.reference[metric])) {
        return { ok: false, reason: `invalid_reference_${key}_${metric}` };
      }
    }
  }

  return { ok: true };
}

function regressionCeiling(reference, rule, metric) {
  if (metric === 'lcp_ms' || metric === 'ttfb_ms') {
    const variableAllowance = Math.max(
      rule.absolute_delta,
      reference * rule.relative_increase,
    );
    return Math.min(rule.absolute_max, reference + variableAllowance);
  }
  if (metric === 'tbt_ms' || metric === 'cls') {
    return Math.min(rule.absolute_max, reference + rule.absolute_delta);
  }
  throw new Error(`Unsupported ceiling metric: ${metric}`);
}

function performanceFloor(reference, rule) {
  return Math.max(rule.absolute_min, reference - rule.drop_points);
}

export function evaluatePerformanceRegression(cellResults, contract) {
  const violations = [];
  const evaluations = [];

  for (const cell of cellResults) {
    if (cell.status !== 'success' || !cell.median) continue;

    const key = cellKey(cell.page, cell.mode);
    const baselineCell = contract.cells[key];
    if (!baselineCell?.reference) {
      violations.push({
        page: cell.page,
        mode: cell.mode,
        metric: 'baseline',
        severity: 'config',
        reason: 'missing_baseline_cell',
      });
      continue;
    }

    for (const metric of PERFORMANCE_POLICY_METRICS) {
      const value = cell.median[metric];
      const reference = baselineCell.reference[metric];
      if (!isFiniteNumber(value) || !isFiniteNumber(reference)) {
        violations.push({
          page: cell.page,
          mode: cell.mode,
          metric,
          severity: 'config',
          reason: 'invalid_metric_or_reference',
        });
        continue;
      }

      if (metric === 'performance_score') {
        const allowed = performanceFloor(reference, contract.policy.performance_score);
        const failed = value < allowed;
        evaluations.push({ page: cell.page, mode: cell.mode, metric, value, reference, allowed, direction: 'min', failed });
        if (failed) {
          violations.push({
            page: cell.page,
            mode: cell.mode,
            metric,
            value,
            baseline: reference,
            allowed,
            severity: 'regression',
          });
        }
        continue;
      }

      const allowed = regressionCeiling(reference, contract.policy[metric], metric);
      const failed = value > allowed;
      evaluations.push({ page: cell.page, mode: cell.mode, metric, value, reference, allowed, direction: 'max', failed });
      if (failed) {
        violations.push({
          page: cell.page,
          mode: cell.mode,
          metric,
          value,
          baseline: reference,
          allowed,
          severity: 'regression',
        });
      }
    }
  }

  return { violations, evaluations };
}
