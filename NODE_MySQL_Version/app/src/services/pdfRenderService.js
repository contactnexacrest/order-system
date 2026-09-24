'use strict';

const puppeteer = require('puppeteer-core');
const env = require('../config/env');

/**
 * Replaces DOMPDF. DOMPDF's `isRemoteEnabled=false` (security: never fetch
 * remote resources into a generated PDF) has no direct Puppeteer
 * equivalent, but the same guarantee holds here for a different reason: we
 * call `page.setContent()` on a static HTML string with no external
 * references (images are already base64 data URIs — see
 * documentDataAssembler.assetsBlock()) and never navigate the page anywhere
 * else, so there is nothing for it to fetch.
 *
 * Uses puppeteer-core (no bundled Chromium download) against a
 * system/cached Chrome binary set via PUPPETEER_EXECUTABLE_PATH — see the
 * deployment guide for the apt-get install step this depends on in
 * production.
 */
let browserPromise = null;

function launchBrowser() {
  const executablePath = env.get('PUPPETEER_EXECUTABLE_PATH');
  if (!executablePath) {
    throw new Error(
      'PUPPETEER_EXECUTABLE_PATH is not set. Install a system Chromium/Chrome and point this env var at its binary (see DEPLOYMENT.md).'
    );
  }
  return puppeteer.launch({
    executablePath,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
}

async function getBrowser() {
  if (browserPromise) {
    // A previously-launched browser can die on its own between requests
    // (crash, OOM, the underlying Chromium process being reaped) — without
    // this check, every future call reused the same dead handle forever
    // (browser.newPage() failing instantly with "Connection closed"), and
    // the only recovery was restarting the whole Node process. Real bug
    // found via live testing: the very first document generation of a
    // fresh process succeeded, but every one after the browser died failed
    // immediately.
    const existing = await browserPromise.catch(() => null);
    if (existing && existing.connected) {
      return browserPromise;
    }
    browserPromise = null;
  }

  browserPromise = launchBrowser().catch((err) => {
    browserPromise = null; // allow retry on next call rather than caching a permanent failure
    throw err;
  });
  return browserPromise;
}

/** @returns {Promise<Buffer>} A4 portrait PDF bytes rendered from a self-contained HTML string. */
async function renderPdfFromHtml(html) {
  return renderOnce(html, true);
}

async function renderOnce(html, allowRetry) {
  const browser = await getBrowser();
  let page;
  try {
    page = await browser.newPage();
  } catch (err) {
    // Narrow race: the browser died between getBrowser()'s `connected`
    // check and this call. Force a fresh launch and retry exactly once —
    // never loop, so a genuinely broken Puppeteer setup still surfaces the
    // real error to the caller instead of hanging.
    if (allowRetry) {
      browserPromise = null;
      return renderOnce(html, false);
    }
    throw err;
  }
  try {
    // 'load' rather than 'networkidle0': the HTML is fully self-contained
    // (every image is a base64 data: URI, per the class comment above), so
    // there is no post-load network activity to wait out — and waiting for
    // it anyway made this hang indefinitely in sandboxed/offline network
    // environments where Chromium's own background requests (e.g. a
    // favicon probe) never resolve either way.
    await page.setContent(html, { waitUntil: 'load' });
    const pdf = await page.pdf({
      format: 'A4',
      landscape: false,
      printBackground: true,
      margin: { top: '0px', bottom: '0px', left: '0px', right: '0px' },
    });
    return pdf;
  } finally {
    await page.close();
  }
}

/** Call once at process shutdown so a held Chromium process doesn't dangle. */
async function closeBrowser() {
  if (browserPromise) {
    const browser = await browserPromise.catch(() => null);
    if (browser) await browser.close();
    browserPromise = null;
  }
}

module.exports = { renderPdfFromHtml, closeBrowser };
