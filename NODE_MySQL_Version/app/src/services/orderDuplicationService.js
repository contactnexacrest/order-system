'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const testModeService = require('../services/testModeService');

/**
 * Port of App\Services\OrderDuplicationService — creates a brand-new order
 * from an existing one — a "repeat order", requested by staff directly
 * (any order, any status, including a closed one) or approved from a
 * client reorder request (reorderRequestController). Deliberately copies
 * only what a fresh order would otherwise need typed in by hand again:
 * client, commercial terms, and product lines. Never copies anything
 * instance-specific to the source order — its dates, documents, payment
 * status, FIRC/INR-actual data, disputes, comments, or stage progress —
 * all of that starts fresh, exactly like any order created through the
 * normal /orders/create form.
 *
 * @param {number} sourceOrderId
 * @param {number} createdBy
 * @param {Array<{description:string, dimensions:?string, finish:?string, quantity:?string, quantity_is_tbc:boolean, unit:?string, unit_price:?string, hs_code:string}>|null} productLines
 *   When null, copies the source order's own current active product lines.
 * @returns {Promise<number>} the new order's id
 */
async function duplicate(sourceOrderId, createdBy, productLines = null) {
  const source = await orderRepository.find(sourceOrderId);
  if (!source) {
    throw new Error(`Cannot duplicate order ${sourceOrderId} — not found.`);
  }
  const client = await clientRepository.find(source.client_id);

  const sequenceNo = await orderRepository.nextSequenceForClient(source.client_id);
  const orderRefFormat = (await companySettingsRepository.get('order_ref_format')) || 'SC/OC/{YYYY}/{NNN}';
  const testModeEnabled = await testModeService.isEnabled();
  const orderReference = testModeService.applyReferencePrefix(
    orderRefFormat
      .replace(/\{YYYY\}/g, String(new Date().getFullYear()))
      .replace(/\{NNN\}/g, String(sequenceNo).padStart(3, '0')) + `-${source.client_id}`,
    testModeEnabled
  );
  const quotationValidityDays = parseInt((await companySettingsRepository.get('quotation_validity_days')) || '30', 10);
  const todayYmd = new Date().toISOString().slice(0, 10);
  const validUntilYmd = new Date(Date.now() + quotationValidityDays * 86400000).toISOString().slice(0, 10);

  const newOrderId = await orderRepository.create(
    {
      order_reference: orderReference,
      client_id: source.client_id,
      sequence_no: sequenceNo,
      buyer_inquiry_ref: (client && client.client_unique_number) || source.buyer_inquiry_ref,
      payment_preset_id: source.payment_preset_id,
      incoterm_id: source.incoterm_id,
      port_of_loading_id: source.port_of_loading_id,
      port_of_discharge_id: source.port_of_discharge_id,
      port_of_discharge_text: source.port_of_discharge_id ? null : source.port_of_discharge_text,
      currency_id: source.currency_id,
      coo_type: source.coo_type || 'TBC',
      include_annexure_a: !!source.include_annexure_a,
      special_requirements: source.special_requirements,
      container_type: source.container_type,
      estimated_total_cbm: source.estimated_total_cbm,
      estimated_gross_weight_kg: source.estimated_gross_weight_kg,
      estimated_net_weight_kg: source.estimated_net_weight_kg,
      estimated_package_count: source.estimated_package_count,
      estimated_package_type: source.estimated_package_type,
      est_lead_time_text: source.est_lead_time_text,
      indicative_freight_low: source.indicative_freight_low,
      indicative_freight_high: source.indicative_freight_high,
      indicative_insurance_amount: source.indicative_insurance_amount,
      buyers_po_ref: 'NIL', // the buyer's own ref is specific to each order — staff records the new one
      quotation_date: todayYmd,
      quotation_valid_until: validUntilYmd,
    },
    createdBy
  );
  if (testModeEnabled) {
    await orderRepository.markTest(newOrderId);
  }

  await orderStageRepository.initializeForOrder(newOrderId);
  await orderPaymentStatusRepository.initializeForOrder(newOrderId);

  if (productLines === null) {
    for (const line of await orderProductRepository.forOrder(sourceOrderId)) {
      await orderProductRepository.duplicate(line.id, newOrderId);
    }
  } else {
    let lineNo = 1;
    for (const line of productLines) {
      await orderProductRepository.add(
        newOrderId,
        lineNo++,
        line.description,
        line.dimensions,
        line.finish,
        line.quantity,
        line.quantity_is_tbc,
        line.unit,
        line.unit_price,
        line.hs_code
      );
    }
  }

  await auditLogRepository.log(createdBy, 'ORDER_DUPLICATED', 'orders', newOrderId, 'source_order_id', null, String(sourceOrderId));

  return newOrderId;
}

module.exports = { duplicate };
