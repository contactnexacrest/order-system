'use strict';

// Owner decision D-381 (18 Nov 2026, confirmed twice): CHA + transport per container per supplier.
// Order of use: the price row's own figure → the row's directory supplier's figure → the product
// default → none, with where it came from ('row' | 'supplier' | 'product'). A row with
// fob_source 'computed' (an older price with the CHA already inside) never gets it, with the note
// "CHA already inside this older price". The rows here carry the supplier's figure as the
// repository joins it (supplier_cha_per_container / supplier_cha_port), so no database is needed.
// PHP twin: tests/Unit/ChaPerSupplierD381Test.php.
const thicknessPrices = require('../../src/services/thicknessPrices');

const rules = () => ({
  grid: true, base_mm: 20, k: 40, cbm_from: 50,
  rows: [{ mm: 20, pct: 0, option: '20 mm' }, { mm: 30, pct: 35, option: '30 mm' }],
  container_cbm: 8, container_max_t: 27, t_per_cbm: 2.7, own: [], category_id: 1, category_name: 'Natural Stone', unit: 'SQM',
});

const row = (own, supplier, port = null, source = 'direct') => ({
  cha_transport_per_container: own, supplier_id: 7, supplier_cha_per_container: supplier, supplier_cha_port: port,
  fob_source: source, includes_packing_loading: 1,
});

describe('D-381 CHA + transport per container per supplier', () => {
  it("the row's own figure comes first", () => {
    const src = thicknessPrices.chaSource(row('60000.00', '45000.00', 'Mundra'), { cha_transport_per_container: '40000' });
    expect(src).toEqual({ value: 60000, source: 'row', port: null, computed: false });
    expect(thicknessPrices.chaSourceTag(src)).toBe('this price');
  });

  it("then the supplier's figure, with its port", () => {
    const src = thicknessPrices.chaSource(row(null, '45000.00', 'Mundra'), { cha_transport_per_container: '40000' });
    expect(src).toEqual({ value: 45000, source: 'supplier', port: 'Mundra', computed: false });
    expect(thicknessPrices.chaSourceTag(src)).toBe('supplier · Mundra');
    expect(thicknessPrices.chaPerContainer(row(null, '45000.00', 'Mundra'), {})).toBe(45000);
    expect(thicknessPrices.chaSourceTag(thicknessPrices.chaSource(row(null, '45000.00'), {}))).toBe('supplier'); // no port: just "supplier"
  });

  it('then the product default, then none', () => {
    const src = thicknessPrices.chaSource(row(null, null), { cha_transport_per_container: '40000' });
    expect(src).toEqual({ value: 40000, source: 'product', port: null, computed: false });
    expect(thicknessPrices.chaSourceTag(src)).toBe('product default');
    const none = thicknessPrices.chaSource(row(null, null), {});
    expect(none.value).toBeNull();
    expect(thicknessPrices.chaSourceTag(none)).toBeNull();
    // A row with no directory supplier (and no joined figure) goes straight to the product default.
    expect(thicknessPrices.chaPerContainer({ cha_transport_per_container: null }, { cha_transport_per_container: '40000' })).toBe(40000);
  });

  it('a computed row never gets per-container CHA', () => {
    const src = thicknessPrices.chaSource(row('60000.00', '45000.00', 'Mundra', 'computed'), { cha_transport_per_container: '40000' });
    expect(src).toEqual({ value: null, source: null, port: null, computed: true });
    expect(thicknessPrices.chaPerContainer(row('60000.00', '45000.00', 'Mundra', 'computed'), { cha_transport_per_container: '40000' })).toBeNull();
    const c = thicknessPrices.cost(rules(), { 20: 1600 }, row(null, '45000.00', 'Mundra', 'computed'), { cha_transport_per_container: '40000' }, 'unit', null);
    expect(c.cha).toBeCloseTo(0, 9);
    expect(c.total).toBeCloseTo(1600, 6);
    expect(c.note).toBe('CHA already inside this older price');
  });

  it("the supplier's figure is used in the cost", () => {
    // ₹48,000 per container ÷ 8 CBM = ₹6,000 per CBM; per m² at 20 mm = 120.
    const c = thicknessPrices.cost(rules(), { 20: 1600 }, row(null, '48000.00', 'Mundra'), { cha_transport_per_container: '40000' }, 'unit', null);
    expect(c.cha).toBeCloseTo(120, 6);
    expect(c.total).toBeCloseTo(1720, 6);
    expect(c.note).toBeNull();
  });

  it('the Edit price placeholder names the fallback', () => {
    expect(thicknessPrices.chaFallbackText(row('60000.00', '45000.00', 'Mundra'), { cha_transport_per_container: '40000' })).toBe("Empty = supplier's ₹45,000.00 (Mundra)");
    expect(thicknessPrices.chaFallbackText(row(null, '45000.00'), {})).toBe("Empty = supplier's ₹45,000.00");
    expect(thicknessPrices.chaFallbackText(row(null, null), { cha_transport_per_container: '40000' })).toBe('Empty = product default ₹40,000.00');
    expect(thicknessPrices.chaFallbackText(row(null, null), {})).toBe('Empty = no CHA + transport');
  });
});
