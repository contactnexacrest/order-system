'use strict';

const db = require('../../src/config/db');
const orderStageRepository = require('../../src/repositories/orderStageRepository');

/** Minimal valid client row, returns its id. */
async function createTestClient() {
  const num = `TEST-${Math.random().toString(16).slice(2, 10)}`;
  const result = await db.execute(
    'INSERT INTO clients (client_unique_number, company_legal_name, billing_address, created_by) VALUES (:num, :name, :addr, NULL)',
    { num, name: 'Jest Test Buyer Ltd', addr: '1 Test Street, Test City' }
  );
  return result.insertId;
}

/**
 * Minimal valid order + its 9 order_stages rows (via the real
 * orderStageRepository.initializeForOrder(), not hand-crafted), in the
 * exact state a genuine new order is in: Stage 1 in_progress, Stages 2-9
 * locked.
 */
async function createTestOrder(clientId, incotermCode = 'FOB') {
  const incoterm = await db.queryOne('SELECT id FROM incoterms WHERE code = :code', { code: incotermCode });
  const currency = await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'");
  const preset = await db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'");

  const ref = `JEST-TEST-${Math.random().toString(16).slice(2, 10)}`;
  const inquiryRef = `JEST-${Math.random().toString(16).slice(2, 8)}`;
  const result = await db.execute(
    `INSERT INTO orders
        (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
         currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status)
     VALUES
        (:ref, :client_id, 1, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL',
         CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active')`,
    {
      ref, client_id: clientId, inquiry_ref: inquiryRef,
      preset_id: preset.id, incoterm_id: incoterm.id, currency_id: currency.id,
    }
  );
  const orderId = result.insertId;
  await orderStageRepository.initializeForOrder(orderId);
  return orderId;
}

module.exports = { createTestClient, createTestOrder };
