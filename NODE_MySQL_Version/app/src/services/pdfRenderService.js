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
  if (!browserPromise) {
    browserPromise = launchBrowser().catch((err) => {
      browserPromise = null; // allow retry on next call rather than caching a permanent failure
      throw err;
    });
  }
  return browserPromise;
}

/** @returns {Promise<Buffer>} A4 portrait PDF bytes rendered from a self-contained HTML string. */
async function renderPdfFromHtml(html) {
  const browser = await getBrowser();
  const page = await browser.newPage();
  try {
    await page.setContent(html, { waitUntil: 'networkidle0' });
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
