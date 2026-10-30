'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const clientRepository = require('../../src/repositories/clientRepository');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const orderDuplicationService = require('../../src/services/orderDuplicationService');
const { createTestOrder } = require('../support/fixtures');

// QA Round 6, R6-N16: clients.email is unique, and Jest runs files in
// parallel on one database — this file's fixed test emails are its own.
const ORIGINAL_EMAIL = `original-${process.pid}-${Date.now()}@buyer.test`;
const RENAMED_EMAIL = `renamed-${process.pid}-${Date.now()}@buyer.test`;
const CONFIRMED_EMAIL = `confirmed-${process.pid}-${Date.now()}@buyer.test`;
const UPDATED_EMAIL = `updated-${process.pid}-${Date.now()}@buyer.test`;

/**
 * Port of StandaloneOrderClientSnapshotTest.php. 90-item backlog
 * "standalone order" follow-on: `clients` is fully admin-editable
 * (clientRepository.update()) and, in the ordinary PI-intake workflow
 * (piIntakeReviewController.accept()), is actually overwritten as a
 * matter of routine every time a buyer confirms/corrects their own
 * details — orderRepository.find() used to live-join it, so that
 * correction on ANY order for a client would silently rewrite what every
 * OTHER order for the same client — including ones closed years ago —
 * displays and prints. This proves the isolation actually holds: editing
 * `clients` directly (an admin correction) must never change an existing
 * order's own resolved values; a PI-intake acceptance must only ever
 * update the one order it was submitted for, never a sibling order for
 * the same client; and duplicating an order re-resolves TODAY's current
 * client details (a duplicate is a new commercial decision made today).
 */
describe('Standalone order client snapshot (90-item backlog "standalone order" follow-on)', () => {
  beforeEach(async () => {
    await db.execute(`UPDATE clients SET email = NULL WHERE email IN (${[ORIGINAL_EMAIL, RENAMED_EMAIL, CONFIRMED_EMAIL, UPDATED_EMAIL].map((e, i) => `:e${i}`).join(', ')})`, Object.fromEntries([ORIGINAL_EMAIL, RENAMED_EMAIL, CONFIRMED_EMAIL, UPDATED_EMAIL].map((e, i) => [`e${i}`, e])));
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createFullTestClient() {
    const result = await db.execute(
      `INSERT INTO clients
          (client_unique_number, company_legal_name, billing_address, vat_eori_tax_no, contact_person,
           email, phone, country_of_destination, consignee_same_as_buyer, notify_party_same_as_consignee, created_by)
       VALUES
          (:num, :name, :addr, :vat, :contact, :email, :phone, :country, 1, 1, NULL)`,
      {
        num: `TEST-${Math.random().toString(16).slice(2, 10)}`,
        name: 'Original Buyer Ltd',
        addr: '1 Original Street, Original City',
        vat: 'VAT-ORIGINAL',
        contact: 'Original Contact',
        email: ORIGINAL_EMAIL,
        phone: '1000000000',
        country: 'Netherlands',
      }
    );
    return result.insertId;
  }

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-client-snapshot-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  /** createTestOrder() hardcodes sequence_no=1, so a second order for the same client needs its own sequence number. */
  async function createTestOrderWithSequence(clientId, sequenceNo) {
    const [incoterm, currency, preset, client] = await Promise.all([
      db.queryOne("SELECT id, code FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id, code FROM currencies WHERE code = 'USD'"),
      db.queryOne(
        `SELECT id, preset_name, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option,
                balance_days, balance_trigger_wording, show_bl_instructions
         FROM payment_presets WHERE preset_name = 'Standard — New Buyer'`
      ),
      db.queryOne('SELECT company_legal_name, vat_eori_tax_no FROM clients WHERE id = :id', { id: clientId }),
    ]);

    const result = await db.execute(
      `INSERT INTO orders
          (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
           currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
           incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text,
           balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
           company_legal_name, vat_eori_tax_no)
       VALUES
          (:ref, :client_id, :seq, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL',
           CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active',
           :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text,
           :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
           :company_legal_name, :vat_eori_tax_no)`,
      {
        ref: `JEST-TEST-${Math.random().toString(16).slice(2, 10)}`,
        client_id: clientId,
        seq: sequenceNo,
        inquiry_ref: `JEST-${Math.random().toString(16).slice(2, 8)}`,
        preset_id: preset.id,
        incoterm_id: incoterm.id,
        currency_id: currency.id,
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
        company_legal_name: client ? client.company_legal_name : null,
        vat_eori_tax_no: client ? client.vat_eori_tax_no : null,
      }
    );
    const orderId = result.insertId;
    await orderStageRepository.initializeForOrder(orderId);
    return orderId;
  }

  function updateData(overrides) {
    return {
      company_legal_name: null, billing_address: null,
      billing_address_line1: null, billing_address_line2: null, billing_city: null,
      billing_postcode: null, billing_country: null,
      consignee_name: null, consignee_address: null,
      consignee_same_as_buyer: 1, consignee_address_line1: null, consignee_address_line2: null,
      consignee_city: null, consignee_postcode: null, consignee_country: null,
      consignee_vat_eori_tax_no: null, consignee_contact_person: null,
      consignee_phone: null, consignee_email: null,
      vat_eori_tax_no: null, contact_person: null,
      email: null, phone: null,
      country_of_destination: null, coo_type: null,
      notify_party_same_as_consignee: 1, notify_party: null,
      notify_party_address_line1: null, notify_party_address_line2: null,
      notify_party_city: null, notify_party_postcode: null, notify_party_country: null,
      notify_party_contact_person: null, notify_party_phone: null, notify_party_email: null,
      ...overrides,
    };
  }

  it('snapshots client details at creation', async () => {
    const clientId = await createFullTestClient();
    const orderId = await createTestOrder(clientId);

    const order = await orderRepository.find(orderId);

    expect(order.company_legal_name).toBe('Original Buyer Ltd');
    expect(order.billing_address).toBe('1 Original Street, Original City');
    expect(order.vat_eori_tax_no).toBe('VAT-ORIGINAL');
    expect(order.contact_person).toBe('Original Contact');
    expect(order.client_email).toBe(ORIGINAL_EMAIL);
    expect(order.client_phone).toBe('1000000000');
    expect(order.country_of_destination).toBe('Netherlands');
  });

  it('editing the clients table does not change an existing order', async () => {
    const clientId = await createFullTestClient();
    const orderId = await createTestOrder(clientId);
    expect((await orderRepository.find(orderId)).company_legal_name).toBe('Original Buyer Ltd');

    await clientRepository.update(clientId, updateData({
      company_legal_name: 'Renamed Buyer Ltd', billing_address: 'Renamed Address',
      vat_eori_tax_no: 'VAT-RENAMED', contact_person: 'Renamed Contact',
      email: RENAMED_EMAIL, phone: '2000000000', country_of_destination: 'Germany',
    }));

    const order = await orderRepository.find(orderId);
    expect(order.company_legal_name).toBe('Original Buyer Ltd');
    expect(order.billing_address).toBe('1 Original Street, Original City');
    expect(order.vat_eori_tax_no).toBe('VAT-ORIGINAL');
    expect(order.client_email).toBe(ORIGINAL_EMAIL);
    expect(order.country_of_destination).toBe('Netherlands');
  });

  it('PI-intake acceptance applies the correction to this order only, never a sibling order', async () => {
    const clientId = await createFullTestClient();
    const orderA = await createTestOrder(clientId);
    const orderB = await createTestOrderWithSequence(clientId, 2);

    // Mirrors piIntakeReviewController.accept() exactly: it corrects the
    // `clients` master row, then applies that same correction onto THIS
    // order's own snapshot — never a sibling order's.
    await clientRepository.update(clientId, updateData({
      company_legal_name: 'Confirmed Buyer Ltd', billing_address: 'Confirmed Address',
      vat_eori_tax_no: 'VAT-CONFIRMED', contact_person: 'Confirmed Contact',
      email: CONFIRMED_EMAIL, phone: '3000000000', country_of_destination: 'France',
    }));
    await orderRepository.applyClientSnapshot(orderA, clientId);

    const reloadedA = await orderRepository.find(orderA);
    const reloadedB = await orderRepository.find(orderB);

    expect(reloadedA.company_legal_name).toBe('Confirmed Buyer Ltd');
    expect(reloadedA.vat_eori_tax_no).toBe('VAT-CONFIRMED');
    expect(reloadedB.company_legal_name).toBe('Original Buyer Ltd');
    expect(reloadedB.vat_eori_tax_no).toBe('VAT-ORIGINAL');
  });

  it("duplicating an order re-resolves today's current client details", async () => {
    const clientId = await createFullTestClient();
    const sourceOrderId = await createTestOrder(clientId);
    expect((await orderRepository.find(sourceOrderId)).company_legal_name).toBe('Original Buyer Ltd');

    // "Later" the client's own details change (e.g. a legitimate admin correction).
    await clientRepository.update(clientId, updateData({
      company_legal_name: 'Updated Buyer Ltd', billing_address: 'Updated Address',
      vat_eori_tax_no: 'VAT-UPDATED', contact_person: 'Updated Contact',
      email: UPDATED_EMAIL, phone: '4000000000', country_of_destination: 'Italy',
    }));

    const adminUserId = await createTestUser('Admin');
    const duplicateOrderId = await orderDuplicationService.duplicate(sourceOrderId, adminUserId);

    const source = await orderRepository.find(sourceOrderId);
    const duplicate = await orderRepository.find(duplicateOrderId);

    expect(source.company_legal_name).toBe('Original Buyer Ltd');
    expect(duplicate.company_legal_name).toBe('Updated Buyer Ltd');
    expect(duplicate.vat_eori_tax_no).toBe('VAT-UPDATED');
  });
});
