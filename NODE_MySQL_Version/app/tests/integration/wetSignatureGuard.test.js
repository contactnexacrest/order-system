'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderBuyerPoDocumentRepository = require('../../src/repositories/orderBuyerPoDocumentRepository');
const orderSupplierPoDocumentRepository = require('../../src/repositories/orderSupplierPoDocumentRepository');
const wetSignatureGuardService = require('../../src/services/wetSignatureGuardService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Regression coverage for the wet-signature-required flag concept (task
 * tracker item #106): recordBuyerPo (Stage 2) and confirmSupplierSigned
 * (Stage 5) used to pass their gate on a button click alone, with no
 * upload of the counterparty's actual signed copy ever required. Pins the
 * fix — wetSignatureGuardService — in both its default-on (blocking) and
 * satisfied (unblocked) states, plus the Admin-toggle-off escape hatch.
 */
describe('Wet-signature-required flag', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestFile(orderId) {
    const result = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'RECEIVED', '/tmp/jest-test-file.pdf', :uuid, 'signed-copy.pdf', 1024, 'application/pdf')`,
      { order_id: orderId, uuid: `${Math.random().toString(16).slice(2)}.pdf` }
    );
    return result.insertId;
  }

  async function createTestSupplierPo(orderId) {
    const supplierResult = await db.execute(
      "INSERT INTO suppliers (supplier_legal_name) VALUES ('Jest WetSig Test Supplier Co')"
    );
    const supplierId = supplierResult.insertId;
    const poResult = await db.execute(
      `INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference)
       VALUES (:order_id, :supplier_id, 'WETSIG-SPO-JEST')`,
      { order_id: orderId, supplier_id: supplierId }
    );
    return poResult.insertId;
  }

  it('blocks Buyer PO confirmation by default with no uploaded copy', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    expect(await companySettingsRepository.get('wet_signature_required_buyer_po')).toBe('1');
    expect(await wetSignatureGuardService.buyerPoBlocked(orderId)).toBe(true);
  });

  it('unblocks Buyer PO confirmation once a copy is attached', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const fileId = await createTestFile(orderId);

    await orderBuyerPoDocumentRepository.attach(orderId, fileId);

    expect(await wetSignatureGuardService.buyerPoBlocked(orderId)).toBe(false);
  });

  it('never blocks Buyer PO confirmation when the flag is off', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    await companySettingsRepository.set('wet_signature_required_buyer_po', '0');
    try {
      expect(await wetSignatureGuardService.buyerPoBlocked(orderId)).toBe(false);
    } finally {
      await companySettingsRepository.set('wet_signature_required_buyer_po', '1');
    }
  });

  it('blocks Supplier PO confirmation by default with no uploaded acknowledgment', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const supplierPoId = await createTestSupplierPo(orderId);

    expect(await companySettingsRepository.get('wet_signature_required_supplier_po')).toBe('1');
    expect(await wetSignatureGuardService.supplierPoBlocked(supplierPoId)).toBe(true);
  });

  it('unblocks Supplier PO confirmation once an acknowledgment is attached', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const supplierPoId = await createTestSupplierPo(orderId);
    const fileId = await createTestFile(orderId);

    await orderSupplierPoDocumentRepository.attach(supplierPoId, fileId);

    expect(await wetSignatureGuardService.supplierPoBlocked(supplierPoId)).toBe(false);
  });
});
