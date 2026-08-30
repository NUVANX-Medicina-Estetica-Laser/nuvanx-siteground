import fs from 'node:fs';
import path from 'node:path';

const envFile = fs.readFileSync('/Users/MARIA/Desktop/nuvanx-siteground/.env.local', 'utf8');
const apiKeyMatch = envFile.match(/(?:GOOGLE_PAGESPEED_API_KEY|PAGESPEED_API_KEY)=['"]?([^'"\n]+)/);
const apiKey = apiKeyMatch ? apiKeyMatch[1] : '';

if (!apiKey) {
  console.error('ERROR: No PageSpeed API key found in .env.local');
  process.exit(1);
}

const targetUrls = [
  { name: 'Portada (Home)', path: '/' },
  { name: 'Valoración Médica (Landing Conversión)', path: '/madrid/valoracion/' },
  { name: 'Hub Tratamientos', path: '/tratamientos/' },
  { name: 'Endolift Facial (Tratamiento Top 1)', path: '/endolift-facial-papada-mandibula/' },
  { name: 'Láser CO2 Fraccionado (Tratamiento Top 2)', path: '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/' },
  { name: 'Neuromoduladores / Botox (Tratamiento Top 3)', path: '/neuromoduladores-botox-madrid/' },
  { name: 'Sede Chamberí (Local SEO)', path: '/medicina-estetica-chamberi/' },
  { name: 'Sede Salamanca–Goya (Local SEO)', path: '/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/' },
  { name: 'Equipo Médico (E-E-A-T)', path: '/equipo-medico/' },
  { name: 'Nosotros / Clínicas', path: '/nosotros/' },
  { name: 'Blog Médico (Hub Editorial)', path: '/blog/' },
  { name: 'Artículo Blog: Endolift Láser Subdérmico', path: '/endolift-ciencia-laser-subdermico/' },
];

const BASE_DOMAIN = 'https://nuvanx.com';

async function auditUrl(urlPath, strategy) {
  const fullUrl = `${BASE_DOMAIN}${urlPath}`;
  const apiUrl = `https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=${encodeURIComponent(fullUrl)}&strategy=${strategy}&key=${apiKey}&category=performance&category=accessibility&category=best-practices&category=seo`;
  
  const res = await fetch(apiUrl);
  if (!res.ok) {
    const errorText = await res.text();
    throw new Error(`HTTP ${res.status} for ${fullUrl} (${strategy}): ${errorText.slice(0, 200)}`);
  }
  const json = await res.json();
  const lh = json.lighthouseResult;
  const cats = lh.categories;
  const audits = lh.audits;

  // Extract opportunities
  const opportunities = [];
  const oppAudits = [
    'render-blocking-resources',
    'unused-css-rules',
    'unused-javascript',
    'modern-image-formats',
    'uses-optimized-images',
    'uses-responsive-images',
    'uses-text-compression',
    'server-response-time',
    'font-display',
    'layout-shifts',
    'prioritize-lcp-image'
  ];
  for (const oppId of oppAudits) {
    const a = audits[oppId];
    if (a && a.score !== null && a.score < 0.9 && (a.numericValue > 0 || (a.details && a.details.items && a.details.items.length > 0))) {
      opportunities.push({
        id: oppId,
        title: a.title,
        displayValue: a.displayValue || '',
        score: a.score
      });
    }
  }

  return {
    url: fullUrl,
    path: urlPath,
    strategy,
    performance: Math.round((cats.performance?.score || 0) * 100),
    accessibility: Math.round((cats.accessibility?.score || 0) * 100),
    bestPractices: Math.round((cats['best-practices']?.score || 0) * 100),
    seo: Math.round((cats.seo?.score || 0) * 100),
    fcp: audits['first-contentful-paint']?.displayValue || 'N/A',
    fcpNum: audits['first-contentful-paint']?.numericValue || 0,
    lcp: audits['largest-contentful-paint']?.displayValue || 'N/A',
    lcpNum: audits['largest-contentful-paint']?.numericValue || 0,
    tbt: audits['total-blocking-time']?.displayValue || 'N/A',
    tbtNum: audits['total-blocking-time']?.numericValue || 0,
    cls: audits['cumulative-layout-shift']?.displayValue || 'N/A',
    clsNum: audits['cumulative-layout-shift']?.numericValue || 0,
    speedIndex: audits['speed-index']?.displayValue || 'N/A',
    speedIndexNum: audits['speed-index']?.numericValue || 0,
    ttfb: audits['server-response-time']?.displayValue || 'N/A',
    opportunities
  };
}

async function runAll() {
  console.log(`Starting PageSpeed audit for ${targetUrls.length} pages in Mobile & Desktop...`);
  const results = [];

  for (const item of targetUrls) {
    console.log(`Auditing: ${item.name} (${item.path})`);
    
    // Mobile
    try {
      const mob = await auditUrl(item.path, 'mobile');
      results.push({ name: item.name, ...mob });
      console.log(`  📱 Mobile: Perf=${mob.performance} Acc=${mob.accessibility} BP=${mob.bestPractices} SEO=${mob.seo} | LCP=${mob.lcp} TBT=${mob.tbt} CLS=${mob.cls}`);
    } catch (err) {
      console.error(`  ❌ Mobile Error: ${err.message}`);
    }

    // Desktop
    try {
      const dsk = await auditUrl(item.path, 'desktop');
      results.push({ name: item.name, ...dsk });
      console.log(`  💻 Desktop: Perf=${dsk.performance} Acc=${dsk.accessibility} BP=${dsk.bestPractices} SEO=${dsk.seo} | LCP=${dsk.lcp} TBT=${dsk.tbt} CLS=${dsk.cls}`);
    } catch (err) {
      console.error(`  ❌ Desktop Error: ${err.message}`);
    }

    // Short pause
    await new Promise(r => setTimeout(r, 800));
  }

  const outPath = '/Users/MARIA/Desktop/nuvanx-siteground/scripts/seo/pagespeed-results.json';
  fs.writeFileSync(outPath, JSON.stringify(results, null, 2), 'utf8');
  console.log(`\nAudit complete! Results saved to ${outPath}`);
}

runAll().catch(console.error);
