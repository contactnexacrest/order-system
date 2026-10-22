'use strict';

const bcrypt = require('bcrypt');
const db = require('../../src/config/db');
const orderStageRepository = require('../../src/repositories/orderStageRepository');

/** Minimal valid client row, returns its id. */
async function createTestClient(email = null) {
  const num = `TEST-${Math.random().toString(16).slice(2, 10)}`;
  const result = await db.execute(
    'INSERT INTO clients (client_unique_number, company_legal_name, billing_address, email, created_by) VALUES (:num, :name, :addr, :email, NULL)',
    { num, name: 'Jest Test Buyer Ltd', addr: '1 Test Street, Test City', email: email || `jest-client-${Math.random().toString(16).slice(2, 10)}@nexacrest.test` }
  );
  return result.insertId;
}

/**
 * Minimal valid order + its 9 order_stages rows (via the real
 * orderStageRepository.initializeForOrder(), not hand-crafted), in the
 * exact state a genuine new order is in: Stage 1 in_progress, Stages 2-9
 * locked.
 *
 * 90-item backlog "standalone order": the incoterm_code/currency_code/
 * preset_name/advance_pct/etc. columns below mirror exactly what
 * orderRepository.js's own insertOrderRow()/resolveSnapshot() does in
 * production, so a test-created order behaves identically to a real
 * one — snapshotted once here, never re-joined.
 */
async function createTestOrder(clientId, incotermCode = 'FOB', presetName = 'Standard — New Buyer', currencyCode = 'USD', portOfLoadingId = null, portOfDischargeId = null) {
  const incoterm = await db.queryOne('SELECT id, code FROM incoterms WHERE code = :code', { code: incotermCode });
  const currency = await db.queryOne('SELECT id, code FROM currencies WHERE code = :code', { code: currencyCode });
  const preset = await db.queryOne(
    `SELECT id, preset_name, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option,
            balance_days, balance_trigger_wording, show_bl_instructions
     FROM payment_presets WHERE preset_name = :preset_name`,
    { preset_name: presetName }
  );

  let portOfLoadingName = null;
  let portOfLoadingCity = null;
  if (portOfLoadingId) {
    const port = await db.queryOne('SELECT name, city FROM ports WHERE id = :id', { id: portOfLoadingId });
    portOfLoadingName = port ? port.name : null;
    portOfLoadingCity = port ? port.city : null;
  }
  let portOfDischargeName = null;
  let portOfDischargeCity = null;
  if (portOfDischargeId) {
    const port = await db.queryOne('SELECT name, city FROM ports WHERE id = :id', { id: portOfDischargeId });
    portOfDischargeName = port ? port.name : null;
    portOfDischargeCity = port ? port.city : null;
  }

  const ref = `JEST-TEST-${Math.random().toString(16).slice(2, 10)}`;
  const inquiryRef = `JEST-${Math.random().toString(16).slice(2, 8)}`;
  const result = await db.execute(
    `INSERT INTO orders
        (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
         currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
         port_of_loading_id, port_of_discharge_id,
         incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text,
         balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
         port_of_loading_name, port_of_loading_city, port_of_discharge_name, port_of_discharge_city)
     VALUES
        (:ref, :client_id, 1, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL',
         CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active',
         :port_of_loading_id, :port_of_discharge_id,
         :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text,
         :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
         :port_of_loading_name, :port_of_loading_city, :port_of_discharge_name, :port_of_discharge_city)`,
    {
      ref, client_id: clientId, inquiry_ref: inquiryRef,
      preset_id: preset.id, incoterm_id: incoterm.id, currency_id: currency.id,
      port_of_loading_id: portOfLoadingId, port_of_discharge_id: portOfDischargeId,
      incoterm_code: incoterm.code,
      currency_code: currency.code,
      preset_name: preset.preset_name,
      advance_pct: preset.advance_pct,
      advance_trigger_text: preset.advance_trigger_text,
      balance_pct: preset.balance_pct,
      balance_trigger_option: preset.balance_trigger_option,
      balance_days: preset.balance_days,
      balance_trigger_wording: preset.balance_trigger_wording,
      show_bl_instructions: preset.show_bl_instructions,
      port_of_loading_name: portOfLoadingName,
      port_of_loading_city: portOfLoadingCity,
      port_of_discharge_name: portOfDischargeName,
      port_of_discharge_city: portOfDischargeCity,
    }
  );
  const orderId = result.insertId;
  await orderStageRepository.initializeForOrder(orderId);
  return orderId;
}

/** A real client_logins row with a known plaintext password, force_password_change off so login lands cleanly. */
async function createTestClientLogin(clientId, password) {
  const hash = await bcrypt.hash(password, 10);
  await db.execute(
    'INSERT INTO client_logins (client_id, password_hash, is_active, force_password_change) VALUES (:client_id, :hash, 1, 0)',
    { client_id: clientId, hash }
  );
}

/** A minimal real file_store row, returns its id. */
async function createTestFile(orderId, clientId) {
  const result = await db.execute(
    `INSERT INTO file_store (client_id, order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
     VALUES (:client_id, :order_id, 'RECEIVED', :path, :uuid, 'test-file.pdf', 1024, 'application/pdf')`,
    {
      client_id: clientId,
      order_id: orderId,
      path: `/tmp/jest-test-file-${Math.random().toString(16).slice(2, 10)}.pdf`,
      uuid: `${Math.random().toString(16).slice(2)}.pdf`,
    }
  );
  return result.insertId;
}

module.exports = { createTestClient, createTestOrder, createTestClientLogin, createTestFile };
