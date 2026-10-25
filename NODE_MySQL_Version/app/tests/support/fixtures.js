'use strict';

const bcrypt = require('bcrypt');
const db = require('../../src/config/db');
const orderAssigneeRepository = require('../../src/repositories/orderAssigneeRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const orderExportBenefitSchemeRepository = require('../../src/repositories/orderExportBenefitSchemeRepository');
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
async function createTestOrder(clientId, incotermCode = 'FOB', presetName = 'Standard — New Buyer', currencyCode = 'USD', portOfLoadingId = null, portOfDischargeId = null, assignedTo = null) {
  const assigneeIds = assignedTo === null ? [] : Array.isArray(assignedTo) ? assignedTo : [assignedTo];
  const incoterm = await db.queryOne('SELECT id, code FROM incoterms WHERE code = :code', { code: incotermCode });
  const currency = await db.queryOne('SELECT id, code FROM currencies WHERE code = :code', { code: currencyCode });
  const preset = await db.queryOne(
    `SELECT id, preset_name, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option,
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

  // 90-item backlog "standalone order" follow-on: mirrors
  // orderRepository.js's own resolveClientSnapshot().
  const client = (await db.queryOne(
    `SELECT company_legal_name, billing_address, billing_address_line1, billing_address_line2,
            billing_city, billing_postcode, billing_country, vat_eori_tax_no, contact_person,
            email, phone, country_of_destination,
            consignee_same_as_buyer, consignee_name, consignee_address, consignee_address_line1,
            consignee_address_line2, consignee_city, consignee_postcode, consignee_country,
            consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
            notify_party_same_as_consignee, notify_party, notify_party_address_line1,
            notify_party_address_line2, notify_party_city, notify_party_postcode, notify_party_country,
            notify_party_contact_person, notify_party_phone, notify_party_email
     FROM clients WHERE id = :id`,
    { id: clientId }
  )) || {};

  const ref = `JEST-TEST-${Math.random().toString(16).slice(2, 10)}`;
  const inquiryRef = `JEST-${Math.random().toString(16).slice(2, 8)}`;
  const result = await db.execute(
    `INSERT INTO orders
        (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
         currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
         port_of_loading_id, port_of_discharge_id,
         incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text, payment_method,
         balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
         port_of_loading_name, port_of_loading_city, port_of_discharge_name, port_of_discharge_city,
         company_legal_name, billing_address, billing_address_line1, billing_address_line2,
         billing_city, billing_postcode, billing_country, vat_eori_tax_no, contact_person,
         client_email, client_phone, country_of_destination,
         consignee_same_as_buyer, consignee_name, consignee_address, consignee_address_line1,
         consignee_address_line2, consignee_city, consignee_postcode, consignee_country,
         consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
         notify_party_same_as_consignee, notify_party, notify_party_address_line1,
         notify_party_address_line2, notify_party_city, notify_party_postcode, notify_party_country,
         notify_party_contact_person, notify_party_phone, notify_party_email)
     VALUES
        (:ref, :client_id, 1, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL',
         CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active',
         :port_of_loading_id, :port_of_discharge_id,
         :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text, :payment_method,
         :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
         :port_of_loading_name, :port_of_loading_city, :port_of_discharge_name, :port_of_discharge_city,
         :company_legal_name, :billing_address, :billing_address_line1, :billing_address_line2,
         :billing_city, :billing_postcode, :billing_country, :vat_eori_tax_no, :contact_person,
         :client_email, :client_phone, :country_of_destination,
         :consignee_same_as_buyer, :consignee_name, :consignee_address, :consignee_address_line1,
         :consignee_address_line2, :consignee_city, :consignee_postcode, :consignee_country,
         :consignee_vat_eori_tax_no, :consignee_contact_person, :consignee_phone, :consignee_email,
         :notify_party_same_as_consignee, :notify_party, :notify_party_address_line1,
         :notify_party_address_line2, :notify_party_city, :notify_party_postcode, :notify_party_country,
         :notify_party_contact_person, :notify_party_phone, :notify_party_email)`,
    {
      ref, client_id: clientId, inquiry_ref: inquiryRef,
      preset_id: preset.id, incoterm_id: incoterm.id, currency_id: currency.id,
      port_of_loading_id: portOfLoadingId, port_of_discharge_id: portOfDischargeId,
      incoterm_code: incoterm.code,
      currency_code: currency.code,
      preset_name: preset.preset_name,
      advance_pct: preset.advance_pct,
      advance_trigger_text: preset.advance_trigger_text,
      payment_method: preset.payment_method,
      balance_pct: preset.balance_pct,
      balance_trigger_option: preset.balance_trigger_option,
      balance_days: preset.balance_days,
      balance_trigger_wording: preset.balance_trigger_wording,
      show_bl_instructions: preset.show_bl_instructions,
      port_of_loading_name: portOfLoadingName,
      port_of_loading_city: portOfLoadingCity,
      port_of_discharge_name: portOfDischargeName,
      port_of_discharge_city: portOfDischargeCity,
      company_legal_name: client.company_legal_name ?? null,
      billing_address: client.billing_address ?? null,
      billing_address_line1: client.billing_address_line1 ?? null,
      billing_address_line2: client.billing_address_line2 ?? null,
      billing_city: client.billing_city ?? null,
      billing_postcode: client.billing_postcode ?? null,
      billing_country: client.billing_country ?? null,
      vat_eori_tax_no: client.vat_eori_tax_no ?? null,
      contact_person: client.contact_person ?? null,
      client_email: client.email ?? null,
      client_phone: client.phone ?? null,
      country_of_destination: client.country_of_destination ?? null,
      consignee_same_as_buyer: client.consignee_same_as_buyer ?? 1,
      consignee_name: client.consignee_name ?? null,
      consignee_address: client.consignee_address ?? null,
      consignee_address_line1: client.consignee_address_line1 ?? null,
      consignee_address_line2: client.consignee_address_line2 ?? null,
      consignee_city: client.consignee_city ?? null,
      consignee_postcode: client.consignee_postcode ?? null,
      consignee_country: client.consignee_country ?? null,
      consignee_vat_eori_tax_no: client.consignee_vat_eori_tax_no ?? null,
      consignee_contact_person: client.consignee_contact_person ?? null,
      consignee_phone: client.consignee_phone ?? null,
      consignee_email: client.consignee_email ?? null,
      notify_party_same_as_consignee: client.notify_party_same_as_consignee ?? 1,
      notify_party: client.notify_party ?? null,
      notify_party_address_line1: client.notify_party_address_line1 ?? null,
      notify_party_address_line2: client.notify_party_address_line2 ?? null,
      notify_party_city: client.notify_party_city ?? null,
      notify_party_postcode: client.notify_party_postcode ?? null,
      notify_party_country: client.notify_party_country ?? null,
      notify_party_contact_person: client.notify_party_contact_person ?? null,
      notify_party_phone: client.notify_party_phone ?? null,
      notify_party_email: client.notify_party_email ?? null,
    }
  );
  const orderId = result.insertId;
  await orderStageRepository.initializeForOrder(orderId);
  await orderComplianceTaskRepository.initializeForOrder(orderId);
  await orderExportBenefitSchemeRepository.initializeForOrder(orderId);
  for (const assigneeId of assigneeIds) {
    await orderAssigneeRepository.add(orderId, assigneeId, assigneeId);
  }
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

// A stage gate only passes once its document is complete (approved, or sent
// to the buyer while mail sending is on) — tests driving a gate create one.
// 'sent' is complete whichever way mail_sending_enabled is set, which
// matters because suites share one test DB and some flip that setting.
async function createTestDocument(orderId, typeCode, status = 'sent') {
  const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
  const result = await db.execute(
    `INSERT INTO documents (order_id, document_type_id, document_reference, status)
     VALUES (:order_id, :type_id, :ref, :status)`,
    { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status }
  );
  return result.insertId;
}

module.exports = { createTestClient, createTestOrder, createTestClientLogin, createTestFile, createTestDocument };
