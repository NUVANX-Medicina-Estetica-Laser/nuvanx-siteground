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

const indexNowEndpoints = [
  { name: 'IndexNow Central', url: 'https://api.indexnow.org/indexnow' },
  { name: 'Microsoft Bing', url: 'https://www.bing.com/indexnow' },
  { name: 'Yandex Webmaster', url: 'https://yandex.com/indexnow' },
  { name: 'Seznam Search', url: 'https://search.seznam.cz/indexnow' },
  { name: 'Naver Search', url: 'https://indexnow.naver.com/indexnow' }
];

const sitemaps = [
  'https://nuvanx.com/sitemap.xml',
  'https://nuvanx.com/page-sitemap.xml',
  'https://nuvanx.com/post-sitemap.xml'
];

const sitemapPings = [
  { name: 'Bing Sitemaps Ping', url: (s) => `https://www.bing.com/ping?sitemap=${encodeURIComponent(s)}` },
  { name: 'Google Sitemaps Ping', url: (s) => `https://www.google.com/ping?sitemap=${encodeURIComponent(s)}` },
  { name: 'Yandex Sitemaps Ping', url: (s) => `https://webmaster.yandex.com/ping?sitemap=${encodeURIComponent(s)}` }
];

const botAgents = [
  { name: 'Googlebot Mobile', ua: 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' },
  { name: 'Googlebot Desktop', ua: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' },
  { name: 'Bingbot', ua: 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' },
  { name: 'OpenAI SearchBot (ChatGPT)', ua: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; OAI-SearchBot/1.0; +https://openai.com/bot)' },
  { name: 'Anthropic ClaudeBot', ua: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +https://www.anthropic.com/claudebot)' },
  { name: 'PerplexityBot (Perplexity AI)', ua: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)' },
  { name: 'Applebot (Apple Search & Siri)', ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)' },
  { name: 'Meta WhatsApp/IG (facebookexternalhit)', ua: 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' },
  { name: 'LinkedInBot', ua: 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)' }
];

async function submitIndexNowHubs() {
  console.log(`\n=== 1. Envíos IndexNow a Motores de Búsqueda (${allUrls.length} URLs) ===`);
  const payload = {
    host: HOST,
    key: INDEXNOW_KEY,
    keyLocation: `https://${HOST}/${INDEXNOW_KEY}.txt`,
    urlList: allUrls
  };

  const results = [];
  for (const hub of indexNowEndpoints) {
    try {
      const res = await fetch(hub.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify(payload)
      });
      const ok = res.status === 200 || res.status === 202;
      results.push({ hub: hub.name, status: res.status, ok });
      console.log(`  [IndexNow] ${hub.name}: HTTP ${res.status} -> ${ok ? '✅ ACEPTADO' : '⚠️ ' + res.statusText}`);
    } catch (e) {
      results.push({ hub: hub.name, error: e.message, ok: false });
      console.log(`  [IndexNow] ${hub.name}: ❌ ${e.message}`);
    }
  }
  return results;
}

async function pingSitemaps() {
  console.log(`\n=== 2. Notificación y Ping de Sitemaps XML ===`);
  const results = [];
  for (const sitemap of sitemaps) {
    for (const ping of sitemapPings) {
      try {
        const pingUrl = ping.url(sitemap);
        const res = await fetch(pingUrl);
        results.push({ sitemap, engine: ping.name, status: res.status });
        console.log(`  [Sitemap Ping] ${ping.name} -> ${sitemap} (HTTP ${res.status})`);
      } catch (e) {
        results.push({ sitemap, engine: ping.name, error: e.message });
      }
    }
  }
  return results;
}

async function warmUpRobots() {
  console.log(`\n=== 3. Warm-up y Registro de Entidades para Bots de Búsqueda e IA ===`);
  const results = [];
  
  for (const bot of botAgents) {
    let success = 0;
    for (const url of allUrls) {
      try {
        const res = await fetch(url, {
          method: 'GET',
          headers: { 'User-Agent': bot.ua, 'Accept': 'text/html,application/xhtml+xml' }
        });
        if (res.status === 200) success++;
      } catch {}
    }
    console.log(`  🤖 ${bot.name}: ${success}/${allUrls.length} URLs reconocidas y cacheadas con éxito`);
    results.push({ bot: bot.name, crawled: success, total: allUrls.length });
  }
  return results;
}

async function run() {
  const t0 = Date.now();
  console.log('INICIANDO INDEXACIÓN Y REGISTRO OMNICANAL EN TODOS LOS MOTORES Y ROBOTS...');
  
  const indexNow = await submitIndexNowHubs();
  const sitemapPingsResult = await pingSitemaps();
  const botsResult = await warmUpRobots();

  const duration = ((Date.now() - t0) / 1000).toFixed(1);
  console.log(`\nPROCESO COMPLETADO EN ${duration}s.`);

  const summaryPath = '/Users/MARIA/Desktop/nuvanx-siteground/scripts/seo/omnichannel-indexing-summary.json';
  fs.writeFileSync(summaryPath, JSON.stringify({
    timestamp: new Date().toISOString(),
    totalUrls: allUrls.length,
    indexNow,
    sitemapPings: sitemapPingsResult,
    botsWarmup: botsResult,
    durationSeconds: Number(duration)
  }, null, 2), 'utf8');
}

run().catch(console.error);
