'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const amountInWords = require('../helpers/amountInWords');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const repo = require('../repositories/merchantTradeRepository');
const { renderPdfFromHtml } = require('./pdfRenderService');
const { trimDecimals, lines: addressLines } = require('./domesticDocumentService');
const { todayIst } = require('../helpers/dbDateTime');
const G = require('./gstCalculator');
const I = require('./importService');

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the two documents of a Merchant
 * Trade deal — the sale invoice to the buyer (in the sale currency) and the
 * purchase order to the supplier (in the purchase currency). New documents
 * for Merchant Trade only, from one template; the export documents are not
 * touched. Downloaded from the deal's page. Port of PHP
 * App\Services\MerchantDocumentService.
 */

const KINDS = { invoice: 'Sale invoice', po: 'Purchase order' };
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

let njk = null;
function environment() {
  if (!njk) njk = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '..', '..', 'templates'), { noCache: true }), { autoescape: true });
  return njk;
}

function dmy(d) {
  const [y, m, day] = String(d).slice(0, 10).split('-');
  return `${day} ${MONTHS[parseInt(m, 10) - 1]} ${y}`;
}

/** Everything the template prints. */
async function context(dealId, kind) {
  const d = await repo.find(dealId);
  if (!d || !KINDS[kind]) throw new Error('Deal not found.');
  const company = async (k) => String((await companySettingsRepository.companyValue(k)) || '');
  const invoice = kind === 'invoice';
  const currency = String(invoice ? d.sale_currency : d.purchase_currency);
  let total = 0n;
  const items = (await repo.lines(dealId)).map((l, i) => {
    const price = String(invoice ? l.sell_price_fc : l.buy_price_fc);
    const cents = I.lineValueCents(String(l.quantity), price);
    total += cents;
    return {
      no: i + 1, description: String(l.description), hsn_code: String(l.hsn_code), quantity: trimDecimals(String(l.quantity)), unit: String(l.unit),
      unit_price: I.fc(G.scaled(price, 2)), amount: I.fc(cents),
    };
  });
  const party = invoice
    ? { label: 'BUYER', name: String(d.buyer_name), address_lines: addressLines(d.buyer_address || ''), country: String(d.buyer_country) }
    : { label: 'SUPPLIER', name: String(d.supplier_name), address_lines: addressLines(d.supplier_address || ''), country: String(d.supplier_country) };
  return {
    kind,
    title: invoice ? 'INVOICE' : 'PURCHASE ORDER',
    number: String(invoice ? d.sale_invoice_no : d.po_no),
    deal_reference: String(d.reference),
    date: dmy(invoice ? (d.invoice_date || todayIst()) : d.deal_date),
    currency,
    incoterm: String((invoice ? d.sale_incoterm : d.purchase_incoterm) || ''),
    origin_country: String(d.origin_country),
    port_of_loading: String(d.port_of_loading || ''),
    port_of_discharge: String(d.port_of_discharge || ''),
    bl_no: String((Number(d.switch_bl) ? d.switch_bl_no : d.bl_no) || ''),
    bl_date: d.bl_date ? dmy(d.bl_date) : '',
    vessel: String(d.vessel || ''),
    party,
    company: {
      name: await company('legal_name'),
      address_lines: addressLines(await company('registered_office')),
      iec: await company('iec_pan'),
      phone: await company('phone'),
      email: await company('email'),
    },
    lines: items,
    total: I.fc(total),
    total_in_words: amountInWords.format(G.money(total), currency),
  };
}

async function html(dealId, kind) {
  return environment().render('MTT/merchant_document.njk', await context(dealId, kind));
}

async function pdf(dealId, kind) {
  const c = await context(dealId, kind);
  return renderPdfFromHtml(await html(dealId, kind), c.number);
}

module.exports = { KINDS, context, html, pdf };
