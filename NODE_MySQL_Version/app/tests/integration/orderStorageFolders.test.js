'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const db = require('../../src/config/db');
const orderStorage = require('../../src/helpers/orderStorage');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderFileReorganizer = require('../../src/services/orderFileReorganizer');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderStorageFoldersTest.php. Owner request (2026-10-26): an
 * order's folders match its steps on the order page, and every document
 * lands in its own step's folder — a Quotation regenerated after the order
 * moved on used to be filed under whatever stage the order was at.
 */
describe('Order storage folders follow the order page steps', () => {
  const ORDER = { client_unique_number: 'NC/SC/2026/1', order_reference: 'SC/ORD/2026/9' };

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('names folders after the order page steps', () => {
    expect(orderStorage.forGeneratedDocument(ORDER, 'QT')).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/01-quotation/generated');
    expect(orderStorage.forGeneratedDocument(ORDER, 'BLI')).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/10-packing-and-bl/generated');
    expect(orderStorage.forGeneratedDocument(ORDER, 'BLE')).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/13-closure/generated');
    expect(orderStorage.forReceived(ORDER, 'buyer_po')).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/04-buyer-po/received');
    expect(orderStorage.folder(ORDER, 'disputes', '7')).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/disputes/7');
  });

  it('files a generated Quotation under the Quotation step whatever stage the order is at', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');
    for (const stage of [1, 2, 3]) {
      await stageGateService.passAndUnlockNext(orderId, stage, userId);
    }

    const result = await documentGenerationService.generate(orderId, 'QT', userId);

    const file = await fileStoreRepository.find(result.pdf_file_id);
    expect(file.server_path).toContain('/01-quotation/generated/');
    expect(fs.existsSync(file.server_path)).toBe(true);
  });

  it('maps old folders to their step when reorganising', () => {
    const old = '/srv/storage/clients/NC-1/SC-ORD-1/';
    expect(orderFileReorganizer.targetFolder(`${old}pi/generated/a.pdf`, ORDER, 'QT'))
      .toEqual(['clients/NC-SC-2026-1/SC-ORD-2026-9/01-quotation/generated', 'generated QT']);
    expect(orderFileReorganizer.targetFolder(`${old}shipping/b.pdf`, ORDER, null)[0]).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/10-packing-and-bl/received');
    expect(orderFileReorganizer.targetFolder(`${old}amendments/SC-AMD-1/signed/c.pdf`, ORDER, null)[0]).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/amendments/SC-AMD-1/received');
    expect(orderFileReorganizer.targetFolder(`${old}04-buyer-po/received/d.pdf`, ORDER, null)[0]).toBe('clients/NC-SC-2026-1/SC-ORD-2026-9/04-buyer-po/received');
    expect(orderFileReorganizer.targetFolder(`${old}mystery/e.pdf`, ORDER, null)[0]).toBeNull();
  });
});
