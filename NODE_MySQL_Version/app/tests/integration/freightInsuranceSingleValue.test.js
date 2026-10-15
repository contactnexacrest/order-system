'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function read(relativePath) {
  return fs.readFileSync(path.join(__dirname, '../..', relativePath), 'utf8');
}

/**
 * Port of FreightInsuranceSingleValueTest.php. 90-item backlog #67: the
 * Freight/Insurance section asked for a low/high range before freight is
 * ever actually booked, and the hint text next to both fields said the
 * literal placeholder "order currency" instead of the order's real
 * currency. Per the user's own wording: a single indicative figure
 * ("current indicative rate only — the real rate is confirmed by Debit
 * Note once cargo is packed and ready"), and the actual currency code
 * shown instead of the placeholder. Consolidated
 * indicative_freight_low/indicative_freight_high into one
 * indicative_freight_amount column (Section BJ, schema.sql).
 */
describe('Freight/Insurance single indicative value (90-item backlog #67)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('indicative_freight_amount round-trips through updateDetails', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);
    expect(order).not.toBeNull();
    expect(Object.prototype.hasOwnProperty.call(order, 'indicative_freight_amount')).toBe(true);
    expect(Object.prototype.hasOwnProperty.call(order, 'indicative_freight_high')).toBe(false);

    await orderRepository.updateDetails(orderId, {
      incoterm_id: order.incoterm_id,
      currency_id: order.currency_id,
      port_of_loading_id: order.port_of_loading_id ?? null,
      port_of_discharge_id: order.port_of_discharge_id ?? null,
      port_of_discharge_text: order.port_of_discharge_text ?? null,
      coo_type: order.coo_type ?? 'To Be Confirmed',
      container_type: order.container_type ?? null,
      buyers_po_ref: order.buyers_po_ref ?? 'NIL',
      special_requirements: order.special_requirements ?? null,
      est_lead_time_text: order.est_lead_time_text ?? null,
      estimated_total_cbm: order.estimated_total_cbm ?? null,
      estimated_gross_weight_kg: order.estimated_gross_weight_kg ?? null,
      estimated_net_weight_kg: order.estimated_net_weight_kg ?? null,
      estimated_package_count: order.estimated_package_count ?? null,
      estimated_package_type: order.estimated_package_type ?? null,
      indicative_freight_amount: '950.00',
      indicative_insurance_amount: '120.00',
    });

    const updated = await orderRepository.find(orderId);
    expect(updated.indicative_freight_amount).toBe('950.00');
    expect(updated.indicative_insurance_amount).toBe('120.00');
  });

  it('create order form shows a single indicative freight field with a live currency label', () => {
    const html = read('views/orders/create.njk');

    expect(html).toContain('name="indicative_freight_amount"');
    expect(html).not.toContain('indicative_freight_low');
    expect(html).not.toContain('indicative_freight_high');
    expect(html).toContain('data-code="{{ cur.code }}"');
    expect(html).toContain('id="freight-currency-unit"');
    expect(html).toContain('onchange="updateFreightCurrencyLabels(this)"');
    expect(html).toContain('updateFreightCurrencyLabels');
    expect(html).toContain('current indicative rate only');
    expect(html).toContain('paid in full in advance against the Debit Note, before the vessel is booked');
  });

  it("edit details form shows a single indicative freight field with the order's own currency", () => {
    const html = read('views/orders/edit_details.njk');

    expect(html).toContain('name="indicative_freight_amount"');
    expect(html).not.toContain('indicative_freight_low');
    expect(html).not.toContain('indicative_freight_high');
    expect(html).toContain('order.currency_code');
    expect(html).toContain('updateFreightCurrencyLabels');
  });

  it('QT and PI templates show a single indicative freight figure, not a range', () => {
    const qt = read('templates/QT/quotation.njk');
    const pi = read('templates/PI/proforma_invoice.njk');

    [qt, pi].forEach((html) => {
      expect(html).toContain('order.indicative_freight_amount');
      expect(html).not.toContain('indicative_freight_low');
      expect(html).not.toContain('indicative_freight_high');
      expect(html).toContain('Current indicative rate only');
      expect(html).toContain('paid in full in advance against our Debit Note, before the vessel is booked');
    });
  });

  it('DOCX builder shows a single indicative freight figure, not a range', () => {
    const js = read('src/services/docx/docxDocumentBuilder.js');

    expect(js).toContain("'indicative_freight_amount'");
    expect(js).not.toContain('indicative_freight_low');
    expect(js).not.toContain('indicative_freight_high');
    expect(js).toContain('Current indicative rate only');
  });
});
