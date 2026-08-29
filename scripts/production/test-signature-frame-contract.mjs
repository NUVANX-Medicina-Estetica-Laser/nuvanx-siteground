import assert from 'node:assert/strict';
import { hasValidSignatureFrame } from './signature-frame-contract.mjs';

const validStandalone = [
  '<article class="nvx-brand-page nvx-brand-page--signature"><div class="entry-content nvx-page__content"><section class="nvx-brand-hero"></section></div></article>',
  '<div class="nvx-brand-hero-wrapper nvx-brand-page nvx-brand-page--signature extra-class"><main></main></div>',
  '<article class="nvx-brand-page--signature nvx-brand-page"><div class="entry-content"></div></article>',
  '<div class="other-wrapper"><article class="nvx-brand-page nvx-brand-page--signature"><div class="entry-content"></div></article></div>',
];

const validGoverned = [
  '<div class="nvx-brand-page" id="nvx-site-root"><main><article class="nvx-brand-page--signature nvx-brand-page__renderer-root"><div class="entry-content"></div></article></main></div>',
  '<div class="site-frame nvx-brand-page"><article class="nvx-brand-page__renderer-root nvx-brand-page--signature"><section></section></article></div>',
  '<article class="nvx-brand-page"><div class="nvx-brand-page--signature nvx-brand-page__renderer-root"></div></article>',
];

const invalid = [
  // Page-specific class only without nvx-brand-page
  '<article class="nvx-brand-page--signature"><div class="entry-content"></div></article>',
  // Missing nvx-brand-page on outer ancestor when inner has renderer-root
  '<div class="other-wrapper"><article class="nvx-brand-page--signature nvx-brand-page__renderer-root"><div class="entry-content"></div></article></div>',
  // Outer global frame present but inner missing page-specific modifier
  '<div class="nvx-brand-page"><article class="nvx-brand-page__renderer-root"><div class="entry-content"></div></article></div>',
  // Non-matching tag/element
  '<span class="nvx-brand-page nvx-brand-page--signature">Text</span>',
  // Empty
  '',
  // Redundant nested duplicate frames (outer global + inner standalone unstripped)
  '<div class="nvx-brand-page"><article class="nvx-brand-page nvx-brand-page--signature"><div class="entry-content"></div></article></div>',
  '<article class="nvx-brand-page"><div class="nvx-brand-page nvx-brand-page--signature"></div></article>',
  // Multiple nested outer nvx-brand-page ancestors before governed renderer
  '<div class="nvx-brand-page"><div class="nvx-brand-page"><article class="nvx-brand-page--signature nvx-brand-page__renderer-root"><div class="entry-content"></div></article></div></div>',
  '<div class="nvx-brand-page"><main class="nvx-brand-page"><article class="nvx-brand-page--signature nvx-brand-page__renderer-root"></article></main></div>',
  // Sibling outer and inner elements (not an ancestor)
  '<div class="nvx-brand-page"></div><article class="nvx-brand-page--signature nvx-brand-page__renderer-root"><div class="entry-content"></div></article>',
  // Modifier-only outer class (token exactness)
  '<div class="nvx-brand-page--hero-frame"><article class="nvx-brand-page--signature nvx-brand-page__renderer-root"><div class="entry-content"></div></article></div>',
  // Matching markup inside HTML comment
  '<!-- <article class="nvx-brand-page nvx-brand-page--signature"><div class="entry-content"></div></article> -->',
  // Inner renderer root with redundant nvx-brand-page in governed frame
  '<div class="nvx-brand-page"><article class="nvx-brand-page nvx-brand-page--signature nvx-brand-page__renderer-root"><div class="entry-content"></div></article></div>',
  // Matching class inside script or style block
  '<script>const html = \'<article class="nvx-brand-page nvx-brand-page--signature"></article>\';</script>',
  '<style>.nvx-brand-page.nvx-brand-page--signature { display: block; }</style>',
];

for (const [index, html] of validStandalone.entries()) {
  assert.equal(hasValidSignatureFrame(html), true, `validStandalone case ${index + 1} must pass`);
}

for (const [index, html] of validGoverned.entries()) {
  assert.equal(hasValidSignatureFrame(html), true, `validGoverned case ${index + 1} must pass`);
}

for (const [index, html] of invalid.entries()) {
  assert.equal(hasValidSignatureFrame(html), false, `invalid case ${index + 1} must fail`);
}

console.log(
  `SIGNATURE_FRAME_CONTRACT_TEST=PASS standalone=${validStandalone.length} governed=${validGoverned.length} invalid=${invalid.length}`,
);
