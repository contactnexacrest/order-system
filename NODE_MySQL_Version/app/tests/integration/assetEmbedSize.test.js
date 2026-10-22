'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');

// Real Chromium-based resizing (documentImageOptimizer's actual mechanism)
// cannot run under Jest — see tests/support/mocks/puppeteerCoreStub.js's own
// docblock. This mocks pdfRenderService.getBrowser() with a fake
// browser/page so the *wiring* itself (documentDataAssembler.assetsBlock()
// actually calls through documentImageOptimizer for each asset type,
// instead of embedding the raw file) is covered directly — the actual
// resize/recompress algorithm is covered by documentImageOptimizer.test.js.
const fakeResizedDataUri = 'data:image/png;base64,UkVTSVpFRA==';
jest.mock('../../src/services/pdfRenderService', () => ({
  getBrowser: jest.fn().mockResolvedValue({
    newPage: jest.fn().mockResolvedValue({
      setContent: jest.fn(),
      evaluate: jest.fn().mockResolvedValue(fakeResizedDataUri),
      close: jest.fn(),
    }),
  }),
}));

const db = require('../../src/config/db');
const assetRepository = require('../../src/repositories/assetRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');

/**
 * External QA report L-45: generated PDFs were 2.2-2.7MB — too large for
 * many recipients' inboxes. Root cause: documentDataAssembler.assetsBlock()
 * embedded the company logo/signature/seal/watermark straight off disk,
 * with no resizing. This locks in the documentImageOptimizer wiring
 * itself, not just the standalone helper.
 */
describe('documentDataAssembler.assetsBlock() embeds through documentImageOptimizer (External QA report L-45)', () => {
  let tmpPath;

  beforeEach(() => {
    tmpPath = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'asset-embed-test-')), 'logo.png');
    // Content doesn't need to be a real decodable image — the mocked
    // browser above never actually decodes it, it's the wiring under test.
    fs.writeFileSync(tmpPath, Buffer.alloc(500_000, 0xab));
  });

  afterEach(() => {
    fs.rmSync(path.dirname(tmpPath), { recursive: true, force: true });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  test('a logo upload is embedded through the optimizer, not as raw bytes', async () => {
    await assetRepository.replace('logo', 'Jest Test Logo', tmpPath, 'image/png', null);

    const block = await documentDataAssembler.assetsBlock();

    expect(block.logo_data_uri).toBe(fakeResizedDataUri);
    expect(block.logo_data_uri).not.toBe(`data:image/png;base64,${fs.readFileSync(tmpPath).toString('base64')}`);
  });
});
