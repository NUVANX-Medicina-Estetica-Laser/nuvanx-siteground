'use strict';

const fs = require('node:fs');
const path = require('node:path');

const READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

function resolveGscAuthOptions(baseDir = __dirname) {
  const credentialsPath = path.resolve(baseDir, 'credentials.json');
  const options = { scopes: [READONLY_SCOPE] };

  if (!fs.existsSync(credentialsPath)) {
    return { options, source: 'ADC', credentialsPath };
  }

  const credentials = JSON.parse(fs.readFileSync(credentialsPath, 'utf8'));
  options.credentials = credentials;
  return { options, source: 'PRIVATE_JSON', credentialsPath };
}

module.exports = {
  READONLY_SCOPE,
  resolveGscAuthOptions,
};
