function stripScriptAndStyleText(html) {
  return String(html || '')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '');
}

export function firstPartyValoracionFormTags(html) {
  const markup = stripScriptAndStyleText(html);
  const formTags = markup.match(/<form\b[^>]*>/gi) || [];
  return formTags.filter((tag) => {
    const classMarker = /\bclass\s*=\s*(?:"[^"]*\bnvx-valoracion-direct-form\b[^"]*"|'[^']*\bnvx-valoracion-direct-form\b[^']*')/i.test(tag);
    const dataMarker = /\bdata-nvx-direct-form(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?(?=\s|\/?>)/i.test(tag);
    return classMarker || dataMarker;
  });
}

export function firstPartyValoracionOwnerTags(html) {
  const markup = stripScriptAndStyleText(html);
  const divTags = markup.match(/<div\b[^>]*>/gi) || [];
  return divTags.filter((tag) => {
    const idMarker = /\bid\s*=\s*(?:"nvx-valoracion-first-party-form"|'nvx-valoracion-first-party-form')/i.test(tag);
    const ownerMarker = /\bdata-nvx-first-party-owner\s*=\s*(?:"1"|'1'|1)(?=\s|\/?>)/i.test(tag);
    return idMarker && ownerMarker;
  });
}

export function browserHubSpotValoracionOwners(html) {
  const markup = stripScriptAndStyleText(html);
  const frameTags = markup.match(/<div\b[^>]*class\s*=\s*(?:"[^"]*\bhs-form-frame\b[^"]*"|'[^']*\bhs-form-frame\b[^']*')[^>]*>/gi) || [];
  const legacyHosts = markup.match(/<div\b[^>]*\bid\s*=\s*(?:"nvx-hubspot-native-form"|'nvx-hubspot-native-form')[^>]*>/gi) || [];
  const hubspotIframes = markup.match(/<iframe\b[^>]*(?:hsforms|hubspot)[^>]*>/gi) || [];
  return [...frameTags, ...legacyHosts, ...hubspotIframes];
}

export function canonicalValoracionFirstPartyIssues(html) {
  const issues = [];
  const owners = firstPartyValoracionOwnerTags(html);
  const forms = firstPartyValoracionFormTags(html);
  const browserOwners = browserHubSpotValoracionOwners(html);

  if (owners.length !== 1) {
    issues.push(`Expected exactly one canonical first-party valoración owner, found ${owners.length}`);
  }
  if (forms.length !== 1) {
    issues.push(`Expected exactly one canonical first-party valoración form, found ${forms.length}`);
  }
  if (browserOwners.length !== 0) {
    issues.push(`Expected zero browser-owned HubSpot valoración surfaces, found ${browserOwners.length}`);
  }

  if (owners.length === 1 && forms.length === 1) {
    const markup = stripScriptAndStyleText(html);
    const ownerStart = markup.search(/<div\b[^>]*\bid\s*=\s*(?:"nvx-valoracion-first-party-form"|'nvx-valoracion-first-party-form')[^>]*>/i);
    const formStart = markup.search(/<form\b[^>]*(?:\bnvx-valoracion-direct-form\b|\bdata-nvx-direct-form\b)[^>]*>/i);
    if (ownerStart < 0 || formStart < ownerStart) {
      issues.push('Canonical first-party valoración form is not contained after its owner mount');
    }
  }

  return issues;
}
