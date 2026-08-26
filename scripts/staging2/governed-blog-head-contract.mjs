import { chromium } from 'playwright';
import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import { promisify } from 'node:util';
import {
  EX_TEMPFAIL,
  isSiteGroundCaptchaInterruption,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const execFileAsync = promisify(execFile);
const base = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const sha = (process.env.EXPECTED_SHA || '').trim();
const stagingRoot = (process.env.STAGING_ROOT || '/home/customer/www/staging2.nuvanx.com/public_html').trim();
const sshAlias = (process.env.ORIGIN_SSH_ALIAS || 'nvx-staging2').trim();

if (!/^[0-9a-f]{40}$/.test(sha)) {
  console.error('GOVERNED_BLOG_HEAD=FAIL_REAL reason=EXPECTED_SHA_must_be_40_hex');
  process.exit(1);
}

const catalogUrl = new URL('../../wp-content/themes/nuvanx-medical/inc/data/seo-blog-post-metadata.json', import.meta.url);
let catalog;
try {
  const rawCatalog = await fs.readFile(catalogUrl, 'utf8');
  try {
    catalog = JSON.parse(rawCatalog);
  } catch (parseErr) {
    console.error(`GOVERNED_BLOG_HEAD=FAIL_REAL reason=catalog_parse_failed error=${parseErr instanceof Error ? parseErr.message : String(parseErr)}`);
    process.exit(1);
  }
  if (!catalog || typeof catalog !== 'object' || Array.isArray(catalog)) {
    console.error('GOVERNED_BLOG_HEAD=FAIL_REAL reason=catalog_shape_invalid error=catalog_not_object');
    process.exit(1);
  }
} catch (readErr) {
  console.error(`GOVERNED_BLOG_HEAD=FAIL_REAL reason=catalog_read_failed error=${readErr instanceof Error ? readErr.message : String(readErr)}`);
  process.exit(1);
}
const norm = (value) => `${String(value).split(/[?#]/, 1)[0].replace(/\/$/, '')}/`;

async function gotoStable(page, url) {
  let lastResponse = null;
  let lastError = null;

  for (let attempt = 1; attempt <= 4; attempt += 1) {
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 40000 });
      lastResponse = response;
      lastError = null;
      if (!response) {
        lastError = new Error(`Navigation completed without an HTTP response for ${url}`);
        if (attempt < 4) await page.waitForTimeout(2200 * attempt);
        continue;
      }
      const status = Number(response.status());
      const headers = response?.headers() || {};
      const currentUrl = page.url() || url;

      if (!isSiteGroundTransientResponse(status, headers, currentUrl)) {
        return { response, transient: false, error: null };
      }
    } catch (error) {
      lastResponse = null;
      lastError = error;
      const currentUrl = page.url() || url;
      if (!isSiteGroundCaptchaInterruption(error, currentUrl) && attempt === 4) {
        return { response: null, transient: false, error };
      }
    }

    if (attempt < 4) await page.waitForTimeout(2200 * attempt);
  }

  const finalStatus = Number(lastResponse?.status() || 0);
  const finalHeaders = lastResponse?.headers() || {};
  const finalUrl = page.url() || url;
  const isTransient =
    !lastResponse ||
    isSiteGroundTransientResponse(finalStatus, finalHeaders, finalUrl) ||
    (lastError ? isSiteGroundCaptchaInterruption(lastError, finalUrl) : false);

  return { response: lastResponse, transient: isTransient, error: lastError };
}

async function disarmRollbackAfterTransientExhaustion(reason = 'transient-challenge-exhaustion') {
  const envFile = (process.env.GITHUB_ENV || '').trim();
  if (envFile) {
    try {
      await fs.appendFile(envFile, 'STAGING_MUTATION_ARMED=0\n', 'utf8');
      console.error(`GOVERNED_BLOG_HEAD_STAGING_ROLLBACK=DISARMED reason=${reason}`);
    } catch (err) {
      console.warn(
        `GOVERNED_BLOG_HEAD_STAGING_ROLLBACK=NOT_DISARMED reason=GITHUB_ENV_write_failed error=${err instanceof Error ? err.message : String(err)}`
      );
    }
  } else {
    console.warn('GOVERNED_BLOG_HEAD_STAGING_ROLLBACK=NOT_DISARMED reason=GITHUB_ENV_unavailable');
  }

  const summaryFile = (process.env.GITHUB_STEP_SUMMARY || '').trim();
  if (summaryFile) {
    try {
      await fs.appendFile(
        summaryFile,
        `\n### Governed blog head contract transient exhaustion\n\nSiteGround Antibot or transient infrastructure challenge prevented complete journal head validation after all bounded retries (${reason}). No real application defect was established, so the Staging rollback was disarmed. This run remains ineligible for Production acceptance.\n`,
        'utf8'
      );
    } catch (err) {
      console.warn(`Failed to write GITHUB_STEP_SUMMARY: ${err instanceof Error ? err.message : String(err)}`);
    }
  }
}

async function loadPublishedStagingPosts() {
  const command = [
    'wp',
    `--path=${stagingRoot}`,
    'post',
    'list',
    '--post_type=post',
    '--post_status=publish',
    '--posts_per_page=-1',
    '--orderby=ID',
    '--order=ASC',
    '--fields=ID,post_name',
    '--format=json',
  ].join(' ');

  try {
    const { stdout } = await execFileAsync('ssh', ['-n', sshAlias, command], {
      encoding: 'utf8',
      maxBuffer: 5 * 1024 * 1024,
      timeout: 60000,
    });
    const posts = JSON.parse(stdout);
    if (!Array.isArray(posts)) throw new TypeError('WP-CLI post inventory is not an array');
    return posts;
  } catch (error) {
    console.error(`GOVERNED_BLOG_HEAD=FAIL_REAL reason=wp_cli_inventory_failed error=${error instanceof Error ? error.message : String(error)}`);
    return null;
  }
}

async function runGovernedBlogHeadContract() {
  const publishedPosts = await loadPublishedStagingPosts();
  if (!publishedPosts) {
    return 1;
  }

  const governed = publishedPosts
    .map((post) => ({ id: Number(post.ID), slug: String(post.post_name || '').trim() }))
    .filter((post) => post.slug && Object.hasOwn(catalog, post.slug));

  if (governed.length === 0) {
    console.error('GOVERNED_BLOG_HEAD=FAIL_REAL reason=no_published_governed_posts_found');
    return 1;
  }

  const catalogCount = Object.keys(catalog).length;
  console.log(
    `GOVERNED_BLOG_HEAD_SOURCE=staging-wp-cli published_posts=${publishedPosts.length} governed_published=${governed.length} catalog_routes=${catalogCount}`
  );
  if (governed.length < catalogCount) {
    console.log(`GOVERNED_BLOG_HEAD_CATALOG_NOT_PUBLISHED_IN_STAGING=${catalogCount - governed.length}`);
  }

  const browser = await chromium.launch({ headless: true });
  let realFailures = 0;
  let transientFailures = 0;

  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });

    for (const post of governed) {
      const page = await context.newPage();
      try {
        const expected = `${base}/${post.slug}/`;
        const result = await gotoStable(page, expected);

        if (result.transient) {
          transientFailures += 1;
          console.warn(`TRANSIENT GOVERNED_BLOG_HEAD slug=${post.slug} reason=siteground_challenge final_url=${page.url() || 'unknown'}`);
          continue;
        }

        if (result.error) {
          realFailures += 1;
          console.error(`FAIL GOVERNED_BLOG_HEAD slug=${post.slug} error=${result.error.message} final_url=${page.url() || 'unknown'}`);
          continue;
        }

        const response = result.response;
        const finalUrl = page.url() || '';
        const headData = await page.evaluate(() => {
          const title = (document.title || '').trim();
          const canonical = Array.from(document.querySelectorAll('link[rel="canonical"]')).map((node) => node.href);
          const og = Array.from(document.querySelectorAll('meta[property="og:url"]')).map((node) => node.content);
          const deploy = document.querySelector('meta[name="nvx-deploy-sha"]')?.content || '';
          const robots = (document.querySelector('meta[name="robots"]')?.content || '').toLowerCase();
          const h1 = (document.querySelector('h1')?.textContent || '').replace(/\s+/g, ' ').trim();
          return { title, canonical, og, deploy, robots, h1 };
        });

        const expectedTitle = String(catalog[post.slug].title || '').trim();
        const catalogCanonical = String(catalog[post.slug].canonical_path || '').trim();
        const expectedCanonical = catalogCanonical.startsWith('/')
          ? `${base}${catalogCanonical.endsWith('/') ? catalogCanonical : `${catalogCanonical}/`}`
          : expected;

        const issues = [];
        if (response?.status() !== 200) issues.push(`http=${response?.status() || 0}`);
        if (norm(finalUrl) !== norm(expected)) issues.push(`final_url=${finalUrl || 'missing'}`);
        if (headData.title !== expectedTitle) issues.push(`title=${headData.title}`);
        if (headData.canonical.length !== 1 || norm(headData.canonical[0] || '') !== norm(expectedCanonical)) {
          issues.push(`canonical=${headData.canonical.join(',')}`);
        }
        // Open Graph URL is an absolute page identity and must follow the same
        // environment-specific canonical as the rendered document. Production
        // validates https://nuvanx.com; Staging validates https://staging2.nuvanx.com.
        if (headData.og.length !== 1 || norm(headData.og[0] || '') !== norm(expectedCanonical)) {
          issues.push(`og=${headData.og.join(',')}`);
        }
        if (headData.deploy !== sha) issues.push(`sha=${headData.deploy || 'missing'}`);
        if (!headData.robots.includes('noindex')) issues.push('noindex-missing');

        if (issues.length) {
          realFailures += 1;
          issues.push(`h1=${headData.h1 || 'missing'}`);
        }
        console.log(
          `${issues.length ? 'FAIL' : 'PASS'} GOVERNED_BLOG_HEAD slug=${post.slug} final_url=${finalUrl || 'missing'}${issues.length ? ` ${issues.join(' | ')}` : ''}`
        );
      } catch (postError) {
        const currentUrl = page.url() || `${base}/${post.slug}/`;
        if (isSiteGroundCaptchaInterruption(postError, currentUrl)) {
          transientFailures += 1;
          const message = postError instanceof Error ? postError.message : String(postError);
          console.warn(`TRANSIENT GOVERNED_BLOG_HEAD slug=${post.slug} challenge_during_eval=${message}`);
        } else {
          realFailures += 1;
          const message = postError instanceof Error ? postError.message : String(postError);
          console.error(`FAIL GOVERNED_BLOG_HEAD slug=${post.slug} unhandled_error=${message}`);
        }
      } finally {
        await page.close().catch(() => {});
      }
    }

    console.log(`GOVERNED_BLOG_HEAD_TOTAL=${governed.length}`);
    console.log(`GOVERNED_BLOG_HEAD_REAL_FAIL=${realFailures}`);
    console.log(`GOVERNED_BLOG_HEAD_TRANSIENT_FAIL=${transientFailures}`);

    if (realFailures > 0) {
      console.error(`GOVERNED_BLOG_HEAD_CONTRACT=FAIL_REAL failures=${realFailures}`);
      return 1;
    }

    if (transientFailures > 0) {
      console.error(`GOVERNED_BLOG_HEAD_CONTRACT=FAIL_TRANSIENT_EXHAUSTED transient=${transientFailures}`);
      await disarmRollbackAfterTransientExhaustion('governed-post-antibot-challenge');
      return EX_TEMPFAIL;
    }

    console.log('GOVERNED_BLOG_HEAD_CONTRACT=PASS');
    return 0;
  } finally {
    await browser.close().catch(() => {});
  }
}

const exitCode = await runGovernedBlogHeadContract().catch((err) => {
  console.error(`GOVERNED_BLOG_HEAD=FAIL_REAL unhandled_crash=${err instanceof Error ? err.message : String(err)}`);
  return 1;
});
process.exit(exitCode);
