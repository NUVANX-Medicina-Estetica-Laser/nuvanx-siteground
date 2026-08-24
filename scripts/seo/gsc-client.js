'use strict';

const { google } = require('googleapis');
const { resolveGscAuthOptions } = require('./gsc-auth-options');

function formatDate(date) {
  return date.toISOString().split('T')[0];
}

/**
 * Returns dynamic date ranges taking typical Google Search Console ~3 day reporting latency into account.
 */
function getGscDateRanges() {
  const now = new Date();
  const end = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - 3));

  const start30 = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth(), end.getUTCDate() - 29));
  const start7 = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth(), end.getUTCDate() - 6));

  const prev7End = new Date(Date.UTC(start7.getUTCFullYear(), start7.getUTCMonth(), start7.getUTCDate() - 1));
  const prev7Start = new Date(Date.UTC(prev7End.getUTCFullYear(), prev7End.getUTCMonth(), prev7End.getUTCDate() - 6));

  return {
    endDate: formatDate(end),
    startDate30: formatDate(start30),
    startDate7: formatDate(start7),
    prev7Start: formatDate(prev7Start),
    prev7End: formatDate(prev7End),
  };
}

function createGscClient() {
  const { options } = resolveGscAuthOptions(__dirname);
  const auth = new google.auth.GoogleAuth(options);
  return google.searchconsole({ version: 'v1', auth });
}

async function queryGsc(sc, siteUrl, requestBody) {
  const res = await sc.searchanalytics.query({
    siteUrl,
    requestBody,
  });
  return res.data.rows || [];
}

module.exports = {
  formatDate,
  getGscDateRanges,
  createGscClient,
  queryGsc,
};
