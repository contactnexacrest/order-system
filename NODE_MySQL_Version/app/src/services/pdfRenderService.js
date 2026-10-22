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

/**
 * @param {string} html self-contained HTML (every image already a base64 data: URI)
 * @param {string|null} [documentReference] External QA report L-04: when
 *   given, a running footer (reference + "Page X of Y") is painted on
 *   every page via Puppeteer's own header/footer template mechanism —
 *   the one reliable way to get live pagination totals into a PDF here
 *   (unlike the watermark image, a CSS `position: fixed` footer can't
 *   know the total page count before printing). Before this, the
 *   document reference only ever appeared once, in the page-1 header
 *   table, so a continuation page carried no identifying reference at all.
 * @returns {Promise<Buffer>} A4 portrait PDF bytes
 */
async function renderPdfFromHtml(html, documentReference = null) {
  return renderOnce(html, true, documentReference);
}

async function renderOnce(html, allowRetry, documentReference = null) {
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
      return renderOnce(html, false, documentReference);
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
    const pdf = await page.pdf(buildPdfOptions(documentReference));
    return pdf;
  } finally {
    await page.close();
  }
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * External QA report L-04: `displayHeaderFooter`/`footerTemplate` is
 * Puppeteer's own built-in mechanism for a page.pdf() running
 * header/footer — the `pageNumber`/`totalPages` spans are special
 * classes Chromium substitutes with live pagination totals at
 * print time, something no amount of static page content could ever
 * know in advance. `margin.bottom` must be non-zero for the footer to
 * have room to draw in — otherwise it's clipped to nothing; the content
 * itself keeps using its own `@page` CSS margin as before (`top`/`left`/
 * `right` stay 0 here, unchanged), confirmed by rendering a real
 * multi-page document that nothing overlaps or gets cut off.
 */
function buildPdfOptions(documentReference) {
  const options = {
    format: 'A4',
    landscape: false,
    printBackground: true,
    margin: { top: '0px', bottom: '0px', left: '0px', right: '0px' },
  };
  if (documentReference) {
    options.margin.bottom = '0.4in';
    options.displayHeaderFooter = true;
    options.headerTemplate = '<div></div>';
    options.footerTemplate = `<div style="width:100%; font-size:8px; padding:0 42px; color:#5B6573; font-family:sans-serif; display:flex; justify-content:space-between;">
      <span>${escapeHtml(documentReference)}</span>
      <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
    </div>`;
  }
  return options;
}

/** Call once at process shutdown so a held Chromium process doesn't dangle. */
async function closeBrowser() {
  if (browserPromise) {
    const browser = await browserPromise.catch(() => null);
    if (browser) await browser.close();
    browserPromise = null;
  }
}

module.exports = { renderPdfFromHtml, closeBrowser, buildPdfOptions };
