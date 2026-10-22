'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const fileUploadService = require('../../src/services/fileUploadService');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Phase 2 item 15 of the approved remediation plan: "Upload BL copy +
 * record BL number once issued by shipping company, lean UI." The
 * "Confirm BL Issued" form only ever let staff type in the BL number and
 * date — there was no way anywhere in the system to attach the shipping
 * line's actual scanned BL document. Port of PHP's BlCopyUploadTest.php.
 *
 * Like bliCoopPrepSection.test.js, the template-structure assertions here
 * check the shipped show.njk source directly rather than fully rendering
 * it — the surrounding page needs a large context to render without
 * throwing on unrelated sections. The backend persistence (the part with
 * real logic to break) is covered against the real DB below.
 */
describe('BL copy upload (Phase 2 item 15)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const templatePath = path.join(__dirname, '..', '..', 'views', 'orders', 'show.njk');
  const source = fs.readFileSync(templatePath, 'utf8');

  it('the Confirm BL Issued form offers an optional scanned-copy upload field', () => {
    expect(source).toContain('name="bl_copy"');
    expect(source).toContain('enctype="multipart/form-data"');
  });

  it('a recorded BL with no copy yet offers a way to attach one afterward', () => {
    expect(source).toContain('/bl-copy');
    expect(source).toContain('Attach Scanned BL Copy');
  });

  it('once a copy is attached, a download link is offered instead', () => {
    expect(source).toContain('Download scanned BL copy');
    expect(source).toContain('/file-store/');
  });

  it('attachBlCopy persists the file id and it can be read back', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk' });
    const fileId = await createTestFile(orderId, null);

    await orderShippingRepository.attachBlCopy(orderId, fileId);

    const shipping = await orderShippingRepository.find(orderId);
    expect(Number(shipping.bl_copy_file_id)).toBe(fileId);
    expect(await fileStoreRepository.find(fileId)).not.toBeNull();
  });

  /**
   * External QA report L-22: every test above exercises
   * orderShippingRepository.attachBlCopy() directly against a pre-made
   * test file, never through fileUploadService.handleUpload() — the
   * actual code path ordersController.recordBlIssued()/attachBlCopy()
   * use, and the one that was actually broken: the 'bl_copy' upload
   * context row was never seeded, so every real attempt failed with
   * "Unknown upload context: bl_copy" before any file was ever written.
   * Unlike PHP's move_uploaded_file() (which requires a genuine HTTP
   * upload and can never succeed from a CLI test), Node's multer-based
   * upload already hands handleUpload() an in-memory buffer, so this can
   * run the real path end to end.
   */
  it('handleUpload resolves the bl_copy context and actually stores the file', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fakeReq = { file: { buffer: Buffer.from('%PDF-1.4 fake pdf content for test'), originalname: 'bl-scan.pdf', mimetype: 'application/pdf' } };

    const fileId = await fileUploadService.handleUpload(fakeReq, 'bl_copy', 'bl_copy', 'test/bl-copy', null, orderId, null);

    const stored = await fileStoreRepository.find(fileId);
    expect(stored).not.toBeNull();
    expect(stored.original_filename).toBe('bl-scan.pdf');
  });
});
