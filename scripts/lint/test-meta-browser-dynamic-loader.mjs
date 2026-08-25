import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const runtimePath = resolve(__dirname, '../../wp-content/themes/nuvanx-medical/inc/nvx-meta-browser-governance.php');

const phpSource = fs.readFileSync(runtimePath, 'utf8');
const jsMatch = phpSource.match(/<script id="nvx-meta-browser-owner-retired">\s*([\s\S]*?)\s*<\/script>/);

if (!jsMatch) {
  console.error('FAIL: Could not extract dynamic loader JS');
  process.exit(1);
}
const jsSource = jsMatch[1];

// Minimal DOM mock that exercises the actual script-element URL assignment APIs
// guarded by the production runtime.
class HTMLScriptElement {
  constructor() {
    this._attributes = new Map();
  }
  setAttribute(name, value) {
    this._attributes.set(String(name).toLowerCase(), String(value));
  }
  setAttributeNS(namespace, name, value) {
    void namespace;
    this.setAttribute(name, value);
  }
  getAttribute(name) {
    const key = String(name).toLowerCase();
    return this._attributes.has(key) ? this._attributes.get(key) : null;
  }
}

Object.defineProperty(HTMLScriptElement.prototype, 'src', {
  get() { return this.getAttribute('src') || ''; },
  set(value) { this.setAttribute('src', value); },
  configurable: true,
  enumerable: true,
});

global.HTMLScriptElement = HTMLScriptElement;
global.document = { baseURI: 'https://nuvanx.com/' };
global.URL = URL;

try {
  eval(jsSource);
} catch (error) {
  console.error('FAIL: Error evaluating JS source:', error);
  process.exit(1);
}

let pass = true;
const blockedUrl = 'https://connect.facebook.net/en_US/fbevents.js';
const safeUrl = 'https://www.googletagmanager.com/gtm.js?id=GTM-TEST';

const assertBlocked = (script, method) => {
  if (script.src !== '' || script.getAttribute('src') !== null) {
    console.error(`FAIL: Legacy pixel URL was not blocked via ${method}`);
    pass = false;
  }
  if (script.getAttribute('data-nvx-meta-browser-retired') !== '1') {
    console.error(`FAIL: Marker attribute not set via ${method}`);
    pass = false;
  }
};

const assertAllowed = (script, method, expectedUrl) => {
  if (script.src !== expectedUrl || script.getAttribute('src') !== expectedUrl) {
    console.error(`FAIL: Safe URL was blocked via ${method}. Expected ${expectedUrl}, got ${script.src}`);
    pass = false;
  }
  if (script.getAttribute('data-nvx-meta-browser-retired') !== null) {
    console.error(`FAIL: Safe URL was incorrectly marked via ${method}`);
    pass = false;
  }
};

const propertyScript = new HTMLScriptElement();
propertyScript.src = blockedUrl;
assertBlocked(propertyScript, 'property assignment');

const attributeScript = new HTMLScriptElement();
attributeScript.setAttribute('src', blockedUrl);
assertBlocked(attributeScript, 'setAttribute');

const namespaceScript = new HTMLScriptElement();
namespaceScript.setAttributeNS(null, 'src', blockedUrl);
assertBlocked(namespaceScript, 'setAttributeNS');

const safePropertyScript = new HTMLScriptElement();
safePropertyScript.src = safeUrl;
assertAllowed(safePropertyScript, 'safe property assignment', safeUrl);

const safeAttributeScript = new HTMLScriptElement();
safeAttributeScript.setAttribute('src', safeUrl);
assertAllowed(safeAttributeScript, 'safe setAttribute', safeUrl);

const safeNamespaceScript = new HTMLScriptElement();
safeNamespaceScript.setAttributeNS(null, 'src', safeUrl);
assertAllowed(safeNamespaceScript, 'safe setAttributeNS', safeUrl);

const mixedCaseScript = new HTMLScriptElement();
mixedCaseScript.src = 'HTTPS://CONNECT.FACEBOOK.NET/EN_US/FBEVENTS.JS';
assertBlocked(mixedCaseScript, 'mixed-case property assignment');

const relativeScript = new HTMLScriptElement();
relativeScript.src = '/safe-relative.js';
assertAllowed(relativeScript, 'relative URL assignment', '/safe-relative.js');

const nonPixelFacebookScript = new HTMLScriptElement();
nonPixelFacebookScript.src = 'https://connect.facebook.net/en_US/sdk.js';
assertAllowed(nonPixelFacebookScript, 'non-pixel Facebook script', 'https://connect.facebook.net/en_US/sdk.js');

if (!pass) process.exit(1);
console.log('META_BROWSER_DYNAMIC_LOADER_JS=PASS property=blocked setAttribute=blocked setAttributeNS=blocked unrelated=allowed');
