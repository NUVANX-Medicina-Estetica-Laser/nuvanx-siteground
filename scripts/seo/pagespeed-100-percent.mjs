import fs from 'node:fs';
import path from 'node:path';

const envFile = fs.readFileSync('/Users/MARIA/Desktop/nuvanx-siteground/.env.local', 'utf8');
const apiKeyMatch = envFile.match(/(?:GOOGLE_PAGESPEED_API_KEY|PAGESPEED_API_KEY)=['"]?([^'"\n]+)/);
const apiKey = apiKeyMatch ? apiKeyMatch[1] : '';

if (!apiKey) {
  console.error('ERROR: No PageSpeed API key found in .env.local');
  process.exit(1);
}

// 81 URLs
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

async function auditSingle(url, strategy, retry = 2) {
  const apiUrl = `https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=${encodeURIComponent(url)}&strategy=${strategy}&key=${apiKey}&category=performance&category=accessibility&category=best-practices&category=seo`;
  
  try {
    const res = await fetch(apiUrl);
    if (!res.ok) {
      const err = await res.text();
      throw new Error(`HTTP ${res.status}: ${err.slice(0, 150)}`);
    }
    const json = await res.json();
    const lh = json.lighthouseResult;
    const cats = lh.categories;
    const audits = lh.audits;

    return {
      url,
      path: new URL(url).pathname,
      strategy,
      performance: Math.round((cats.performance?.score || 0) * 100),
      accessibility: Math.round((cats.accessibility?.score || 0) * 100),
      bestPractices: Math.round((cats['best-practices']?.score || 0) * 100),
      seo: Math.round((cats.seo?.score || 0) * 100),
      fcp: audits['first-contentful-paint']?.displayValue || 'N/A',
      lcp: audits['largest-contentful-paint']?.displayValue || 'N/A',
      tbt: audits['total-blocking-time']?.displayValue || 'N/A',
      cls: audits['cumulative-layout-shift']?.displayValue || 'N/A',
      speedIndex: audits['speed-index']?.displayValue || 'N/A',
      ttfb: audits['server-response-time']?.displayValue || 'N/A'
    };
  } catch (error) {
    if (retry > 0) {
      await new Promise(r => setTimeout(r, 2000));
      return auditSingle(url, strategy, retry - 1);
    }
    return {
      url,
      path: new URL(url).pathname,
      strategy,
      error: error.message
    };
  }
}

async function runPool(items, concurrency, worker) {
  const results = [];
  let index = 0;

  async function next() {
    while (index < items.length) {
      const current = index++;
      const item = items[current];
      const result = await worker(item, current, items.length);
      results[current] = result;
    }
  }

  const workers = Array.from({ length: concurrency }, () => next());
  await Promise.all(workers);
  return results;
}

async function main() {
  console.log(`Starting 100% PageSpeed Audit for ${allUrls.length} URLs (Mobile + Desktop = ${allUrls.length * 2} runs)...`);
  
  const tasks = [];
  for (const u of allUrls) {
    tasks.push({ url: u, strategy: 'mobile' });
    tasks.push({ url: u, strategy: 'desktop' });
  }

  let completed = 0;
  const total = tasks.length;

  const results = await runPool(tasks, 4, async (task) => {
    const res = await auditSingle(task.url, task.strategy);
    completed++;
    const sym = task.strategy === 'mobile' ? '📱' : '💻';
    if (res.error) {
      console.log(`[${completed}/${total}] ${sym} ${task.url} -> ❌ ERROR: ${res.error}`);
    } else {
      console.log(`[${completed}/${total}] ${sym} ${res.path} -> Perf:${res.performance} Acc:${res.accessibility} BP:${res.bestPractices} SEO:${res.seo} | LCP:${res.lcp} TBT:${res.tbt} CLS:${res.cls}`);
    }
    return res;
  });

  const outPath = '/Users/MARIA/Desktop/nuvanx-siteground/scripts/seo/pagespeed-all-81-pages.json';
  fs.writeFileSync(outPath, JSON.stringify(results, null, 2), 'utf8');
  console.log(`\nAudit 100% Complete! Results saved to ${outPath}`);
}

main().catch(console.error);
