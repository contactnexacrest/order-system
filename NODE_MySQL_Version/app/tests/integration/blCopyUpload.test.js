'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
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
});
