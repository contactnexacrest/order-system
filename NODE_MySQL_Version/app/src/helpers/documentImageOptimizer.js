'use strict';

const fs = require('fs');
const crypto = require('crypto');
const env = require('../config/env');

/**
 * External QA report L-45: generated PDFs were 2.2-2.7MB — too large for
 * many recipients' inboxes. Root cause: every raster image a document
 * embeds (company logo/signature/seal/watermark, and Annexure A product
 * photos) was read straight off disk and base64-embedded as-is in
 * documentDataAssembler.js/documentGenerationService.js — even though
 * none of these ever needs to render larger than a few centimetres on an
 * A4 page. A handful of phone-camera product photos, or even just one
 * near-the-upload-cap logo/seal/signature/watermark, directly accounts
 * for the exact size range reported.
 *
 * Resizes/recompresses a *copy* for embedding only, via an off-screen
 * <canvas> in the same Chromium instance already used to render the PDF
 * (pdfRenderService.getBrowser()) — deliberately not a new npm image
 * library, so this mirrors the PHP side's GD-based
 * App\Helpers\DocumentImageOptimizer with the same max dimension/quality
 * and the same storage/pdf_image_cache caching, without the two stacks
 * needing two different decoders to stay in parity.
 */
const MAX_DIMENSION = 1000; // px — generous for a logo/seal/signature/watermark/product photo on an A4 page
const JPEG_QUALITY = 0.82;

function cachePathsFor(filePath) {
  const dir = `${env.storageBasePath().replace(/\/+$/, '')}/pdf_image_cache`;
  const name = crypto.createHash('sha256').update(filePath).digest('hex');
  return { dir, file: `${dir}/${name}.cache` };
}

async function dataUriForEmbed(filePath, mimeType) {
  const raw = fs.readFileSync(filePath);

  if (mimeType === 'image/svg+xml') {
    // Vector — already small, resizing makes no sense.
    return `data:${mimeType};base64,${raw.toString('base64')}`;
  }

  const { dir, file } = cachePathsFor(filePath);
  const sourceMtimeMs = fs.statSync(filePath).mtimeMs;
  if (fs.existsSync(file) && fs.statSync(file).mtimeMs >= sourceMtimeMs) {
    return fs.readFileSync(file, 'utf8');
  }

  let dataUri;
  try {
    dataUri = await render(raw, mimeType);
  } catch (err) {
    // Unreadable/corrupt — fall back to the raw file rather than fail the whole document.
    dataUri = `data:${mimeType};base64,${raw.toString('base64')}`;
  }

  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(file, dataUri, 'utf8');
  return dataUri;
}

async function render(raw, mimeType) {
  // eslint-disable-next-line global-require -- required lazily to avoid a
  // require() cycle with pdfRenderService.js at module-load time.
  const { getBrowser } = require('../services/pdfRenderService');
  const inputDataUri = `data:${mimeType};base64,${raw.toString('base64')}`;

  const browser = await getBrowser();
  const page = await browser.newPage();
  try {
    await page.setContent('<!doctype html><html><body></body></html>', { waitUntil: 'load' });
    const result = await page.evaluate(
      ({ dataUri, maxDim, quality }) => new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
          try {
            const scale = Math.min(1, maxDim / Math.max(img.naturalWidth, img.naturalHeight));
            const w = Math.max(1, Math.round(img.naturalWidth * scale));
            const h = Math.max(1, Math.round(img.naturalHeight * scale));
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, w, h);

            // A handful of sample points rather than scanning every pixel —
            // enough to catch the overwhelmingly common case (a
            // logo/signature/seal PNG with a transparent background). A
            // false negative here only costs a slightly larger JPEG where a
            // PNG would have been smaller/cleaner, never a broken image.
            const stepX = Math.max(1, Math.floor(w / 10));
            const stepY = Math.max(1, Math.floor(h / 10));
            const pixels = ctx.getImageData(0, 0, w, h).data;
            let hasAlpha = false;
            for (let y = 0; y < h && !hasAlpha; y += stepY) {
              for (let x = 0; x < w; x += stepX) {
                if (pixels[(y * w + x) * 4 + 3] !== 255) { hasAlpha = true; break; }
              }
            }

            resolve(hasAlpha ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', quality));
          } catch (e) {
            reject(e instanceof Error ? e.message : String(e));
          }
        };
        img.onerror = () => reject('image failed to decode');
        img.src = dataUri;
      }),
      { dataUri: inputDataUri, maxDim: MAX_DIMENSION, quality: JPEG_QUALITY }
    );
    return result;
  } finally {
    await page.close();
  }
}

module.exports = { dataUriForEmbed };
