'use strict';

const db = require('../../src/config/db');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const orderDuplicationService = require('../../src/services/orderDuplicationService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of StandaloneSupplierPoSnapshotTest.php. 90-item backlog
 * "standalone order" follow-on: order_supplier_po.supplier_id was the
 * same live-FK pattern the orders table itself had —
 * orderSupplierPoRepository.findLatestForOrder() joined `suppliers` on
 * every read, so a change to a supplier's own name/address/GSTIN/PAN/
 * contact details would retroactively rewrite what an already-issued,
 * signed, or closed Supplier PO displays and prints. Suppliers have no
 * dedicated edit screen in the app today, but the same architectural risk
 * applies the moment one is added (or to a direct DB edit) — so this
 * closes it the same way the orders table was: snapshot the supplier's
 * details once, at Supplier PO creation, onto the row itself.
 */
describe('Standalone Supplier PO snapshot (90-item backlog "standalone order" follow-on)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-spo-snapshot-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fullSupplierPoData(overrides = {}) {
    return {
      material_stone_type: null, grade: null, surface_finish: null,
      dimensions: null, dimensional_tolerance: null, quantity: null,
      unit: null, colour_reference: null, special_requirements: null,
      unit_price_inr: null, basic_value_inr: null, gst_rate_pct: null,
      gst_amount_inr: null, total_payable_inr: null, advance_pct: null,
      advance_amount_inr: null, balance_amount_inr: null,
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
      ...overrides,
    };
  }

  it('snapshots supplier details at creation', async () => {
    const supplierId = await supplierRepository.create({
      supplier_legal_name: 'Original Stone Works',
      address: 'Plot 1, Udaipur',
      gstin: 'GSTIN-ORIG',
      pan: 'PAN-ORIG',
      contact_person: 'Original Contact',
      phone: '9000000000',
      supplier_type: 'Manufacturer',
    });
    const orderId = await createTestOrder(await createTestClient());
    await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-SNAP-1', fullSupplierPoData());

    const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);

    expect(supplierPo.supplier_legal_name).toBe('Original Stone Works');
    expect(supplierPo.supplier_address).toBe('Plot 1, Udaipur');
    expect(supplierPo.supplier_gstin).toBe('GSTIN-ORIG');
    expect(supplierPo.supplier_pan).toBe('PAN-ORIG');
    expect(supplierPo.supplier_contact_person).toBe('Original Contact');
    expect(supplierPo.supplier_phone).toBe('9000000000');
    expect(supplierPo.supplier_type).toBe('Manufacturer');
  });

  it('editing the suppliers table does not change an existing Supplier PO', async () => {
    const supplierId = await supplierRepository.create({
      supplier_legal_name: 'Stable Stone Co',
      address: 'Original Address',
    });
    const orderId = await createTestOrder(await createTestClient());
    await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-SNAP-2', fullSupplierPoData());

    await db.execute('UPDATE suppliers SET supplier_legal_name = :name, address = :address WHERE id = :id', {
      name: 'Renamed Stone Co', address: 'Renamed Address', id: supplierId,
    });

    const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(supplierPo.supplier_legal_name).toBe('Stable Stone Co');
    expect(supplierPo.supplier_address).toBe('Original Address');
  });

  it("duplicating an order re-resolves today's current supplier details", async () => {
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Pre-Change Supplier Name' });
    const clientId = await createTestClient();
    const sourceOrderId = await createTestOrder(clientId);
    await orderSupplierPoRepository.create(sourceOrderId, supplierId, 'SUPPO-SNAP-3', fullSupplierPoData());

    // "Later" the supplier's own name changes.
    await db.execute('UPDATE suppliers SET supplier_legal_name = :name WHERE id = :id', {
      name: 'Post-Change Supplier Name', id: supplierId,
    });

    const adminUserId = await createTestUser('Admin');
    const duplicateOrderId = await orderDuplicationService.duplicate(sourceOrderId, adminUserId);

    const source = await orderSupplierPoRepository.findLatestForOrder(sourceOrderId);
    const duplicate = await orderSupplierPoRepository.findLatestForOrder(duplicateOrderId);

    expect(source.supplier_legal_name).toBe('Pre-Change Supplier Name');
    expect(duplicate.supplier_legal_name).toBe('Post-Change Supplier Name');
  });
});
