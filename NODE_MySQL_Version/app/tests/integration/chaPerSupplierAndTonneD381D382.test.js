'use strict';

// Owner decision D-381 (18 Nov 2026): CHA + transport per container per supplier — the Edit Supplier
// box (amount, port, reason when either changes, audit SUPPLIER_CHA_CHANGED old → new), the chain
// row → supplier → product default with its source, and an older 'computed' price that never gets
// the per-container CHA. D-382: a block price typed per tonne on Edit price is stored per CBM
// (× the weight per CBM) with what was typed kept in entered_unit / entered_price.
// PHP twin: tests/Integration/ChaPerSupplierAndTonneD381D382Test.php.
const crypto = require('crypto');
const db = require('../../src/config/db');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const pricingService = require('../../src/services/pricingService');
const supplierCha = require('../../src/services/supplierCha');
const supplierOffers = require('../../src/services/supplierOffers');
const thicknessPrices = require('../../src/services/thicknessPrices');

const todayYmd = () => {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

describe('D-381 CHA per supplier and D-382 a price per tonne', () => {
  let admin;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    admin = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D381 User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-d381-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role_id: role.id }
    )).insertId;
  });
  afterAll(async () => {
    await db.pool.end();
  });

  async function supplier() {
    const id = await supplierRepository.create({ supplier_legal_name: `D381 Quarry ${crypto.randomBytes(3).toString('hex')}`, types: [] }, admin);
    return supplierRepository.find(id);
  }

  /** A block product sold per CBM (old forms "block", 2.9 t per CBM) with one price per CBM from this supplier. */
  async function offer(supplierId, ownCha, fobSource = 'direct') {
    const pid = await productRepository.create({ name: `D381 CHM Black block ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '25161100', reference_unit: 'CBM' }, admin);
    await db.execute("UPDATE catalog_products SET forms = 'block', weight_t_per_cbm = 2.9, cha_transport_per_container = 40000 WHERE id = :id", { id: pid });
    const oid = await productSupplierRepository.create({
      product_id: pid, supplier_name: 'D381 Quarry', location: null, contact_person: null, contact_phone: null, contact_email: null,
      fob_source: fobSource, fob_value: fobSource === 'direct' ? 80000 : null, factory_cost: fobSource === 'computed' ? 85000 : null, transportation_cost: null,
      packing_cost: null, loading_cost: null, cha_cost: null, is_primary: 1, notes: null,
    }, admin);
    await productSupplierRepository.setPriceDetails(oid, supplierId, true, ownCha !== null ? Number(ownCha) : null);
    return [await productRepository.find(pid), await productSupplierRepository.find(oid)];
  }

  it('the Edit Supplier box needs a reason and is audited', async () => {
    const s = await supplier();
    expect(supplierCha.read({ cha_transport_per_container: '', cha_port: '' }, s)).toEqual([null, null]); // nothing changed: no reason needed
    let [change, error] = supplierCha.read({ cha_transport_per_container: '45000', cha_port: 'Mundra', cha_reason: '' }, s);
    expect(change).toBeNull();
    expect(error).toBe('CHA + transport — reason for the change: A reason is required — nothing was saved.');
    [, error] = supplierCha.read({ cha_transport_per_container: '45,000.555', cha_port: '', cha_reason: 'New transporter rate' }, s);
    expect(error).toBe('CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty.');
    [, error] = supplierCha.read({ cha_transport_per_container: '', cha_port: 'Mundra', cha_reason: 'New transporter rate' }, s);
    expect(error).toBe('Port this figure is for: enter the CHA + transport figure too, or leave both empty.');

    [change, error] = supplierCha.read({ cha_transport_per_container: '45,000', cha_port: ' Mundra ', cha_reason: 'New transporter rate to Mundra' }, s);
    expect(error).toBeNull();
    await supplierCha.save(Number(s.id), s, change, admin);
    const fresh = await supplierRepository.find(Number(s.id));
    expect([String(fresh.cha_transport_per_container), String(fresh.cha_port)]).toEqual(['45000.00', 'Mundra']);
    const audit = await db.queryOne("SELECT old_value, new_value, reason FROM audit_log WHERE action_type = 'SUPPLIER_CHA_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: Number(s.id) });
    expect(audit).toEqual({ old_value: 'none', new_value: '₹45,000.00 (Mundra)', reason: 'New transporter rate to Mundra' });
    expect(supplierCha.read({ cha_transport_per_container: '45000.00', cha_port: 'Mundra' }, fresh)).toEqual([null, null]); // the same figure again: nothing to save
  });

  it('the chain row → supplier → product, and the computed rule', async () => {
    const s = await supplier();
    await db.execute("UPDATE suppliers SET cha_transport_per_container = 45000, cha_port = 'Mundra' WHERE id = :id", { id: Number(s.id) });
    const settings = await pricingService.settings();

    let [product, row] = await offer(Number(s.id), null);
    let parts = await pricingService.fobParts(product, row, 'cbm', null, settings);
    expect([parts.cha_source, parts.cha_source_tag]).toEqual(['supplier', 'supplier · Mundra']);
    const rules = await thicknessPrices.rules(product, settings);
    const expected = thicknessPrices.chaPerCbm(45000, Number(rules.container_cbm), rules.container_max_t, rules.t_per_cbm).per_cbm;
    expect(parts.cha).toBeCloseTo(expected, 3);
    expect(thicknessPrices.chaFallbackText(row, product)).toBe("Empty = supplier's ₹45,000.00 (Mundra)");
    // The same row read without the join still finds the supplier's figure (Node: withSupplierCha reads it first).
    const bare = { ...row };
    delete bare.supplier_cha_per_container;
    delete bare.supplier_cha_port;
    expect(thicknessPrices.chaPerContainer(await thicknessPrices.withSupplierCha(bare), product)).toBe(45000);
    expect((await pricingService.fobParts(product, bare, 'cbm', null, settings)).cha_source_tag).toBe('supplier · Mundra');

    [product, row] = await offer(Number(s.id), '60000');
    expect((await pricingService.fobParts(product, row, 'cbm', null, settings)).cha_source_tag).toBe('this price');

    const plain = await supplierRepository.create({ supplier_legal_name: `D381 Plain ${crypto.randomBytes(3).toString('hex')}`, types: [] }, admin);
    [product, row] = await offer(plain, null);
    expect((await pricingService.fobParts(product, row, 'cbm', null, settings)).cha_source_tag).toBe('product default');

    [product, row] = await offer(Number(s.id), '60000', 'computed');
    parts = await pricingService.fobParts(product, row, 'cbm', null, settings);
    expect(parts.cha).toBeCloseTo(0, 9);
    expect(parts.note).toBe('CHA already inside this older price');
    expect(parts.total).toBeCloseTo(85000, 3);
  });

  it('a price per tonne is saved per CBM with what was typed', async () => {
    const s = await supplier();
    const [product, row] = await offer(Number(s.id), null);
    expect(await supplierOffers.tonnePriced(product, false, String(row.price_basis))).toBe(true);
    expect((await supplierOffers.priceShape(product)).units.map((u) => u.label)).toContain('Tonne');
    let [summary, error] = await supplierOffers.save(product, row, { price: 30000, unit: 'TON', from_mm: null, own: {}, quoted_on: todayYmd(),
      valid_until: '', cha: null, includes_packing_loading: true, notes: '' }, admin);
    expect(error).toBeNull();
    expect(String(summary)).toContain('(₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM)');
    let fresh = await productSupplierRepository.find(Number(row.id));
    expect([String(fresh.fob_value), String(fresh.entered_unit), String(fresh.entered_price)]).toEqual(['87000.00', 'TON', '30000.00']);
    expect(supplierOffers.enteredText(fresh, product)).toBe('₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM');

    // Typed per CBM again: the typed tonne price is cleared.
    [, error] = await supplierOffers.save(product, fresh, { price: 86000, unit: 'CBM', from_mm: null, own: {}, quoted_on: todayYmd(),
      valid_until: '', cha: null, includes_packing_loading: true, notes: '' }, admin);
    expect(error).toBeNull();
    fresh = await productSupplierRepository.find(Number(row.id));
    expect([String(fresh.fob_value), fresh.entered_unit, fresh.entered_price]).toEqual(['86000.00', null, null]);
    // (No weight per CBM: refused with the lineUnits weight message — tonnePriceD382.test.js.)
  });
});
