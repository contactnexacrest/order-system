'use strict';

// Owner decision D-382 (18 Nov 2026): Edit price / + Add product for a block — "Priced per" CBM or
// Tonne. A price per tonne is stored per CBM (price × the product's weight per CBM) in the usual
// fields; what was typed is kept (entered_unit 'TON', entered_price) for display:
// "₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM". Without a weight per CBM the per-tonne
// choice is refused with the lineUnits weight message. The products here have no id (no "Sold as"
// ticks to read), so whether they are a block comes from their old forms.
// PHP twin: tests/Unit/TonnePriceD382Test.php.
const supplierOffers = require('../../src/services/supplierOffers');

const BLOCK = { id: 0, forms: 'block', reference_unit: 'CBM' };

describe('D-382 a block price typed per tonne', () => {
  it('the display text', () => {
    expect(supplierOffers.tonneText(30000, 2.9)).toBe('₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM');
  });

  it('a price per tonne is stored per CBM', async () => {
    const inp = await supplierOffers.fromTonne({ price: 30000, unit: 'TON' }, BLOCK, false, 'unit', 2.9);
    expect(inp.tonne_error).toBeNull();
    expect(inp.price).toBe(87000);
    expect(inp.unit).toBe('CBM');
    expect([inp.entered_unit, inp.entered_price]).toEqual(['TON', 30000]);
  });

  it('a price per CBM is left as it is', async () => {
    const inp = await supplierOffers.fromTonne({ price: 87000, unit: 'CBM' }, BLOCK, false, 'unit', 2.9);
    expect([inp.price, inp.unit, inp.entered_unit, inp.entered_price, inp.tonne_error]).toEqual([87000, 'CBM', null, null, null]);
  });

  it('without a weight per CBM, Tonne is refused', async () => {
    const inp = await supplierOffers.fromTonne({ price: 30000, unit: 'TON' }, BLOCK, false, 'unit', 0);
    expect(inp.tonne_error).toBe('Set the weight per CBM on the product or its category (Products) — needed to compare a price per Ton.');
    expect(inp.price).toBe(30000); // nothing converted
  });

  it('Tonne only for a block priced per CBM', async () => {
    expect(await supplierOffers.tonnePriced(BLOCK, false)).toBe(true);
    expect(await supplierOffers.tonnePriced({ id: 0, forms: 'block', reference_unit: 'SQM' }, false, 'cbm')).toBe(true); // an offer priced per CBM
    expect(await supplierOffers.tonnePriced({ id: 0, forms: 'slab', reference_unit: 'CBM' }, false)).toBe(false); // not a block
    expect(await supplierOffers.tonnePriced({ id: 0, forms: '', reference_unit: 'CBM' }, false)).toBe(false); // no forms = all forms, not a block
    expect(await supplierOffers.tonnePriced(BLOCK, true)).toBe(false); // a thickness grid
    expect(await supplierOffers.tonnePriced({ id: 0, forms: 'block', reference_unit: 'SQM' }, false)).toBe(false); // priced per SQM
    const inp = await supplierOffers.fromTonne({ price: 30000, unit: 'TON' }, { id: 0, forms: 'slab', reference_unit: 'CBM' }, false, 'unit', 2.9);
    expect(inp.tonne_error).toBe('Priced per: Tonne is only for a block priced per CBM — choose the unit the price is per.');
  });
});
