'use strict';

const fs = require('fs');

const orderRepository = require('../repositories/orderRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderPackingRepository = require('../repositories/orderPackingRepository');
const orderCrateRepository = require('../repositories/orderCrateRepository');
const orderShippingRepository = require('../repositories/orderShippingRepository');
const orderProductionRepository = require('../repositories/orderProductionRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const assetRepository = require('../repositories/assetRepository');
const documentRepository = require('../repositories/documentRepository');

/**
 * Pulls every field a QT/PI/OC template needs from the DB and assembles a
 * single flat object to hand to Nunjucks. This is the one place that reads
 * company_settings/payment_presets/order data for document rendering —
 * per ARCHITECTURE.md section 4: "a code reviewer can grep templates/ for
 * anything that looks like a literal business value and it should return
 * nothing outside of DB seed data." Templates only ever see variables from
 * here, never a DB call of their own.
 */
async function assemble(orderId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }
  const products = await orderProductRepository.forOrder(orderId);
  const payment = await orderPaymentStatusRepository.find(orderId);

  const company = await companyBlock();
  const assets = await assetsBlock();

  const fobValue = await orderProductRepository.totalFobValue(orderId);
  const advancePct = parseFloat(order.advance_pct);
  const balancePct = parseFloat(order.balance_pct);
  const advanceAmount = round2(fobValue * advancePct / 100);
  const balanceAmount = round2(fobValue - advanceAmount);

  const isFob = String(order.incoterm_code).toUpperCase() === 'FOB';
  const portOfDischarge = order.port_of_discharge_name ?? order.port_of_discharge_text ?? 'TBC';

  // Cross-document references: PI cites the QT ref, OC cites the PI
  // ref. Looked up from documents already generated for this order —
  // null (not an error) until that earlier-stage document exists.
  const qtDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'QT');
  const piDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'PI');
  const ciDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CI');

  return {
    company,
    assets,
    order: {
      buyer_inquiry_ref: order.buyer_inquiry_ref,
      incoterm_code: order.incoterm_code,
      incoterm_label: `${order.incoterm_code} ${order.port_of_loading_name ?? 'Chennai, India'} — Incoterms® 2020`,
      is_fob: isFob,
      port_of_loading: order.port_of_loading_name ?? 'Chennai, India',
      port_of_discharge: portOfDischarge,
      container_type: order.container_type ?? 'TBC',
      est_lead_time_text: order.est_lead_time_text ?? 'TBC',
      est_shipment_date_text: order.est_shipment_date_text ?? 'TBC',
      production_status_text: order.production_status_text ?? 'Not yet commenced',
      coo_type: order.coo_type ?? 'TBC',
      currency_code: order.currency_code,
      buyers_po_ref: order.buyers_po_ref ?? 'NIL',
      quotation_date: formatDate(order.quotation_date),
      quotation_valid_until: formatDate(order.quotation_valid_until),
      pi_date: formatDate(order.pi_date),
      pi_valid_until: formatDate(order.pi_valid_until),
      quotation_ref: qtDoc ? qtDoc.document_reference : null,
      pi_ref: piDoc ? piDoc.document_reference : null,
      ci_ref: ciDoc ? ciDoc.document_reference : null,
      ci_date: ciDoc ? formatDate(ciDoc.generated_at ?? null) : null,
      special_requirements: order.special_requirements,
      estimated_total_cbm: order.estimated_total_cbm,
      estimated_gross_weight_kg: order.estimated_gross_weight_kg,
      estimated_net_weight_kg: order.estimated_net_weight_kg,
      estimated_package_count: order.estimated_package_count,
      estimated_package_type: order.estimated_package_type,
      indicative_freight_low: order.indicative_freight_low,
      indicative_freight_high: order.indicative_freight_high,
      indicative_insurance_amount: order.indicative_insurance_amount,
    },
    buyer: {
      company_legal_name: order.company_legal_name,
      billing_address: order.billing_address,
      consignee_name: order.consignee_name,
      consignee_address: order.consignee_address,
      vat_eori_tax_no: order.vat_eori_tax_no,
      contact_person: order.contact_person,
      email: order.client_email,
      phone: order.client_phone,
      country_of_destination: order.country_of_destination,
      notify_party: order.notify_party,
    },
    products: products.map((p) => ({
      description: p.description,
      dimensions: p.dimensions,
      finish: p.finish,
      hs_code: p.hs_code,
      quantity: p.quantity_is_tbc ? 'TBC' : formatNumber(p.quantity),
      unit: p.unit,
      unit_price: p.unit_price !== null ? formatMoney(p.unit_price) : 'TBC',
      amount: p.fob_value !== null ? formatMoney(p.fob_value) : 'TBC',
    })),
    financial: {
      fob_value: formatMoney(fobValue),
      fob_value_raw: fobValue,
      advance_pct: trimTrailingZeros(advancePct.toFixed(2)),
      advance_amount: formatMoney(advanceAmount),
      advance_trigger_text: order.advance_trigger_text,
      balance_pct: trimTrailingZeros(balancePct.toFixed(2)),
      balance_amount: formatMoney(balanceAmount),
      balance_trigger_option: order.balance_trigger_option,
      balance_days: order.balance_days,
      // freight/insurance are indicative-only, not summed into the binding total for FOB
      total_value: formatMoney(fobValue),
      preset_name: order.preset_name,
    },
    payment_status: payment
      ? {
          advance_amount: payment.advance_amount !== null ? formatMoney(payment.advance_amount) : null,
          advance_remittance_received_at: formatDate(payment.advance_remittance_received_at),
          advance_cleared_at: formatDate(payment.advance_cleared_at),
          freight_amount: payment.freight_amount !== null ? formatMoney(payment.freight_amount) : null,
          freight_remittance_received_at: formatDate(payment.freight_remittance_received_at),
          freight_cleared_at: formatDate(payment.freight_cleared_at),
          balance_amount: payment.balance_amount !== null ? formatMoney(payment.balance_amount) : formatMoney(balanceAmount),
          balance_remittance_received_at: formatDate(payment.balance_remittance_received_at),
          balance_cleared_at: formatDate(payment.balance_cleared_at),
          balance_due_date: formatDate(payment.balance_due_date),
        }
      : null,
    supplier_po: await supplierPoBlock(orderId),
    freight: await freightBlock(orderId),
    packing: await packingBlock(orderId),
    crates: await cratesBlock(orderId),
    shipping: await shippingBlock(orderId),
    production: await productionBlock(orderId),
  };
}

/**
 * Phase C blocks: Supplier PO, Freight, Packing/Crates, Shipping/BL,
 * and Production tracking. Each is simply null/empty until that stage
 * of the order has actually happened — templates render 'TBC'/'—' for
 * a null block via Nunjucks' default filter, exactly like the Phase B
 * fields do for an order that hasn't reached that stage yet.
 */
async function supplierPoBlock(orderId) {
  const row = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (!row) {
    return null;
  }
  return {
    supplier_po_reference: row.supplier_po_reference,
    supplier_legal_name: row.supplier_legal_name,
    supplier_address: row.supplier_address,
    supplier_gstin: row.supplier_gstin,
    supplier_pan: row.supplier_pan,
    supplier_contact_person: row.supplier_contact_person,
    supplier_phone: row.supplier_phone,
    supplier_type: row.supplier_type,
    material_stone_type: row.material_stone_type,
    grade: row.grade,
    surface_finish: row.surface_finish,
    dimensions: row.dimensions,
    dimensional_tolerance: row.dimensional_tolerance,
    quantity: row.quantity !== null ? formatNumber(row.quantity) : 'TBC',
    unit: row.unit,
    colour_reference: row.colour_reference || 'NIL',
    special_requirements: row.special_requirements || 'NIL',
    unit_price_inr: formatMoney(row.unit_price_inr),
    basic_value_inr: formatMoney(row.basic_value_inr),
    gst_rate_pct: row.gst_rate_pct,
    gst_amount_inr: formatMoney(row.gst_amount_inr),
    total_payable_inr: formatMoney(row.total_payable_inr),
    advance_pct: row.advance_pct,
    advance_amount_inr: formatMoney(row.advance_amount_inr),
    balance_amount_inr: formatMoney(row.balance_amount_inr),
    delivery_location: row.delivery_location,
    required_delivery_date: formatDate(row.required_delivery_date),
    packing_requirement: row.packing_requirement || 'NIL',
    status: row.status,
  };
}

async function freightBlock(orderId) {
  const row = await orderFreightRepository.find(orderId);
  if (!row) {
    return null;
  }
  const freightRaw = row.confirmed_freight_rate !== null ? parseFloat(row.confirmed_freight_rate) : null;
  const insuranceRaw = row.insurance_amount !== null ? parseFloat(row.insurance_amount) : 0.0;
  return {
    confirmed_freight_rate: freightRaw !== null ? formatMoney(freightRaw) : null,
    insurance_amount: row.insurance_amount !== null ? formatMoney(row.insurance_amount) : 'NIL',
    // FDN's "TOTAL AMOUNT DUE" needs one number to wire, not a
    // formula string — computed here (not in the template) because
    // confirmed_freight_rate/insurance_amount above are already
    // comma-formatted display strings by the time a template sees
    // them.
    total_freight_and_insurance: freightRaw !== null ? formatMoney(freightRaw + insuranceRaw) : null,
    freight_forwarder_name: row.freight_forwarder_name,
    freight_forwarder_contact: row.freight_forwarder_contact,
    gst_treatment: row.gst_treatment,
    freight_cleared_at: formatDate(row.freight_cleared_at),
  };
}

async function packingBlock(orderId) {
  const row = await orderPackingRepository.find(orderId);
  if (!row) {
    return null;
  }
  return {
    actual_quantity_packed: row.actual_quantity_packed !== null ? formatNumber(row.actual_quantity_packed) : 'TBC',
    crate_count: row.crate_count,
    total_net_weight_kg: row.total_net_weight_kg,
    total_gross_weight_kg: row.total_gross_weight_kg,
    total_cbm: row.total_cbm,
    packing_date: formatDate(row.packing_date),
    shortfall_pct: row.shortfall_pct,
  };
}

async function cratesBlock(orderId) {
  const rows = await orderCrateRepository.forOrder(orderId);
  return rows.map((c) => ({
    crate_no: c.crate_no,
    marks_numbers: c.marks_numbers,
    product_description: c.product_description,
    dimensions_lwh_cm: c.dimensions_lwh_cm,
    pcs: c.pcs,
    net_weight_kg: c.net_weight_kg,
    gross_weight_kg: c.gross_weight_kg,
    cbm: c.cbm,
    hs_code: c.hs_code,
  }));
}

async function shippingBlock(orderId) {
  const row = await orderShippingRepository.find(orderId);
  if (!row) {
    return null;
  }
  return {
    shipping_line: row.shipping_line,
    vessel_name: row.vessel_name,
    voyage_number: row.voyage_number,
    etd: formatDate(row.etd),
    eta: formatDate(row.eta),
    container_type: row.container_type,
    container_no: row.container_no,
    seal_no: row.seal_no,
    bl_number: row.bl_number,
    bl_date: formatDate(row.bl_date),
    bl_originals_received_count: row.bl_originals_received_count,
  };
}

async function productionBlock(orderId) {
  const row = await orderProductionRepository.find(orderId);
  if (!row) {
    return null;
  }
  return {
    production_start_date: formatDate(row.production_start_date),
    expected_completion_date: formatDate(row.expected_completion_date),
  };
}

async function companyBlock() {
  const keys = [
    'legal_name', 'registered_office', 'corporate_office', 'gstin', 'iec_pan',
    'md_name', 'md_title', 'phone', 'email',
    'bank_name', 'bank_branch', 'bank_account_no', 'swift_bic', 'ifsc', 'bank_address',
    'lut_number', 'lut_valid_fy',
    'rcmc_number', 'rcmc_valid_until',
    'rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight',
    'quantity_shortfall_tolerance_pct', 'non_usd_price_buffer_pct',
  ];
  const out = {};
  for (const key of keys) {
    out[key] = await companySettingsRepository.get(key);
  }
  return out;
}

/**
 * Puppeteer's page.setContent() has no filesystem/base-URL context (and we
 * deliberately never point it at the app's own authenticated preview route),
 * so images must be embedded directly as base64 data URIs, read straight
 * off disk via assetRepository's stored server_path.
 */
async function assetsBlock() {
  const out = {};
  for (const type of ['logo', 'signature', 'seal', 'watermark']) {
    const asset = await assetRepository.findActiveByType(type);
    let dataUri = null;
    if (asset && asset.server_path && fs.existsSync(asset.server_path)) {
      const buf = fs.readFileSync(asset.server_path);
      dataUri = `data:${asset.mime_type || 'image/png'};base64,${buf.toString('base64')}`;
    }
    out[`${type}_data_uri`] = dataUri;
  }
  return out;
}

function formatMoney(value) {
  if (value === null || value === undefined || value === '') {
    return 'TBC';
  }
  return Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * The one human-readable sentence for a balance_trigger_option +
 * balance_days pair — kept as a single source of truth so the QT/PI/OC
 * templates' inline if/else and any place OUTSIDE that render pipeline
 * (e.g. amendmentService's frozen snapshot text) say exactly the same
 * thing, rather than one place rendering the sentence and another leaking
 * the raw ENUM code ('A_BEFORE_SHIPMENT') into a buyer- or legally-facing
 * document.
 */
function balanceTriggerSentence(option, days) {
  const d = days ?? 0;
  if (option === 'A_BEFORE_SHIPMENT') {
    return `Payable before shipment — within ${d} working days of receiving shipment readiness confirmation from NexaCrest.`;
  }
  return `Payable against scanned copy of Bill of Lading, within ${d} days of BL date.`;
}

function formatNumber(value) {
  if (value === null || value === undefined || value === '') {
    return 'TBC';
  }
  const float = Number(value);
  return trimTrailingZeros(float.toFixed(3));
}

function formatDate(value) {
  if (!value) {
    return null;
  }
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) {
    return String(value);
  }
  const months = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];
  return `${d.getUTCDate()} ${months[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}

function round2(n) {
  return Math.round((n + Number.EPSILON) * 100) / 100;
}

/** number_format-then-rtrim-zeros-then-rtrim-dot, e.g. "10.00" -> "10", "12.50" -> "12.5". */
function trimTrailingZeros(numStr) {
  if (!numStr.includes('.')) {
    return numStr;
  }
  return numStr.replace(/0+$/, '').replace(/\.$/, '');
}

module.exports = {
  assemble,
  companyBlock,
  assetsBlock,
  formatMoney,
  formatNumber,
  formatDate,
  balanceTriggerSentence,
};
