/**
 * Canonical structural validator for Signature phase pages.
 *
 * Accepted markup has two valid forms:
 * 1. Standalone root: A renderer root element (<article> or <div>) containing both
 *    `nvx-brand-page` and `nvx-brand-page--signature` when no global outer frame exists.
 * 2. Governed frame: An outer global `nvx-brand-page` ancestor containing an inner
 *    renderer root with `nvx-brand-page--signature` and `nvx-brand-page__renderer-root`
 *    after `nvx_remove_redundant_inner_brand_page_class` normalizes redundant classes.
 */
export function hasValidSignatureFrame(html) {
  if (typeof html !== 'string' || html.trim() === '') {
    return false;
  }
  const cleanHtml = html.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>|<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '');

  // Form 1: Standalone root containing both classes on the same element
  const standaloneRegex = /<(?:div|article)\b[^>]*\bclass=["']([^"']*)["'][^>]*>/gi;
  let match;
  while ((match = standaloneRegex.exec(cleanHtml)) !== null) {
    const classList = match[1].split(/\s+/).filter(Boolean);
    if (classList.includes('nvx-brand-page') && classList.includes('nvx-brand-page--signature')) {
      return true;
    }
  }

  // Form 2: Outer global nvx-brand-page with inner nvx-brand-page--signature + nvx-brand-page__renderer-root
  const outerGlobalPattern = /<(?:div|article)\b[^>]*\bclass=["'][^"']*\bnvx-brand-page\b[^"']*["'][^>]*>([\s\S]*)<\/(?:div|article)>/i;
  const outerMatch = cleanHtml.match(outerGlobalPattern);
  if (outerMatch) {
    const innerHtml = outerMatch[1];
    const innerElemRegex = /<(?:div|article)\b[^>]*\bclass=["']([^"']*)["'][^>]*>/gi;
    let innerTag;
    while ((innerTag = innerElemRegex.exec(innerHtml)) !== null) {
      const innerClasses = innerTag[1].split(/\s+/).filter(Boolean);
      if (innerClasses.includes('nvx-brand-page--signature') && innerClasses.includes('nvx-brand-page__renderer-root')) {
        return true;
      }
    }
  }

  return false;
}
