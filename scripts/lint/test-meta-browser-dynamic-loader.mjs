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

// Minimal DOM Mock to support HTMLScriptElement descriptor overrides
class HTMLScriptElement {
  constructor() {
    this._attributes = new Map();
  }
  setAttribute(name, value) {
    this._attributes.set(String(name).toLowerCase(), String(value));
  }
  getAttribute(name) {
    const k = String(name).toLowerCase();
    return this._attributes.has(k) ? this._attributes.get(k) : null;
  }
}

Object.defineProperty(HTMLScriptElement.prototype, 'src', {
  get() { return this.getAttribute('src') || ''; },
  set(value) { this.setAttribute('src', value); },
  configurable: true,
  enumerable: true
});

global.HTMLScriptElement = HTMLScriptElement;
global.document = { baseURI: 'https://example.com/' };
// Simulate the URL global
global.URL = URL;

// Execute the extracted script
try {
  eval(jsSource);
} catch (e) {
  console.error('FAIL: Error evaluating JS source:', e);
  process.exit(1);
}

let pass = true;

const assertBlocked = (script, method) => {
  if (script.src !== '') {
    console.error(`FAIL: Legacy pixel URL was not blocked via ${method}`);
    pass = false;
  }
  if (script.getAttribute('data-nvx-meta-browser-retired') !== '1') {
    console.error(`FAIL: Marker attribute not set via ${method}`);
    pass = false;
  }
};

const assertAllowed = (script, method, expectedUrl) => {
  if (script.src !== expectedUrl) {
    console.error(`FAIL: Safe URL was blocked via ${method}. Expected ${expectedUrl}, got ${script.src}`);
    pass = false;
  }
};

// Test 1: Property assignment
const script1 = new HTMLScriptElement();
script1.src = 'https://connect.facebook.net/en_US/fbevents.js';
assertBlocked(script1, 'property assignment');

// Test 2: setAttribute
const script2 = new HTMLScriptElement();
script2.setAttribute('src', 'https://connect.facebook.net/en_US/fbevents.js');
assertBlocked(script2, 'setAttribute');

// Test 3: Safe URL property assignment
const script3 = new HTMLScriptElement();
script3.src = 'https://example.com/safe.js';
assertAllowed(script3, 'property assignment', 'https://example.com/safe.js');

// Test 4: Safe URL setAttribute
const script4 = new HTMLScriptElement();
script4.setAttribute('src', 'https://example.com/safe.js');
assertAllowed(script4, 'setAttribute', 'https://example.com/safe.js');

// Test 5: Mixed case blocked URL
const script5 = new HTMLScriptElement();
script5.src = 'HTTPS://CONNECT.FACEBOOK.NET/EN_US/FBEVENTS.JS';
assertBlocked(script5, 'mixed-case property assignment');

// Test 6: Missing document.baseURI fallback (relative URLs)
const script6 = new HTMLScriptElement();
script6.src = '/safe-relative.js';
assertAllowed(script6, 'relative URL assignment', '/safe-relative.js');

if (!pass) process.exit(1);
console.log('META_BROWSER_DYNAMIC_LOADER_JS=PASS');
