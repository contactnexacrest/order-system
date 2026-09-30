'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const referenceNumberService = require('./referenceNumberService');
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
 * One exception, added 2026-09-30: if the source order has a Supplier PO,
 * its supplier and pricing terms are carried forward onto the new order
 * as a fresh order_supplier_po row with status='draft' (see
 * carrySupplierPoForward() below) — a repeat client order almost always
 * means a repeat order to the same supplier too, and staff shouldn't have
 * to re-type terms that haven't changed. It's a draft, not an issued PO:
 * nothing is sent to the supplier, and staff review/adjust it through the
 * normal Stage 5 flow once this new order reaches that stage.
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

  const orderRefFormat = (await companySettingsRepository.get('order_ref_format')) || 'SC/OC/{YYYY}/{NNN}';
  const testModeEnabled = await testModeService.isEnabled();
  const quotationValidityDays = parseInt((await companySettingsRepository.get('quotation_validity_days')) || '30', 10);
  const todayYmd = new Date().toISOString().slice(0, 10);
  const validUntilYmd = new Date(Date.now() + quotationValidityDays * 86400000).toISOString().slice(0, 10);

  // QA-5 CONC-03: see orderRepository.createWithNextSequence()'s docblock.
  const { orderId: newOrderId } = await orderRepository.createWithNextSequence(
    source.client_id,
    (sequenceNo) => ({
      order_reference: testModeService.applyReferencePrefix(
        orderRefFormat
          .replace(/\{YYYY\}/g, String(new Date().getFullYear()))
          .replace(/\{NNN\}/g, String(sequenceNo).padStart(3, '0')) + `-${source.client_id}`,
        testModeEnabled
      ),
      buyer_inquiry_ref: (client && client.client_unique_number) || source.buyer_inquiry_ref,
      payment_preset_id: source.payment_preset_id,
      incoterm_id: source.incoterm_id,
      port_of_loading_id: source.port_of_loading_id,
      port_of_discharge_id: source.port_of_discharge_id,
      port_of_discharge_text: source.port_of_discharge_id ? null : source.port_of_discharge_text,
      currency_id: source.currency_id,
      coo_type: source.coo_type || 'To Be Confirmed',
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
      duplicated_from_order_id: source.id,
    }),
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

  await carrySupplierPoForward(sourceOrderId, newOrderId, createdBy);

  return newOrderId;
}

/**
 * See module docblock. Silently does nothing if the source order never
 * had a Supplier PO — that's the common case for an order duplicated
 * before it ever reached Stage 5, and is not an error.
 */
async function carrySupplierPoForward(sourceOrderId, newOrderId, createdBy) {
  const sourceSupplierPo = await orderSupplierPoRepository.findLatestForOrder(sourceOrderId);
  if (!sourceSupplierPo) {
    return;
  }

  const documentGenerationService = require('./documentGenerationService');
  const docTypeId = await documentGenerationService.documentTypeIdFor('SUPPO');
  const newReference = docTypeId ? await referenceNumberService.generateDocumentRef(docTypeId) : null;
  if (!newReference) {
    return;
  }

  const newSupplierPoId = await orderSupplierPoRepository.create(
    newOrderId,
    sourceSupplierPo.supplier_id,
    newReference,
    {
      material_stone_type: sourceSupplierPo.material_stone_type,
      grade: sourceSupplierPo.grade,
      surface_finish: sourceSupplierPo.surface_finish,
      dimensions: sourceSupplierPo.dimensions,
      dimensional_tolerance: sourceSupplierPo.dimensional_tolerance,
      quantity: sourceSupplierPo.quantity,
      unit: sourceSupplierPo.unit,
      colour_reference: sourceSupplierPo.colour_reference,
      special_requirements: sourceSupplierPo.special_requirements,
      unit_price_inr: sourceSupplierPo.unit_price_inr,
      basic_value_inr: sourceSupplierPo.basic_value_inr,
      gst_rate_pct: sourceSupplierPo.gst_rate_pct,
      gst_amount_inr: sourceSupplierPo.gst_amount_inr,
      total_payable_inr: sourceSupplierPo.total_payable_inr,
      advance_pct: sourceSupplierPo.advance_pct,
      advance_amount_inr: sourceSupplierPo.advance_amount_inr,
      balance_amount_inr: sourceSupplierPo.balance_amount_inr,
      delivery_location: sourceSupplierPo.delivery_location,
      // Deliberately NOT carried over: required_delivery_date and
      // delivery_confirmation_due_date are specific to the source
      // shipment's own timeline — staff set fresh dates for this order.
      required_delivery_date: null,
      packing_requirement: sourceSupplierPo.packing_requirement,
    },
    'draft'
  );

  await auditLogRepository.log(
    createdBy,
    'SUPPLIER_PO_CARRIED_FORWARD',
    'order_supplier_po',
    newSupplierPoId,
    'source_supplier_po_id',
    null,
    String(sourceSupplierPo.id)
  );
}

module.exports = { duplicate };
