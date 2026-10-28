'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const paymentActionService = require('../../src/services/paymentActionService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const templatesDir = path.join(__dirname, '../../templates');

/**
 * Port of CfrBuyerInsuresTest.php. QA Round 5, Q-R5-2 (owner decision:
 * "Buyer insures"). Under Incoterms 2020 only CIF puts insurance on the
 * seller; under CFR the seller pays freight only. A CFR order that has an
 * insurance figure stored must never show it on a document, charge it on
 * the Debit Note or its payment link, or count it as a cost. CIF keeps
 * everything exactly as before.
 */
describe('CFR: buyer insures (QA Round 5, Q-R5-2)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function orderWithFreight(incoterm) {
    const orderId = await createTestOrder(await createTestClient(), incoterm);
    await orderProductRepository.add(orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '1825.00');
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '1200.00', insurance_amount: '150.00' });
    return orderId;
  }

  it('CFR documents carry freight only', async () => {
    const orderId = await orderWithFreight('CFR');

    const ci = await documentDataAssembler.assemble(orderId, 'CI');
    expect(ci.order.is_cif).toBe(false);
    expect(ci.financial.freight_insurance_amount).toBe('1,200.00');
    expect(ci.financial.total_commercial_invoice_value).toBe('19,450.00');

    const fdn = await documentDataAssembler.assemble(orderId, 'FDN');
    expect(fdn.freight.insurance_amount).toBe('NIL');
    expect(fdn.freight.total_freight_and_insurance).toBe('1,200.00');
  });

  it('CIF documents still include insurance', async () => {
    const orderId = await orderWithFreight('CIF');

    const ci = await documentDataAssembler.assemble(orderId, 'CI');
    expect(ci.order.is_cif).toBe(true);
    expect(ci.financial.freight_insurance_amount).toBe('1,350.00');

    const fdn = await documentDataAssembler.assemble(orderId, 'FDN');
    expect(fdn.freight.insurance_amount).toBe('150.00');
    expect(fdn.freight.total_freight_and_insurance).toBe('1,350.00');
  });

  it('the Debit Note payment link asks for freight only on CFR', async () => {
    const cfr = await orderRepository.find(await orderWithFreight('CFR'));
    const cif = await orderRepository.find(await orderWithFreight('CIF'));

    expect(await paymentActionService.amountDue(cfr, 'freight')).toBe(1200);
    expect(await paymentActionService.amountDue(cif, 'freight')).toBe(1350);
  });

  it('profit ignores insurance on CFR', async () => {
    expect((await orderProfitabilityService.computeForOrder(await orderWithFreight('CFR'))).insurance_cost_inr).toBe(0);
    expect((await orderProfitabilityService.computeForOrder(await orderWithFreight('CIF'))).insurance_cost_inr).toBe(150);
  });

  it('the Quotation and Proforma print "buyer insures" on CFR', () => {
    for (const template of ['QT/quotation.njk', 'PI/proforma_invoice.njk']) {
      const src = fs.readFileSync(path.join(templatesDir, template), 'utf8');
      expect(src).toContain('{% elif not order.is_cif %}NIL — buyer arranges insurance (CFR).');
      expect(src).toContain("{% if not order.is_cif %}Buyer's responsibility.");
      expect(src).toContain('Freight (CFR order — buyer arranges insurance)');
    }
    const ci = fs.readFileSync(path.join(templatesDir, 'CI/commercial_invoice.njk'), 'utf8');
    expect(ci).toContain('<td class="k">Freight{% if order.is_cif or order.is_fob %} &amp; Insurance{% endif %}</td>');
  });

  // QA Round 5, R5-12: no section heading is left alone at a page foot.
  it('the layout keeps section headings with their content', () => {
    const layout = fs.readFileSync(path.join(templatesDir, '_layout.njk'), 'utf8');
    expect(layout).toMatch(/\.section-title \{[^}]*page-break-after: avoid;/);
    expect(layout).toMatch(/\.section-title-highlight \{[^}]*page-break-after: avoid;/);
  });
});
