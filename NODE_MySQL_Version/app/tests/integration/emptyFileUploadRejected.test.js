'use strict';

const db = require('../../src/config/db');
const fileUploadService = require('../../src/services/fileUploadService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report R-10: "A 0-byte file is accepted as the buyer's
 * signed PO (Buyer Action Link upload)." fileUploadService.storeOne()
 * only ever checked size against the upper bound (too large) — never a
 * lower one — so an empty file passed straight through and got stored
 * as a real, unusable "signed PO".
 */
describe('Empty file upload rejected (External QA report R-10)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('rejects a 0-byte file instead of storing it', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fakeReq = { file: { buffer: Buffer.alloc(0), originalname: 'empty.pdf', mimetype: 'application/pdf' } };

    await expect(
      fileUploadService.handleUpload(fakeReq, 'bl_copy', 'bl_copy', 'test/empty-upload', null, orderId, null)
    ).rejects.toThrow(/empty/);
  });

  it('still stores a genuine non-empty file', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fakeReq = { file: { buffer: Buffer.from('%PDF-1.4 fake pdf content for test'), originalname: 'bl-scan.pdf', mimetype: 'application/pdf' } };

    const fileId = await fileUploadService.handleUpload(fakeReq, 'bl_copy', 'bl_copy', 'test/nonempty-upload', null, orderId, null);
    expect(fileId).toBeTruthy();
  });
});
