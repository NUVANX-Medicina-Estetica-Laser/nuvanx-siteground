import assert from 'node:assert/strict';
import { isIgnorableExternalConsoleError } from './console-error-classifier.mjs';
import './test-browser-request-failure-classifier.mjs';

const corsNoise = "Access to XMLHttpRequest at 'https://maps.googleapis.com/$rpc/google.internal.maps.mapsjs.v1.MapsJsInternalService/GetPlaceWidgetMetadata' from origin 'https://www.google.com' has been blocked by CORS policy";
const widgetNoise = '<gmp-place-details-compact>: Encountered a network request error: Rpc failed due to xhr error. uri: https://maps.googleapis.com/$rpc/google.internal.maps.mapsjs.v1.MapsJsInternalService/GetPlaceWidgetMetadata';

assert.equal(isIgnorableExternalConsoleError(corsNoise), true);
assert.equal(isIgnorableExternalConsoleError(widgetNoise), true);
assert.equal(isIgnorableExternalConsoleError('Uncaught TypeError: app.init is not a function'), false);
assert.equal(isIgnorableExternalConsoleError('CORS error from https://nuvanx.com/api'), false);

console.log('CONSOLE_ERROR_CLASSIFIER=PASS ignored_known_google_place_noise=2 blocked_unknown_errors=2');
