import fs from 'node:fs';

const allUrls = [
  "https://nuvanx.com/",
  "https://nuvanx.com/acido-hialuronico-relleno-madrid/",
  "https://nuvanx.com/aviso-legal/",
  "https://nuvanx.com/bioestimuladores-colageno-madrid/",
  "https://nuvanx.com/blog/",
  "https://nuvanx.com/botox-madrid-precio-neuromoduladores/",
  "https://nuvanx.com/btl-exilite-ipl-madrid/",
  "https://nuvanx.com/calidad-piel-firmeza-luminosidad-madrid/",
  "https://nuvanx.com/casos-de-pacientes/",
  "https://nuvanx.com/cicatrices-acne-poros-textura-madrid/",
  "https://nuvanx.com/clinicas-de-medicina-estetica-nuvanx/",
  "https://nuvanx.com/clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca/",
  "https://nuvanx.com/contacto/",
  "https://nuvanx.com/contorno-corporal-masculino-madrid/",
  "https://nuvanx.com/emfusion/",
  "https://nuvanx.com/endolaser-corporal-grasa-localizada/",
  "https://nuvanx.com/endolaser-corporal-vs-no-invasivos-grasa-localizada/",
  "https://nuvanx.com/endolift-ciencia-laser-subdermico/",
  "https://nuvanx.com/endolift-facial-papada-mandibula/",
  "https://nuvanx.com/endolift-primeras-72-horas-que-esperar/",
  "https://nuvanx.com/endolift-vs-hifu-diferencias-reales/",
  "https://nuvanx.com/endolift-vs-lifting-quirurgico-cuando-operarse/",
  "https://nuvanx.com/equipo-medico/",
  "https://nuvanx.com/estetica-avanzada/",
  "https://nuvanx.com/exion-body/",
  "https://nuvanx.com/exion-btl-fractional-rf-face-body/",
  "https://nuvanx.com/exion-btl/",
  "https://nuvanx.com/exion-face/",
  "https://nuvanx.com/exion-fractional-rf-vs-morpheus8-comparativa/",
  "https://nuvanx.com/exion-fractional/",
  "https://nuvanx.com/exposoma-cutaneo-envejecimiento-piel-factores-externos/",
  "https://nuvanx.com/flacidez-grasa-localizada-brazos-madrid/",
  "https://nuvanx.com/flacidez-muslos-internos-subgluteo-madrid/",
  "https://nuvanx.com/gracias/",
  "https://nuvanx.com/grasa-espalda-zona-sujetador-madrid/",
  "https://nuvanx.com/grasa-localizada-abdomen-flancos-madrid/",
  "https://nuvanx.com/intrusismo-tratamientos-inyectables-riesgos/",
  "https://nuvanx.com/inversion-medicina-estetica/",
  "https://nuvanx.com/ipl-medica-btl-exilite-manchas-rojeces-acne-fotorejuvenecimiento/",
  "https://nuvanx.com/labios-acido-hialuronico-madrid/",
  "https://nuvanx.com/laser-co2-fraccionado-madrid-textura-cicatrices-poro/",
  "https://nuvanx.com/laser-co2-vs-radiofrecuencia-cuando-elegir/",
  "https://nuvanx.com/laserlipolisis-vs-liposuccion/",
  "https://nuvanx.com/madrid/",
  "https://nuvanx.com/madrid/valoracion/",
  "https://nuvanx.com/manchas-rojeces-fotorejuvenecimiento-ipl-madrid/",
  "https://nuvanx.com/mas-informacion-sobre-las-cookies/",
  "https://nuvanx.com/matriz-diagnostico-facial-estructura-piel-musculo-grasa/",
  "https://nuvanx.com/medicina-estetica-chamberi/",
  "https://nuvanx.com/medicina-estetica-goya-barrio-salamanca/",
  "https://nuvanx.com/medicina-estetica-goya/",
  "https://nuvanx.com/medicina-estetica-laser/",
  "https://nuvanx.com/medicina-estetica/",
  "https://nuvanx.com/neuromodulador-botox-madrid/",
  "https://nuvanx.com/neuromoduladores-botox-madrid/",
  "https://nuvanx.com/neuromoduladores-faciales-madrid/",
  "https://nuvanx.com/neuromoduladores-madrid/",
  "https://nuvanx.com/nosotros/",
  "https://nuvanx.com/ojeras-surco-lagrimal-madrid/",
  "https://nuvanx.com/orden-tratamientos-faciales-que-tratar-primero/",
  "https://nuvanx.com/papada-definicion-mandibular-madrid/",
  "https://nuvanx.com/papada-sin-cirugia-madrid-opciones-endolift/",
  "https://nuvanx.com/plan-anual-medicina-estetica-sin-sobretratar/",
  "https://nuvanx.com/politica-de-cookies-ue/",
  "https://nuvanx.com/politica-de-cookies/",
  "https://nuvanx.com/politica-privacidad/",
  "https://nuvanx.com/por-que-nuvanx/",
  "https://nuvanx.com/precio-neuromoduladores-madrid/",
  "https://nuvanx.com/protocolo-novias-madrid/",
  "https://nuvanx.com/protocolos-signature/",
  "https://nuvanx.com/remodelacion-corporal-laser-madrid/",
  "https://nuvanx.com/rinomodelacion-sin-cirugia-madrid-guia/",
  "https://nuvanx.com/rinomodelacion-sin-cirugia-madrid/",
  "https://nuvanx.com/smartlipo-laserlipolisis-endolift/",
  "https://nuvanx.com/soluciones-medicas/",
  "https://nuvanx.com/tratamiento-postparto-abdomen-contorno-corporal-madrid/",
  "https://nuvanx.com/tratamiento-rodillas-grasa-flacidez-madrid/",
  "https://nuvanx.com/tratamientos-faciales-sin-cirugia-guia-medica-diagnostico/",
  "https://nuvanx.com/tratamientos/",
  "https://nuvanx.com/well-aging-48-cambios-hormonales-piel/",
  "https://nuvanx.com/well-aging-estrategia-medica-global/"
];

const INDEXNOW_KEY = '53546cf8077aa596b76aac664739bbb4';
const HOST = 'nuvanx.com';

async function verifyIndexability(url) {
  try {
    const res = await fetch(url, {
      headers: {
        'user-agent': 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
      },
      redirect: 'follow'
    });
    const status = res.status;
    const finalUrl = res.url;
    const xRobots = res.headers.get('x-robots-tag') || '';
    const html = await res.text();

    const robotsMatch = html.match(/<meta\b[^>]*\bname=["']robots["'][^>]*content=["']([^"']+)["']/i);
    const robotsMeta = robotsMatch ? robotsMatch[1] : '';

    const canonicalMatch = html.match(/<link\b[^>]*\brel=["']canonical["'][^>]*href=["']([^"']+)["']/i);
    const canonicalHref = canonicalMatch ? canonicalMatch[1] : '';

    const isNoIndex = /noindex/i.test(robotsMeta) || /noindex/i.test(xRobots);
    const hasSchema = /<script\b[^>]*type=["']application\/ld\+json["']/i.test(html);

    return {
      url,
      status,
      finalUrl,
      isIndexed: !isNoIndex && status === 200,
      indexingDirective: isNoIndex ? 'noindex' : 'index,follow',
      canonical: canonicalHref,
      canonicalMatches: canonicalHref.replace(/\/$/, '') === url.replace(/\/$/, ''),
      hasSchema
    };
  } catch (err) {
    return {
      url,
      status: 'ERR',
      isIndexed: false,
      error: err.message
    };
  }
}

async function submitIndexNow(urls) {
  const payload = {
    host: HOST,
    key: INDEXNOW_KEY,
    keyLocation: `https://${HOST}/${INDEXNOW_KEY}.txt`,
    urlList: urls
  };

  const endpoints = [
    'https://api.indexnow.org/indexnow',
    'https://www.bing.com/indexnow'
  ];

  const results = [];
  for (const ep of endpoints) {
    try {
      const res = await fetch(ep, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify(payload)
      });
      results.push({ endpoint: ep, status: res.status, ok: res.ok || res.status === 202 || res.status === 200 });
    } catch (e) {
      results.push({ endpoint: ep, error: e.message });
    }
  }
  return results;
}

async function pingGoogleSitemaps() {
  const sitemapUrl = 'https://nuvanx.com/sitemap.xml';
  const pingUrl = `https://www.google.com/ping?sitemap=${encodeURIComponent(sitemapUrl)}`;
  try {
    const res = await fetch(pingUrl);
    return { googlePingStatus: res.status };
  } catch (e) {
    return { googlePingError: e.message };
  }
}

async function main() {
  console.log(`Starting indexability scan for all ${allUrls.length} URLs...`);
  
  const verification = [];
  for (const u of allUrls) {
    const v = await verifyIndexability(u);
    verification.push(v);
    console.log(`[${verification.length}/${allUrls.length}] ${u} -> HTTP ${v.status} | ${v.indexingDirective} | Canonical: ${v.canonicalMatches ? 'OK' : 'MISMATCH (' + v.canonical + ')'}`);
  }

  // Filter indexable URLs for submission
  const indexableUrls = verification.filter(v => v.isIndexed).map(v => v.url);
  console.log(`\nFound ${indexableUrls.length} publicly indexable URLs (out of ${allUrls.length}). Submitting to IndexNow...`);
  
  const indexNowResults = await submitIndexNow(indexableUrls);
  console.log('IndexNow Submission Results:', indexNowResults);

  const googlePing = await pingGoogleSitemaps();
  console.log('Google Sitemaps Ping:', googlePing);

  const outPath = '/Users/MARIA/Desktop/nuvanx-siteground/scripts/seo/indexability-report.json';
  fs.writeFileSync(outPath, JSON.stringify({
    timestamp: new Date().toISOString(),
    totalUrls: allUrls.length,
    indexableCount: indexableUrls.length,
    indexNowResults,
    googlePing,
    verification
  }, null, 2), 'utf8');

  console.log(`\nFull report saved to ${outPath}`);
}

main().catch(console.error);
