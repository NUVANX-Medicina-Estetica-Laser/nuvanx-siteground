'use strict';

const fs = require('node:fs');
const path = require('node:path');

const ROW_LIMIT = 25000;
const MAX_PAGES = 20;
const SETTLED_LAG_DAYS = 3;
const WINDOW_DAYS = 90;
const SOURCE = 'Google Search Console Search Analytics API';
const PROPERTY = process.env.GSC_SITE_URL || process.env.SEARCH_CONSOLE_PROPERTY || 'https://nuvanx.com/';
const REDACTED_OUTPUT = process.env.GSC_REDACTED_OUTPUT || path.join(__dirname, 'artifacts', 'gsc-search-analytics-redacted.json');
const PRIVATE_OUTPUT = process.env.GSC_PRIVATE_OUTPUT || '';

const TIER_A_CLUSTERS = {
  valoracion_medica_madrid: /\bvaloracion\b|consulta\s+medica|medicina\s+estetica\s+madrid/u,
  endolift_papada_mandibula: /endolift|papada|mandibul/u,
  endolaser_corporal: /endolaser|laserlipolisis|grasa\s+localizada|smartlipo/u,
  laser_co2_fraccionado: /\bco2\b|co₂|laser\s+co2|laser\s+fraccionad[oa]|cicatrices?\s+(?:de\s+)?acne/u,
  exion: /\bexion\b/u,
};

function sanitizeGscError(err) {
  if (!err) return 'UNKNOWN_ERROR';
  const code = String(err.code || err.status || 'GSC_API_ERROR').replace(/[^a-zA-Z0-9_]/g, '');
  const reason = err.errors?.[0]?.reason || err.response?.data?.error?.status || '';
  const cleanReason = String(reason).replace(/[^a-zA-Z0-9_]/g, '');
  return `code=${code}${cleanReason ? ` reason=${cleanReason}` : ''}`;
}

function formatDate(date) {
  return date.toISOString().split('T')[0];
}

function utcDayOffset(base, days) {
  return new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), base.getUTCDate() + days));
}

function settledWindows(now = new Date()) {
  const today = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
  const currentEnd = utcDayOffset(today, -SETTLED_LAG_DAYS);
  const currentStart = utcDayOffset(currentEnd, -(WINDOW_DAYS - 1));
  const previousEnd = utcDayOffset(currentStart, -1);
  const previousStart = utcDayOffset(previousEnd, -(WINDOW_DAYS - 1));
  return {
    current: { startDate: formatDate(currentStart), endDate: formatDate(currentEnd) },
    previous: { startDate: formatDate(previousStart), endDate: formatDate(previousEnd) },
  };
}

/**
 * Exhaust the rows Search Console exposes through startRow pagination.
 * `pageExhausted` means no additional API rows were available; Google still
 * documents Search Analytics as a top-rows dataset, not a complete event log.
 */
async function queryAll(queryFn, sc, siteUrl, requestBody, options = {}) {
  const rowLimit = options.rowLimit || ROW_LIMIT;
  const maxPages = options.maxPages || MAX_PAGES;
  const rows = [];
  let startRow = 0;
  let pages = 0;

  while (pages < maxPages) {
    const batch = await queryFn(sc, siteUrl, {
      ...requestBody,
      dataState: 'final',
      rowLimit,
      startRow,
    });
    rows.push(...batch);
    pages += 1;
    startRow += batch.length;

    if (batch.length < rowLimit) {
      return { rows, pages, pageExhausted: true, sentinelRequests: 0 };
    }
  }

  // A full final allowed page can be the true end of the exposed dataset.
  // Probe one additional row before declaring the safety cap exceeded.
  const sentinel = await queryFn(sc, siteUrl, {
    ...requestBody,
    dataState: 'final',
    rowLimit: 1,
    startRow,
  });
  if (sentinel.length === 0) {
    return { rows, pages, pageExhausted: true, sentinelRequests: 1 };
  }

  throw new Error(`GSC_PAGINATION_CAP_REACHED_${maxPages}x${rowLimit}`);
}

function normalizeQuery(value) {
  return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

function numeric(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function aggregateRows(rows, dimensions) {
  const out = new Map();
  for (const row of rows) {
    const keys = Array.isArray(row.keys) ? row.keys : [];
    const dims = {};
    dimensions.forEach((name, index) => { dims[name] = String(keys[index] || ''); });
    const key = JSON.stringify(dims);
    const impressions = numeric(row.impressions);
    const clicks = numeric(row.clicks);
    const position = numeric(row.position);
    const current = out.get(key) || { ...dims, clicks: 0, impressions: 0, weightedPosition: 0 };
    current.clicks += clicks;
    current.impressions += impressions;
    current.weightedPosition += position * impressions;
    out.set(key, current);
  }

  return [...out.values()].map((row) => ({
    ...row,
    ctr: row.impressions > 0 ? row.clicks / row.impressions : 0,
    position: row.impressions > 0 ? row.weightedPosition / row.impressions : 0,
  }));
}

function buildOwnership(rows) {
  const byQuery = new Map();
  for (const row of rows) {
    const [query, page] = row.keys || [];
    if (!query || !page) continue;
    if (!byQuery.has(query)) byQuery.set(query, new Set());
    byQuery.get(query).add(page);
  }
  const matrix = [...byQuery.entries()].map(([query, pages]) => ({ query, pages: [...pages].sort() }));
  return {
    matrix,
    uniqueQueryCount: matrix.length,
    queryUrlPairCount: matrix.reduce((sum, row) => sum + row.pages.length, 0),
    cannibalized: matrix.filter((row) => row.pages.length > 1),
  };
}

function buildWindowComparison(currentRows, previousRows) {
  const previous = new Map(previousRows.map((row) => [`${row.query}\u0000${row.page}`, row]));
  const current = new Map(currentRows.map((row) => [`${row.query}\u0000${row.page}`, row]));
  const keys = new Set([...current.keys(), ...previous.keys()]);
  const rows = [];

  for (const key of keys) {
    const now = current.get(key) || null;
    const before = previous.get(key) || null;
    const query = now?.query || before?.query || '';
    const page = now?.page || before?.page || '';
    const currentClicks = numeric(now?.clicks);
    const previousClicks = numeric(before?.clicks);
    const currentImpressions = numeric(now?.impressions);
    const previousImpressions = numeric(before?.impressions);
    const currentCtr = numeric(now?.ctr);
    const previousCtr = numeric(before?.ctr);
    const currentPosition = numeric(now?.position);
    const previousPosition = numeric(before?.position);

    rows.push({
      query,
      page,
      status: now && before ? 'retained' : now ? 'new' : 'lost',
      current: { clicks: currentClicks, impressions: currentImpressions, ctr: currentCtr, position: currentPosition },
      previous: { clicks: previousClicks, impressions: previousImpressions, ctr: previousCtr, position: previousPosition },
      delta: {
        clicks: currentClicks - previousClicks,
        impressions: currentImpressions - previousImpressions,
        ctr: currentCtr - previousCtr,
        position: now && before ? currentPosition - previousPosition : null,
      },
    });
  }

  return {
    rows,
    summary: {
      pairCount: rows.length,
      newPairCount: rows.filter((row) => row.status === 'new').length,
      lostPairCount: rows.filter((row) => row.status === 'lost').length,
      retainedPairCount: rows.filter((row) => row.status === 'retained').length,
      impressionGainPairCount: rows.filter((row) => row.delta.impressions > 0).length,
      impressionLossPairCount: rows.filter((row) => row.delta.impressions < 0).length,
      clickGainPairCount: rows.filter((row) => row.delta.clicks > 0).length,
      clickLossPairCount: rows.filter((row) => row.delta.clicks < 0).length,
      improvedPositionPairCount: rows.filter((row) => row.status === 'retained' && row.delta.position < 0).length,
      worsenedPositionPairCount: rows.filter((row) => row.status === 'retained' && row.delta.position > 0).length,
    },
  };
}

function buildAnalyses(currentWebRows, previousWebRows, imageRows, videoRows) {
  const queryPage = aggregateRows(currentWebRows, ['query', 'page']);
  const previousQueryPage = aggregateRows(previousWebRows, ['query', 'page']);
  const pages = aggregateRows(currentWebRows, ['page']);
  const queries = aggregateRows(currentWebRows, ['query']);
  const ownership = buildOwnership(currentWebRows);
  const windowComparison = buildWindowComparison(queryPage, previousQueryPage);

  const quickWins = queryPage.filter((row) => row.impressions > 0 && row.position >= 4 && row.position <= 20);
  const sortedPageImpressions = pages.map((row) => row.impressions).sort((a, b) => a - b);
  const sortedCtr = pages.map((row) => row.ctr).sort((a, b) => a - b);
  const percentile = (values, p) => values.length ? values[Math.min(values.length - 1, Math.floor((values.length - 1) * p))] : 0;
  const impressionP75 = percentile(sortedPageImpressions, 0.75);
  const ctrMedian = percentile(sortedCtr, 0.5);
  const highImpressionLowCtr = pages.filter((row) => row.impressions >= impressionP75 && row.ctr < ctrMedian);

  const tierA = {};
  for (const [cluster, pattern] of Object.entries(TIER_A_CLUSTERS)) {
    const matched = queryPage.filter((row) => pattern.test(normalizeQuery(row.query)));
    const pageSet = new Set(matched.map((row) => row.page));
    tierA[cluster] = {
      rows: matched,
      matchedQueryCount: new Set(matched.map((row) => row.query)).size,
      landingPageCount: pageSet.size,
    };
  }

  const branded = queries.filter((row) => /\bnuvanx\b/u.test(normalizeQuery(row.query)));
  const nonBranded = queries.filter((row) => !/\bnuvanx\b/u.test(normalizeQuery(row.query)));

  return {
    queryPage,
    previousQueryPage,
    pages,
    queries,
    ownership,
    windowComparison,
    quickWins,
    highImpressionLowCtr,
    tierA,
    branded,
    nonBranded,
    imageRows,
    videoRows,
  };
}

function redactReport(windows, datasets, analyses) {
  return {
    generatedAt: new Date().toISOString(),
    property: PROPERTY,
    source: SOURCE,
    dataState: 'final',
    settledLagDays: SETTLED_LAG_DAYS,
    windows,
    privacy: {
      repositoryVisibility: 'public',
      rawQueriesPersisted: false,
      exactClicksImpressionsCtrPositionPersisted: false,
      privateOutputEnabled: Boolean(PRIVATE_OUTPUT),
      policy: 'Raw Search Console query/page observations remain in runner memory unless GSC_PRIVATE_OUTPUT is explicitly configured outside the public workspace.',
    },
    api: {
      searchAnalyticsQuerySucceeded: true,
      rowLimit: ROW_LIMIT,
      pagination: 'startRow_with_empty_sentinel_after_full_cap_page',
      dataCompleteness: 'bounded_top_rows_not_guaranteed_complete_by_google',
      datasets: Object.fromEntries(Object.entries(datasets).map(([name, value]) => [name, {
        rows: value.rows.length,
        pages: value.pages,
        pageExhausted: value.pageExhausted,
        sentinelRequests: value.sentinelRequests,
      }])),
    },
    analyses: {
      queryUrlOwnership: {
        generated: true,
        uniqueQueryCount: analyses.ownership.uniqueQueryCount,
        queryUrlPairCount: analyses.ownership.queryUrlPairCount,
        cannibalizedQueryCount: analyses.ownership.cannibalized.length,
      },
      positions4To20: { generated: true, opportunityCount: analyses.quickWins.length },
      highImpressionLowCtr: { generated: true, pageCount: analyses.highImpressionLowCtr.length },
      brandedVsNonBranded: {
        generated: true,
        brandedQueryCount: analyses.branded.length,
        nonBrandedQueryCount: analyses.nonBranded.length,
      },
      tierA: Object.fromEntries(Object.entries(analyses.tierA).map(([cluster, value]) => [cluster, {
        matchedQueryCount: value.matchedQueryCount,
        landingPageCount: value.landingPageCount,
        exactMetricsComputedInMemory: true,
      }])),
      previousWindowComparison: {
        generated: true,
        metricDeltasComputedInMemory: true,
        ...analyses.windowComparison.summary,
      },
      searchSurfaces: {
        web: datasets.currentWeb.rows.length,
        image: datasets.image.rows.length,
        video: datasets.video.rows.length,
      },
    },
    generativeAi: {
      apiAcquisition: 'unsupported_by_search_analytics_type_enum',
      requiredAcquisitionPath: 'authenticated Search Console Generative AI performance report UI/export',
      rollout: 'subset_of_sites',
      baselineStatus: 'pending_first_party_ui_export',
      knownDataAnomaly: {
        active: true,
        since: '2026-08-13',
        effect: 'reported_impressions_drop_due_to_logging_bug',
      },
      sources: [
        'https://developers.google.com/webmaster-tools/v1/searchanalytics/query',
        'https://developers.google.com/search/blog/2026/06/gen-ai-performance-reports',
        'https://support.google.com/webmasters/answer/6211453',
      ],
    },
  };
}

function privateReport(windows, datasets, analyses) {
  return {
    generatedAt: new Date().toISOString(),
    property: PROPERTY,
    source: SOURCE,
    dataState: 'final',
    windows,
    raw: {
      currentWeb: datasets.currentWeb.rows,
      previousWeb: datasets.previousWeb.rows,
      currentDaily: datasets.currentDaily.rows,
      image: datasets.image.rows,
      video: datasets.video.rows,
    },
    derived: {
      queryPage: analyses.queryPage,
      previousQueryPage: analyses.previousQueryPage,
      pages: analyses.pages,
      queries: analyses.queries,
      ownership: analyses.ownership.matrix,
      cannibalization: analyses.ownership.cannibalized,
      positions4To20: analyses.quickWins,
      highImpressionLowCtr: analyses.highImpressionLowCtr,
      branded: analyses.branded,
      nonBranded: analyses.nonBranded,
      tierA: Object.fromEntries(Object.entries(analyses.tierA).map(([cluster, value]) => [cluster, value.rows])),
      windowComparison: analyses.windowComparison.rows,
    },
  };
}

function assertPrivateOutputBoundary(outputPath, env = process.env) {
  if (!outputPath) return;
  const resolved = path.resolve(outputPath);
  const workspace = env.GITHUB_WORKSPACE ? path.resolve(env.GITHUB_WORKSPACE) : '';
  if (env.GITHUB_ACTIONS === 'true' && workspace && (resolved === workspace || resolved.startsWith(`${workspace}${path.sep}`))) {
    throw new Error('GSC_PRIVATE_OUTPUT_MUST_BE_OUTSIDE_PUBLIC_WORKSPACE');
  }
}

async function runFullGscAnalysis() {
  const { createGscClient, queryGsc } = require('./gsc-client');
  const sc = createGscClient();
  const windows = settledWindows();
  const query = (window, dimensions, type = 'web') => queryAll(queryGsc, sc, PROPERTY, {
    startDate: window.startDate,
    endDate: window.endDate,
    dimensions,
    type,
    aggregationType: 'auto',
  });

  const [currentWeb, previousWeb, currentDaily, image, video] = await Promise.all([
    query(windows.current, ['query', 'page', 'country', 'device'], 'web'),
    query(windows.previous, ['query', 'page', 'country', 'device'], 'web'),
    query(windows.current, ['date'], 'web'),
    query(windows.current, ['query', 'page'], 'image'),
    query(windows.current, ['query', 'page'], 'video'),
  ]);

  const datasets = { currentWeb, previousWeb, currentDaily, image, video };
  const analyses = buildAnalyses(currentWeb.rows, previousWeb.rows, image.rows, video.rows);
  const redacted = redactReport(windows, datasets, analyses);

  fs.mkdirSync(path.dirname(REDACTED_OUTPUT), { recursive: true });
  fs.writeFileSync(REDACTED_OUTPUT, JSON.stringify(redacted, null, 2));

  assertPrivateOutputBoundary(PRIVATE_OUTPUT);
  if (PRIVATE_OUTPUT) {
    fs.mkdirSync(path.dirname(path.resolve(PRIVATE_OUTPUT)), { recursive: true });
    fs.writeFileSync(PRIVATE_OUTPUT, JSON.stringify(privateReport(windows, datasets, analyses), null, 2), { mode: 0o600 });
  }

  console.log(
    `GSC_SEARCH_ANALYTICS=PASS property=${PROPERTY} data_state=final current_rows=${currentWeb.rows.length} ` +
    `previous_rows=${previousWeb.rows.length} image_rows=${image.rows.length} video_rows=${video.rows.length} ` +
    `ownership_queries=${analyses.ownership.uniqueQueryCount} cannibalized_queries=${analyses.ownership.cannibalized.length} ` +
    `quick_wins=${analyses.quickWins.length} raw_persisted=${PRIVATE_OUTPUT ? 1 : 0} public_raw=0`
  );

  return { redacted, datasets, analyses };
}

if (require.main === module) {
  runFullGscAnalysis().catch((err) => {
    console.error(`GSC_SEARCH_ANALYTICS=FAIL ${sanitizeGscError(err)}`);
    process.exit(1);
  });
}

module.exports = {
  runFullGscAnalysis,
  settledWindows,
  queryAll,
  aggregateRows,
  buildAnalyses,
  buildWindowComparison,
  redactReport,
  privateReport,
  assertPrivateOutputBoundary,
};
