'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const nunjucks = require('nunjucks');
const env = require('../config/env');
const orderStorage = require('../helpers/orderStorage');
const { formatIndian } = require('../helpers/amountInWords');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const repo = require('../repositories/domesticOrderRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderRepository = require('../repositories/orderRepository');
const D = require('./domesticOrderService');
const G = require('./gstCalculator');
const { renderPdfFromHtml } = require('./pdfRenderService');

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the documents of a Domestic
 * Trade (DOM) order — new documents, for DOM orders only; the export
 * documents are not touched:
 *
 *   DQT quotation · DOC order confirmation · GTI GST tax invoice · DCH delivery challan
 *
 * One template (templates/DOM/domestic_document.njk) prints all four. Every
 * figure is worked out here (gstCalculator) so the template only prints.
 * Port of PHP App\Services\DomesticDocumentService.
 */

const TITLES = { DQT: 'QUOTATION', DOC: 'ORDER CONFIRMATION', GTI: 'TAX INVOICE', DCH: 'DELIVERY CHALLAN' };
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

let njk = null;
function environment() {
  if (!njk) njk = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '..', '..', 'templates'), { noCache: true }), { autoescape: true });
  return njk;
}

/** "10.500" => "10.5", "18.00" => "18", "10" => "10". */
function trimDecimals(v) {
  const s = String(v);
  return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
}

/** An address as its non-empty lines. */
function lines(text) {
  return String(text || '').split(/\r\n|\r|\n/).map((l) => l.trim()).filter((l) => l !== '');
}

/** 'YYYY-MM-DD' => '05 Nov 2026' (PHP 'd M Y'). */
function displayDate(d) {
  if (!d) return '';
  const [y, m, day] = String(d).slice(0, 10).split('-');
  return `${day} ${MONTHS[parseInt(m, 10) - 1]} ${y}`;
}

async function context(orderId, code, reference) {
  const order = await orderRepository.find(orderId);
  const dom = await repo.find(orderId);
  if (!order || !dom || !D.DOCUMENTS[code]) throw new Error('Not a domestic order or document.');
  const t = await D.totals(orderId);
  const company = require('./orderSnapshotService').companyReader(dom.company_snapshot_json); // D-262: frozen at dispatch
  const state = async (c) => (c ? `${await repo.stateName(c)} (${c})` : '');
  const isInvoice = code === 'GTI';
  return {
    code,
    title: TITLES[code],
    reference,
    document_date: displayDate(isInvoice ? dom.tax_invoice_date : todayIst()),
    order_reference: String(order.order_reference),
    buyer_po_ref: String(dom.buyer_po_ref || ''),
    company: {
      name: await company('legal_name'),
      address_lines: lines(await company('registered_office')),
      gstin: await company('gstin'),
      pan: await company('iec_pan'),
      phone: await company('phone'),
      email: await company('email'),
      state: await state(dom.seller_state_code),
      bank_name: await company('bank_name'),
      bank_branch: await company('bank_branch'),
      bank_account_no: await company('bank_account_no'),
      ifsc: await company('ifsc'),
    },
    buyer: {
      name: String(order.company_legal_name || ''),
      address_lines: lines(order.billing_address),
      gstin: String(dom.buyer_gstin || ''),
      state: await state(dom.buyer_state_code),
    },
    delivery_address_lines: lines(dom.delivery_address || order.billing_address),
    place_of_supply: await state(dom.place_of_supply),
    intra_state: t.intra_state,
    lines: t.lines.map((l, i) => ({
      no: i + 1,
      description: String(l.description),
      hsn_code: String(l.hsn_code),
      quantity: trimDecimals(l.quantity),
      unit: String(l.unit),
      rate: G.inr(l.rate_inr),
      gst_rate: `${trimDecimals(l.gst_rate_pct)}%`,
      taxable: G.inr(l.taxable),
      cgst: G.inr(l.cgst),
      sgst: G.inr(l.sgst),
      igst: G.inr(l.igst),
      line_total: G.inr(l.line_total),
    })),
    hsn_summary: t.hsn_summary.map((h) => ({
      hsn_code: h.hsn_code,
      gst_rate: `${trimDecimals(h.gst_rate_pct)}%`,
      taxable: G.inr(h.taxable),
      cgst: G.inr(h.cgst),
      sgst: G.inr(h.sgst),
      igst: G.inr(h.igst),
      tax_total: G.inr(h.tax_total),
    })),
    taxable_total: G.inr(t.taxable_total),
    cgst_total: G.inr(t.cgst_total),
    sgst_total: G.inr(t.sgst_total),
    igst_total: G.inr(t.igst_total),
    tax_total: G.inr(t.tax_total),
    round_off: G.inr(t.round_off),
    invoice_total: G.inr(t.invoice_total),
    amount_in_words: formatIndian(t.invoice_total),
    payment_terms_days: Number(dom.payment_terms_days),
    validity_days: 15,
    show_transport: ['GTI', 'DCH'].includes(code),
    tax_invoice_no: String(dom.tax_invoice_no || ''),
    tax_invoice_date: displayDate(dom.tax_invoice_date),
    dispatch_date: displayDate(dom.dispatch_date),
    transporter_name: String(dom.transporter_name || ''),
    transporter_id: String(dom.transporter_id || ''),
    vehicle_no: String(dom.vehicle_no || ''),
    lr_no: String(dom.lr_no || ''),
    eway_bill_no: String(dom.eway_bill_no || ''),
    eway_bill_date: displayDate(dom.eway_bill_date),
    e_invoice_note: isInvoice && (await D.setting('dom_e_invoice_applicable')) === '1',
  };
}

async function html(orderId, code, reference) {
  return environment().render('DOM/domestic_document.njk', await context(orderId, code, reference));
}

/** Generates a DOM document as a PDF and lists it on the order. @returns {Promise<[number|null, string|null]>} [file id, error] */
async function generate(orderId, code, userId) {
  const order = await orderRepository.find(orderId);
  const dom = await repo.find(orderId);
  let error = null;
  if (!order || !dom) error = 'Order not found.';
  else if (!D.DOCUMENTS[code]) error = 'Unknown document.';
  else if (!D.documentAllowed(dom, code)) error = `The ${D.DOCUMENTS[code].toLowerCase()} is generated from the ${D.STAGES[D.DOCUMENT_FROM[code]]} stage on.`;
  else if ((await repo.lines(orderId)).length === 0) error = 'Add at least one line first.';
  if (error !== null) return [null, error];
  const reference = await D.documentReference(orderId, code);
  const pdf = await renderPdfFromHtml(await html(orderId, code, reference), reference);
  const dir = path.join((env.storageBasePath() || '').replace(/\/+$/, ''), orderStorage.forGeneratedDocument(order, code));
  fs.mkdirSync(dir, { recursive: true });
  const uuid = `${crypto.randomBytes(16).toString('hex')}.pdf`;
  fs.writeFileSync(path.join(dir, uuid), pdf);
  const fileId = await fileStoreRepository.insertGenerated(null, orderId, null, path.join(dir, uuid), uuid, `${code} ${reference.replace(/\//g, '-')}.pdf`, pdf.length, 'application/pdf', userId, false);
  await repo.addDocument(orderId, code, reference, fileId, userId);
  await auditLogRepository.log(userId, 'DOMESTIC_DOCUMENT_GENERATED', 'orders', orderId, code, null, reference);
  return [fileId, null];
}

module.exports = { context, html, generate, trimDecimals, lines };
