'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');

/**
 * External QA report L-45: generated PDFs were 2.2-2.7MB — too large for
 * many recipients' inboxes. Root cause: every raster image a document
 * embeds was read straight off disk and base64-embedded as-is, with no
 * resizing/recompression.
 *
 * Real Chromium (documentImageOptimizer's actual resize mechanism) cannot
 * run under Jest — see tests/support/mocks/puppeteerCoreStub.js's own
 * docblock, and pdfRenderServiceFooter.test.js, which defers that to
 * manual/E2E verification. These tests instead mock
 * services/pdfRenderService.getBrowser() the same way the existing
 * document-generation integration tests mock the whole module, so the
 * *wiring* (SVG shortcut, disk caching, graceful fallback when the
 * browser is unavailable — which is also the real, non-test behavior if
 * Chromium ever crashes in production) is covered directly.
 */
describe('documentImageOptimizer.dataUriForEmbed (External QA report L-45)', () => {
  let tmpDir;
  let storageDir;

  beforeEach(() => {
    jest.resetModules();
    tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'doc-image-optimizer-test-'));
    storageDir = fs.mkdtempSync(path.join(os.tmpdir(), 'doc-image-optimizer-storage-'));
    jest.doMock('../../src/config/env', () => ({
      storageBasePath: () => storageDir,
    }));
  });

  afterEach(() => {
    fs.rmSync(tmpDir, { recursive: true, force: true });
    fs.rmSync(storageDir, { recursive: true, force: true });
    jest.dontMock('../../src/config/env');
    jest.dontMock('../../src/services/pdfRenderService');
  });

  function writeFile(name, content) {
    const p = path.join(tmpDir, name);
    fs.writeFileSync(p, content);
    return p;
  }

  it('passes an SVG through unchanged, never touching the browser', async () => {
    jest.doMock('../../src/services/pdfRenderService', () => ({
      getBrowser: jest.fn(() => { throw new Error('must not be called for an SVG'); }),
    }));
    const { dataUriForEmbed } = require('../../src/helpers/documentImageOptimizer');
    const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';
    const p = writeFile('mark.svg', svg);

    const result = await dataUriForEmbed(p, 'image/svg+xml');

    expect(result).toBe(`data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}`);
  });

  it('embeds the resized/recompressed result the browser produces, and caches it to disk', async () => {
    const fakePage = { setContent: jest.fn(), evaluate: jest.fn().mockResolvedValue('data:image/jpeg;base64,UkVTSVpFRA=='), close: jest.fn() };
    const fakeBrowser = { newPage: jest.fn().mockResolvedValue(fakePage) };
    jest.doMock('../../src/services/pdfRenderService', () => ({
      getBrowser: jest.fn().mockResolvedValue(fakeBrowser),
    }));
    const { dataUriForEmbed } = require('../../src/helpers/documentImageOptimizer');
    const p = writeFile('photo.jpg', Buffer.from([0xff, 0xd8, 0xff]));

    const result = await dataUriForEmbed(p, 'image/jpeg');

    expect(result).toBe('data:image/jpeg;base64,UkVTSVpFRA==');
    expect(fakePage.close).toHaveBeenCalled();

    const cacheFiles = fs.readdirSync(`${storageDir}/pdf_image_cache`);
    expect(cacheFiles).toHaveLength(1);
    expect(fs.readFileSync(`${storageDir}/pdf_image_cache/${cacheFiles[0]}`, 'utf8')).toBe(result);
  });

  it('returns the cached result on a second call without invoking the browser again', async () => {
    const fakePage = { setContent: jest.fn(), evaluate: jest.fn().mockResolvedValue('data:image/jpeg;base64,QUJD'), close: jest.fn() };
    const getBrowserMock = jest.fn().mockResolvedValue({ newPage: jest.fn().mockResolvedValue(fakePage) });
    jest.doMock('../../src/services/pdfRenderService', () => ({ getBrowser: getBrowserMock }));
    const { dataUriForEmbed } = require('../../src/helpers/documentImageOptimizer');
    const p = writeFile('photo2.jpg', Buffer.from([0xff, 0xd8, 0xff]));

    const first = await dataUriForEmbed(p, 'image/jpeg');
    const second = await dataUriForEmbed(p, 'image/jpeg');

    expect(second).toBe(first);
    expect(getBrowserMock).toHaveBeenCalledTimes(1);
  });

  it('falls back to embedding the raw file when the browser is unavailable, rather than failing the whole document', async () => {
    // The real behavior under Jest today (puppeteer-core is unconditionally
    // stubbed to throw — see this file's docblock), and also the real
    // production behavior if Chromium itself ever crashes or fails to launch.
    jest.doMock('../../src/services/pdfRenderService', () => ({
      getBrowser: jest.fn().mockRejectedValue(new Error('puppeteer-core is stubbed out under Jest')),
    }));
    const { dataUriForEmbed } = require('../../src/helpers/documentImageOptimizer');
    const raw = Buffer.from([0xff, 0xd8, 0xff, 0x01, 0x02]);
    const p = writeFile('photo3.jpg', raw);

    const result = await dataUriForEmbed(p, 'image/jpeg');

    expect(result).toBe(`data:image/jpeg;base64,${raw.toString('base64')}`);
  });
});
