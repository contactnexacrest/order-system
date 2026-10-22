'use strict';

const db = require('../../src/config/db');
const fileUploadService = require('../../src/services/fileUploadService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of DangerousContentUploadRejectedTest.php. External QA report
 * L-15 (UP-02): "Uploaded file MIME type is taken from the browser: an
 * HTML file named .pdf is stored and served as text/html (mitigated:
 * nosniff + attachment). Fix: Sniff MIME server-side; reject when
 * content and extension disagree." fileUploadService's
 * looksLikeDangerousContent() now checks the buffer's own leading
 * bytes, regardless of what extension or browser Content-Type was
 * claimed.
 */
describe('Dangerous content upload rejected (External QA report L-15)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function tryUpload(content, claimedName, claimedType) {
    const orderId = await createTestOrder(await createTestClient());
    const fakeReq = { file: { buffer: Buffer.from(content), originalname: claimedName, mimetype: claimedType } };
    return fileUploadService.handleUpload(fakeReq, 'bl_copy', 'bl_copy', 'test/dangerous-upload', null, orderId, null);
  }

  it('rejects an HTML file named .pdf', async () => {
    await expect(
      tryUpload('<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'invoice.pdf', 'application/pdf')
    ).rejects.toThrow(/does not match a safe file type/);
  });

  it('rejects a bare html tag named .pdf', async () => {
    await expect(
      tryUpload('<html><body>hello</body></html>', 'invoice.pdf', 'application/pdf')
    ).rejects.toThrow(/does not match a safe file type/);
  });

  it('rejects a PHP file named .pdf', async () => {
    await expect(
      tryUpload('<?php echo shell_exec($_GET["cmd"]); ?>', 'invoice.pdf', 'application/pdf')
    ).rejects.toThrow(/does not match a safe file type/);
  });

  it('is case-insensitive about the doctype', async () => {
    await expect(
      tryUpload('<!DoCtYpE HTML><html></html>', 'invoice.pdf', 'application/pdf')
    ).rejects.toThrow(/does not match a safe file type/);
  });

  it('does not reject a legitimate PDF', async () => {
    const fileId = await tryUpload('%PDF-1.4 fake pdf content for test', 'invoice.pdf', 'application/pdf');
    expect(fileId).toBeTruthy();
  });
});
