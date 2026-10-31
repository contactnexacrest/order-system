'use strict';

const crypto = require('crypto');
const documentRepository = require('../repositories/documentRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderShippingRepository = require('../repositories/orderShippingRepository');

/**
 * Zoho integration Stage 2 (2026-10-29) — what an order's Commercial
 * Invoice or freight Debit Note says, as the export invoice that goes to
 * Zoho Books. Reads the same data the documents are built from; it never
 * changes a document. Same rules as the PHP stack (ZohoInvoiceData.php):
 *
 * - CI: one line per active order product, at its unit price, so the
 *   total is the FOB value. Freight and insurance are NOT on it — on
 *   CFR/CIF orders they are billed on the Debit Note, its own invoice, so
 *   Books does not count them twice.
 * - Debit Note: ocean freight, plus insurance on CIF. Its lines carry the
 *   goods' HS code (the first product's) — owner decision 2026-10-29 (CA
 *   advice): freight billed with export goods is part of the zero-rated
 *   export, so it goes under the goods' code.
 * - Number and date: the document's own reference and printed date.
 * - Exchange rate (INR per unit of the order currency): the customs
 *   exchange rate on the shipping bill (owner decision 2026-10-29, CA
 *   advice), so the GST return matches the shipping bill. Until it is
 *   recorded on the order the invoice is not sent.
 *
 * Every "not ready" case throws an Error with a message staff can act on;
 * caSyncService logs it and retries on the next run.
 */

const KIND_LABELS = { ci: 'Commercial Invoice', dn: 'Debit Note' };

function round2(value) {
  return Math.round(value * 100) / 100;
}

async function build(orderId, kind, documentId) {
  const order = await orderRepository.find(orderId);
  const document = await documentRepository.find(documentId);
  if (!order || !document) {
    throw new Error(`Order #${orderId} or its document #${documentId} no longer exists.`);
  }
  const label = KIND_LABELS[kind];
  if (document.drift_flagged_at) {
    throw new Error(`${label} not sent: ${document.document_reference} is flagged on the order page as possibly out of date (an earlier document was regenerated after it). Generate and approve a revised ${label} first.`);
  }
  const currency = String(order.currency_code || 'INR');
  const payment = (await orderPaymentStatusRepository.find(orderId)) || {};

  const lines = kind === 'ci' ? await ciLines(orderId, label) : await debitNoteLines(orderId, order, label);
  const total = lines.reduce((sum, line) => sum + round2(line.quantity * line.rate), 0);

  let rate = 1;
  if (currency !== 'INR') {
    const shipping = (await orderShippingRepository.find(orderId)) || {};
    if (!(Number(shipping.customs_exchange_rate) > 0)) {
      throw new Error(`${label} not sent: record the shipping bill's customs exchange rate on the order (Shipping Bill, on the order page) first.`);
    }
    rate = Number(shipping.customs_exchange_rate);
  }

  const date = String(documentRepository.printedDate(document)).slice(0, 10);
  let dueDate = null;
  if (kind === 'ci' && payment.balance_due_date && String(payment.balance_due_date) >= date) {
    dueDate = String(payment.balance_due_date);
  }

  return {
    order_id: orderId,
    kind,
    document_id: documentId,
    revision: Number(document.revision_number),
    client_id: Number(order.client_id),
    company_legal_name: String(order.company_legal_name),
    currency_code: currency,
    invoice_number: String(document.document_reference),
    date,
    due_date: dueDate,
    reference_number: String(order.buyer_inquiry_ref),
    summary: summary(kind, order),
    exchange_rate: rate,
    total: round2(total),
    lines,
  };
}

/**
 * Owner rule D-212: "Commercial Invoice for order NX-12 · CIF · buyer PO
 * PO-778 · Acme GmbH" — kept as an internal comment on the Books invoice.
 * Port of ZohoInvoiceData::summary().
 */
function summary(kind, order) {
  const parts = [`${kind === 'dn' ? 'Freight Debit Note' : 'Commercial Invoice'} for order ${order.buyer_inquiry_ref || ''}`];
  if (order.incoterm_code) parts.push(String(order.incoterm_code));
  if (order.buyers_po_ref) parts.push(`buyer PO ${order.buyers_po_ref}`);
  parts.push(String(order.company_legal_name || ''));
  return parts.filter((p) => p !== '').join(' · ');
}

/** A fingerprint of everything sent (not which revision it came from): when it changes, the Books invoice is updated. */
function fingerprint(invoice) {
  // The summary is a comment added once, not part of the invoice (so
  // invoices already in Books are not re-sent because of it).
  const { document_id: _documentId, revision: _revision, summary: _summary, ...rest } = invoice;
  return crypto.createHash('sha256').update(JSON.stringify(rest)).digest('hex');
}

async function ciLines(orderId, label) {
  const lines = [];
  for (const product of await orderProductRepository.forOrder(orderId)) {
    if (Number(product.quantity_is_tbc) === 1 || product.quantity === null || product.unit_price === null) {
      throw new Error(`${label} not sent: line ${product.line_no} has no confirmed quantity or unit price.`);
    }
    const quantity = Number(product.quantity);
    const rate = Number(product.unit_price);
    if (Math.abs(round2(quantity * rate) - Number(product.fob_value)) > 0.01) {
      throw new Error(`${label} not sent: line ${product.line_no} quantity x unit price (${(quantity * rate).toFixed(2)}) does not equal its value (${product.fob_value}).`);
    }
    const description = String(product.description || '').trim();
    const details = [product.dimensions ? `Size: ${product.dimensions}` : null, product.finish ? `Finish: ${product.finish}` : null].filter(Boolean);
    lines.push({
      name: (description !== '' ? description : `Line ${product.line_no}`).slice(0, 100),
      description: [description, ...details].join('. '),
      hs_code: product.hs_code || null,
      quantity,
      unit: product.unit || null,
      rate,
    });
  }
  if (lines.length === 0) {
    throw new Error(`${label} not sent: the order has no products.`);
  }
  return lines;
}

async function debitNoteLines(orderId, order, label) {
  const freight = await orderFreightRepository.find(orderId);
  if (!freight || freight.confirmed_freight_rate === null) {
    throw new Error(`${label} not sent: no confirmed freight rate on the order.`);
  }
  const from = String(order.port_of_loading_name || order.port_of_loading_city || '');
  const to = String(order.port_of_discharge_name || order.port_of_discharge_city || '');
  const route = from !== '' && to !== '' ? `${from} to ${to}` : '';
  const incoterm = String(order.incoterm_code || '').toUpperCase();
  const goods = (await orderProductRepository.forOrder(orderId)).find((p) => Number(p.is_active ?? 1) === 1 && p.hs_code);
  const hsCode = goods ? String(goods.hs_code) : null;
  const lines = [{
    name: 'Ocean freight',
    description: `Ocean freight${route !== '' ? `, ${route}` : ''} (${incoterm})`,
    hs_code: hsCode,
    quantity: 1,
    unit: null,
    rate: Number(freight.confirmed_freight_rate),
  }];
  if (incoterm === 'CIF' && Number(freight.insurance_amount || 0) > 0) {
    lines.push({ name: 'Marine insurance', description: 'Marine insurance (CIF)', hs_code: hsCode, quantity: 1, unit: null, rate: Number(freight.insurance_amount) });
  }
  return lines;
}

module.exports = { KIND_LABELS, build, fingerprint, summary };
