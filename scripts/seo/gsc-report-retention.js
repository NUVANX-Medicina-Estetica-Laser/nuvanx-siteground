'use strict';

const fs = require('node:fs');

function persistRedactedSearchAnalytics(indexingResultsPath, redacted) {
  if (!fs.existsSync(indexingResultsPath)) {
    throw new Error('GSC_INDEXING_RESULTS_MISSING_FOR_REDACTED_RETENTION');
  }
  const document = JSON.parse(fs.readFileSync(indexingResultsPath, 'utf8'));
  document.searchAnalyticsRedacted = redacted;
  fs.writeFileSync(indexingResultsPath, JSON.stringify(document, null, 2));
  return document;
}

module.exports = {
  persistRedactedSearchAnalytics,
};
