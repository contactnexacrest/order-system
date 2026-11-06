'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const repo = require('../repositories/domesticOrderRepository');
const G = require('./gstCalculator');

const divisionService = () => require('./divisionService');
const orderRepository = () => require('../repositories/orderRepository');

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the Domestic Trade (DOM)
 * division — selling to Indian resellers with GST, in INR.
 *
 * A DOM order is an ordinary orders row (division DOM, currency INR, so the
 * division rules, access checks, lists and reports all apply) plus its own
 * domestic record and stages:
 *
 *   enquiry → quotation → order confirmation → purchase → dispatch →
 *   tax invoice → payment → closed
 *
 * No BL, LUT, FIRC or export benefits (those modules are off for DOM). The
 * tax invoice number is fixed when the goods move to dispatch — GST law wants
 * the invoice issued at the removal of the goods, and the e-way bill needs its
 * number — and the lines and totals are frozen from then on.
 *
 * DOM's own rules are its division settings (Admin › Divisions › Domestic
 * Trade): the default GST rate, the e-way bill threshold, e-invoicing and the
 * payment terms — standard Indian rules as starting values, to be confirmed by
 * the CA. GST rates per HSN / category are in Admin › GST rates.
 *
 * D-257 Phase 3: the Import & Distribution (IMP) division sells its stock to
 * Indian resellers through the same order: an IMP local sale is this order in
 * the IMP division, with IMP's own numbers (IMP/26-27/0001), the same GST
 * rules, and its goods issued from stock (importService) when the tax invoice
 * is fixed, instead of a supplier PO.
 * Port of PHP App\Services\DomesticOrderService.
 */

const CODE = 'DOM';

/** D-257 Phase 3: the divisions that sell in India with this order (Domestic Trade, and Import & Distribution from stock). */
const LOCAL_CODES = ['DOM', 'IMP'];

const STAGES = {
  enquiry: 'Enquiry',
  quotation: 'Quotation',
  order_confirmation: 'Order confirmation',
  purchase: 'Purchase',
  dispatch: 'Dispatch',
  tax_invoice: 'Tax invoice',
  payment: 'Payment',
  closed: 'Closed',
};
const STAGE_KEYS = Object.keys(STAGES);

/** Starting values of DOM's own settings, used when a setting is missing (to be confirmed by the CA). */
const DEFAULTS = {
  dom_default_gst_rate: '18',
  dom_eway_threshold_inr: '50000',
  dom_e_invoice_applicable: '0',
  dom_payment_terms_days: '30',
};

const DOCUMENTS = { DQT: 'Quotation', DOC: 'Order confirmation', GTI: 'GST tax invoice', DCH: 'Delivery challan' };
const DOCUMENT_FROM = { DQT: 'quotation', DOC: 'order_confirmation', GTI: 'dispatch', DCH: 'dispatch' };
const LINES_OPEN = ['enquiry', 'quotation', 'order_confirmation', 'purchase'];
const GST_RATES = ['0.00', '0.25', '3.00', '5.00', '12.00', '18.00', '28.00', '40.00'];

const len = (s) => [...String(s)].length;
const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s) && !Number.isNaN(Date.parse(s));

async function divisionId(code = CODE) {
  const d = (await divisionService().all()).find((x) => x.code === code);
  return d ? Number(d.id) : null;
}

async function isActive(code = CODE) {
  const id = await divisionId(code);
  const d = id !== null ? await divisionService().find(id) : null;
  return !!d && d.status === 'active';
}

async function isDomesticOrder(order) {
  for (const code of LOCAL_CODES) {
    const id = await divisionId(code);
    if (id !== null && Number(order && order.division_id) === id) return true;
  }
  return false;
}

/** The division code of a local-sale order: IMP for Import & Distribution, else DOM. */
async function codeOfDivision(divId) {
  const imp = await divisionId('IMP');
  return imp !== null && divId !== null && divId !== undefined && Number(divId) === imp ? 'IMP' : CODE;
}

/** The division code of a local-sale order (by its orders row). */
async function codeOfOrder(orderId) {
  const order = await orderRepository().find(orderId);
  return codeOfDivision(order ? Number(order.division_id) : null);
}

/** The counter series of a document in a division: DOM keeps its own (GTI, QT…); IMP's are IMP-GTI, IMP-QT… */
function series(code, s) {
  return code === CODE ? s : `${code}-${s}`;
}

async function setting(key) {
  const id = await divisionId();
  const v = id !== null ? await repo.divisionSetting(id, key) : null;
  return v !== null && v !== '' ? v : (DEFAULTS[key] || '');
}

/** NexaCrest's GST state: the first two digits of its GSTIN (Settings). */
async function sellerStateCode() {
  const gstin = String((await companySettingsRepository.companyValue('gstin')) || '').trim().toUpperCase();
  return /^[0-9]{2}/.test(gstin) ? gstin.slice(0, 2) : '';
}

function stageIndex(stage) {
  const i = STAGE_KEYS.indexOf(stage);
  return i < 0 ? 0 : i;
}

function atOrAfter(stage, than) {
  return stageIndex(stage) >= stageIndex(than);
}

/** The GST rate of a line: the longest HSN prefix that matches, else its category's, else DOM's default. */
async function gstRateFor(hsn, categoryId = null) {
  let best = null;
  let bestLen = -1;
  let byCategory = null;
  for (const r of await repo.gstRates(true)) {
    const prefix = String(r.hsn_prefix || '');
    if (prefix !== '' && hsn.startsWith(prefix) && prefix.length > bestLen) {
      best = String(r.rate_pct);
      bestLen = prefix.length;
    }
    if (prefix === '' && categoryId !== null && Number(r.category_id || 0) === Number(categoryId)) byCategory = String(r.rate_pct);
  }
  return G.money(G.scaled(best ?? byCategory ?? (await setting('dom_default_gst_rate')), 2));
}

// ------------------------------------------------------------ create

/** The buyer's GST details from a form, checked. @returns {Promise<[object, string|null]>} */
async function partyFields(input) {
  const gstin = String(input.buyer_gstin || '').trim().toUpperCase();
  const state = String(input.buyer_state_code || '').trim();
  const pos = String(input.place_of_supply || '').trim();
  const days = String(input.payment_terms_days === undefined || input.payment_terms_days === null ? '' : input.payment_terms_days).trim();
  const fields = {
    buyer_gstin: gstin !== '' ? gstin : null,
    buyer_state_code: state !== '' ? state : null,
    place_of_supply: pos,
    delivery_address: String(input.delivery_address || '').trim() || null,
    payment_terms_days: days === '' ? parseInt(await setting('dom_payment_terms_days'), 10) : parseInt(days, 10),
    buyer_po_ref: [...String(input.buyer_po_ref || '').trim()].slice(0, 80).join('') || null,
  };
  let error = null;
  if (gstin !== '' && !G.isValidGstin(gstin)) error = "The buyer's GSTIN is not valid (15 characters: state code, PAN, entity number, Z and a check character).";
  else if (state === '' || !(await repo.isState(state))) error = "Choose the buyer's state.";
  else if (gstin !== '' && gstin.slice(0, 2) !== state) error = `The buyer's GSTIN starts with state code ${gstin.slice(0, 2)}, which does not match the state chosen.`;
  else if (pos === '' || !(await repo.isState(pos))) error = 'Choose the place of supply (the state the goods are delivered to).';
  else if (days !== '' && (!/^\d{1,3}$/.test(days) || parseInt(days, 10) > 365)) error = 'Payment terms are a number of days, 0 to 365.';
  else if (len(fields.delivery_address || '') > 1000) error = 'The delivery address is too long.';
  return [fields, error];
}

/** Creates a DOM order. @returns {Promise<[number|null, string|null]>} */
async function create(input, userId, code = CODE) {
  const divCode = LOCAL_CODES.includes(code) ? code : CODE;
  const domId = await divisionId(divCode);
  if (domId === null || !(await isActive(divCode))) return [null, `${divCode === 'IMP' ? 'Import & Distribution' : 'Domestic Trade'} is not active yet. The MD activates it in Admin › Divisions.`];
  const client = await repo.client(parseInt(input.client_id || 0, 10));
  if (!client || Number(client.is_active) !== 1 || (!(await divisionService().allowsClient(client.id)) && !divisionService().seesAll())) {
    return [null, 'Please select a valid client.'];
  }
  const [fields, error] = await partyFields(input);
  if (error !== null) return [null, error];
  const seller = await sellerStateCode();
  if (seller === '') return [null, "NexaCrest's GSTIN is missing in Settings, so the GST state is unknown."];
  const currency = await db.queryOne("SELECT id FROM currencies WHERE code = 'INR'");
  if (!currency) return [null, 'The INR currency is missing.'];
  const incoterm = await db.queryOne('SELECT id FROM incoterms ORDER BY is_default DESC, id LIMIT 1');
  const preset = await db.queryOne('SELECT id FROM payment_presets ORDER BY is_default DESC, id LIMIT 1');
  const today = todayIst();
  const validUntil = (() => {
    const d = new Date(`${today}T00:00:00`);
    d.setDate(d.getDate() + 15);
    return require('../helpers/dbDateTime').ymd(d);
  })();
  const testMode = await require('./testModeService').isEnabled();

  const orderId = await divisionService().withDivision(domId, async () => {
    const reference = await require('./referenceNumberService').generateOrderReference();
    return db.transaction(async (trx) => {
      const result = await orderRepository().createWithNextSequence(Number(client.id), () => ({
        order_reference: reference,
        buyer_inquiry_ref: client.client_unique_number,
        payment_preset_id: Number(preset.id),
        incoterm_id: Number(incoterm.id),
        port_of_loading_id: null,
        port_of_discharge_id: null,
        port_of_discharge_text: null,
        currency_id: Number(currency.id),
        coo_type: 'Not applicable',
        include_annexure_a: false,
        estimated_total_cbm: '',
        estimated_gross_weight_kg: '',
        estimated_net_weight_kg: '',
        indicative_freight_amount: '',
        indicative_insurance_amount: '',
        buyers_po_ref: fields.buyer_po_ref || 'NIL',
        quotation_date: today,
        quotation_valid_until: validUntil,
        division_id: domId,
      }), userId, trx);
      const id = Number(result.orderId);
      // A domestic sale has no Incoterm, port or export payment preset: those ids are kept for the schema only.
      await trx.execute('UPDATE orders SET incoterm_code = NULL, country_of_destination = :c WHERE id = :id', { c: 'India', id });
      if (testMode) await orderRepository().markTest(id, trx);
      await repo.insert(id, { ...fields, seller_state_code: seller }, userId, trx);
      await repo.fillClientGst(client.id, fields.buyer_gstin, fields.buyer_state_code, fields.place_of_supply, trx);
      return id;
    });
  });
  await auditLogRepository.log(userId, 'DOMESTIC_ORDER_CREATED', 'orders', orderId, 'division', null, divCode);
  return [orderId, null];
}

/** Changes the buyer's GST details while the order is still before its tax invoice. */
async function saveParty(orderId, input, userId) {
  const dom = await repo.find(orderId);
  if (!dom) return 'Order not found.';
  if (!LINES_OPEN.includes(dom.stage)) return "The tax invoice number is fixed, so the buyer's GST details can no longer change.";
  const [fields, error] = await partyFields(input);
  if (error !== null) return error;
  await repo.update(orderId, fields, userId);
  await auditLogRepository.log(userId, 'DOMESTIC_PARTY_SAVED', 'orders', orderId, 'place_of_supply', dom.place_of_supply, fields.place_of_supply);
  return null;
}

// ------------------------------------------------------------ lines

async function addLine(orderId, input, userId) {
  const dom = await repo.find(orderId);
  if (!dom) return 'Order not found.';
  if (!LINES_OPEN.includes(dom.stage)) return 'The tax invoice number is fixed, so the lines can no longer change.';
  const description = String(input.description || '').trim();
  const hsn = String(input.hsn_code || '').replace(/\D/g, '');
  const qty = String(input.quantity === undefined || input.quantity === null ? '' : input.quantity).trim();
  const unit = String(input.unit || '').trim();
  const rate = String(input.rate_inr === undefined || input.rate_inr === null ? '' : input.rate_inr).trim();
  const gst = String(input.gst_rate_pct === undefined || input.gst_rate_pct === null ? '' : input.gst_rate_pct).trim();
  let error = null;
  if (description === '' || len(description) > 500) error = 'Enter what is sold (up to 500 characters).';
  else if (hsn.length < 4 || hsn.length > 8) error = 'Enter the HSN code (4 to 8 digits).';
  else if (!/^\d{1,10}(\.\d{1,3})?$/.test(qty) || G.scaled(qty, 3) <= 0n) error = 'Enter a quantity above zero (up to 3 decimals).';
  else if (unit === '' || len(unit) > 20) error = 'Enter the unit (e.g. SQM, PCS).';
  else if (!/^\d{1,11}(\.\d{1,2})?$/.test(rate) || G.scaled(rate, 2) <= 0n) error = 'Enter the rate in ₹ above zero (up to 2 decimals).';
  else if (gst !== '' && !GST_RATES.includes(G.money(G.scaled(gst, 2)))) error = 'The GST rate must be one of 0, 0.25, 3, 5, 12, 18, 28 or 40 %.';
  // D-257 Phase 3: an Import & Distribution sale comes out of stock, which is kept per product.
  else if ((await codeOfOrder(orderId)) === 'IMP' && !(await require('../repositories/importRepository').product(input.product_id || 0))) error = 'Choose the product: an Import & Distribution sale comes out of stock.';
  if (error !== null) return error;
  await repo.addLine(orderId, {
    product_id: input.product_id ? parseInt(input.product_id, 10) : null,
    description,
    hsn_code: hsn,
    quantity: qty,
    unit: unit.toUpperCase(),
    rate_inr: rate,
    gst_rate_pct: gst !== '' ? G.money(G.scaled(gst, 2)) : await gstRateFor(hsn),
  }, userId);
  await auditLogRepository.log(userId, 'DOMESTIC_LINE_ADDED', 'orders', orderId, 'line', null, description);
  return null;
}

async function removeLine(orderId, lineId, userId) {
  const dom = await repo.find(orderId);
  if (!dom || !LINES_OPEN.includes(dom.stage)) return 'The tax invoice number is fixed, so the lines can no longer change.';
  if (!(await repo.removeLine(orderId, lineId))) return 'Line not found.';
  await auditLogRepository.log(userId, 'DOMESTIC_LINE_REMOVED', 'orders', orderId, 'line', String(lineId), null);
  return null;
}

/** The GST figures of the order (its lines, which are frozen once the invoice number is fixed). */
async function totals(orderId) {
  const dom = await repo.find(orderId);
  return G.compute(await repo.lines(orderId), String((dom && dom.seller_state_code) || ''), String((dom && dom.place_of_supply) || ''));
}

// ------------------------------------------------------------ steps

async function savePurchase(orderId, input, userId) {
  const dom = await repo.find(orderId);
  const name = String(input.supplier_name || '').trim();
  const ref = String(input.supplier_po_ref || '').trim();
  let error = null;
  if (!dom) error = 'Order not found.';
  else if (!['order_confirmation', 'purchase'].includes(dom.stage)) error = 'The purchase is recorded after the order confirmation and before dispatch.';
  else if ((await codeOfOrder(orderId)) === 'IMP') error = 'An Import & Distribution sale comes out of stock: there is no supplier PO.';
  else if (name === '' || len(name) > 255) error = 'Enter the supplier.';
  else if (ref === '' || len(ref) > 80) error = 'Enter the supplier PO number.';
  if (error !== null) return error;
  await repo.update(orderId, { supplier_name: name, supplier_po_ref: ref }, userId);
  await auditLogRepository.log(userId, 'DOMESTIC_PURCHASE_SAVED', 'orders', orderId, 'supplier_po_ref', dom.supplier_po_ref, ref);
  return null;
}

/** Whether this order's goods need an e-way bill (consignment value above DOM's threshold). */
async function needsEwayBill(dom, t = null) {
  const value = dom.invoice_total !== null && dom.invoice_total !== undefined ? dom.invoice_total : ((t && t.invoice_total) || '0');
  return G.scaled(String(value), 2) > G.scaled(await setting('dom_eway_threshold_inr'), 2);
}

async function saveDispatch(orderId, input, userId) {
  const dom = await repo.find(orderId);
  const date = String(input.dispatch_date || '').trim();
  const transporter = String(input.transporter_name || '').trim();
  const transporterId = String(input.transporter_id || '').trim().toUpperCase();
  const vehicle = String(input.vehicle_no || '').replace(/\s+/g, '').toUpperCase();
  const lr = String(input.lr_no || '').trim();
  const km = String(input.distance_km === undefined || input.distance_km === null ? '' : input.distance_km).trim();
  const eway = String(input.eway_bill_no || '').replace(/\D/g, '');
  const ewayDate = String(input.eway_bill_date || '').trim();
  let error = null;
  if (!dom) error = 'Order not found.';
  else if (dom.stage !== 'dispatch') error = 'Dispatch details are recorded at the Dispatch stage.';
  else if (!isDate(date)) error = 'Enter the dispatch date.';
  else if (date < String(dom.tax_invoice_date)) error = `The goods cannot leave before the tax invoice date (${dom.tax_invoice_date}).`;
  else if (transporter === '' || len(transporter) > 150) error = 'Enter the transporter.';
  else if (transporterId !== '' && !/^[0-9A-Z]{15}$/.test(transporterId)) error = "The transporter ID is the transporter's 15-character GSTIN or TRANSIN.";
  else if (!/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$/.test(vehicle)) error = 'Enter the vehicle number (e.g. KA01AB1234).';
  else if (len(lr) > 40) error = 'The LR number is too long.';
  else if (km !== '' && (!/^\d{1,4}$/.test(km) || parseInt(km, 10) > 4000)) error = 'The distance is in km, up to 4,000.';
  else if (eway !== '' && eway.length !== 12) error = 'An e-way bill number has 12 digits.';
  else if (eway !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(ewayDate)) error = 'Enter the e-way bill date.';
  if (error !== null) return error;
  await repo.update(orderId, {
    dispatch_date: date, transporter_name: transporter, transporter_id: transporterId || null,
    vehicle_no: vehicle, lr_no: lr || null, distance_km: km !== '' ? parseInt(km, 10) : null,
    eway_bill_no: eway || null, eway_bill_date: eway !== '' ? ewayDate : null,
  }, userId);
  await auditLogRepository.log(userId, 'DOMESTIC_DISPATCH_SAVED', 'orders', orderId, 'vehicle_no', dom.vehicle_no, vehicle);
  return null;
}

async function markInvoiceSent(orderId, date, userId) {
  const dom = await repo.find(orderId);
  let error = null;
  if (!dom) error = 'Order not found.';
  else if (dom.stage !== 'tax_invoice') error = 'The invoice is marked as sent at the Tax invoice stage.';
  else if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) error = 'Enter the date the invoice was sent.';
  else if (date < String(dom.tax_invoice_date)) error = 'The invoice cannot be sent before its own date.';
  if (error !== null) return error;
  await repo.update(orderId, { invoice_sent_on: date }, userId);
  await auditLogRepository.log(userId, 'DOMESTIC_INVOICE_SENT', 'orders', orderId, 'invoice_sent_on', null, date);
  return null;
}

/** Rule D-212: what a DOM receipt is, for which order and buyer, what it settles, and the bank reference. */
async function paymentDescription(order, dom, amount, bankReference, note) {
  const buyer = String(order.company_legal_name || '');
  // D-257 Phase 3: an Import & Distribution sale is a "local sale" (of imported stock).
  const kind = (await codeOfDivision(order.division_id)) === 'IMP' ? 'Local sale' : 'Domestic sale';
  const text = dom.tax_invoice_no
    ? `${kind} receipt of ₹${G.inr(amount)} from ${buyer} against GST tax invoice ${dom.tax_invoice_no} (order ${order.order_reference}), bank ref ${bankReference}`
    : `${kind} advance of ₹${G.inr(amount)} from ${buyer} against order ${order.order_reference}, bank ref ${bankReference}`;
  const n = String(note || '').trim();
  return n !== '' ? `${text}; note: ${n}` : text;
}

/**
 * QA batch 2 (ORD-12): the domestic order row is locked while a receipt is checked and written, so the same
 * bank reference (or more than the invoice) cannot be recorded twice by two people at the same moment.
 */
async function recordPayment(orderId, input, userId) {
  const db = require('../config/db');
  const done = await db.transaction(async (trx) => {
    await trx.queryOne('SELECT order_id FROM domestic_orders WHERE order_id = :id FOR UPDATE', { id: orderId });
    return recordPaymentLocked(orderId, input, userId, trx);
  });
  if (done.error !== null) return done.error;
  await auditLogRepository.log(userId, 'DOMESTIC_PAYMENT_RECORDED', 'orders', orderId, 'amount_inr', null, done.amount);
  return null;
}

async function recordPaymentLocked(orderId, input, userId, trx) {
  const order = await orderRepository().find(orderId);
  const dom = await repo.find(orderId, trx);
  const date = String(input.received_on || '').trim();
  const amount = String(input.amount_inr === undefined || input.amount_inr === null ? '' : input.amount_inr).trim();
  const ref = String(input.bank_reference || '').trim();
  const note = String(input.note || '').trim();
  let error = null;
  if (!order || !dom) error = 'Order not found.';
  else if (!atOrAfter(dom.stage, 'order_confirmation') || dom.stage === 'closed') error = 'Payments are recorded from the order confirmation until the order is closed.';
  else if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date > todayIst()) error = 'Enter the date the money reached the bank (not in the future).';
  else if (!/^\d{1,11}(\.\d{1,2})?$/.test(amount) || G.scaled(amount, 2) <= 0n) error = 'Enter the amount received in ₹.';
  else if (ref === '' || len(ref) > 60) error = 'Enter the bank reference (UTR / NEFT / RTGS / cheque number).';
  else if (await repo.bankReferenceUsed(ref, trx)) error = 'This bank reference is already recorded. Check the payment is not entered twice.';
  else if (len(note) > 255) error = 'The note is too long.';
  // QA batch 2 (ORD-12): once the tax invoice is fixed, a receipt never takes the total paid above it.
  if (error === null && dom.invoice_total !== null && dom.invoice_total !== undefined) {
    let paidPaise = 0n;
    for (const p of await repo.payments(orderId, trx)) paidPaise += G.scaled(String(p.amount_inr), 2);
    const owed = G.scaled(String(dom.invoice_total), 2) - paidPaise;
    if (G.scaled(amount, 2) > owed) error = `This is more than the buyer still owes on tax invoice ${dom.tax_invoice_no} (₹${G.money(owed > 0n ? owed : 0n)}).`;
  }
  if (error !== null) return { error, amount };
  const description = await paymentDescription(order, dom, amount, ref, note);
  await repo.addPayment(orderId, date, amount, ref, description, note !== '' ? note : null, userId, trx);
  return { error: null, amount };
}

async function paidTotal(orderId) {
  let paise = 0n;
  for (const p of await repo.payments(orderId)) paise += G.scaled(String(p.amount_inr), 2);
  return G.money(paise);
}

// ------------------------------------------------------------ stages

/** What still stops the order moving on from its stage (empty = it may move on). */
async function blockers(orderId) {
  const dom = await repo.find(orderId);
  if (!dom) return ['Order not found.'];
  const lines = await repo.lines(orderId);
  const has = async (code) => !!(await repo.latestDocument(orderId, code));
  const out = [];
  switch (dom.stage) {
    case 'enquiry':
      if (lines.length === 0) out.push('Add at least one line.');
      break;
    case 'quotation':
      if (!(await has('DQT'))) out.push('Generate the quotation.');
      break;
    case 'order_confirmation':
      if (!dom.buyer_gstin) out.push("Enter the buyer's GSTIN (a reseller buys on its GST registration).");
      if (!(await has('DOC'))) out.push('Generate the order confirmation.');
      break;
    case 'purchase':
      if ((await codeOfOrder(orderId)) === 'IMP') {
        // D-257 Phase 3: the goods come out of IMP's stock when the tax invoice is fixed.
        const order = await orderRepository().find(orderId);
        out.push(...(await require('./importService').stockShortfalls(Number(order.division_id), lines)));
      } else if (!dom.supplier_po_ref) out.push('Record the supplier PO.');
      if (lines.length === 0) out.push('Add at least one line.');
      break;
    case 'dispatch':
      if (!dom.dispatch_date || !dom.vehicle_no) out.push('Record the dispatch (date, transporter and vehicle).');
      if ((await needsEwayBill(dom)) && !dom.eway_bill_no) out.push(`Enter the e-way bill number: the consignment is above ₹${G.inr(await setting('dom_eway_threshold_inr'))}.`);
      if (!(await has('GTI'))) out.push('Generate the GST tax invoice.');
      if (!(await has('DCH'))) out.push('Generate the delivery challan.');
      break;
    case 'tax_invoice':
      if (!dom.invoice_sent_on) out.push('Mark the tax invoice as sent to the buyer.');
      break;
    case 'payment': {
      const due = G.scaled(String(dom.invoice_total), 2) - G.scaled(await paidTotal(orderId), 2);
      if (due > 100n) out.push(`Still to receive: ₹${G.inr(G.money(due))}.`); // ₹1 tolerance, as for every other receipt
      break;
    }
    case 'closed':
      out.push('The order is closed.');
      break;
    default:
      break;
  }
  return out;
}

/** At dispatch the tax invoice number is fixed (DOM/26-27/0001) and the GST totals frozen. */
async function fixTaxInvoice(orderId, userId, trx) {
  const date = todayIst();
  const fy = financialYear.label(date);
  const short = `${fy.slice(2, 4)}-${fy.slice(5, 7)}`;
  const code = await codeOfOrder(orderId);
  const number = `${code}/${short}/${String(await repo.nextNumber(series(code, 'GTI'), fy)).padStart(4, '0')}`;
  const t = await totals(orderId);
  await repo.update(orderId, {
    tax_invoice_no: number,
    tax_invoice_date: date,
    taxable_total: t.taxable_total,
    cgst_total: t.cgst_total,
    sgst_total: t.sgst_total,
    igst_total: t.igst_total,
    round_off: t.round_off,
    invoice_total: t.invoice_total,
  }, userId, trx);
  // D-262: the company details the invoice prints are kept with it.
  await (trx || db).execute('UPDATE domestic_orders SET company_snapshot_json = :j WHERE order_id = :o AND company_snapshot_json IS NULL',
    { j: await require('./orderSnapshotService').companyValuesJson(), o: orderId });
}

/** Moves the order to its next stage when nothing stops it. @returns {Promise<string|null>} why not, or null */
async function advance(orderId, expectedStage, userId) {
  const dom = await repo.find(orderId);
  if (!dom) return 'Order not found.';
  if (dom.stage !== expectedStage) return 'Someone has already moved this order on. The page shows where it is now.';
  const b = await blockers(orderId);
  if (b.length > 0) return b.join(' ');
  const next = STAGE_KEYS[stageIndex(dom.stage) + 1];
  let stockError = null;
  let moved;
  try {
    moved = await db.transaction(async (trx) => {
      if (!(await repo.moveStage(orderId, dom.stage, next, userId, trx))) return false;
      if (next === 'dispatch') {
        await fixTaxInvoice(orderId, userId, trx);
        // D-257 Phase 3: an Import & Distribution sale takes its goods out of stock now (locked, never below zero).
        const order = await orderRepository().find(orderId);
        if ((await codeOfDivision(Number(order.division_id))) === 'IMP') {
          stockError = await require('./importService').issueForSale(Number(order.division_id), orderId, String(order.order_reference), await repo.lines(orderId), userId, trx);
          if (stockError !== null) throw new Error('stock');
        }
      }
      if (next === 'closed') await trx.execute("UPDATE orders SET status = 'complete', is_locked = 1 WHERE id = :id", { id: orderId });
      return true;
    });
  } catch (e) {
    if (stockError !== null) return stockError;
    throw e;
  }
  if (!moved) return 'Someone has already moved this order on. The page shows where it is now.';
  await auditLogRepository.log(userId, 'DOMESTIC_STAGE_MOVED', 'orders', orderId, 'stage', dom.stage, next);
  return null;
}

/** A reference for a DOM document: DOM/QT/26-27/0001 (the tax invoice uses its own fixed number). */
async function documentReference(orderId, code) {
  if (code === 'GTI') return String(((await repo.find(orderId)) || {}).tax_invoice_no || '');
  const existing = await repo.latestDocument(orderId, code);
  if (existing) return String(existing.reference); // a regenerated document keeps its number
  const fy = financialYear.label(todayIst());
  const short = `${fy.slice(2, 4)}-${fy.slice(5, 7)}`;
  const s = { DQT: 'QT', DOC: 'OC', DCH: 'DC' }[code];
  const division = await codeOfOrder(orderId);
  return `${division}/${s}/${short}/${String(await repo.nextNumber(series(division, s), fy)).padStart(4, '0')}`;
}

/** Whether a document may be generated now (its stage reached, not after closing). */
function documentAllowed(dom, code) {
  return !!DOCUMENT_FROM[code] && atOrAfter(dom.stage, DOCUMENT_FROM[code]) && dom.stage !== 'closed';
}

module.exports = {
  CODE, LOCAL_CODES, codeOfDivision, codeOfOrder, STAGES, STAGE_KEYS, DEFAULTS, DOCUMENTS, DOCUMENT_FROM, LINES_OPEN, GST_RATES,
  divisionId, isActive, isDomesticOrder, setting, sellerStateCode, stageIndex, atOrAfter, gstRateFor,
  partyFields, create, saveParty, addLine, removeLine, totals, savePurchase, needsEwayBill, saveDispatch,
  markInvoiceSent, paymentDescription, recordPayment, paidTotal, blockers, advance, documentReference, documentAllowed,
};
