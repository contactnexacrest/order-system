'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const repo = require('../repositories/importRepository');
const { renderPdfFromHtml } = require('./pdfRenderService');
const { trimDecimals, lines: addressLines } = require('./domesticDocumentService');
const G = require('./gstCalculator');
const I = require('./importService');

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the import purchase order (IPO)
 * to a foreign supplier, in the supplier's currency — a new document for
 * Import & Distribution only; the export documents are not touched. It is
 * downloaded from the import's page (not stored with an order's files: an
 * import is not a sales order). Port of PHP App\Services\ImportDocumentService.
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

let njk = null;
function environment() {
  if (!njk) njk = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '..', '..', 'templates'), { noCache: true }), { autoescape: true });
  return njk;
}

/** 'YYYY-MM-DD' => '05 Nov 2026' (PHP 'd M Y'). */
function dmy(d) {
  const [y, m, day] = String(d).slice(0, 10).split('-');
  return `${day} ${MONTHS[parseInt(m, 10) - 1]} ${y}`;
}

/** Everything the template prints. */
async function context(shipmentId) {
  const s = await repo.find(shipmentId);
  if (!s) throw new Error('Import not found.');
  const company = async (k) => String((await companySettingsRepository.companyValue(k)) || '');
  let total = 0n;
  const items = (await repo.lines(shipmentId)).map((l, i) => {
    const cents = I.lineValueCents(String(l.quantity), String(l.unit_price_fc));
    total += cents;
    return {
      no: i + 1,
      description: String(l.description),
      hsn_code: String(l.hsn_code),
      quantity: trimDecimals(String(l.quantity)),
      unit: String(l.unit),
      unit_price: I.fc(G.scaled(String(l.unit_price_fc), 2)),
      amount: I.fc(cents),
    };
  });
  return {
    reference: String(s.reference),
    po_date: dmy(s.po_date),
    currency: String(s.currency_code),
    incoterm: String(s.incoterm || ''),
    payment_terms: String(s.payment_terms || ''),
    lc_no: String(s.lc_no || ''),
    lc_bank: String(s.lc_bank || ''),
    supplier: { name: String(s.supplier_name), address_lines: addressLines(s.supplier_address || ''), country: String(s.supplier_country) },
    company: {
      name: await company('legal_name'),
      address_lines: addressLines(await company('registered_office')),
      gstin: await company('gstin'),
      iec: await company('iec_pan'),
      phone: await company('phone'),
      email: await company('email'),
    },
    lines: items,
    total: I.fc(total),
  };
}

async function html(shipmentId) {
  return environment().render('IMP/import_po.njk', await context(shipmentId));
}

async function pdf(shipmentId) {
  const s = await repo.find(shipmentId);
  return renderPdfFromHtml(await html(shipmentId), s ? String(s.reference) : null);
}

module.exports = { context, html, pdf };
