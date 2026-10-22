'use strict';

const db = require('../../src/config/db');
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const amendmentService = require('../../src/services/amendmentService');
const orderRepository = require('../../src/repositories/orderRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of AmendmentBalanceTriggerWordingTest.php. Found while verifying
 * that an activated amendment's payment terms correctly flow into the
 * "standalone order" snapshot: orders.balance_trigger_wording is custom
 * free text snapshotted from whichever preset the order started with
 * (90-item backlog "standalone order") — written for THAT preset's own
 * balance_trigger_option. An amendment can change the option (e.g.
 * before-shipment -> against-BL) without touching the wording, which
 * would otherwise leave stale text printed alongside the new option.
 * amendmentService.attachSignedCopyAndActivate() now clears the wording
 * whenever the option actually changes, falling back to
 * documentDataAssembler.balanceTriggerSentence()'s generic built-in
 * sentence for the new option — and leaves it untouched when the option
 * is unchanged.
 */
describe('Amendment balance-trigger wording (90-item backlog "standalone order" follow-on)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocument(orderId, typeCode = 'QT') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, 'draft')`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}` }
    );
    return result.insertId;
  }

  async function activateAmendment(orderId, balanceTriggerOption, balanceDays) {
    const amendmentId = await amendmentRepository.create(
      `AMD-WORDING-TEST-${Math.random().toString(16).slice(2, 10)}`,
      orderId,
      'Jest amendment wording test',
      'importer',
      { note: 'snapshot' },
      null, null, null,
      balanceTriggerOption, balanceDays, null, null
    );
    await amendmentService.approveByMd(amendmentId, 1);
    const documentId = await createTestDocument(orderId, 'QT');
    await amendmentRepository.attachDocument(amendmentId, documentId);
    const fileId = await createTestFile(orderId, null);
    await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1);
    return amendmentId;
  }

  it('an amendment changing the balance trigger option clears the stale wording', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer');
    const before = await orderRepository.find(orderId);
    expect(before.balance_trigger_option).toBe('A_BEFORE_SHIPMENT');
    expect(before.balance_trigger_wording).toContain('before shipment');

    await activateAmendment(orderId, 'B_AGAINST_BL', 14);

    const after = await orderRepository.find(orderId);
    expect(after.balance_trigger_option).toBe('B_AGAINST_BL');
    expect(after.balance_trigger_wording).toBeNull();

    const sentence = documentDataAssembler.balanceTriggerSentence(after.balance_trigger_option, after.balance_days, after.balance_trigger_wording);
    expect(sentence).toContain('Bill of Lading');
    expect(sentence).not.toContain('before shipment');
  });

  it('an amendment not changing the balance trigger option keeps the existing wording', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer');
    const originalWording = (await orderRepository.find(orderId)).balance_trigger_wording;

    const amendmentId = await amendmentRepository.create(
      `AMD-WORDING-TEST-${Math.random().toString(16).slice(2, 10)}`,
      orderId,
      'Jest amendment wording test',
      'importer',
      { note: 'snapshot' },
      25.0, null, null, null, null, null, null
    );
    await amendmentService.approveByMd(amendmentId, 1);
    const documentId = await createTestDocument(orderId, 'QT');
    await amendmentRepository.attachDocument(amendmentId, documentId);
    const fileId = await createTestFile(orderId, null);
    await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1);

    const after = await orderRepository.find(orderId);
    expect(after.balance_trigger_option).toBe('A_BEFORE_SHIPMENT');
    expect(after.balance_trigger_wording).toBe(originalWording);
    expect(Number(after.advance_pct)).toBe(25);
  });
});
