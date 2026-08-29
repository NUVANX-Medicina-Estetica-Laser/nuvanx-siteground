/**
 * Canonical structural validator for Signature phase pages.
 *
 * Accepted markup has two valid forms:
 * 1. Standalone root: A renderer root element (<article> or <div>) containing both
 *    `nvx-brand-page` and `nvx-brand-page--signature` when no global outer frame exists.
 * 2. Governed frame: Exactly ONE outer global `nvx-brand-page` ancestor containing an inner
 *    renderer root with `nvx-brand-page--signature` and `nvx-brand-page__renderer-root`
 *    after `nvx_remove_redundant_inner_brand_page_class` normalizes redundant classes.
 */
export function hasValidSignatureFrame(html) {
  if (typeof html !== 'string' || html.trim() === '') {
    return false;
  }

  // Strip comments, scripts, and styles before tokenization
  const cleanHtml = html
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '');

  const voidElements = new Set([
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr',
  ]);

  const tagRegex = /<\/?([a-zA-Z0-9:-]+)([^>]*?)(\/?)>/g;
  const stack = [];
  let match;

  let foundValidStandalone = false;
  let foundValidGoverned = false;

  while ((match = tagRegex.exec(cleanHtml)) !== null) {
    const isClosing = match[0].startsWith('</');
    const tagName = match[1].toLowerCase();
    const attrString = match[2];
    const isSelfClosing = match[3] === '/' || voidElements.has(tagName);

    if (isClosing) {
      for (let i = stack.length - 1; i >= 0; i--) {
        if (stack[i].tag === tagName) {
          stack.length = i;
          break;
        }
      }
      continue;
    }

    let classes = new Set();
    const classMatch = attrString.match(/\bclass=["']([^"']*)["']/i);
    if (classMatch) {
      classes = new Set(classMatch[1].trim().split(/\s+/).filter(Boolean));
    }

    const currentElem = { tag: tagName, classes };

    if (tagName === 'div' || tagName === 'article') {
      const hasBrandPage = classes.has('nvx-brand-page');
      const hasSignature = classes.has('nvx-brand-page--signature');
      const hasRendererRoot = classes.has('nvx-brand-page__renderer-root');

      const brandPageAncestorCount = stack.filter((parent) => parent.classes.has('nvx-brand-page')).length;

      // Form 1: Standalone root (requires both classes and NO outer nvx-brand-page ancestor)
      if (hasBrandPage && hasSignature && brandPageAncestorCount === 0) {
        foundValidStandalone = true;
      }

      // Form 2: Governed frame (requires signature + renderer-root under exactly ONE nvx-brand-page ancestor, rejecting redundant inner nvx-brand-page)
      if (!hasBrandPage && hasSignature && hasRendererRoot && brandPageAncestorCount === 1) {
        foundValidGoverned = true;
      }
    }

    if (!isSelfClosing) {
      stack.push(currentElem);
    }
  }

  return foundValidStandalone || foundValidGoverned;
}
