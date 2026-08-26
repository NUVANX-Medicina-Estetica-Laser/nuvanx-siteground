#!/usr/bin/env node

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { isSiteGroundTransientResponse } from './siteground-transient-classifier.mjs';

const execFileAsync = promisify(execFile);
const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/+$/, '');
const expectedSha = String(
  process.env.EXPECTED_SHA || process.env.CANDIDATE_SHA || process.env.GITHUB_SHA || ''
).trim();
const originSshAlias = String(process.env.ORIGIN_SSH_ALIAS || 'nvx-staging2').trim();

const route = '/matriz-diagnostico-facial-estructura-piel-musculo-grasa/';
const url = `${baseUrl}${route}`;
const expectedCanonical = url;
const expectedOgUrl = expectedCanonical;
const expectedTitle = 'Matriz de diagnóstico facial: estructura, músculo, piel y grasa';
const expectedH1 = expectedTitle;
const expectedRuntimeContract = '20260815-immutable-request-final-query-lock-v3';
const neighbouringSlug = 'tratamientos-faciales-sin-cirugia-guia-medica-diagnostico';
const originFallbackAllowed = baseUrl === 'https://staging2.nuvanx.com';

function attr(tag, name) {
  const match = tag.match(new RegExp(`${name}\\s*=\\s*["']([^"']*)["']`, 'i'));
  return match ? match[1].trim() : '';
}

function tags(html, name) {
  return html.match(new RegExp(`<${name}\\b[^>]*>`, 'gi')) || [];
}

function firstText(html, name) {
  const match = html.match(new RegExp(`<${name}\\b([^>]*)>([\\s\\S]*?)<\\/${name}>`, 'i'));
  if (!match) return { attrs: '', text: '' };
  return {
    attrs: match[1] || '',
    text: match[2].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim(),
  };
}

function canonicalFrom(html) {
  for (const tag of tags(html, 'link')) {
    if (attr(tag, 'rel').toLowerCase() === 'canonical') return attr(tag, 'href');
  }
  return '';
}

function metaContent(html, key, value) {
  for (const tag of tags(html, 'meta')) {
    if (attr(tag, key).toLowerCase() === value.toLowerCase()) return attr(tag, 'content');
  }
  return '';
}

function headersObject(headers) {
  return Object.fromEntries(headers.entries());
}

async function fetchEdge() {
  let response;
  try {
    response = await fetch(url, {
      redirect: 'manual',
      headers: {
        'cache-control': 'no-cache',
        pragma: 'no-cache',
        'user-agent': 'NUVANX-Staging-QA governed-blog-runtime-contract',
        accept: 'text/html,application/xhtml+xml',
      },
    });
  } catch (error) {
    console.error(`GOVERNED_BLOG_RUNTIME=TRANSIENT url=${url} mode=edge error=${error?.message || error}`);
    process.exit(75);
  }

  let html = '';
  try {
    html = await response.text();
  } catch (error) {
    console.error(`GOVERNED_BLOG_RUNTIME=TRANSIENT url=${url} mode=edge body_error=${error?.message || error}`);
    process.exit(75);
  }

  return {
    status: response.status,
    headers: headersObject(response.headers),
    finalUrl: response.url || url,
    html,
    mode: 'edge',
  };
}

async function fetchOriginAfterChallenge(edgeResult) {
  if (!originFallbackAllowed) {
    console.error(`GOVERNED_BLOG_RUNTIME=TRANSIENT url=${url} mode=edge status=${edgeResult.status}`);
    process.exit(75);
  }

  const remoteCommand = [
    'curl -kSs --max-time 30',
    '--resolve staging2.nuvanx.com:443:127.0.0.1',
    "-H 'Cache-Control: no-cache'",
    "-H 'Pragma: no-cache'",
    "-b 'wpSGCacheBypass=1'",
    "-A 'Mozilla/5.0 NUVANX-Staging-QA governed-blog-runtime-origin'",
    "-H 'Accept: text/html,application/xhtml+xml'",
    `-w '\\n__NVX_HTTP_STATUS__:%{http_code}\\n'`,
    `'${url}'`,
  ].join(' ');

  let stdout;
  try {
    ({ stdout } = await execFileAsync('ssh', ['-n', originSshAlias, remoteCommand], {
      encoding: 'utf8',
      maxBuffer: 8 * 1024 * 1024,
      timeout: 45000,
    }));
  } catch (error) {
    console.error(`GOVERNED_BLOG_RUNTIME=TRANSIENT url=${url} mode=origin-fallback error=${error?.message || error}`);
    process.exit(75);
  }

  const statusMatch = stdout.match(/\n__NVX_HTTP_STATUS__:(\d{3})\s*$/);
  if (!statusMatch) {
    console.error(`GOVERNED_BLOG_RUNTIME=TRANSIENT url=${url} mode=origin-fallback reason=status_marker_missing`);
    process.exit(75);
  }

  const status = Number(statusMatch[1]);
  const html = stdout.slice(0, statusMatch.index);
  if (status === 408 || status === 429 || status >= 500) {
    console.error(`GOVERNED_BLOG_RUNTIME=TRANSIENT url=${url} mode=origin-fallback status=${status}`);
    process.exit(75);
  }

  console.log(`GOVERNED_BLOG_RUNTIME_ORIGIN_FALLBACK=PASS url=${url} edge_status=${edgeResult.status} origin_status=${status}`);
  return { status, headers: {}, finalUrl: url, html, mode: 'origin-fallback' };
}

let result = await fetchEdge();
if (isSiteGroundTransientResponse(result.status, result.headers, result.finalUrl)) {
  result = await fetchOriginAfterChallenge(result);
} else if (result.status === 408 || result.status === 429 || result.status >= 500) {
  console.error(`GOVERNED_BLOG_RUNTIME=TRANSIENT url=${url} mode=edge status=${result.status}`);
  process.exit(75);
}

const html = result.html;
const title = firstText(html, 'title').text;
const canonical = canonicalFrom(html);
const ogUrl = metaContent(html, 'property', 'og:url');
const deploySha = metaContent(html, 'name', 'nvx-deploy-sha');
const runtimeContract = metaContent(html, 'name', 'nvx-governed-blog-runtime-contract');
const h1 = firstText(html, 'h1');
const h1IdMatch = h1.attrs.match(/\bid\s*=\s*["']([^"']+)["']/i);
const h1Id = h1IdMatch ? h1IdMatch[1] : '';

const issues = [];
if (result.status !== 200) issues.push(`status:${result.status}`);
if (title !== expectedTitle) issues.push(`title:${title}`);
if (canonical !== expectedCanonical) issues.push(`canonical:${canonical}`);
if (ogUrl !== expectedOgUrl) issues.push(`og_url:${ogUrl}`);
if (h1.text !== expectedH1) issues.push(`h1:${h1.text}`);
if (!h1Id.includes('3334')) issues.push(`h1_id:${h1Id}`);
if (expectedSha && deploySha !== expectedSha) issues.push(`deploy_sha:${deploySha}`);
if (runtimeContract !== expectedRuntimeContract) issues.push(`runtime_contract:${runtimeContract || 'missing'}`);
if (
  title.includes('Tratamientos faciales sin cirugía') ||
  canonical.includes(neighbouringSlug) ||
  ogUrl.includes(neighbouringSlug) ||
  h1Id.includes('3310')
) {
  issues.push('neighbouring_post_leak:3310');
}

if (issues.length > 0) {
  console.error(
    `GOVERNED_BLOG_RUNTIME=FAIL url=${url} ` +
    `detail=${JSON.stringify({ mode: result.mode, status: result.status, title, canonical, ogUrl, h1: h1.text, h1Id, deploySha, runtimeContract, issues })}`
  );
  process.exit(1);
}

console.log(
  `GOVERNED_BLOG_RUNTIME=PASS url=${url} mode=${result.mode} post_id=3334 canonical=${canonical} og_url=${ogUrl} sha=${deploySha} runtime_contract=${runtimeContract}`
);
