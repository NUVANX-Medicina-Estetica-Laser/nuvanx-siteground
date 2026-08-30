#!/usr/bin/env node
/**
 * NUVANX Automated Cross-Posting Engine: Instagram Reels -> YouTube Shorts
 *
 * 1. Fetches published video reels from Instagram Graph API (@nuvanx_).
 * 2. Formats titles, descriptions, and tags with E-E-A-T medical disclosure,
 *    sanitary accreditation (CS20144 / CS20073), and canonical website URLs.
 * 3. Downloads clean original video files and maintains a persistent sync-state.json.
 * 4. Queues or uploads them directly to YouTube Data API v3.
 *
 * @package nuvanx-medical
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '../..');

// Load environment variables
function loadEnv() {
  const envPath = path.join(REPO_ROOT, '.env.local');
  if (fs.existsSync(envPath)) {
    const lines = fs.readFileSync(envPath, 'utf8').split('\n');
    for (const line of lines) {
      const match = line.match(/^([A-Z0-9_]+)=['"]?([^'"]*)['"]?/i);
      if (match && !process.env[match[1]]) {
        process.env[match[1]] = match[2].trim();
      }
    }
  }
}

loadEnv();

const META_TOKEN = process.env.META_TOKEN || process.env.META_API_TOKEN_AGOSTO_2026;
const IG_USER_ID = '17841474094610850'; // @nuvanx_
const ARTIFACTS_DIR = path.join(REPO_ROOT, 'artifacts/instagram_videos');
const STATE_FILE = path.join(ARTIFACTS_DIR, 'sync-state.json');

if (!fs.existsSync(ARTIFACTS_DIR)) {
  fs.mkdirSync(ARTIFACTS_DIR, { recursive: true });
}

function loadSyncState() {
  if (fs.existsSync(STATE_FILE)) {
    try {
      return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
    } catch {
      return { synced_ids: [], last_sync: null, items: {} };
    }
  }
  return { synced_ids: [], last_sync: null, items: {} };
}

function saveSyncState(state) {
  fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2), 'utf8');
}

/**
 * Maps caption text to the most relevant canonical NUVANX URL.
 */
function resolveTreatmentLink(caption) {
  const lower = caption.toLowerCase();
  if (lower.includes('endolift') || lower.includes('papada') || lower.includes('mandíbula') || lower.includes('mandibula')) {
    return 'https://nuvanx.com/endolift-facial-papada-mandibula/';
  }
  if (lower.includes('brazo') || lower.includes('abdomen') || lower.includes('flanco') || lower.includes('endoláser') || lower.includes('endolaser')) {
    return 'https://nuvanx.com/endolaser-corporal-grasa-localizada/';
  }
  if (lower.includes('co2') || lower.includes('mancha') || lower.includes('cicatriz') || lower.includes('textura')) {
    return 'https://nuvanx.com/resurfacing-laser-co2-fraccionado/';
  }
  if (lower.includes('exion') || lower.includes('radiofrecuencia') || lower.includes('colágeno') || lower.includes('colageno')) {
    return 'https://nuvanx.com/btl-exion-madrid/';
  }
  if (lower.includes('labio') || lower.includes('hialurónico') || lower.includes('hialuronico') || lower.includes('botox') || lower.includes('toxina') || lower.includes('arruga')) {
    return 'https://nuvanx.com/medicina-estetica/';
  }
  return 'https://nuvanx.com/tratamientos/';
}

/**
 * Formats YouTube Shorts title (<100 chars, click-worthy and search-optimized).
 */
function formatYouTubeTitle(caption) {
  const firstLine = caption.split('\n')[0]
    .replace(/[^\w\s\u00C0-\u017F¿?¡!⚡👨‍⚕️💉🫦☁️💪]/gi, '')
    .trim();
  
  let title = firstLine.length > 70 ? firstLine.substring(0, 67) + '...' : firstLine;
  if (!title.toLowerCase().includes('nuvanx') && !title.toLowerCase().includes('rivera')) {
    title = `${title} | NUVANX`;
  }
  return `${title} #Shorts`;
}

/**
 * Enriches the Instagram caption with medical provenance, clinic licensing, and canonical URLs.
 */
function formatYouTubeDescription(caption, link, permalink) {
  return `${caption.trim()}

━━━━━━━━━━━━━━━━━━━━━
👨‍⚕️ Dirección Médica: Dr. José Javier Rivera Tejeda (Col. ICOMEM Nº 282864786)
📖 Información clínica y valoración:
👉 ${link}

🏥 CLÍNICAS NUVANX MADRID (Centros Sanitarios Autorizados):
📍 Sede Chamberí: C. de Fernández de la Hoz, 45 (CS20144)
📍 Sede Salamanca–Goya: C. de Goya, 115 (CS20073)
📞 Cita y valoración personalizada: https://nuvanx.com/madrid/valoracion/

🔗 Publicado originalmente en @nuvanx_: ${permalink}
#NUVANX #MedicinaEsteticaMadrid #DrRivera #Shorts #EndoliftMadrid #SaludFacial`;
}

async function syncInstagramToYouTube() {
  console.log('=== NUVANX Automation Engine: Instagram -> YouTube ===');
  if (!META_TOKEN) {
    console.error('Error: META_TOKEN is not configured.');
    process.exit(1);
  }

  const state = loadSyncState();
  console.log(`Current sync state: ${state.synced_ids.length} processed items.`);

  const mediaUrl = `https://graph.facebook.com/v19.0/${IG_USER_ID}/media?fields=id,caption,media_type,media_url,permalink,timestamp&access_token=${META_TOKEN}`;
  
  console.log('Querying Meta Graph API for Instagram media...');
  const res = await fetch(mediaUrl);
  const data = await res.json();

  if (!data.data || !Array.isArray(data.data)) {
    console.error('Failed to fetch media from Meta API:', data);
    process.exit(1);
  }

  const videoPosts = data.data.filter((m) => m.media_type === 'VIDEO');
  console.log(`Found ${videoPosts.length} video reels on Instagram.\n`);

  let newItems = 0;
  for (const post of videoPosts) {
    const isNew = !state.synced_ids.includes(post.id);
    const shortcode = post.permalink.split('/')[4] || post.id;
    const filename = `nuvanx-ig-${post.timestamp.slice(0, 10)}-${shortcode}.mp4`;
    const filePath = path.join(ARTIFACTS_DIR, filename);

    const treatmentLink = resolveTreatmentLink(post.caption || '');
    const ytTitle = formatYouTubeTitle(post.caption || '');
    const ytDesc = formatYouTubeDescription(post.caption || '', treatmentLink, post.permalink);

    // Download video file if not already downloaded
    if (!fs.existsSync(filePath) && post.media_url) {
      console.log(`[DOWNLOAD] Fetching Reel ${shortcode} (${post.timestamp.slice(0, 10)})...`);
      try {
        const fileStream = await fetch(post.media_url);
        const buffer = await fileStream.arrayBuffer();
        fs.writeFileSync(filePath, Buffer.from(buffer));
        console.log(`  ✓ Saved ${filename} (${(buffer.byteLength / 1024 / 1024).toFixed(2)} MB)`);
      } catch (err) {
        console.error(`  ✗ Error downloading ${shortcode}:`, err.message);
        continue;
      }
    }

    if (isNew) {
      newItems++;
      state.synced_ids.push(post.id);
      state.items[post.id] = {
        id: post.id,
        shortcode,
        date: post.timestamp,
        file: filename,
        youtube_title: ytTitle,
        canonical_link: treatmentLink,
        status: 'READY_TO_UPLOAD',
        prepared_at: new Date().toISOString(),
      };
      console.log(`[ENRICHED] Queued for YouTube: "${ytTitle}"`);
    }
  }

  state.last_sync = new Date().toISOString();
  saveSyncState(state);

  console.log(`\nSync finished. ${newItems} new Reels formatted and queued for YouTube.`);
  console.log(`State persisted to: ${STATE_FILE}`);
}

syncInstagramToYouTube().catch((err) => {
  console.error('Fatal sync error:', err);
  process.exit(1);
});
