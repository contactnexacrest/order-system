'use strict';

/**
 * Port of App\Helpers\OrderStorage. Where an order's files live on disk
 * (owner request 2026-10-26: "make the UI and the folder structure the
 * same"). One folder per order-page step, named like the step itself, so
 * the folder a file sits in is the step it belongs to:
 *
 *   clients/<client no>/<order ref>/
 *     01-quotation/            generated QT/Annexure A; annexure-images/
 *     04-buyer-po/             generated Buyer PO; received/ = the buyer's signed copies
 *     05-proforma-invoice/     generated PI; lc/ = LC copies
 *     06-order-confirmation/   generated OC
 *     07-supplier-po/          generated Supplier PO; received/ = the supplier's acknowledgment
 *     09-freight/              generated Freight Debit Note
 *     10-packing-and-bl/       generated PL/BLI; received/ = draft BLs, scanned BL, packing approvals
 *     11-commercial-invoice/   generated CI
 *     12-financials/           generated CA financial annexure (internal)
 *     13-closure/              generated COO prep sheet, BL endorsement
 *     payments/                client-reported payment proofs
 *     amendments/<ref>/        generated/ and received/ (signed copy)
 *     disputes/<id>/, order-updates/, additional-documents/, checklists/, reference/
 *
 * A generated document goes by its own document type — never by the stage
 * the order happened to be at when it was generated. The leaf filenames
 * stay random UUIDs (spec Section 14 — never guessable); only the folders
 * are human-organised. If the step numbering ever changes, change AREAS and
 * run src/jobs/reorganizeOrderFiles.js to move existing files.
 */

const AREAS = {
  quotation: '01-quotation',
  annexure: '01-quotation/annexure-images',
  buyer_po: '04-buyer-po',
  proforma_invoice: '05-proforma-invoice',
  lc: '05-proforma-invoice/lc',
  order_confirmation: '06-order-confirmation',
  supplier_po: '07-supplier-po',
  freight: '09-freight',
  packing_bl: '10-packing-and-bl',
  commercial_invoice: '11-commercial-invoice',
  financials: '12-financials',
  closure: '13-closure',
  payments: 'payments',
  amendments: 'amendments',
  disputes: 'disputes',
  order_updates: 'order-updates',
  additional_documents: 'additional-documents',
  checklists: 'checklists',
  reference: 'reference',
  other: 'other',
};

const DOCUMENT_TYPE_AREAS = {
  QT: 'quotation', ANNEXA: 'quotation',
  BUYERPO: 'buyer_po',
  PI: 'proforma_invoice',
  OC: 'order_confirmation',
  SUPPO: 'supplier_po',
  FDN: 'freight',
  PL: 'packing_bl', BLI: 'packing_bl',
  CI: 'commercial_invoice',
  CAFIN: 'financials',
  COOPREP: 'closure', BLE: 'closure',
  AMD: 'amendments',
  CHECKLIST: 'checklists', CHECKLIST_2_FINANCE: 'checklists', CHECKLIST_3_PACKING: 'checklists', CHECKLIST_4_SHIPPING: 'checklists',
  SOP_A_SALES: 'reference', SOP_B_SALES: 'reference', STAGEGATE: 'reference', WALLREF: 'reference',
};

function segment(value) {
  return String(value ?? '').replace(/[^A-Za-z0-9_-]+/g, '-') || 'x';
}

/** @param {{client_unique_number: string, order_reference: string}} order */
function orderRoot(order) {
  return `clients/${segment(order.client_unique_number)}/${segment(order.order_reference)}`;
}

/** Relative to STORAGE_BASE_PATH, e.g. clients/NC-1/SC-ORD-1/10-packing-and-bl/received. */
function folder(order, area, sub = null) {
  const base = `${orderRoot(order)}/${AREAS[area] || AREAS.other}`;
  return sub ? `${base}/${sub}` : base;
}

/** The folder a generated document of this type is written to. */
function forGeneratedDocument(order, documentTypeCode) {
  return folder(order, DOCUMENT_TYPE_AREAS[documentTypeCode] || 'other', 'generated');
}

/** The folder files received from outside (buyer, supplier, CHA) for this step go to. */
function forReceived(order, area) {
  return folder(order, area, 'received');
}

module.exports = { AREAS, DOCUMENT_TYPE_AREAS, segment, orderRoot, folder, forGeneratedDocument, forReceived };
