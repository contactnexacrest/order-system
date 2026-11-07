'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const clientRepository = require('../repositories/clientRepository');
const lookupRepository = require('../repositories/lookupRepository');
const zohoGuard = require('./zohoGuard');
const crypto = require('crypto');
const db = require('../config/db');
const encryptionService = require('./encryptionService');
const zohoApiBudget = require('./zohoApiBudget');
const { todayIst } = require('../helpers/dbDateTime');
const { KIND_LABELS } = require('./zohoInvoiceData');
const { LOGISTICS_SERVICES, DOC_KINDS } = require('../repositories/orderVendorBillRepository');
const { CATEGORIES: EXPENSE_CATEGORIES } = require('../repositories/orderExpenseRepository');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');

/**
 * CA / Accounting module — the Zoho Books connection. It sends what the
 * app owns to Books (Commercial Invoices and Debit Notes, buyer and LC
 * payments, suppliers' and logistics partners' bills and the payments to
 * them, order expenses, order costs and export benefit journals), reads
 * them back so zohoRestoreService can put back anything changed or
 * deleted there, and imports the expenses the CA books in Books (owner
 * decisions 2026-10-29; see docs/SOP/ca-03-zoho-sync.md) — all via the
 * same OAuth self-client refresh-token flow (same shape as
 * zohoMailService, a separate credential set since Zoho Mail and Zoho
 * Books are different API scopes). Every public function here either
 * returns a result or throws — caSyncService is the one place that
 * catches those throws, logs them to zoho_sync_log, and moves on to the
 * next item, so this module is free to fail loudly and correctly on any
 * bad response.
 *
 * Zoho integration Stage 1 (2026-10-29): NexaCrest's Zoho One account
 * lives in Zoho's Indian data centre, so accounts.zoho.in /
 * www.zohoapis.in are the defaults. A buyer is created in Books as an
 * "overseas" customer in the order's own currency, and a payment goes in
 * as the foreign amount at the actual rate (INR actual / foreign amount),
 * so Books treats the sale as an export and the bank gets the INR actual.
 * Every Zoho change made while building this is logged in
 * docs/zoho/zoho-change-log.csv.
 */

// Tests swap in a fake Zoho; null = real HTTP.
let transport = null;
// Access tokens by refresh token — Zoho limits how many may be issued, so
// one is reused for 50 minutes (this server runs for days).
const tokens = new Map();
// Whether each Books organisation is GST-registered.
const gstRegistered = new Map();
// Account id by name, per organisation.
const accounts = new Map();
// Each GST-registered Books organisation's own GSTIN.
const orgGstin = new Map();
// Tax id by tax name (GST18, IGST18, ...), per organisation.
const taxes = new Map();

// Owner decision 2 Nov 2026 (D-239): token → how to refresh it (re-fetch on 401).
const tokenOwners = new Map();
// Tests: a fresh fake Zoho starts with no shared token and no calls counted.
let resetDone = Promise.resolve();

/** Tests only: a second process — nothing in memory, only the shared token cache (D-239). */
function forgetTokensInMemoryForTests() {
  tokens.clear();
  tokenOwners.clear();
}

/** Tests only: answer every Zoho request with fn(method, url, payload, headers, formEncoded) instead of the network (null restores real HTTP). */
function setTransport(fn) {
  transport = fn;
  tokens.clear();
  tokenOwners.clear();
  if (fn) {
    resetDone = Promise.all([db.execute('DELETE FROM zoho_token_cache'), db.execute('DELETE FROM zoho_api_usage')]).catch(() => {});
  }
  // A fake Zoho is not limited unless a test asks for the real limits (zohoApiBudget.useSettingLimits()).
  zohoApiBudget.setTestLimits(fn ? 1000000 : null, fn ? 1000000 : null);
  gstRegistered.clear();
  orgGstin.clear();
  taxes.clear();
  accounts.clear();
}

/** On only when this server allows Zoho (.env, zohoGuard) and the zoho_books_enabled setting is ticked. */
async function isEnabled() {
  return zohoGuard.integrationEnabled() && (await companySettingsRepository.get('zoho_books_enabled')) === '1';
}

/**
 * Pushes one settlement leg as a Zoho Books Customer Payment against the
 * order's client (creating the Zoho contact on first use, cached on
 * clients.zoho_contact_id after). A foreign-currency leg goes in as its
 * foreign amount at the actual rate (INR actual / foreign amount), so the
 * deposit account receives exactly the INR actual.
 *
 * Stage 2: with invoiceNumber (the leg's invoice already in Books), the
 * payment is applied to it straight away, up to what the invoice still
 * owes; without, it stays unapplied (an advance held until the CI is in
 * Books) and applyPaymentToInvoice() applies it later.
 *
 * @throws on any missing config, HTTP failure, or unexpected response
 * @return {Promise<{payment_id: string, applied: number}>} the Zoho-side customerpayment_id and the amount applied to the invoice
 */
/**
 * QA batch 4, ZOH-2: find-before-create for money records. When an earlier
 * run's POST reached Books but the app never recorded it (an unreadable
 * answer, a timeout, the app's own write failing), the next run finds the
 * record by its unique reference and adopts it instead of posting a second
 * copy. Null when Books has none (or the list cannot be read). Port of
 * ZohoBooksService::findExistingByReference.
 */
async function findExistingByReference(config, accessToken, endpoint, listKey, idKey, reference, filters, matches) {
  const ref = String(reference || '').trim();
  if (ref === '') return null;
  const query = new URLSearchParams({ organization_id: config.organizationId, reference_number: ref, ...filters }).toString();
  const res = await request('GET', `https://${config.apiDomain}/books/v3/${endpoint}?${query}`, {}, authHeaders(accessToken));
  if (!res.ok) return null;
  const decoded = safeJson(res.body);
  const rows = decoded && Array.isArray(decoded[listKey]) ? decoded[listKey] : [];
  for (const row of rows) {
    if (row && String(row.reference_number || '').trim() === ref && row[idKey] && matches(row)) {
      return String(row[idKey]);
    }
  }
  return null;
}

/** ZOH-2: a Books list row is the same money record — same amount (and same date when Books gives one). */
function sameMoney(row, amount, date) {
  const rowAmount = row.amount !== undefined ? row.amount : (row.total !== undefined ? row.total : -1);
  return Math.abs(Number(rowAmount) - Number(amount)) < 0.01
    && (!date || row.date === undefined || String(row.date).slice(0, 10) === String(date).slice(0, 10));
}

async function pushRevenuePayment(clientId, companyLegalName, currencyCode, foreignAmount, amountInr, date, referenceNumber, invoiceNumber = null, bankChargesInr = 0, description = '') {
  zohoGuard.assertCustomerAllowed(companyLegalName);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const contactId = await findOrCreateContact(config, accessToken, clientId, companyLegalName, currencyCode);
  const payload = paymentPayload(config, contactId, currencyCode, foreignAmount, amountInr, date, referenceNumber, bankChargesInr, description);
  let applied = 0;
  if (invoiceNumber !== null && invoiceNumber !== undefined) {
    [payload.invoices, applied] = await invoiceApplication(config, accessToken, invoiceNumber, contactId, payload.amount);
  }

  // QA batch 4, ZOH-2: a payment an earlier run already put in Books is adopted, never posted twice.
  const existing = await findExistingByReference(config, accessToken, 'customerpayments', 'customerpayments', 'payment_id', referenceNumber, { customer_id: contactId },
    (row) => sameMoney(row, payload.amount, date) && (row.customer_id === undefined || String(row.customer_id) === String(contactId)));
  if (existing !== null) return { payment_id: existing, applied: 0 };
  const res = await request('POST', `https://${config.apiDomain}/books/v3/customerpayments?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const paymentId = decoded && decoded.payment && decoded.payment.payment_id;
  if (!res.ok || typeof paymentId !== 'string') {
    throw new Error(`Zoho Books customer payment failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return { payment_id: paymentId, applied };
}

/**
 * Stage 2: applies a payment already in Books (pushed unapplied — the
 * advance, held until the CI arrived) to its invoice, up to what the
 * invoice still owes. Books needs the whole payment re-sent with the
 * invoice added, so the same leg details are passed again.
 *
 * @throws on any failure
 * @return {Promise<number>} the amount applied (in the order's currency)
 */
async function applyPaymentToInvoice(paymentId, clientId, companyLegalName, currencyCode, foreignAmount, amountInr, date, referenceNumber, invoiceNumber, bankChargesInr = 0, description = '') {
  zohoGuard.assertCustomerAllowed(companyLegalName);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const contactId = await findOrCreateContact(config, accessToken, clientId, companyLegalName, currencyCode);
  const payload = paymentPayload(config, contactId, currencyCode, foreignAmount, amountInr, date, referenceNumber, bankChargesInr, description);
  let applied;
  [payload.invoices, applied] = await invoiceApplication(config, accessToken, invoiceNumber, contactId, payload.amount);
  if (applied <= 0) {
    throw new Error(`Invoice ${invoiceNumber} in Zoho Books has nothing left to pay, so payment ${referenceNumber} stays unapplied there — ask the CA how to book it.`);
  }

  const res = await request('PUT', `https://${config.apiDomain}/books/v3/customerpayments/${encodeURIComponent(paymentId)}?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  if (!res.ok || !decoded || !decoded.payment) {
    throw new Error(`Zoho Books payment update failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return applied;
}

/**
 * Stage 2: creates or updates the Books invoice for an order's Commercial
 * Invoice or freight Debit Note (zohoInvoiceData.build()), numbered with
 * our own document reference, and marks it as sent (no email goes out) so
 * payments can be applied to it. The invoice is always looked up by
 * number first, so a retry never makes a second one — and an invoice with
 * that number on another customer is refused.
 *
 * @throws on any failure
 * @return {Promise<{zoho_invoice_id: string, zoho_invoice_number: string, status: string, created: boolean}>}
 */
async function pushInvoice(invoice) {
  zohoGuard.assertCustomerAllowed(String(invoice.company_legal_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const contactId = await findOrCreateContact(config, accessToken, invoice.client_id, String(invoice.company_legal_name), String(invoice.currency_code));
  const gst = await isGstRegistered(config, accessToken);

  const lineItems = invoice.lines.map((line) => {
    const item = { name: line.name, description: line.description, quantity: line.quantity, rate: line.rate };
    // Owner decision 2 Nov 2026 (D-240): a line picked from a product already in Books is linked to its item.
    if (line.item_id) item.item_id = String(line.item_id);
    if (line.unit !== null) item.unit = line.unit;
    if (line.hs_code !== null) {
      // A Books organisation that is not GST-registered rejects hsn_or_sac
      // (seen in the test organisation, Z-028), so there the HS code goes
      // into the description instead.
      if (gst) item.hsn_or_sac = line.hs_code;
      else item.description += `. HS code ${line.hs_code}`;
    }
    return item;
  });
  const payload = {
    customer_id: contactId,
    invoice_number: invoice.invoice_number,
    date: invoice.date,
    reference_number: invoice.reference_number,
    line_items: lineItems,
  };
  if (invoice.due_date !== null) payload.due_date = invoice.due_date;
  if (invoice.currency_code !== 'INR') payload.exchange_rate = round(Number(invoice.exchange_rate), 6);
  if (gst) payload.gst_treatment = 'overseas';
  // D-257 Phase 2: the Division reporting tag, only once two or more divisions are active (none today).
  const tags = await divisionTags(await orderDivisionId(invoice.order_id));
  if (tags.length > 0) payload.tags = tags;

  if (gst && String(invoice.invoice_number).length > 16) {
    // GST: a tax invoice number may have at most 16 characters (Books
    // refuses longer ones in a GST-registered organisation, Z-134). Every
    // reference has used the 2-digit year since 2026-10-29.
    throw new Error(`${KIND_LABELS[invoice.kind]} ${invoice.invoice_number} not sent: a GST invoice number may have at most 16 characters and this one has ${String(invoice.invoice_number).length}. Book it in Zoho Books by hand, or generate it again after the 2-digit-year change (SC/CI/26/...).`);
  }
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}&ignore_auto_number_generation=true`;
  const existing = await findInvoiceByNumber(config, accessToken, String(invoice.invoice_number));
  if (existing && String(existing.customer_id) !== contactId) {
    throw new Error(`Invoice number ${invoice.invoice_number} is already used in Zoho Books for ${existing.customer_name || 'another customer'}. Nothing was changed.`);
  }
  if (existing && String(existing.status || '') !== 'draft') {
    // Books refuses to change a sent invoice without a reason (code
    // 110701, seen in the test organisation, Z-049); it keeps the reason in
    // the invoice's history.
    payload.reason = `Revised in the order system: ${KIND_LABELS[invoice.kind]} ${invoice.invoice_number} revision ${invoice.revision} approved.`;
  }
  // Zoho has been seen answering HTTP 500 with a cut-off body to a create
  // or update that did go through (test organisation, Z-029), so when the
  // response cannot be read the result is read back from the invoice list
  // (asked again if it lags behind the create).
  const res = existing
    ? await request('PUT', `https://${config.apiDomain}/books/v3/invoices/${encodeURIComponent(String(existing.invoice_id))}${query}`, payload, authHeaders(accessToken))
    : await request('POST', `https://${config.apiDomain}/books/v3/invoices${query}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const saved = (decoded && decoded.code === 0 && decoded.invoice && decoded.invoice.invoice_id && String(decoded.invoice.invoice_number || '') === String(invoice.invoice_number)
    ? decoded.invoice
    : null) || await awaitInvoiceByNumber(config, accessToken, String(invoice.invoice_number));
  if (!saved || Math.abs(Number(saved.total) - Number(invoice.total)) > 0.01) {
    throw new Error(`Zoho Books invoice ${existing ? 'update' : 'creation'} for ${invoice.invoice_number} failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }

  let status = String(saved.status || '');
  if (status === 'draft') {
    const sent = await request('POST', `https://${config.apiDomain}/books/v3/invoices/${encodeURIComponent(String(saved.invoice_id))}/status/sent?organization_id=${encodeURIComponent(config.organizationId)}`, {}, authHeaders(accessToken));
    if (!sent.ok) {
      throw new Error(`Zoho Books invoice ${invoice.invoice_number} was saved but could not be marked as sent (HTTP ${sent.status}): ${sent.body.slice(0, 500)}`);
    }
    status = 'sent';
  }

  // Owner rule D-212: what the invoice is, as an internal comment in Books
  // (comments never print on Zoho's own invoice PDF, and our own documents
  // are unchanged). Added once, when Books first gets the invoice; a
  // failure here does not undo the invoice.
  if (!existing && invoice.summary) {
    try {
      await request('POST', `https://${config.apiDomain}/books/v3/invoices/${encodeURIComponent(String(saved.invoice_id))}/comments?organization_id=${encodeURIComponent(config.organizationId)}`,
        { description: String(invoice.summary), show_comment_to_clients: false }, authHeaders(accessToken));
    } catch (e) {
      zohoApiBudget.rethrow(e); // D-239: a rate limit still stops the run
      // the invoice itself is in Books; the comment is a courtesy
    }
  }

  return { zoho_invoice_id: String(saved.invoice_id), zoho_invoice_number: String(saved.invoice_number), status, created: !existing };
}

// Stage 3: which Books account a bill or an expense is booked to, by
// account name (looked up in the organisation's chart of accounts).
// Defaults chosen 2026-10-29 for the CA to confirm.
const BILL_ACCOUNTS = {
  supplier: 'Cost of Goods Sold',
  cha: 'Transportation Expense',
  transport: 'Transportation Expense',
  cha_transport: 'Transportation Expense',
  ocean_freight: 'Transportation Expense',
  other: 'Other Expenses',
};
const EXPENSE_ACCOUNTS = {
  travel_fuel: 'Travel Expense',
  travel_ticket: 'Travel Expense',
  local_conveyance: 'Travel Expense',
  hotel: 'Travel Expense',
  food: 'Travel Expense',
  courier: 'Postage',
  samples: 'Other Expenses',
  other: 'Other Expenses',
};
// What an expense was paid from: the company account is the deposit
// account; these are found by name.
const PAID_THROUGH_ACCOUNTS = { company_card: 'Company Card', staff: 'Employee Reimbursements' };
/** Owner decision 2026-10-29: the Books expense account for each order cost entry that goes to Books. */
const COST_ACCOUNTS = {
  ecgc_insurance: 'Insurance Expense',
  due_diligence: 'Consultant Expense',
  documentation: 'Export Documentation Charges',
  commission: 'Commission Expense',
  packing_crates: 'Packing Material',
  inspection_fee: 'Inspection Charges',
  courier: 'Postage',
  other: 'Other Expenses',
};
/** Owner decision 2026-10-29: where an export benefit lands when received. */
const BENEFIT_INCOME_ACCOUNT = 'Export Incentive Income';
const BENEFIT_SCRIP_ACCOUNT = 'Export Incentive e-Scrips';

const money = (n) => Number(n).toFixed(2);

/**
 * Stage 3: a supplier's or logistics partner's tax invoice as a Books bill,
 * under the vendor (created on first use), looked up by number for that
 * vendor first so a retry never makes a second one.
 *
 * In a GST-registered organisation the bill carries its GST: the taxable
 * value at the bill's own rate (GST / taxable: 5, 12, 18, 28 or 40 %) as
 * IGST when the vendor's GSTIN is from another state, or CGST + SGST
 * (Books' GST group) when it is from NexaCrest's state — compared by the
 * first two digits of the two GSTINs. A bill without GST from a registered
 * vendor goes in at 0 %; from an unregistered vendor, without tax. Refused
 * with a message staff can act on: GST from a vendor with no GSTIN in the
 * app, a GST amount that is not one of the GST rates of the taxable value.
 * Not handled yet (CA to decide): reverse charge and blocked input credit.
 * In an organisation that is not GST-registered the bill goes in at its
 * total.
 *
 * With zohoBillId (owner decision 2026-10-29: a bill changed directly in
 * Books) the same bill is put back to the app's values instead.
 *
 * @throws on any failure
 * @return {Promise<string>} the Zoho bill_id
 */
async function pushBill(bill, zohoBillId = null) {
  zohoGuard.assertCustomerAllowed(String(bill.buyer_name));
  assertVendorAllowed(String(bill.vendor_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const line = await billTax(config, accessToken, bill);
  const vendorId = await findOrCreateVendor(config, accessToken, bill);
  const existing = zohoBillId === null ? await findBill(config, accessToken, String(bill.bill_no), vendorId) : null;
  if (existing) {
    // QA batch 4, ZOH-14: a bill found by number is only adopted when its total agrees, as a new bill is checked below.
    if (Math.abs(Number(existing.total !== undefined && existing.total !== null ? existing.total : bill.total_inr) - Number(bill.total_inr)) > 1.0) {
      throw new Error(`Bill ${bill.bill_no} is in Zoho Books with total ${existing.total}, but the app has ${bill.total_inr} — check it in Books with the CA.`);
    }
    return String(existing.bill_id);
  }

  const forKey = bill.vendor_kind === 'supplier' ? 'supplier' : String(bill.bill_for);
  const what = bill.vendor_kind === 'supplier'
    ? `Supplier ${bill.bill_for === 'balance' ? 'balance' : 'advance'} bill`
    : `${LOGISTICS_SERVICES[bill.bill_for] || 'Logistics'} bill`;
  const payload = {
    vendor_id: vendorId,
    bill_number: String(bill.bill_no),
    date: String(bill.bill_date),
    reference_number: String(bill.buyer_inquiry_ref),
    line_items: [{
      account_id: await accountId(config, accessToken, BILL_ACCOUNTS[forKey] || BILL_ACCOUNTS.other),
      name: what,
      // Owner rule D-212: a supplier bill also names the Supplier PO it is billed against.
      description: `${what} for order ${bill.buyer_inquiry_ref}${bill.vendor_kind === 'supplier' && bill.supplier_po_reference ? ` (Supplier PO ${bill.supplier_po_reference})` : ''}: taxable ${money(bill.taxable_inr)} + GST ${money(bill.gst_inr)}${bill.note ? `. ${bill.note}` : ''}`,
      rate: line.rate,
      quantity: 1,
      ...(line.tax_id !== null ? { tax_id: line.tax_id } : {}),
      ...(line.reverse_charge_tax_id !== null ? { reverse_charge_tax_id: line.reverse_charge_tax_id } : {}),
    }],
    ...(line.reverse_charge_tax_id !== null ? { is_reverse_charge_applied: true } : {}),
  };
  if (zohoBillId !== null) {
    const put = await request('PUT', `https://${config.apiDomain}/books/v3/bills/${encodeURIComponent(zohoBillId)}?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
    if (!put.ok) {
      throw new Error(`Zoho Books bill update for ${bill.bill_no} failed (HTTP ${put.status}): ${put.body.slice(0, 500)}`);
    }
    return zohoBillId;
  }
  const res = await request('POST', `https://${config.apiDomain}/books/v3/bills?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const saved = await findBill(config, accessToken, String(bill.bill_no), vendorId);
  if (!saved) {
    throw new Error(`Zoho Books bill creation for ${bill.bill_no} failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  if (Math.abs(Number(saved.total ?? bill.total_inr) - Number(bill.total_inr)) > 1) {
    throw new Error(`Bill ${bill.bill_no} was created in Zoho Books with total ${saved.total}, but the app has ${bill.total_inr} — check it in Books with the CA.`);
  }
  return String(saved.bill_id);
}

/**
 * Stage 3: one payment to a vendor as a Books vendor payment from the
 * deposit account. With targetBill (the tax invoice already in Books) it is
 * applied to that bill, up to what the bill still owes; without, it is held
 * unapplied (an advance paid on a proforma) until applyVendorPayment().
 *
 * @throws on any failure
 * @return {Promise<{payment_id: string, applied: number}>}
 */
async function pushVendorPayment(payment, bill, targetBill) {
  zohoGuard.assertCustomerAllowed(String(bill.buyer_name));
  assertVendorAllowed(String(bill.vendor_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const vendorId = await findOrCreateVendor(config, accessToken, bill);
  const payload = vendorPaymentPayload(config, vendorId, payment, bill);
  let applied = 0;
  if (targetBill) {
    [payload.bills, applied] = await billApplication(config, accessToken, targetBill, vendorId, payload.amount);
  }
  // QA batch 4, ZOH-2: a payment an earlier run already put in Books is adopted, never posted twice.
  const existing = await findExistingByReference(config, accessToken, 'vendorpayments', 'vendorpayments', 'payment_id', payload.reference_number || '', { vendor_id: vendorId },
    (row) => sameMoney(row, payload.amount, payload.date || '') && (row.vendor_id === undefined || String(row.vendor_id) === String(vendorId)));
  if (existing !== null) return { payment_id: existing, applied: 0 };
  const res = await request('POST', `https://${config.apiDomain}/books/v3/vendorpayments?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const paymentId = decoded && decoded.vendorpayment && decoded.vendorpayment.payment_id;
  if (!res.ok || typeof paymentId !== 'string') {
    throw new Error(`Zoho Books vendor payment failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return { payment_id: paymentId, applied };
}

/**
 * Owner decision 2026-10-29: puts a vendor payment changed directly in Books
 * back to the app's values — applied in full to its bill when it was applied
 * before (zohoBillId).
 *
 * @throws on any failure
 */
async function restoreVendorPayment(payment, bill, zohoBillId) {
  zohoGuard.assertCustomerAllowed(String(bill.buyer_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const vendorId = await findOrCreateVendor(config, accessToken, bill);
  const payload = vendorPaymentPayload(config, vendorId, payment, bill);
  if (zohoBillId !== null) {
    payload.bills = [{ bill_id: zohoBillId, amount_applied: payload.amount }];
  }
  const res = await request('PUT', `https://${config.apiDomain}/books/v3/vendorpayments/${encodeURIComponent(String(payment.zoho_payment_id))}?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  if (!res.ok) {
    throw new Error(`Zoho Books vendor payment ${payment.payment_ref} could not be put back (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
}

/**
 * Owner decision 2026-10-29: puts a customer payment changed directly in
 * Books back to the app's values (same amount, rate and bank charges as
 * sent) — applied in full to its invoice when it was applied before.
 *
 * @throws on any failure
 */
async function restoreRevenuePayment(paymentId, clientId, companyLegalName, currencyCode, foreignAmount, amountInr, date, referenceNumber, zohoInvoiceId, bankChargesInr = 0, description = '') {
  zohoGuard.assertCustomerAllowed(companyLegalName);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const contactId = await findOrCreateContact(config, accessToken, clientId, companyLegalName, currencyCode);
  const payload = paymentPayload(config, contactId, currencyCode, foreignAmount, amountInr, date, referenceNumber, bankChargesInr, description);
  if (zohoInvoiceId !== null) {
    payload.invoices = [{ invoice_id: zohoInvoiceId, amount_applied: payload.amount }];
  }
  const res = await request('PUT', `https://${config.apiDomain}/books/v3/customerpayments/${encodeURIComponent(paymentId)}?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  if (!res.ok) {
    throw new Error(`Zoho Books payment ${referenceNumber} could not be put back (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
}

/**
 * Stage 3: applies a vendor payment held unapplied (paid on a proforma) to
 * the tax invoice that replaced it, now that it is in Books.
 *
 * @throws on any failure
 * @return {Promise<number>} the amount applied
 */
async function applyVendorPayment(payment, bill, targetBill) {
  zohoGuard.assertCustomerAllowed(String(bill.buyer_name));
  assertVendorAllowed(String(bill.vendor_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const vendorId = await findOrCreateVendor(config, accessToken, bill);
  const payload = vendorPaymentPayload(config, vendorId, payment, bill);
  let applied;
  [payload.bills, applied] = await billApplication(config, accessToken, targetBill, vendorId, payload.amount);
  if (applied <= 0) {
    throw new Error(`Bill ${targetBill.bill_no} in Zoho Books has nothing left to pay, so payment ${payment.payment_ref} stays unapplied there — ask the CA how to book it.`);
  }
  const res = await request('PUT', `https://${config.apiDomain}/books/v3/vendorpayments/${encodeURIComponent(String(payment.zoho_payment_id))}?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  if (!res.ok || !decoded || !decoded.vendorpayment) {
    throw new Error(`Zoho Books vendor payment update failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return applied;
}

/**
 * Stage 3: one order expense as a Books expense, paid through the deposit
 * account (company account), "Company Card" or "Employee Reimbursements"
 * (paid by staff). Its description starts with the buyer's name and order
 * reference, so a TEST- order's expenses are found by the clean-up script.
 *
 * With zohoExpenseId (owner decision 2026-10-29: an expense changed directly
 * in Books) the same expense is put back to the app's values.
 *
 * @throws on any failure
 * @return {Promise<string>} the Zoho expense_id
 */
async function pushExpense(expense, zohoExpenseId = null) {
  zohoGuard.assertCustomerAllowed(String(expense.buyer_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const paidThrough = expense.paid_by === 'company_account'
    ? config.depositAccountId
    : await accountId(config, accessToken, PAID_THROUGH_ACCOUNTS[expense.paid_by] || 'Employee Reimbursements');
  const label = EXPENSE_CATEGORIES[expense.category] || 'Expense';
  const payload = {
    account_id: await accountId(config, accessToken, EXPENSE_ACCOUNTS[expense.category] || EXPENSE_ACCOUNTS.other),
    paid_through_account_id: paidThrough,
    amount: round(Number(expense.amount_inr), 2),
    date: String(expense.expense_date),
    reference_number: `${expense.buyer_inquiry_ref}-EXP-${expense.id}`,
    description: `${expense.buyer_name} / ${expense.buyer_inquiry_ref}: ${label} — ${expense.details}${expense.paid_by === 'staff' && expense.staff_name ? ` (paid by ${expense.staff_name})` : ''}`,
  };
  // QA batch 4, ZOH-2: an expense an earlier run already put in Books is adopted (then updated), never posted twice.
  if (zohoExpenseId === null || zohoExpenseId === undefined) {
    zohoExpenseId = await findExistingByReference(config, accessToken, 'expenses', 'expenses', 'expense_id', payload.reference_number, {}, () => true);
  }
  const url = `https://${config.apiDomain}/books/v3/expenses${zohoExpenseId !== null ? `/${encodeURIComponent(zohoExpenseId)}` : ''}?organization_id=${encodeURIComponent(config.organizationId)}`;
  const res = await request(zohoExpenseId !== null ? 'PUT' : 'POST', url, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const expenseId = decoded && decoded.expense && decoded.expense.expense_id;
  if (!res.ok || typeof expenseId !== 'string') {
    throw new Error(`Zoho Books expense failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return expenseId;
}

/**
 * Owner decision 2026-10-29: one order cost entry as a Books expense, from
 * the account it was paid from (company account = the deposit account).
 * With zohoExpenseId (already in Books) the same expense is updated, e.g.
 * after the inspection fee was corrected.
 *
 * @throws on any failure
 * @return {Promise<string>} the Zoho expense_id
 */
async function pushCostEntry(cost, zohoExpenseId = null) {
  const { CATEGORIES, PAID_FROM } = require('../repositories/orderCostEntryRepository');
  zohoGuard.assertCustomerAllowed(String(cost.buyer_name));
  const label = CATEGORIES[cost.category] || 'Order cost';
  if (!PAID_FROM[cost.paid_from || '']) {
    throw new Error(`Order cost "${label}" (INR ${Number(cost.amount_inr).toFixed(2)}) not sent: it does not say what it was paid from. Remove it and record it again with "Paid from".`);
  }
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const payload = {
    account_id: await accountId(config, accessToken, COST_ACCOUNTS[cost.category] || COST_ACCOUNTS.other),
    paid_through_account_id: cost.paid_from === 'company_account' ? config.depositAccountId : await accountId(config, accessToken, PAID_THROUGH_ACCOUNTS.company_card),
    amount: round(Number(cost.amount_inr), 2),
    date: String(cost.cost_date),
    reference_number: `${cost.buyer_inquiry_ref}-COST-${cost.id}`,
    description: `${cost.buyer_name} / ${cost.buyer_inquiry_ref}: ${label}${cost.description ? ` — ${cost.description}` : ''}`,
  };
  // QA batch 4, ZOH-2: an expense an earlier run already put in Books is adopted (then updated), never posted twice.
  if (zohoExpenseId === null || zohoExpenseId === undefined) {
    zohoExpenseId = await findExistingByReference(config, accessToken, 'expenses', 'expenses', 'expense_id', payload.reference_number, {}, () => true);
  }
  const url = `https://${config.apiDomain}/books/v3/expenses${zohoExpenseId !== null ? `/${encodeURIComponent(zohoExpenseId)}` : ''}?organization_id=${encodeURIComponent(config.organizationId)}`;
  const res = await request(zohoExpenseId !== null ? 'PUT' : 'POST', url, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const expenseId = decoded && decoded.expense && decoded.expense.expense_id;
  if (!res.ok || typeof expenseId !== 'string') {
    throw new Error(`Zoho Books expense for order cost "${label}" failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return expenseId;
}

/**
 * Owner decision 2026-10-29: an export benefit, when received, as a Books
 * journal — Duty Drawback paid into the bank (the deposit account), RoDTEP
 * / RoSCTL credited as e-scrips (an asset account), against Export
 * Incentive Income. Any other scheme is refused: the CA books it by hand.
 * With journalId (already in Books) the same journal is updated, e.g.
 * after a corrected receipt.
 *
 * @throws on any failure
 * @return {Promise<string>} the Zoho journal_id
 */
async function pushBenefitJournal(benefit, journalId = null) {
  const scheme = String(benefit.scheme_name);
  const what = `${scheme} ${Number(benefit.received_amount).toFixed(2)}`;
  if (benefit.buyer_name) {
    zohoGuard.assertCustomerAllowed(String(benefit.buyer_name));
  }
  if (String(benefit.currency_code).toUpperCase() !== 'INR') {
    throw new Error(`Export benefit ${what} not sent: it is recorded in ${benefit.currency_code}, not rupees. Ask the CA to book it.`);
  }
  const key = scheme.replace(/[ -]/g, '').toUpperCase();
  let kind = null;
  if (key.includes('DRAWBACK')) kind = 'bank';
  else if (['RODTEP', 'ROSCTL'].includes(key)) kind = 'scrip';
  if (kind === null) {
    throw new Error(`Export benefit ${what} not sent: only Duty Drawback, RoDTEP and RoSCTL go to Zoho Books automatically. Ask the CA to book "${scheme}" by hand.`);
  }
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const amount = round(Number(benefit.received_amount), 2);
  const orderRef = String(benefit.buyer_inquiry_ref || '');
  const notes = (`${benefit.buyer_name || ''}${orderRef !== '' ? ` / ${orderRef}` : ''}: ${scheme} received${benefit.reference_number ? ` (claim ${benefit.reference_number})` : ''}`
    + ` · ₹${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${benefit.received_reference ? `${kind === 'bank' ? ' · bank ref ' : ' · e-scrip '}${benefit.received_reference}` : ''}`).replace(/^[ :]+|[ :]+$/g, '');
  const payload = {
    journal_date: String(benefit.received_at),
    reference_number: `${orderRef !== '' ? `${orderRef}-` : ''}BEN-${benefit.id}`,
    notes,
    line_items: [
      { account_id: kind === 'bank' ? config.depositAccountId : await accountId(config, accessToken, BENEFIT_SCRIP_ACCOUNT), debit_or_credit: 'debit', amount, description: kind === 'bank' ? `${scheme} paid into the bank` : `${scheme} e-scrip credited` },
      { account_id: await accountId(config, accessToken, BENEFIT_INCOME_ACCOUNT), debit_or_credit: 'credit', amount, description: `${scheme} received` },
    ],
  };
  // QA batch 4, ZOH-2: a journal an earlier run already put in Books is adopted (then updated), never posted twice.
  if (journalId === null || journalId === undefined) {
    journalId = await findExistingByReference(config, accessToken, 'journals', 'journals', 'journal_id', payload.reference_number, {}, () => true);
  }
  const url = `https://${config.apiDomain}/books/v3/journals${journalId !== null ? `/${encodeURIComponent(journalId)}` : ''}?organization_id=${encodeURIComponent(config.organizationId)}`;
  const res = await request(journalId !== null ? 'PUT' : 'POST', url, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const id = decoded && decoded.journal && decoded.journal.journal_id;
  if (!res.ok || id === undefined || id === null) {
    throw new Error(`Zoho Books journal for export benefit ${what} failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return String(id);
}

/**
 * Owner decision 2026-10-31 (GST under LUT): the account the CA moves the
 * claimed input credit into when a refund claim is filed (an asset, set up
 * once in Zoho Books); a refund the bank credits is taken out of it.
 */
const GST_REFUND_ACCOUNT = 'GST Refund Receivable';

/**
 * Owner decision 2026-10-31 (GST under LUT, option A): a GST refund the bank
 * credited, as a Books journal — the deposit account debited, GST Refund
 * Receivable credited — with the description the app builds (rule D-212). A
 * claim number starting TEST- marks test data: its notes then start TEST-
 * too, so the test clean-up finds it. credit: a
 * gstRefundRepository.creditsForZohoSync() row. Returns the journal_id.
 * Port of ZohoBooksService::pushGstRefundJournal().
 */
async function pushGstRefundJournal(credit) {
  const paymentDescription = require('./paymentDescription');
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const amount = round(Number(credit.amount), 2);
  const description = paymentDescription.gstRefund(credit);
  const test = String(credit.claim_arn).startsWith(zohoGuard.TEST_PREFIX);
  const payload = {
    journal_date: String(credit.credited_on).slice(0, 10),
    reference_number: `GSTREF-${credit.claim_arn}-${credit.id}`,
    notes: `${test ? `${zohoGuard.TEST_PREFIX} ` : ''}${description}`,
    line_items: [
      { account_id: config.depositAccountId, debit_or_credit: 'debit', amount, description },
      { account_id: await accountId(config, accessToken, GST_REFUND_ACCOUNT), debit_or_credit: 'credit', amount, description: `GST refund under LUT, claim ${credit.claim_arn}` },
    ],
  };
  // QA batch 4, ZOH-2: a journal an earlier run already put in Books is adopted, never posted twice.
  const existing = await findExistingByReference(config, accessToken, 'journals', 'journals', 'journal_id', payload.reference_number, {}, () => true);
  if (existing !== null) return existing;
  const res = await request('POST', `https://${config.apiDomain}/books/v3/journals?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const id = decoded && decoded.journal && decoded.journal.journal_id;
  if (!res.ok || id === undefined || id === null) {
    throw new Error(`Zoho Books journal for the GST refund of claim ${credit.claim_arn} (₹${amount.toFixed(2)}) failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return String(id);
}

/**
 * Port of ZohoBooksService::payoutCostJournalPayload() (D-264): the journal for one associate payout's
 * payout difference — "Associate payout costs" debited and the bank (the deposit account the commission is
 * paid from) credited; a negative difference books the same two accounts the other way round.
 */
async function payoutCostJournalPayload(payout, costsAccountId, bankAccountId, order = null, test = false) {
  const associatePayoutService = require('./associatePayoutService');
  order = order || await require('../repositories/orderRepository').find(Number(payout.order_id));
  const difference = round(Number(payout.payout_difference_inr), 2);
  const amount = Math.abs(difference);
  const description = await associatePayoutService.payoutCostDescription(payout, order);
  return {
    journal_date: String(payout.paid_on).slice(0, 10),
    reference_number: `APC-${(order && order.order_reference) || payout.order_id}-${payout.id}`,
    notes: `${test ? `${zohoGuard.TEST_PREFIX} ` : ''}${description}`,
    line_items: [
      { account_id: costsAccountId, debit_or_credit: difference >= 0 ? 'debit' : 'credit', amount, description },
      { account_id: bankAccountId, debit_or_credit: difference >= 0 ? 'credit' : 'debit', amount, description: difference >= 0 ? 'Associate payout costs paid from the bank' : 'Associate payout cost saving (the rupee came out cheaper)' },
    ],
  };
}

/** Port of ZohoBooksService::pushPayoutCostJournal() (D-264, owner 7 Nov 2026: "yes add the payout difference to zoho"). */
async function pushPayoutCostJournal(payout, costsAccountId, journalId = null) {
  const order = await require('../repositories/orderRepository').find(Number(payout.order_id));
  const client = order ? await clientRepository.find(Number(order.client_id)) : null;
  const buyer = String((client && client.company_legal_name) || '');
  zohoGuard.assertCustomerAllowed(buyer);
  const config = await loadConfig();
  const payload = await payoutCostJournalPayload(payout, costsAccountId, config.depositAccountId, order, buyer.startsWith(zohoGuard.TEST_PREFIX));
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  // QA batch 4, ZOH-2: a journal an earlier run already put in Books is adopted (then updated), never posted twice.
  if (journalId === null) journalId = await findExistingByReference(config, accessToken, 'journals', 'journals', 'journal_id', payload.reference_number, {}, () => true);
  const url = `https://${config.apiDomain}/books/v3/journals${journalId !== null ? `/${encodeURIComponent(journalId)}` : ''}?organization_id=${encodeURIComponent(config.organizationId)}`;
  const res = await request(journalId !== null ? 'PUT' : 'POST', url, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const id = decoded && decoded.journal && decoded.journal.journal_id;
  if (!res.ok || id === undefined || id === null) {
    throw new Error(`Zoho Books journal for the associate payout cost of ${payload.reference_number} (₹${Math.abs(Number(payout.payout_difference_inr)).toFixed(2)}) failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return String(id);
}

/** Clean-up before go-live: every Books journal whose notes start with TEST- (export benefits of TEST- orders). */
async function listTestJournals() {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const out = [];
  for (const row of await getList(accessToken, `https://${config.apiDomain}/books/v3/journals?organization_id=${encodeURIComponent(config.organizationId)}`, 'journals')) {
    if (String(row.notes || '').startsWith(zohoGuard.TEST_PREFIX)) {
      out.push({ journal_id: String(row.journal_id), label: `${row.notes || ''} INR ${row.total ?? ''} on ${row.journal_date || ''}`.trim() });
    }
  }
  return out;
}

/**
 * Owner decision 2026-10-29 (the per-order "App vs Zoho" check): reads
 * only. A contact's invoices and customer payments (a customer), or bills
 * and vendor payments (a vendor), from the Books lists.
 */
async function contactRecords(contactId, vendor) {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const org = `organization_id=${encodeURIComponent(config.organizationId)}`;
  const out = {};
  for (const key of vendor ? ['bills', 'vendorpayments'] : ['invoices', 'customerpayments']) {
    out[key] = await getAllPages(accessToken, `https://${config.apiDomain}/books/v3/${key}?${org}&${vendor ? 'vendor_id=' : 'customer_id='}${encodeURIComponent(contactId)}`, key);
  }
  return out;
}

/**
 * Owner decision 2026-10-29 (the app is the source of truth for what it
 * sends): every Books expense and every journal, id => total, read in full
 * pages — so one sync reads each list once, not each record.
 *
 * @return {Promise<Object<string, number>>}
 */
async function totalsById(kind) {
  const [path, idKey] = { expense: ['expenses', 'expense_id'], journal: ['journals', 'journal_id'] }[kind];
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const out = {};
  for (const row of await getAllPages(accessToken, `https://${config.apiDomain}/books/v3/${path}?organization_id=${encodeURIComponent(config.organizationId)}`, path)) {
    out[String(row[idKey])] = Number(row.total || 0);
  }
  return out;
}

/**
 * Whether Books still has this invoice (looked up by its number, the way the
 * app finds invoices — the single-invoice read can fail). The row, or null.
 */
async function invoiceByNumber(invoiceNumber) {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  return findInvoiceByNumber(config, accessToken, invoiceNumber);
}

/** One Books record by id — an expense, journal, bill, customer payment or vendor payment (reads only). Null when Books no longer has it. */
async function readRecord(kind, id) {
  const [path, key] = {
    expense: ['expenses', 'expense'], journal: ['journals', 'journal'], bill: ['bills', 'bill'],
    customerpayment: ['customerpayments', 'payment'], vendorpayment: ['vendorpayments', 'vendorpayment'],
  }[kind];
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const res = await request('GET', `https://${config.apiDomain}/books/v3/${path}/${encodeURIComponent(id)}?organization_id=${encodeURIComponent(config.organizationId)}`, null, authHeaders(accessToken));
  if (res.status === 404) {
    return null;
  }
  const decoded = safeJson(res.body);
  if (!res.ok || !decoded || !decoded[key]) {
    // Books answers an unknown id with an error code rather than 404 for some records.
    if (decoded && [1002, 4000, 4500, 1004].includes(Number(decoded.code || 0))) {
      return null;
    }
    throw new Error(`Zoho Books ${kind} ${id} read failed (HTTP ${res.status}): ${res.body.slice(0, 300)}`);
  }
  return decoded[key];
}

/**
 * The "Test Books connection" button (Admin -> Settings): logs in with the
 * saved credentials and checks the organisation and the deposit account
 * exist, and which of this system's active currencies Books has not
 * turned on. Reads only — creates nothing in Zoho.
 *
 * @throws on any missing config, login failure, unknown organisation or deposit account
 * @return {Promise<{organization: string, deposit_account: string, missing_currencies: string[]}>}
 */
async function testConnection() {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const organizations = await getList(accessToken, `https://${config.apiDomain}/books/v3/organizations`, 'organizations');
  const organization = organizations.find((o) => String(o.organization_id || '') === config.organizationId);
  if (!organization) {
    const seen = organizations.map((o) => `${o.name || '?'} (${o.organization_id || '?'})`).join(', ');
    throw new Error(`Organization ID ${config.organizationId} was not found. This Zoho login can see: ${seen || 'no organisations'}.`);
  }

  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const accounts = await getList(accessToken, `https://${config.apiDomain}/books/v3/bankaccounts${query}`, 'bankaccounts');
  const deposit = accounts.find((a) => String(a.account_id || '') === config.depositAccountId);
  if (!deposit) {
    const seen = accounts.map((a) => `${a.account_name || '?'} (${a.account_id || '?'})`).join(', ');
    throw new Error(`Deposit account ID ${config.depositAccountId} was not found in Zoho Books. Its bank accounts are: ${seen || 'none'}.`);
  }

  const booksCurrencies = (await getList(accessToken, `https://${config.apiDomain}/books/v3/settings/currencies${query}`, 'currencies')).map((c) => String(c.currency_code || ''));
  const missing = (await lookupRepository.currencies()).map((c) => String(c.code)).filter((code) => !booksCurrencies.includes(code));

  return {
    organization: String(organization.name || config.organizationId),
    deposit_account: String(deposit.account_name || config.depositAccountId),
    missing_currencies: missing,
  };
}

/**
 * Every expense recorded in Zoho Books (Phase 4 — one-way import, the
 * reverse direction of pushRevenuePayment()). Reads only the Expenses
 * list endpoint's own summary fields — never a per-item detail call — so
 * an import with hundreds of expenses stays a handful of requests; see
 * ca-04-expenses.md for why Zoho's own TDS fields aren't read here.
 *
 * @throws on any missing config, HTTP failure, or unexpected response
 */
async function listExpenses() {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const expenses = [];
  let page = 1;
  let hasMore = true;
  while (hasMore) {
    const res = await request(
      'GET',
      `https://${config.apiDomain}/books/v3/expenses?organization_id=${encodeURIComponent(config.organizationId)}&page=${page}&per_page=200`,
      null,
      authHeaders(accessToken)
    );
    const decoded = safeJson(res.body);
    if (!res.ok || !decoded || !Array.isArray(decoded.expenses)) {
      throw new Error(`Zoho Books expense list failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }

    for (const row of decoded.expenses) {
      if (!row.expense_id) continue;
      expenses.push({
        zohoExpenseId: String(row.expense_id),
        category: row.account_name || 'Uncategorized',
        description: row.description || null,
        vendorName: row.vendor_name || null,
        amount: parseFloat(row.total || 0),
        currencyCode: row.currency_code || 'INR',
        expenseDate: row.date || todayIst(),
        referenceNumber: String(row.reference_number || ''),
      });
    }

    hasMore = !!(decoded.page_context && decoded.page_context.has_more_page);
    page += 1;
  }

  return expenses;
}

/**
 * Cleanup before go-live (jobs/zohoCleanupTestRecords.js): every Books
 * customer whose name starts with TEST-, with its payments and (Stage 2)
 * its invoices.
 *
 * @return {Promise<Array<{contact_id: string, name: string, payments: Array<{payment_id: string, label: string}>, invoices: Array<{invoice_id: string, label: string}>}>>}
 */
async function listTestContacts() {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;

  const contacts = [];
  for (const row of await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts${query}&contact_name_startswith=TEST-`, 'contacts')) {
    const payments = (await getList(accessToken, `https://${config.apiDomain}/books/v3/customerpayments${query}&customer_id=${encodeURIComponent(String(row.contact_id))}`, 'customerpayments')).map((p) => ({
      payment_id: String(p.payment_id),
      label: [p.reference_number || '', p.currency_code || '', p.amount ?? '', `on ${p.date || ''}`].map(String).filter((part) => part !== '').join(' '),
    }));
    const invoices = (await getList(accessToken, `https://${config.apiDomain}/books/v3/invoices${query}&customer_id=${encodeURIComponent(String(row.contact_id))}`, 'invoices')).map((i) => ({
      invoice_id: String(i.invoice_id),
      label: [i.invoice_number || '', i.currency_code || '', i.total ?? '', `on ${i.date || ''}`].map(String).filter((part) => part !== '').join(' '),
    }));
    // Stage 3: a TEST- vendor's payments and bills.
    let vendorPayments = [];
    let bills = [];
    if (row.contact_type === 'vendor') {
      vendorPayments = (await getList(accessToken, `https://${config.apiDomain}/books/v3/vendorpayments${query}&vendor_id=${encodeURIComponent(String(row.contact_id))}`, 'vendorpayments'))
        .map((vp) => ({ payment_id: String(vp.payment_id), label: `${vp.reference_number || ''} INR ${vp.amount ?? ''} on ${vp.date || ''}`.trim() }));
      bills = (await getList(accessToken, `https://${config.apiDomain}/books/v3/bills${query}&vendor_id=${encodeURIComponent(String(row.contact_id))}`, 'bills'))
        .map((b) => ({ bill_id: String(b.bill_id), label: `${b.bill_number || ''} INR ${b.total ?? ''} on ${b.date || ''}`.trim() }));
    }
    contacts.push({ contact_id: String(row.contact_id), name: String(row.contact_name || ''), payments, invoices, vendor_payments: vendorPayments, bills });
  }
  return contacts;
}

/**
 * Stage 3 clean-up: every Books expense whose description starts with
 * TEST- (an order expense's description starts with the buyer's name).
 *
 * @return {Promise<Array<{expense_id: string, label: string}>>}
 */
async function listTestExpenses() {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const out = [];
  let page = 1;
  let more;
  do {
    const res = await request('GET', `https://${config.apiDomain}/books/v3/expenses?organization_id=${encodeURIComponent(config.organizationId)}&page=${page}&per_page=200`, null, authHeaders(accessToken));
    const decoded = safeJson(res.body);
    if (!res.ok || !decoded || !Array.isArray(decoded.expenses)) {
      throw new Error(`Zoho Books expense list failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
    for (const row of decoded.expenses) {
      if (String(row.description || '').startsWith(zohoGuard.TEST_PREFIX)) {
        out.push({ expense_id: String(row.expense_id), label: `${row.description || ''} INR ${row.total ?? ''} on ${row.date || ''}`.trim() });
      }
    }
    more = Boolean(decoded.page_context && decoded.page_context.has_more_page);
    page += 1;
  } while (more);
  return out;
}

/**
 * Deletes one Books record made while testing — 'customerpayment',
 * 'invoice', 'vendorpayment', 'bill', 'expense' or 'contact' (payments, then
 * invoices and bills, then the contact: Books refuses to delete a contact
 * that still has transactions). A record already gone counts as deleted,
 * and returns false so the clean-up does not log a deletion that never
 * happened.
 *
 * @return {Promise<boolean>} true when Books deleted it, false when it was already gone
 *
 * @throws on any other failure
 */
async function deleteRecord(kind, id) {
  const paths = { customerpayment: 'customerpayments', invoice: 'invoices', vendorpayment: 'vendorpayments', bill: 'bills', expense: 'expenses', journal: 'journals', contact: 'contacts' };
  if (!paths[kind]) {
    throw new Error(`Unknown Zoho Books record kind ${kind}.`);
  }
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const res = await request('DELETE', `https://${config.apiDomain}/books/v3/${paths[kind]}/${encodeURIComponent(id)}?organization_id=${encodeURIComponent(config.organizationId)}`, null, authHeaders(accessToken));
  if (res.status === 404) {
    return false;
  }
  if (!res.ok) {
    throw new Error(`Zoho Books ${kind} ${id} delete failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return true;
}

/**
 * Owner decision 2 Nov 2026 (D-238): a client pushed to Books as a customer
 * on its own (zoho_sync_clients). Creates it when contactId is null (linking
 * an existing Books customer of the same name instead of a duplicate),
 * otherwise updates it. Currency only on create — it cannot change.
 * Returns { contact_id, action: 'created' | 'linked' | 'updated' }.
 */
async function pushCustomerContact(client, contactId, currencyCode) {
  const name = String(client.company_legal_name);
  zohoGuard.assertCustomerAllowed(name);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const payload = customerPayload(client, await isGstRegistered(config, accessToken));
  let id = contactId;
  let action = 'updated';
  if (id === null) {
    const found = (await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts${query}&contact_name=${encodeURIComponent(name)}`, 'contacts'))
      .find((row) => String(row.contact_name || '') === name && String(row.contact_type || 'customer') === 'customer');
    if (found) {
      id = String(found.contact_id);
      action = 'linked';
    }
  }
  if (id === null) {
    const currency = (await getList(accessToken, `https://${config.apiDomain}/books/v3/settings/currencies${query}`, 'currencies')).find((c) => c.currency_code === currencyCode);
    if (!currency) {
      throw new Error(`Currency ${currencyCode} is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.`);
    }
    const res = await request('POST', `https://${config.apiDomain}/books/v3/contacts${query}`, { ...payload, contact_type: 'customer', currency_id: String(currency.currency_id) }, authHeaders(accessToken));
    const decoded = safeJson(res.body);
    const newId = decoded && decoded.contact && decoded.contact.contact_id;
    if (!res.ok || typeof newId !== 'string') {
      throw new Error(`Zoho Books customer creation failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
    return { contact_id: newId, action: 'created' };
  }
  const res = await request('PUT', `https://${config.apiDomain}/books/v3/contacts/${encodeURIComponent(id)}${query}`, payload, authHeaders(accessToken));
  if (!res.ok) {
    throw new Error(`Zoho Books customer update failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  // A customer marked inactive earlier is made active again when the client is active in the app.
  await request('POST', `https://${config.apiDomain}/books/v3/contacts/${encodeURIComponent(id)}/active${query}`, {}, authHeaders(accessToken));
  return { contact_id: id, action };
}

/** The customer fields the app owns (never amounts or costs). */
function customerPayload(client, gstRegistered) {
  const name = String(client.company_legal_name);
  const address = {};
  for (const [key, col] of [['address', 'billing_address_line1'], ['street2', 'billing_address_line2'], ['city', 'billing_city'], ['zip', 'billing_postcode'], ['country', 'billing_country']]) {
    const v = String(client[col] || '');
    if (v.trim() !== '') address[key] = v;
  }
  const payload = { contact_name: name, company_name: name, billing_address: address };
  const person = {};
  const first = String(client.contact_person || '').trim().slice(0, 100);
  const email = String(client.email || '').trim();
  const phone = String(client.phone || '').trim();
  if (first) person.first_name = first;
  if (email) person.email = email;
  if (phone) person.phone = phone;
  if (Object.keys(person).length > 0) {
    payload.contact_persons = [{ ...person, is_primary_contact: true }];
  }
  const vat = String(client.vat_eori_tax_no || '').trim();
  payload.notes = `Kept in step by the NexaCrest order system${vat ? ` · VAT / EORI / Tax Reg. No.: ${vat}` : ''}.`;
  if (gstRegistered) payload.gst_treatment = 'overseas';
  return payload;
}

/**
 * Owner decision 2 Nov 2026 (D-238): a product of the Products menu as a
 * Books sales item, rate 0 — the catalogue holds sourcing costs only, never
 * a selling price, and no cost is ever sent to Books.
 */
async function pushItem(product, itemId) {
  const name = String(product.name).slice(0, 100);
  if (!zohoGuard.isProduction() && !name.startsWith(zohoGuard.TEST_PREFIX)) {
    throw new Error(`Not sent: ZOHO_ENVIRONMENT is test, and only products whose name starts with TEST- may go to Zoho (this one is "${name}").`);
  }
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const payload = itemPayload(product, await isGstRegistered(config, accessToken));
  let id = itemId;
  let action = 'updated';
  if (id === null) {
    const found = (await getList(accessToken, `https://${config.apiDomain}/books/v3/items${query}&name=${encodeURIComponent(name)}`, 'items')).find((row) => String(row.name || '') === name);
    if (found) {
      id = String(found.item_id);
      action = 'linked';
    }
  }
  if (id === null) {
    const res = await request('POST', `https://${config.apiDomain}/books/v3/items${query}`, payload, authHeaders(accessToken));
    const decoded = safeJson(res.body);
    const newId = decoded && decoded.item && decoded.item.item_id;
    if (!res.ok || typeof newId !== 'string') {
      throw new Error(`Zoho Books item creation failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
    return { item_id: newId, action: 'created' };
  }
  const res = await request('PUT', `https://${config.apiDomain}/books/v3/items/${encodeURIComponent(id)}${query}`, payload, authHeaders(accessToken));
  if (!res.ok) {
    throw new Error(`Zoho Books item update failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  await request('POST', `https://${config.apiDomain}/books/v3/items/${encodeURIComponent(id)}/active${query}`, {}, authHeaders(accessToken));
  return { item_id: id, action };
}

/** The item fields: name, description, HS code, rate 0 — never a cost. */
function itemPayload(product, gstRegistered) {
  let description = String(product.specifications || '').trim().slice(0, 2000);
  const hs = String(product.hs_code || '').trim();
  // Owner decision D-245 P2: a product has no fixed selling price any more (each order's price is
  // built from its cost and the seller), so the item's rate is 0. Costs are never sent.
  const rate = 0;
  const payload = { name: String(product.name).slice(0, 100), rate, item_type: 'sales', product_type: 'goods' };
  if (hs !== '') {
    if (gstRegistered) {
      payload.hsn_or_sac = hs;
    } else {
      description = `${description}${description !== '' ? '. ' : ''}HS code ${hs}`.trim();
    }
  }
  payload.description = description;
  return payload;
}

/** Owner decision 2 Nov 2026 (D-238): inactive in Books, never deleted. kind = 'contact' | 'item'. */
async function markInactive(kind, id) {
  const path = { contact: 'contacts', item: 'items' }[kind];
  if (!path) throw new Error(`Unknown kind ${kind}`);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const res = await request('POST', `https://${config.apiDomain}/books/v3/${path}/${encodeURIComponent(id)}/inactive?organization_id=${encodeURIComponent(config.organizationId)}`, {}, authHeaders(accessToken));
  if (!res.ok) {
    throw new Error(`Zoho Books could not mark the ${kind} inactive (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
}

async function loadConfig() {
  // The .env switch comes first, so "switched off on this server" is what
  // staff see — not a missing-credentials message.
  if (!zohoGuard.integrationEnabled()) {
    throw new Error('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED is not true in .env).');
  }
  const clientId = (await companySettingsRepository.get('zoho_books_client_id')) || '';
  const clientSecret = (await companySettingsRepository.get('zoho_books_client_secret')) || '';
  const refreshToken = (await companySettingsRepository.get('zoho_books_refresh_token')) || '';
  const organizationId = (await companySettingsRepository.get('zoho_books_organization_id')) || '';
  const depositAccountId = (await companySettingsRepository.get('zoho_books_deposit_account_id')) || '';

  if (!clientId || !clientSecret || !refreshToken || !organizationId || !depositAccountId) {
    throw new Error('Zoho Books is enabled but not fully configured — fill in every zoho_books_* setting.');
  }

  return {
    clientId,
    clientSecret,
    refreshToken,
    organizationId,
    depositAccountId,
    accountsDomain: (await companySettingsRepository.get('zoho_books_accounts_domain')) || 'accounts.zoho.in',
    apiDomain: (await companySettingsRepository.get('zoho_books_api_domain')) || 'www.zohoapis.in',
  };
}

/**
 * The buyer's Books contact: an "overseas" customer (export, no GST) in
 * the order's currency. A Books customer has one currency, so a contact
 * found or cached in another currency is refused with a clear message
 * instead of booking the amount in the wrong currency.
 */
async function findOrCreateContact(config, accessToken, clientId, companyLegalName, currencyCode) {
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const client = await clientRepository.find(clientId);
  if (client && client.zoho_contact_id) {
    const contactId = String(client.zoho_contact_id);
    const res = await request('GET', `https://${config.apiDomain}/books/v3/contacts/${encodeURIComponent(contactId)}${query}`, null, authHeaders(accessToken));
    const decoded = safeJson(res.body);
    if (!res.ok || !decoded || !decoded.contact) {
      throw new Error(`Zoho Books contact lookup failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
    assertContactCurrency(companyLegalName, String(decoded.contact.currency_code || ''), currencyCode);
    return contactId;
  }

  const search = await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts${query}&contact_name=${encodeURIComponent(companyLegalName)}`, 'contacts');
  const existing = search[0] && search[0].contact_id;
  if (typeof existing === 'string' && existing !== '') {
    assertContactCurrency(companyLegalName, String(search[0].currency_code || ''), currencyCode);
    await clientRepository.setZohoContactId(clientId, existing);
    return existing;
  }

  const currency = (await getList(accessToken, `https://${config.apiDomain}/books/v3/settings/currencies${query}`, 'currencies')).find((c) => c.currency_code === currencyCode);
  if (!currency) {
    throw new Error(`Currency ${currencyCode} is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.`);
  }

  const createRes = await request(
    'POST',
    `https://${config.apiDomain}/books/v3/contacts${query}`,
    {
      contact_name: companyLegalName,
      company_name: companyLegalName,
      contact_type: 'customer',
      currency_id: String(currency.currency_id),
      ...((await isGstRegistered(config, accessToken)) ? { gst_treatment: 'overseas' } : {}),
    },
    authHeaders(accessToken)
  );
  const createDecoded = safeJson(createRes.body);
  const newContactId = createDecoded && createDecoded.contact && createDecoded.contact.contact_id;
  if (!createRes.ok || typeof newContactId !== 'string') {
    throw new Error(`Zoho Books contact creation failed (HTTP ${createRes.status}): ${createRes.body.slice(0, 500)}`);
  }

  await clientRepository.setZohoContactId(clientId, newContactId);
  return newContactId;
}

/**
 * The Customer Payment fields for one leg: the foreign amount at the
 * actual rate (INR actual / foreign amount), into the deposit account.
 */
/**
 * amountInr is what reached the deposit account. bankChargesInr (the LC
 * receipt, owner decision 2026-10-29) is what the bank kept: the payment
 * then covers both, at (credited + charges) / foreign amount, and Books
 * books the charges as bank charges — in the payment's own currency.
 */
function paymentPayload(config, contactId, currencyCode, foreignAmount, amountInr, date, referenceNumber, bankChargesInr = 0, description = '') {
  const charges = Number(bankChargesInr) || 0;
  const payload = {
    customer_id: contactId,
    payment_mode: 'banktransfer',
    amount: round(amountInr + charges, 2),
    date,
    reference_number: referenceNumber,
    // Owner rule D-212: what the payment is, for which order, what it settles, its bank reference.
    description,
    account_id: config.depositAccountId,
  };
  if (charges > 0) {
    payload.bank_charges = round(charges, 2);
  }
  if (currencyCode !== 'INR') {
    if (foreignAmount === null || foreignAmount === undefined || foreignAmount <= 0) {
      throw new Error(`Payment ${referenceNumber} has no ${currencyCode} amount recorded, so its rate cannot be worked out.`);
    }
    const rate = (amountInr + charges) / foreignAmount;
    payload.amount = round(foreignAmount, 2);
    payload.exchange_rate = round(rate, 6);
    if (charges > 0) {
      payload.bank_charges = round(charges / rate, 2);
    }
  }
  return payload;
}

/** The "invoices" part of a payment: as much of amount as invoice invoiceNumber still owes. @return {Promise<[Array, number]>} */
async function invoiceApplication(config, accessToken, invoiceNumber, contactId, amount) {
  const invoice = await awaitInvoiceByNumber(config, accessToken, invoiceNumber);
  if (!invoice || String(invoice.customer_id) !== contactId) {
    throw new Error(`Invoice ${invoiceNumber} was not found in Zoho Books for this customer.`);
  }
  const applied = round(Math.min(amount, Number(invoice.balance || 0)), 2);
  return [applied > 0 ? [{ invoice_id: String(invoice.invoice_id), amount_applied: applied }] : [], applied];
}

/**
 * One invoice by its exact number, from the invoice list (the
 * single-invoice read has been seen failing with HTTP 500 in the test
 * organisation, Z-029; the list does not).
 */
/**
 * findInvoiceByNumber(), asked up to three times a few seconds apart: for
 * an invoice the app knows is in Books, as the list can lag a few seconds
 * behind a create (test organisation, 2026-10-29).
 */
async function awaitInvoiceByNumber(config, accessToken, invoiceNumber) {
  for (let attempt = 0; attempt < 3; attempt += 1) {
    if (attempt > 0) {
      await new Promise((resolve) => { setTimeout(resolve, 3000); });
    }
    const invoice = await findInvoiceByNumber(config, accessToken, invoiceNumber);
    if (invoice) {
      return invoice;
    }
  }
  return null;
}

async function findInvoiceByNumber(config, accessToken, invoiceNumber) {
  const rows = await getList(accessToken, `https://${config.apiDomain}/books/v3/invoices?organization_id=${encodeURIComponent(config.organizationId)}&invoice_number=${encodeURIComponent(invoiceNumber)}`, 'invoices');
  return rows.find((row) => String(row.invoice_number || '') === invoiceNumber) || null;
}

/**
 * Whether the Books organisation is GST-registered. Only then are the GST
 * fields (gst_treatment, hsn_or_sac) sent: an organisation that is not
 * registered rejects them (the test organisation, Z-025 / Z-028).
 */
async function isGstRegistered(config, accessToken) {
  if (!gstRegistered.has(config.organizationId)) {
    const org = encodeURIComponent(config.organizationId);
    const res = await request('GET', `https://${config.apiDomain}/books/v3/organizations/${org}?organization_id=${org}`, null, authHeaders(accessToken));
    const decoded = safeJson(res.body);
    if (!res.ok || !decoded || !decoded.organization) {
      throw new Error(`Zoho Books organisation lookup failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
    gstRegistered.set(config.organizationId, Boolean(decoded.organization.is_registered_for_gst));
    orgGstin.set(config.organizationId, String((decoded.organization.tax_settings && decoded.organization.tax_settings.tax_reg_no) || '').toUpperCase());
  }
  return gstRegistered.get(config.organizationId);
}

/** The Books sync go-live date (zoho_books_sync_start_date), or null when not set. */
async function syncStartDate() {
  const date = String((await companySettingsRepository.get('zoho_books_sync_start_date')) || '').trim();
  return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : null;
}

/**
 * The bill's one line: its value and GST tax (see pushBill()).
 *
 * Owner decision 2026-10-29 (CA advice): a transport bill marked
 * reverse charge (a GTA that charges no GST) goes with the 5% GST as
 * reverse charge — the bill stays at its taxable value and Books
 * works out the tax we owe and can claim back. IGST5 for a transporter
 * registered in another state, else GST5 (Books treats a vendor with
 * no GSTIN as in our own state).
 *
 * @return {Promise<{rate: number, tax_id: ?string, reverse_charge_tax_id: ?string}>}
 */
async function billTax(config, accessToken, bill) {
  const taxable = round(Number(bill.taxable_inr), 2);
  const gstAmount = round(Number(bill.gst_inr), 2);
  if (!(await isGstRegistered(config, accessToken))) {
    return { rate: round(Number(bill.total_inr), 2), tax_id: null, reverse_charge_tax_id: null };
  }
  const vendorGstin = String(bill.vendor_gstin || '').trim().toUpperCase();
  const otherState = vendorGstin !== '' && vendorGstin.slice(0, 2) !== String(orgGstin.get(config.organizationId) || '').slice(0, 2);
  if (Number(bill.gst_reverse_charge)) {
    if (gstAmount > 0) {
      throw new Error(`Bill ${bill.bill_no} not sent: it is marked reverse charge but carries GST (${gstAmount.toFixed(2)}). Under reverse charge the transporter charges no GST — correct the bill.`);
    }
    return { rate: taxable, tax_id: null, reverse_charge_tax_id: await taxId(config, accessToken, otherState ? 'IGST5' : 'GST5') };
  }
  if (vendorGstin === '') {
    if (gstAmount > 0) {
      throw new Error(`Bill ${bill.bill_no} not sent: it carries GST (${gstAmount.toFixed(2)}), but ${bill.vendor_name} has no GSTIN in the app. Add the GSTIN to the supplier / logistics partner, or correct the bill.`);
    }
    return { rate: taxable, tax_id: null, reverse_charge_tax_id: null };
  }
  const percent = [0, 5, 12, 18, 28, 40].find((candidate) => Math.abs(round(taxable * candidate / 100, 2) - gstAmount) <= 1);
  if (percent === undefined) {
    throw new Error(`Bill ${bill.bill_no} not sent: GST ${gstAmount.toFixed(2)} on taxable ${taxable.toFixed(2)} is not 5, 12, 18, 28 or 40 % — correct the bill (a bill with several GST rates needs the CA).`);
  }
  return { rate: taxable, tax_id: await taxId(config, accessToken, `${otherState ? 'IGST' : 'GST'}${percent}`), reverse_charge_tax_id: null };
}

/** A Books tax's id by its name (GST18 = CGST + SGST group, IGST18, ...). */
async function taxId(config, accessToken, name) {
  if (!taxes.has(config.organizationId)) {
    const byName = new Map();
    for (const row of await getList(accessToken, `https://${config.apiDomain}/books/v3/settings/taxes?organization_id=${encodeURIComponent(config.organizationId)}`, 'taxes')) {
      byName.set(String(row.tax_name), String(row.tax_id));
    }
    taxes.set(config.organizationId, byName);
  }
  const id = taxes.get(config.organizationId).get(name);
  if (!id) {
    throw new Error(`Zoho Books has no tax named "${name}". Check Zoho Books -> Settings -> Taxes (or ask the CA), then sync again.`);
  }
  return id;
}

/** In test, only vendors whose name starts with TEST- may be sent, so the clean-up script finds them. */
function assertVendorAllowed(name) {
  if (!zohoGuard.isProduction() && !name.startsWith(zohoGuard.TEST_PREFIX)) {
    throw new Error(`Not sent: ZOHO_ENVIRONMENT is test, and only vendors whose name starts with TEST- may go to Zoho (this one is "${name}").`);
  }
}

/** The bill's vendor in Books (supplier or logistics partner), created on first use and remembered on its row. */
async function findOrCreateVendor(config, accessToken, bill) {
  if (bill.vendor_zoho_contact_id) return String(bill.vendor_zoho_contact_id);
  const name = String(bill.vendor_name);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const match = (await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts${query}&contact_type=vendor&contact_name=${encodeURIComponent(name)}`, 'contacts'))
    .find((row) => String(row.contact_name || '') === name);
  let found = match ? String(match.contact_id) : null;
  if (!found) {
    const payload = { contact_name: name, company_name: name, contact_type: 'vendor' };
    if (await isGstRegistered(config, accessToken)) {
      Object.assign(payload, bill.vendor_gstin ? { gst_treatment: 'business_gst', gst_no: String(bill.vendor_gstin) } : { gst_treatment: 'business_none' });
    }
    if (bill.vendor_pan) payload.pan_no = String(bill.vendor_pan);
    const res = await request('POST', `https://${config.apiDomain}/books/v3/contacts${query}`, payload, authHeaders(accessToken));
    const decoded = safeJson(res.body);
    found = decoded && decoded.contact && decoded.contact.contact_id;
    if (!res.ok || typeof found !== 'string') {
      throw new Error(`Zoho Books vendor creation failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
  }
  await zohoPurchaseRepository.setVendorContactId(String(bill.vendor_kind), Number(bill.vendor_id), found);
  return found;
}

/** One bill by its number for this vendor. */
async function findBill(config, accessToken, billNumber, vendorId) {
  const rows = await getList(accessToken, `https://${config.apiDomain}/books/v3/bills?organization_id=${encodeURIComponent(config.organizationId)}&vendor_id=${encodeURIComponent(vendorId)}&bill_number=${encodeURIComponent(billNumber)}`, 'bills');
  return rows.find((row) => String(row.bill_number || '') === billNumber && String(row.vendor_id ?? vendorId) === vendorId) || null;
}

function vendorPaymentPayload(config, vendorId, payment, bill) {
  return {
    vendor_id: vendorId,
    amount: round(Number(payment.amount_inr), 2),
    date: String(payment.paid_at),
    payment_mode: String(payment.payment_mode),
    reference_number: String(payment.payment_ref),
    description: vendorPaymentDescription(payment, bill),
    paid_through_account_id: config.depositAccountId,
  };
}

/**
 * Owner decision 2026-10-31: the bank UTR stays the reference (bank
 * reconciliation); the description says which payment it is, as the
 * customer payments do — supplier advance / balance, or a logistics
 * partner's advance / final payment — with the order and the bill.
 */
function vendorPaymentDescription(payment, bill) {
  const order = String(bill.buyer_inquiry_ref || '');
  const billLabel = `${DOC_KINDS[bill.doc_kind] || 'Bill'} ${bill.bill_no}`;
  // QA batch 4, ZOH-18 (rule D-212): the bank reference is named in the description too, as on the buyer's payments.
  const bankRef = String(payment.payment_ref || '').trim() !== '' ? ` · bank ref ${String(payment.payment_ref).trim()}` : '';
  if (bill.vendor_kind === 'supplier') {
    const leg = payment.leg === 'balance' ? 'balance' : 'advance';
    return `Supplier ${leg} payment for order ${order}${bill.supplier_po_reference ? ` · Supplier PO ${bill.supplier_po_reference}` : ''} · against ${billLabel}${bankRef}`;
  }
  const service = LOGISTICS_SERVICES[bill.bill_for] || 'Logistics';
  const kind = bill.payment_kind === 'advance' ? 'advance' : 'final';
  return `${service} — ${kind} payment for order ${order} · against ${billLabel}${bankRef}`;
}

/** As much of amount as the bill still owes. @return {Promise<[Array, number]>} */
async function billApplication(config, accessToken, targetBill, vendorId, amount) {
  const row = await findBill(config, accessToken, String(targetBill.bill_no), vendorId);
  if (!row) throw new Error(`Bill ${targetBill.bill_no} was not found in Zoho Books for this vendor.`);
  const applied = round(Math.min(amount, Number(row.balance || 0)), 2);
  return [applied > 0 ? [{ bill_id: String(row.bill_id), amount_applied: applied }] : [], applied];
}

/** A Books account's id by its name; a missing account is refused with where to add it. */
async function accountId(config, accessToken, name) {
  if (!accounts.has(config.organizationId)) {
    const byName = new Map();
    for (const row of await getList(accessToken, `https://${config.apiDomain}/books/v3/chartofaccounts?organization_id=${encodeURIComponent(config.organizationId)}`, 'chartofaccounts')) {
      byName.set(String(row.account_name), String(row.account_id));
    }
    accounts.set(config.organizationId, byName);
  }
  const id = accounts.get(config.organizationId).get(name);
  if (!id) {
    throw new Error(`Zoho Books has no account named "${name}". Add it in Zoho Books -> Accountant -> Chart of Accounts (or ask the CA which account to use), then sync again.`);
  }
  return id;
}

function assertContactCurrency(companyLegalName, contactCurrency, currencyCode) {
  if (contactCurrency !== '' && contactCurrency !== currencyCode) {
    throw new Error(`${companyLegalName} is set up in Zoho Books in ${contactCurrency}, but this payment is in ${currencyCode}. A Zoho Books customer has one currency — ask the CA how to book it.`);
  }
}

/** Every page of a Books list (per_page 200), for lists that can grow past one page. */
async function getAllPages(accessToken, url, key) {
  const rows = [];
  let page = 1;
  let more = true;
  while (more) {
    const res = await request('GET', `${url}&page=${page}&per_page=200`, null, authHeaders(accessToken));
    const decoded = safeJson(res.body);
    if (!res.ok || !decoded || !Array.isArray(decoded[key])) {
      throw new Error(`Zoho Books ${key} request failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
    rows.push(...decoded[key]);
    more = !!(decoded.page_context && decoded.page_context.has_more_page);
    page += 1;
  }
  return rows;
}

/** The `key` list of a Books GET, or throws. */
async function getList(accessToken, url, key) {
  const res = await request('GET', url, null, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  if (!res.ok || !decoded || !Array.isArray(decoded[key])) {
    throw new Error(`Zoho Books ${key} request failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return decoded[key];
}

function round(value, places) {
  const f = 10 ** places;
  return Math.round(value * f) / f;
}

function authHeaders(accessToken) {
  return { Authorization: `Zoho-oauthtoken ${accessToken}`, 'Content-Type': 'application/json' };
}

async function getAccessToken(accountsDomain, clientId, clientSecret, refreshToken) {
  // Zoho issues only a few access tokens per refresh token in ten minutes.
  // Owner decision 2 Nov 2026 (D-239): one token (valid an hour) is shared
  // for 50 minutes by every process — the hourly sync, "Sync now", "Sync this
  // order", the checks, and the PHP stack — through zoho_token_cache
  // (encrypted), refreshed under a database lock so two requests never both
  // fetch a new one.
  await resetDone;
  const cached = tokens.get(refreshToken);
  if (cached && cached.expires > Date.now()) {
    return cached.token;
  }
  const owner = [accountsDomain, clientId, clientSecret, refreshToken];
  const key = crypto.createHash('sha256').update(`${zohoGuard.environment()}|${refreshToken}`).digest('hex');
  let fromDb = await cachedToken(key);
  if (fromDb) return rememberToken(refreshToken, fromDb.token, fromDb.expires, owner);
  const lockName = `nx_zoho_token_${key.slice(0, 40)}`;
  const conn = await db.pool.getConnection();
  try {
    const [lockRows] = await conn.query('SELECT GET_LOCK(?, 15) AS got', [lockName]);
    const locked = Number(lockRows && lockRows[0] ? lockRows[0].got : 0) === 1;
    fromDb = await cachedToken(key); // another request may have refreshed it while we waited
    if (fromDb) return rememberToken(refreshToken, fromDb.token, fromDb.expires, owner);
    // QA batch 4, ZOH-16: another request is still fetching the token — never ask Zoho for a second one at the same time.
    if (!locked) throw new Error('Zoho Books sign-in is being renewed by another request — try again in a minute.');
    const res = await request('POST', `https://${accountsDomain}/oauth/v2/token`, {
      refresh_token: refreshToken,
      client_id: clientId,
      client_secret: clientSecret,
      grant_type: 'refresh_token',
    }, { 'Content-Type': 'application/x-www-form-urlencoded' }, true);
    const decoded = safeJson(res.body);
    if (!res.ok || !decoded || !decoded.access_token) {
      throw new Error(`Zoho Books OAuth token refresh failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
    }
    const expires = Date.now() + 3000 * 1000;
    await db.execute(`INSERT INTO zoho_token_cache (cache_key, token_enc, expires_at) VALUES (:k, :t, FROM_UNIXTIME(:x))
                      ON DUPLICATE KEY UPDATE token_enc = VALUES(token_enc), expires_at = VALUES(expires_at)`,
    { k: key, t: encryptionService.encrypt(String(decoded.access_token)), x: Math.floor(expires / 1000) });
    return rememberToken(refreshToken, String(decoded.access_token), expires, owner);
  } finally {
    try {
      await conn.query('SELECT RELEASE_LOCK(?)', [lockName]);
    } finally {
      conn.release();
    }
  }
}

async function cachedToken(key) {
  const row = await db.queryOne('SELECT token_enc, UNIX_TIMESTAMP(expires_at) AS x FROM zoho_token_cache WHERE cache_key = :k AND expires_at > NOW()', { k: key });
  if (!row) return null;
  const token = encryptionService.decrypt(String(row.token_enc));
  return token ? { token, expires: Number(row.x) * 1000 } : null;
}

function rememberToken(refreshToken, token, expires, owner) {
  tokens.set(refreshToken, { token, expires });
  tokenOwners.set(token, owner);
  return token;
}

/** D-239: Zoho refused the token (401) — forget it everywhere, so the next call fetches a new one. */
async function forgetToken(token) {
  const owner = tokenOwners.get(token);
  tokenOwners.delete(token);
  if (!owner) return;
  tokens.delete(owner[3]);
  const key = crypto.createHash('sha256').update(`${zohoGuard.environment()}|${owner[3]}`).digest('hex');
  await db.execute('DELETE FROM zoho_token_cache WHERE cache_key = :k', { k: key });
}

async function request(method, url, payload, headers, formEncoded = false) {
  zohoGuard.assertRequestAllowed(url);
  await resetDone;
  // Owner decision 2 Nov 2026 (D-239): every call counts against this app's daily / per-minute allowance.
  await zohoApiBudget.acquire();
  let res = await send(method, url, payload, headers, formEncoded);
  if (res.status === 401 && headers && typeof headers.Authorization === 'string' && headers.Authorization.startsWith('Zoho-oauthtoken ')) {
    // A token Zoho no longer accepts: forget it, fetch a new one, try once more.
    const old = headers.Authorization.slice('Zoho-oauthtoken '.length);
    const owner = tokenOwners.get(old);
    if (owner) {
      await forgetToken(old);
      const fresh = await getAccessToken(...owner);
      headers = { ...headers, Authorization: `Zoho-oauthtoken ${fresh}` };
      await zohoApiBudget.acquire();
      res = await send(method, url, payload, headers, formEncoded);
    }
  }
  zohoApiBudget.assertNotRateLimited(res.status, res.body);
  return res;
}

async function send(method, url, payload, headers, formEncoded) {
  if (transport) {
    const fake = await transport(method, url, payload, headers, formEncoded);
    return { ok: fake.status >= 200 && fake.status < 300, status: fake.status, body: fake.body };
  }
  let body;
  if (method !== 'GET' && method !== 'DELETE') body = formEncoded ? new URLSearchParams(payload).toString() : JSON.stringify(payload);
  // QA batch 4, ZOH-15 / PAR-6: a 20-second limit, as PHP's CURLOPT_TIMEOUT, and the same error text as PHP.
  let res;
  try {
    res = await fetch(url, { method, headers, body, signal: AbortSignal.timeout(20000) });
    return { ok: res.ok, status: res.status, body: await res.text() };
  } catch (e) {
    throw new Error(`Zoho Books API request failed: ${e && e.message ? e.message : String(e)}`);
  }
}

function safeJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/**
 * The Zoho bill for an associate's commission: the gross commission, with the TDS tax when one
 * is chosen and (D-256) the GST tax for commission when GST was paid on their tax invoice.
 */
function associateBillPayload(c, cost, vendorId, accountIdValue) {
  return {
    vendor_id: vendorId,
    bill_number: c.bill_no,
    date: c.paid_on,
    reference_number: String(cost.buyer_inquiry_ref),
    line_items: [{
      account_id: accountIdValue,
      name: 'Associate commission',
      description: c.bill_description,
      rate: c.gross_inr,
      quantity: 1,
      ...(c.gst_inr > 0 ? { tax_id: c.gst_tax_id } : {}),
    }],
    ...(c.tds_tax_id !== null ? { tds_tax_id: c.tds_tax_id } : {}),
  };
}

/** D-256: the payment is what was paid — the commission after TDS plus the GST paid on top. */
function associatePaymentPayload(c, vendorId, billId, paidThroughAccountId) {
  const amount = round(c.paid_inr + c.gst_inr, 2);
  return {
    vendor_id: vendorId,
    amount,
    date: c.paid_on,
    payment_mode: 'Bank Transfer',
    reference_number: c.reference,
    description: c.payment_description,
    paid_through_account_id: paidThroughAccountId,
    bills: [{ bill_id: billId, amount_applied: amount }],
  };
}

/**
 * Port of ZohoBooksService::pushAssociateCommission(). Owner decision 1 Nov
 * 2026 (AS-12): an associate's paid commission goes to Books as the
 * associate's own bill (the associate a vendor, the bill numbered with
 * their invoice) and a vendor payment from the bank with the UTR, applied
 * to it — instead of an expense. The bill is the gross commission; the
 * payment is what was paid, so any TDS deducted stays on the bill for the
 * CA to book against the TDS payable account. Returns the Zoho bill_id.
 */
async function pushAssociateCommission(cost) {
  const associateRepository = require('../repositories/associateRepository');
  zohoGuard.assertCustomerAllowed(String(cost.buyer_name));
  const c = await associateCommission(cost);
  // Owner decision D-256 (5 Nov 2026): GST on the associate's tax invoice goes on the bill as a GST tax,
  // so the bill total equals what was paid and the input credit is booked — never a bill without it.
  if (c.gst_inr > 0 && c.gst_tax_id === null) {
    throw new Error(`Associate commission on order ${cost.buyer_inquiry_ref} not sent: GST of INR ${c.gst_inr.toFixed(2)} was paid on the associate's tax invoice, but no Zoho GST tax is chosen for commission (Associates → Commission settings → Zoho GST tax for commission). Choose it, then sync again.`);
  }
  assertVendorAllowed(String(c.associate.name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  let vendorId = String(c.associate.zoho_contact_id || '');
  if (vendorId === '') {
    const name = String(c.associate.name);
    const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
    for (const row of await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts${query}&contact_type=vendor&contact_name=${encodeURIComponent(name)}`, 'contacts')) {
      if (String(row.contact_name || '') === name) vendorId = String(row.contact_id);
    }
    if (vendorId === '') {
      const payload = { contact_name: name, company_name: name, contact_type: 'vendor', ...(c.associate.pan ? { pan_no: String(c.associate.pan) } : {}) };
      if (await isGstRegistered(config, accessToken)) {
        Object.assign(payload, c.associate.gstin ? { gst_treatment: 'business_gst', gst_no: String(c.associate.gstin) }
          : ((c.associate.based_in || 'india') === 'india' ? { gst_treatment: 'business_none' } : { gst_treatment: 'overseas' }));
      }
      const res = await request('POST', `https://${config.apiDomain}/books/v3/contacts${query}`, payload, authHeaders(accessToken));
      const decoded = safeJson(res.body);
      vendorId = String((decoded && decoded.contact && decoded.contact.contact_id) || '');
      if (!res.ok || vendorId === '') {
        throw new Error(`Zoho Books vendor creation for associate ${name} failed (HTTP ${res.status}): ${String(res.body).slice(0, 500)}`);
      }
    }
    await associateRepository.setFields(Number(c.associate.id), { zoho_contact_id: vendorId });
  }
  let bill = await findBill(config, accessToken, c.bill_no, vendorId);
  if (!bill) {
    const res = await request('POST', `https://${config.apiDomain}/books/v3/bills?organization_id=${encodeURIComponent(config.organizationId)}`,
      associateBillPayload(c, cost, vendorId, await accountId(config, accessToken, COST_ACCOUNTS.commission)), authHeaders(accessToken));
    bill = await findBill(config, accessToken, c.bill_no, vendorId);
    if (!bill) {
      throw new Error(`Zoho Books bill for the associate commission on order ${cost.buyer_inquiry_ref} failed (HTTP ${res.status}): ${String(res.body).slice(0, 500)}`);
    }
  }
  const openBalance = (c.tds_tax_id !== null ? c.paid_inr : c.gross_inr) + c.gst_inr;
  if (c.paid_inr > 0 && Number(bill.balance ?? openBalance) >= openBalance - 0.005) {
    const res = await request('POST', `https://${config.apiDomain}/books/v3/vendorpayments?organization_id=${encodeURIComponent(config.organizationId)}`,
      associatePaymentPayload(c, vendorId, String(bill.bill_id), config.depositAccountId), authHeaders(accessToken));
    const decoded = safeJson(res.body);
    if (!res.ok || !(decoded && decoded.vendorpayment && typeof decoded.vendorpayment.payment_id === 'string')) {
      throw new Error(`Zoho Books vendor payment for the associate commission on order ${cost.buyer_inquiry_ref} failed (HTTP ${res.status}): ${String(res.body).slice(0, 500)}`);
    }
  }
  return String(bill.bill_id);
}

/**
 * Owner rule D-212: what the associate commission bill and payment say —
 * the associate, the order (and buyer), their invoice, the
 * TDS, and the bank reference. Built from the facts.
 */
async function associateCommission(cost) {
  const db = require('../config/db');
  const associateRepository = require('../repositories/associateRepository');
  const associateDocumentRepository = require('../repositories/associateDocumentRepository');
  const incentiveRepository = require('../repositories/incentiveRepository');
  const payouts = await db.query(
    "SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND status = 'paid' AND amount_inr > 0 AND clawback_of_payout_id IS NULL ORDER BY id",
    { o: cost.order_id }
  );
  if (!payouts.length) {
    throw new Error(`Associate commission on order ${cost.buyer_inquiry_ref} not sent: no paid commission found.`);
  }
  const a = await associateRepository.find(Number(payouts[0].associate_id));
  if (!a) {
    throw new Error(`Associate commission on order ${cost.buyer_inquiry_ref} not sent: the associate is missing.`);
  }
  const inc = (await incentiveRepository.forOrder(Number(cost.order_id))) || {};
  const invoices = [];
  for (const p of payouts) {
    const inv = await associateDocumentRepository.invoiceForPayout(Number(p.id));
    if (inv && inv.reference) invoices.push(String(inv.reference));
  }
  const paid = round(payouts.reduce((sum, p) => sum + Number(p.paid_inr), 0), 2);
  const tds = round(payouts.reduce((sum, p) => sum + Number(p.tds_inr || 0), 0), 2);
  const refs = [...new Set(payouts.map((p) => String(p.paid_reference || '')).filter((r) => r !== ''))];
  const paidOn = payouts.map((p) => String(p.paid_on).slice(0, 10)).sort().pop();
  const billNo = invoices.length ? invoices.join('/').slice(0, 50) : `COMM-${cost.buyer_inquiry_ref}-${payouts[0].id}`;
  // Owner decision 2 Nov 2026 (D-235): the rate and section the app worked out for the TDS.
  const associateTds = require('./associateTds');
  const tdsSettings = await associateTds.settings();
  const taxed = payouts.find((p) => Number(p.tds_inr || 0) > 0);
  // QA batch 4, PAR-5: the first rate (and section) recorded among the taxed payouts, as PHP.
  const taxedRates = payouts.filter((p) => Number(p.tds_inr || 0) > 0).map((p) => p.tds_rate).filter((r) => r !== null && r !== undefined);
  const tdsRate = taxedRates.length ? Number(taxedRates[0]) : null;
  const taxedSections = payouts.filter((p) => Number(p.tds_inr || 0) > 0).map((p) => p.tds_section).filter((v) => v);
  const tdsSection = taxed ? (taxedSections[0] || null) : null;
  // Owner decision D-245 P3: the level fixed at PI, on the invoiced FOB of the goods.
  const associateCommission = require('./associateCommission');
  const orderRow = await require('../repositories/orderRepository').find(Number(cost.order_id));
  const fixedLevel = orderRow ? associateCommission.fixedLevel(orderRow, Number(a.id)) : null;
  const rate = fixedLevel ? associateCommission.levelLabel(fixedLevel)
    : (inc.associate_rate_pct !== null && inc.associate_rate_pct !== undefined
      ? `${Number(inc.associate_rate_pct).toFixed(2).replace(/0+$/, '').replace(/\.$/, '')}%` : '');
  const gst = Math.round(payouts.reduce((sum, p) => sum + Number(p.gst_inr || 0), 0) * 100) / 100;
  const gstTaxId = String((await require('../repositories/incentiveRepository').settings()).zoho_gst_commission_tax_id || '').trim();
  const what = `Associate commission — ${a.name} — for order ${cost.buyer_inquiry_ref} (${cost.buyer_name})`
    + (rate !== '' ? `, ${rate} on the invoiced FOB of the goods` : '')
    + (inc.associate_basis_fc ? ` (basis ${inc.associate_currency || ''} ${Number(inc.associate_basis_fc).toFixed(2)})` : '')
    + (gst > 0 ? ` · GST ${gst.toFixed(2)} on the associate's tax invoice (input credit)` : '');
  return {
    associate: a,
    bill_no: billNo,
    paid_on: paidOn,
    gross_inr: round(paid + tds, 2),
    paid_inr: paid,
    tds_inr: tds,
    reference: refs.join(', '),
    bill_description: what + (invoices.length ? ` · associate invoice ${invoices.join(', ')}` : ' · no associate invoice on file') + (tds > 0 ? ` · TDS ${tds.toFixed(2)} deducted` : ''),
    payment_description: `${associateTds.paymentDescription(a.name, [String(cost.buyer_inquiry_ref)], round(paid + tds, 2), tds, tdsRate, tdsSection, paid, refs.join(', '), tdsSettings.section, gst)} · against bill ${billNo}`,
    // With a Zoho TDS tax set in Settings the bill carries the TDS (Zoho's TDS feature) and its balance is the net; otherwise the
    // bill is the gross, the payment the net, and the TDS is left on the bill for the CA to book.
    tds_tax_id: tds > 0 && tdsSettings.zoho_tax_id !== '' ? tdsSettings.zoho_tax_id : null,
    tds_left_for_ca: tds > 0 && tdsSettings.zoho_tax_id === '',
    // D-256: GST paid on top on the associate's tax invoice, booked on the bill with this Zoho tax.
    gst_inr: gst,
    gst_tax_id: gst > 0 && gstTaxId !== '' ? gstTaxId : null,
  };
}

// ---------------------------------------------------------------- D-257 Phase 2: divisions and domestic sales

async function orderDivisionId(orderId) {
  const ds = require('./divisionService');
  if (orderId === null || orderId === undefined) return ds.EXPORT_ID;
  const row = await db.queryOne('SELECT division_id FROM orders WHERE id = :id', { id: orderId });
  return row && row.division_id ? Number(row.division_id) : ds.EXPORT_ID; // QA batch 4, PAR-3: no division = Export, as PHP
}

/**
 * The "Division" reporting tag for a Books record — only when two or more
 * divisions are active (with one it says nothing) and the tag is set up in
 * Settings: zoho_division_tag_id, and zoho_division_tag_option_<code> for
 * each division. Otherwise none, so nothing sent to Books changes.
 */
async function divisionTags(divisionId) {
  const ds = require('./divisionService');
  if (!(await ds.isMulti())) return [];
  const division = await ds.find(divisionId);
  const tagId = String((await companySettingsRepository.companyValue('zoho_division_tag_id')) || '').trim();
  const option = division ? String((await companySettingsRepository.companyValue(`zoho_division_tag_option_${String(division.code).toLowerCase()}`)) || '').trim() : '';
  return tagId !== '' && option !== '' ? [{ tag_id: tagId, tag_option_id: option }] : [];
}

/**
 * A Domestic Trade GST tax invoice as a Books invoice (pure: no network).
 * Same state: GST group (GST18 = CGST + SGST); another state: IGST18.
 * gst_treatment business_gst with the buyer's GSTIN (unregistered:
 * business_none), place of supply as Books' state code. Rule D-212: the
 * notes say what the invoice is, for which order and buyer.
 */
async function domesticInvoicePayload(orderId, contactId, taxIdByName) {
  const orderRepository = require('../repositories/orderRepository');
  const domRepo = require('../repositories/domesticOrderRepository');
  const D = require('./domesticOrderService');
  const { trimDecimals } = require('./domesticDocumentService');
  const order = await orderRepository.find(orderId);
  const dom = await domRepo.find(orderId);
  if (!order || !dom || !dom.tax_invoice_no) throw new Error('This domestic order has no tax invoice yet.');
  const t = await D.totals(orderId);
  const lineItems = [];
  for (const l of t.lines) {
    lineItems.push({
      name: [...String(l.description)].slice(0, 100).join(''),
      description: String(l.description),
      hsn_or_sac: String(l.hsn_code),
      quantity: Number(l.quantity),
      unit: String(l.unit),
      rate: Number(l.rate_inr),
      tax_id: await taxIdByName(`${t.intra_state ? 'GST' : 'IGST'}${trimDecimals(l.gst_rate_pct)}`),
    });
  }
  const due = new Date(`${String(dom.tax_invoice_date).slice(0, 10)}T00:00:00`);
  due.setDate(due.getDate() + Number(dom.payment_terms_days));
  const payload = {
    customer_id: contactId,
    invoice_number: String(dom.tax_invoice_no),
    reference_number: String(order.order_reference),
    date: String(dom.tax_invoice_date).slice(0, 10),
    due_date: require('../helpers/dbDateTime').ymd(due),
    gst_treatment: dom.buyer_gstin ? 'business_gst' : 'business_none',
    place_of_supply: await domRepo.stateAbbr(dom.place_of_supply),
    line_items: lineItems,
    // ZOH-8: the rupee round-off printed on the tax invoice, so Books' total is the invoice total.
    adjustment: round(Number(dom.round_off || 0), 2),
    adjustment_description: 'Round off',
    notes: `Domestic GST tax invoice ${dom.tax_invoice_no} for order ${order.order_reference}, ${order.company_legal_name}, place of supply ${await domRepo.stateName(dom.place_of_supply)}.`,
  };
  if (dom.buyer_gstin) payload.gst_no = String(dom.buyer_gstin);
  const tags = await divisionTags(Number(order.division_id));
  if (tags.length > 0) payload.tags = tags;
  return payload;
}

/**
 * Sends a Domestic Trade tax invoice to Books (created once; a retry finds
 * it by number). The buyer becomes a Books customer in INR with its GSTIN.
 * Live-tested in the Zoho TEST organisation on 5 Nov 2026 (Zoho change log Z-647 onwards).
 */
async function pushDomesticInvoice(orderId, userId = null) {
  const orderRepository = require('../repositories/orderRepository');
  const domRepo = require('../repositories/domesticOrderRepository');
  const order = await orderRepository.find(orderId);
  const dom = await domRepo.find(orderId);
  if (!order || !dom || !dom.tax_invoice_no) throw new Error('This domestic order has no tax invoice yet.');
  zohoGuard.assertCustomerAllowed(String(order.company_legal_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  if (String(dom.tax_invoice_no).length > 16) throw new Error(`Invoice number ${dom.tax_invoice_no} is longer than 16 characters, which Zoho Books does not accept for a GST invoice.`);
  const contactId = await domesticContact(config, accessToken, order, dom);
  const payload = await domesticInvoicePayload(orderId, contactId, (name) => taxId(config, accessToken, name));
  const existing = await findInvoiceByNumber(config, accessToken, String(dom.tax_invoice_no));
  // QA batch 4, ZOH-24: never adopt another customer's invoice that happens to carry the same number.
  if (existing && existing.customer_id !== undefined && String(existing.customer_id) !== String(contactId)) {
    throw new Error(`Invoice number ${dom.tax_invoice_no} is already used in Zoho Books for ${existing.customer_name || 'another customer'}. Nothing was changed.`);
  }
  let invoiceId;
  if (existing) {
    invoiceId = String(existing.invoice_id);
    if (String(existing.status || '') === 'draft') await markInvoiceSent(config, accessToken, invoiceId); // a retry after the app stopped before marking it
  } else {
    const response = await request('POST', `https://${config.apiDomain}/books/v3/invoices${query}&ignore_auto_number_generation=true`, payload, authHeaders(accessToken));
    const decoded = safeJson(response.body);
    invoiceId = String((decoded && decoded.invoice && decoded.invoice.invoice_id) || '');
    if (invoiceId === '') throw new Error(`Zoho Books invoice creation failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
    // D-257 Zoho: sent (no e-mail goes out), so the buyer's receipts can be applied to it.
    await markInvoiceSent(config, accessToken, invoiceId);
  }
  await domRepo.update(orderId, { zoho_invoice_id: invoiceId }, userId);
  return invoiceId;
}

// ---------------------------------------------------------------- D-257 Phase 3: imports

/**
 * Books accounts for an import (names to be confirmed by the CA): the goods
 * and the customs duty go to stock (they are part of the landed cost); the
 * IGST paid at customs is held until it is taken as input credit.
 */
const IMPORT_ACCOUNTS = {
  // Live test 5 Nov 2026: Books' "Inventory Asset" is a stock account that needs an item on every line, so the
  // landed cost goes to an "Imported Goods Stock" asset account instead (name to be confirmed by the CA).
  goods: 'Imported Goods Stock',
  duty: 'Imported Goods Stock',
  igst_paid: 'IGST Paid on Import',
  igst_credit: 'Input IGST',
};

/**
 * The foreign supplier's bill for an import (pure: no network), in the
 * supplier's currency at the customs exchange rate. gst_treatment
 * overseas: no GST on the bill itself (IGST is paid at customs).
 * Rule D-212: the notes say what the bill is, from whom, for which import.
 */
async function importBillPayload(s, lines, vendorId, accountIdByName) {
  const items = [];
  for (const l of lines) {
    items.push({
      account_id: await accountIdByName(IMPORT_ACCOUNTS.goods),
      name: [...String(l.description)].slice(0, 100).join(''),
      description: String(l.description),
      hsn_or_sac: String(l.hsn_code),
      quantity: Number(l.quantity),
      rate: Number(l.unit_price_fc),
    });
  }
  const payload = {
    vendor_id: vendorId,
    bill_number: String(s.reference),
    reference_number: String(s.bl_no || ''),
    date: String(s.bl_date || s.po_date),
    currency_code: String(s.currency_code),
    exchange_rate: Number(s.customs_fx_rate),
    gst_treatment: 'overseas',
    line_items: items,
    notes: `Import purchase from ${s.supplier_name} (${s.supplier_country}) against import PO ${s.reference}`
      + (s.bl_no ? `, BL ${s.bl_no}` : '') + (s.boe_no ? `, bill of entry ${s.boe_no}` : '') + '.',
  };
  const tags = await divisionTags(Number(s.division_id));
  if (tags.length) payload.tags = tags;
  return payload;
}

/**
 * The customs duty of an import as a Books bill from Customs (pure: no
 * network): basic customs duty and social welfare surcharge (added to the
 * stock value) and the IGST paid on import (held for input credit).
 */
async function customsDutyBillPayload(s, vendorId, accountIdByName) {
  if (!s.boe_no || s.bcd_total === null || s.bcd_total === undefined) throw new Error('This import is not cleared yet.');
  const what = `import PO ${s.reference}, bill of entry ${s.boe_no} dated ${s.boe_date} at ${s.port_code}`;
  const payload = {
    vendor_id: vendorId,
    bill_number: `BE-${s.boe_no}`,
    reference_number: String(s.reference),
    date: String(s.boe_date),
    line_items: [
      { account_id: await accountIdByName(IMPORT_ACCOUNTS.duty), name: 'Basic customs duty', description: `Basic customs duty on ${what}`, quantity: 1, rate: Number(s.bcd_total) },
      { account_id: await accountIdByName(IMPORT_ACCOUNTS.duty), name: 'Social welfare surcharge', description: `Social welfare surcharge on ${what}`, quantity: 1, rate: Number(s.sws_total) },
      { account_id: await accountIdByName(IMPORT_ACCOUNTS.igst_paid), name: 'IGST paid on import', description: `IGST paid on import on ${what} (input credit)`, quantity: 1, rate: Number(s.igst_total) },
    ],
    notes: `Customs duty and IGST on ${what}, cleared through ${s.cha_name}.`,
  };
  const tags = await divisionTags(Number(s.division_id));
  if (tags.length) payload.tags = tags;
  return payload;
}

/** The journal that takes the IGST paid on import as input credit (pure: no network): debit Input IGST, credit IGST Paid on Import. */
async function importIgstCreditJournalPayload(s, accountIdByName) {
  if (!s.igst_credit_on || s.igst_total === null || s.igst_total === undefined) throw new Error('The IGST credit of this import is not recorded yet.');
  const amount = round(Number(s.igst_total), 2);
  const G = require('./gstCalculator');
  const payload = {
    journal_date: String(s.igst_credit_on),
    reference_number: `BE-${s.boe_no}`,
    notes: `IGST paid on import taken as input credit: import PO ${s.reference}, bill of entry ${s.boe_no} dated ${s.boe_date}, ₹${G.inr(String(s.igst_total))}.`,
    line_items: [
      { account_id: await accountIdByName(IMPORT_ACCOUNTS.igst_credit), debit_or_credit: 'debit', amount, description: `Input IGST on bill of entry ${s.boe_no}` },
      { account_id: await accountIdByName(IMPORT_ACCOUNTS.igst_paid), debit_or_credit: 'credit', amount, description: `IGST paid on import, bill of entry ${s.boe_no}` },
    ],
  };
  const tags = await divisionTags(Number(s.division_id));
  if (tags.length) payload.tags = tags;
  return payload;
}

/**
 * Sends a cleared import to Books: the supplier's bill and the customs duty
 * bill (each created once; a retry finds it by number). Live test in the Zoho
 * TEST organisation on 5 Nov 2026 (Z-647 onwards).
 */
async function pushImport(shipmentId, userId = null) {
  const importRepository = require('../repositories/importRepository');
  const s = await importRepository.find(shipmentId);
  if (!s || !s.boe_no || s.bcd_total === null) throw new Error('This import is not cleared yet.');
  assertVendorAllowed(String(s.supplier_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const account = (name) => accountId(config, accessToken, name);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const vendor = (name, currency) => namedContact(config, accessToken, name, 'vendor', currency);
  const supplierId = await vendor(String(s.supplier_name), String(s.currency_code));
  const customsId = await vendor(zohoGuard.isProduction() ? 'Indian Customs' : `${zohoGuard.TEST_PREFIX}Indian Customs`, null);
  const ids = {};
  const jobs = [
    ['bill_id', String(s.reference), supplierId, await importBillPayload(s, await importRepository.lines(shipmentId), supplierId, account), 'zoho_bill_id'],
    ['duty_bill_id', `BE-${s.boe_no}`, customsId, await customsDutyBillPayload(s, customsId, account), 'zoho_duty_bill_id'],
  ];
  if (importClearanceTotal(s) > 0) {
    // D-257 Zoho: the clearance charges as a bill from the CHA (landed cost).
    const chaName = String(s.cha_name || '').trim() !== '' ? String(s.cha_name) : 'Import clearance agent';
    assertVendorAllowed(chaName);
    const chaId = await namedContact(config, accessToken, chaName, 'vendor', null);
    jobs.push(['clearance_bill_id', `CLR-${s.boe_no}`, chaId, await importClearanceBillPayload(s, chaId, account), 'zoho_clearance_bill_id']);
  }
  for (const [key, number, vendorId, payload, column] of jobs) {
    let existing = s[column] ? { bill_id: s[column] } : await findBill(config, accessToken, number, vendorId);
    if (!existing) {
      const response = await request('POST', `https://${config.apiDomain}/books/v3/bills${query}`, payload, authHeaders(accessToken));
      existing = await findBill(config, accessToken, number, vendorId);
      if (!existing) throw new Error(`Zoho Books bill ${number} failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
    }
    ids[key] = String(existing.bill_id);
    await importRepository.update(shipmentId, { [column]: ids[key] }, userId);
  }
  return ids;
}

// ---------------------------------------------------------------- D-257 Phase 4: merchant trade

/** Books accounts for a merchant trade deal (names to be confirmed by the CA): no GST, the goods never enter India. */
const MERCHANT_ACCOUNTS = { sales: 'Merchanting Trade Sales', purchases: 'Merchanting Trade Purchases' };

/**
 * Our sale invoice to the foreign buyer as a Books invoice (pure: no
 * network), in the sale currency at the rate realised on the buyer's money
 * (left to Books until money has come in). gst_treatment overseas, no GST.
 * Rule D-212: the notes say what the invoice is, for which deal, buyer and route.
 */
async function merchantInvoicePayload(d, lines, customerId, accountIdByName) {
  if (!d.sale_invoice_no) throw new Error('This deal has no sale invoice yet.');
  const items = [];
  for (const l of lines) {
    items.push({
      account_id: await accountIdByName(MERCHANT_ACCOUNTS.sales),
      name: [...String(l.description)].slice(0, 100).join(''),
      description: String(l.description),
      hsn_or_sac: String(l.hsn_code),
      quantity: Number(l.quantity),
      rate: Number(l.sell_price_fc),
    });
  }
  const f = await require('./merchantTradeService').figures(d);
  const bl = d.bl_no ? `, BL ${Number(d.switch_bl) ? `${d.switch_bl_no} (switch BL)` : d.bl_no}` : '';
  const payload = {
    customer_id: customerId,
    invoice_number: String(d.sale_invoice_no),
    reference_number: String(d.reference),
    date: String(d.invoice_date).slice(0, 10),
    currency_code: String(d.sale_currency),
    gst_treatment: 'overseas',
    line_items: items,
    notes: `Merchant trade sale invoice ${d.sale_invoice_no} for deal ${d.reference} to ${d.buyer_name} (${d.buyer_country}): goods from ${d.origin_country} shipped directly to the buyer${bl}; they do not enter India.`,
  };
  if (f.sell_rate !== null) payload.exchange_rate = Number(f.sell_rate);
  const tags = await divisionTags(Number(d.division_id));
  if (tags.length > 0) payload.tags = tags;
  return payload;
}

/** The foreign supplier's bill for a merchant trade deal (pure: no network), at the rate realised on the payments to the supplier. */
async function merchantBillPayload(d, lines, vendorId, accountIdByName) {
  const items = [];
  for (const l of lines) {
    items.push({
      account_id: await accountIdByName(MERCHANT_ACCOUNTS.purchases),
      name: [...String(l.description)].slice(0, 100).join(''),
      description: String(l.description),
      hsn_or_sac: String(l.hsn_code),
      quantity: Number(l.quantity),
      rate: Number(l.buy_price_fc),
    });
  }
  const f = await require('./merchantTradeService').figures(d);
  const payload = {
    vendor_id: vendorId,
    bill_number: String(d.po_no),
    reference_number: String(d.reference),
    date: String(d.bl_date || d.deal_date).slice(0, 10),
    currency_code: String(d.purchase_currency),
    gst_treatment: 'overseas',
    line_items: items,
    notes: `Merchant trade purchase from ${d.supplier_name} (${d.supplier_country}) against PO ${d.po_no}, deal ${d.reference}${d.bl_no ? `, BL ${d.bl_no}` : ''}; goods shipped directly to the buyer abroad.`,
  };
  if (f.buy_rate !== null) payload.exchange_rate = Number(f.buy_rate);
  const tags = await divisionTags(Number(d.division_id));
  if (tags.length > 0) payload.tags = tags;
  return payload;
}

/**
 * Sends a merchant trade deal to Books: the sale invoice (customer = the
 * foreign buyer) and the supplier's bill, each created once (a retry finds
 * it by number). Live-tested in the Zoho TEST organisation on  5 Nov 2026 (Z-647 onwards).
 */
async function pushMerchantDeal(dealId, userId = null) {
  const merchantRepository = require('../repositories/merchantTradeRepository');
  const d = await merchantRepository.find(dealId);
  if (!d || !d.sale_invoice_no) throw new Error('This deal has no sale invoice yet.');
  zohoGuard.assertCustomerAllowed(String(d.buyer_name));
  assertVendorAllowed(String(d.supplier_name));
  if (String(d.sale_invoice_no).length > 16) throw new Error(`Invoice number ${d.sale_invoice_no} is longer than 16 characters, which Zoho Books does not accept.`);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const account = (name) => accountId(config, accessToken, name);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const contact = (name, type, currency) => namedContact(config, accessToken, name, type, currency);
  const lines = await merchantRepository.lines(dealId);
  const customerId = await contact(String(d.buyer_name), 'customer', String(d.sale_currency));
  let invoiceId;
  const existing = d.zoho_invoice_id ? { invoice_id: d.zoho_invoice_id } : await findInvoiceByNumber(config, accessToken, String(d.sale_invoice_no));
  // QA batch 4, ZOH-24: never adopt another customer's invoice that happens to carry the same number.
  if (existing && existing.customer_id !== undefined && String(existing.customer_id) !== String(customerId)) {
    throw new Error(`Invoice number ${d.sale_invoice_no} is already used in Zoho Books for ${existing.customer_name || 'another customer'}. Nothing was changed.`);
  }
  if (existing) {
    invoiceId = String(existing.invoice_id);
    if (String(existing.status || '') === 'draft') await markInvoiceSent(config, accessToken, invoiceId);
  } else {
    const response = await request('POST', `https://${config.apiDomain}/books/v3/invoices${query}&ignore_auto_number_generation=true`, await merchantInvoicePayload(d, lines, customerId, account), authHeaders(accessToken));
    const decoded = safeJson(response.body);
    invoiceId = String((decoded && decoded.invoice && decoded.invoice.invoice_id) || '');
    if (invoiceId === '') throw new Error(`Zoho Books invoice creation failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
    // D-257 Zoho: sent (no e-mail goes out), so the buyer's payments can be applied to it.
    await markInvoiceSent(config, accessToken, invoiceId);
  }
  await merchantRepository.update(dealId, { zoho_invoice_id: invoiceId }, userId);
  const vendorId = await contact(String(d.supplier_name), 'vendor', String(d.purchase_currency));
  let bill = d.zoho_bill_id ? { bill_id: d.zoho_bill_id } : await findBill(config, accessToken, String(d.po_no), vendorId);
  if (!bill) {
    const response = await request('POST', `https://${config.apiDomain}/books/v3/bills${query}`, await merchantBillPayload(d, lines, vendorId, account), authHeaders(accessToken));
    bill = await findBill(config, accessToken, String(d.po_no), vendorId);
    if (!bill) throw new Error(`Zoho Books bill ${d.po_no} failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
  }
  await merchantRepository.update(dealId, { zoho_bill_id: String(bill.bill_id) }, userId);
  return { invoice_id: invoiceId, bill_id: String(bill.bill_id) };
}

// ---------------------------------------------------------------- D-257 Zoho: the new divisions' money

const money2 = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fixed2 = (v) => round(Number(v), 2).toFixed(2);

/**
 * A named Books contact (customer or vendor) found by its exact name, or
 * created (foreign-currency contacts as overseas). ZOH-7: Books takes an
 * invoice's / bill's currency from its contact — a same-named contact in
 * another currency gets its own "<name> (<currency>)" contact instead.
 */
async function namedContact(config, accessToken, name, type, currency) {
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const find = async (n) => {
    const rows = await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts${query}&contact_type=${type}&contact_name=${encodeURIComponent(n)}`, 'contacts');
    return rows.find((row) => String(row.contact_name || '') === n) || null;
  };
  let row = await find(name);
  if (row && currency !== null && row.currency_code && String(row.currency_code) !== currency) {
    name = `${name} (${currency})`;
    row = await find(name);
  }
  if (row) return String(row.contact_id);
  const payload = { contact_name: name, company_name: name, contact_type: type, ...(currency !== null ? { currency_id: await currencyId(config, accessToken, currency), gst_treatment: 'overseas' } : {}) };
  const response = await request('POST', `https://${config.apiDomain}/books/v3/contacts${query}`, payload, authHeaders(accessToken));
  const decoded = safeJson(response.body);
  const id = decoded && decoded.contact && decoded.contact.contact_id;
  if (!response.ok || typeof id !== 'string') throw new Error(`Zoho Books ${type} creation failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
  return id;
}

/** Books' id of a currency (a contact takes currency_id, not the code — live test 5 Nov 2026). */
async function currencyId(config, accessToken, code) {
  const rows = await getList(accessToken, `https://${config.apiDomain}/books/v3/settings/currencies?organization_id=${encodeURIComponent(config.organizationId)}`, 'currencies');
  const found = rows.find((r) => r.currency_code === code);
  if (!found) throw new Error(`Currency ${code} is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.`);
  return String(found.currency_id);
}

/** Duplicate guard for a retry: a payment of this contact with the same bank reference and amount is the same payment. */
async function existingPayment(config, accessToken, vendor, contactId, reference, amount) {
  const [path, field] = vendor ? ['vendorpayments', 'vendor_id'] : ['customerpayments', 'customer_id'];
  const url = `https://${config.apiDomain}/books/v3/${path}?organization_id=${encodeURIComponent(config.organizationId)}&${field}=${encodeURIComponent(contactId)}&reference_number=${encodeURIComponent(reference)}`;
  const rows = await getList(accessToken, url, path);
  const found = rows.find((r) => String(r.reference_number || '') === reference && Math.abs(Number(r.amount || 0) - amount) <= 0.01);
  return found ? String(found.payment_id) : null;
}

/** A Books invoice or bill by id, from the list (its balance, total and status). */
async function documentRow(config, accessToken, kind, number, id) {
  const url = `https://${config.apiDomain}/books/v3/${kind}s?organization_id=${encodeURIComponent(config.organizationId)}&${kind}_number=${encodeURIComponent(number)}`;
  const rows = await getList(accessToken, url, `${kind}s`);
  return rows.find((r) => String(r[`${kind}_id`] || '') === id) || null;
}

/** Marks a new Books invoice as sent (no e-mail goes out), so payments can be applied to it. */
async function markInvoiceSent(config, accessToken, invoiceId) {
  const response = await request('POST', `https://${config.apiDomain}/books/v3/invoices/${encodeURIComponent(invoiceId)}/status/sent?organization_id=${encodeURIComponent(config.organizationId)}`, {}, authHeaders(accessToken));
  if (!response.ok && !String(response.body).toLowerCase().includes('already')) {
    throw new Error(`Zoho Books could not mark invoice ${invoiceId} as sent (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
  }
}

/**
 * The payment payload for the new divisions (pure: no network). Rule D-212:
 * the description is the app's own; the bank reference is the reference
 * number. A foreign-currency payment goes in its own currency at the rate
 * the bank gave.
 */
function divisionPaymentPayload(p, depositAccountId) {
  const payload = {
    [p.vendor ? 'vendor_id' : 'customer_id']: p.contact_id,
    payment_mode: p.vendor ? 'Bank Transfer' : 'banktransfer',
    amount: round(p.amount, 2),
    date: p.date,
    reference_number: p.reference,
    description: p.description,
    [p.vendor ? 'paid_through_account_id' : 'account_id']: depositAccountId,
  };
  if (p.rate !== null) payload.exchange_rate = round(p.rate, 6);
  if (p.apply_to !== null && p.apply_amount > 0) {
    payload[p.vendor ? 'bills' : 'invoices'] = [{ [p.vendor ? 'bill_id' : 'invoice_id']: p.apply_to, amount_applied: round(p.apply_amount, 2) }];
  }
  return payload;
}

/**
 * Sends one payment of a new division and applies it to its invoice or bill
 * (up to what that still owes; any excess stays unapplied as an advance). A
 * retry finds the payment by its bank reference.
 */
async function sendDivisionPayment(config, accessToken, vendor, contactId, docKind, docNumber, docId, amount, date, reference, description, rate) {
  const row = await documentRow(config, accessToken, docKind, docNumber, docId);
  if (!row) throw new Error(`${docKind.charAt(0).toUpperCase()}${docKind.slice(1)} ${docNumber} was not found in Zoho Books, so payment ${reference} was not sent. Sync again; if it stays, check the ${docKind} in Books.`);
  // The payment goes to the contact the invoice / bill is on (Books refuses any other — live test 5 Nov 2026).
  contactId = String(row[vendor ? 'vendor_id' : 'customer_id'] || contactId);
  const existing = await existingPayment(config, accessToken, vendor, contactId, reference, round(amount, 2));
  if (existing !== null) return { payment_id: existing, applied: 0 };
  const applied = round(Math.min(amount, Number(row.balance || 0)), 2);
  const payload = divisionPaymentPayload({ contact_id: contactId, vendor, amount, date, reference, description, rate, apply_to: docId, apply_amount: applied }, config.depositAccountId);
  const path = vendor ? 'vendorpayments' : 'customerpayments';
  const response = await request('POST', `https://${config.apiDomain}/books/v3/${path}?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(response.body);
  const id = decoded && decoded[vendor ? 'vendorpayment' : 'payment'] && decoded[vendor ? 'vendorpayment' : 'payment'].payment_id;
  if (!response.ok || typeof id !== 'string') throw new Error(`Zoho Books ${vendor ? 'vendor' : 'customer'} payment ${reference} failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
  return { payment_id: id, applied };
}

/**
 * D-257 Zoho (ZOH-6/7): the buyer's INR, GST-registered Books customer for
 * Domestic Trade / local sales — kept apart from its export (overseas,
 * foreign-currency) customer, remembered in clients.zoho_contact_id_inr. A
 * same-named contact that is not INR, or is overseas, gets an
 * "<name> (INR)" contact instead.
 */
async function domesticContact(config, accessToken, order, dom) {
  const domRepo = require('../repositories/domesticOrderRepository');
  const client = await clientRepository.find(order.client_id);
  if (client && client.zoho_contact_id_inr) return String(client.zoho_contact_id_inr);
  const query = `?organization_id=${encodeURIComponent(config.organizationId)}`;
  const name = String(order.company_legal_name);
  let id = null;
  for (const candidate of [name, `${name} (INR)`]) {
    const rows = await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts${query}&contact_type=customer&contact_name=${encodeURIComponent(candidate)}`, 'contacts');
    let existing = null;
    for (const row of rows) if (String(row.contact_name || '') === candidate) existing = row;
    if (!existing) {
      const contact = {
        contact_name: candidate,
        company_name: name,
        contact_type: 'customer',
        currency_code: 'INR',
        gst_treatment: dom.buyer_gstin ? 'business_gst' : 'business_none',
        place_of_contact: await domRepo.stateAbbr(dom.buyer_state_code || dom.place_of_supply),
      };
      if (dom.buyer_gstin) contact.gst_no = String(dom.buyer_gstin);
      const response = await request('POST', `https://${config.apiDomain}/books/v3/contacts${query}`, contact, authHeaders(accessToken));
      const decoded = safeJson(response.body);
      id = String((decoded && decoded.contact && decoded.contact.contact_id) || '');
      if (id === '') throw new Error(`Zoho Books contact creation failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
      break;
    }
    if (String(existing.currency_code || 'INR') === 'INR' && String(existing.gst_treatment || '') !== 'overseas') {
      id = String(existing.contact_id);
      break;
    }
  }
  if (id === null) throw new Error(`Zoho Books already has "${name}" and "${name} (INR)" as customers that are not INR / GST — rename one in Books, then send again.`);
  await db.execute('UPDATE clients SET zoho_contact_id_inr = :z WHERE id = :id', { z: id, id: Number(order.client_id) });
  return id;
}

/**
 * Domestic Trade (and Import & Distribution's local sale): a buyer's receipt
 * as a Books customer payment, applied to the GST tax invoice. The invoice
 * must be in Books first.
 */
async function pushDomesticReceipt(payment) {
  const orderRepository = require('../repositories/orderRepository');
  const domRepo = require('../repositories/domesticOrderRepository');
  const orderId = Number(payment.order_id);
  const order = await orderRepository.find(orderId);
  const dom = await domRepo.find(orderId);
  if (!order || !dom || !dom.zoho_invoice_id) throw new Error('The tax invoice of this order is not in Zoho Books yet.');
  zohoGuard.assertCustomerAllowed(String(order.company_legal_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const contactId = await domesticContact(config, accessToken, order, dom);
  return sendDivisionPayment(config, accessToken, false, contactId, 'invoice', String(dom.tax_invoice_no), String(dom.zoho_invoice_id),
    Number(payment.amount_inr), String(payment.received_on).slice(0, 10), String(payment.bank_reference), String(payment.description), null);
}

/**
 * Import & Distribution: a payment to the supplier abroad (advance, balance
 * or LC) as a Books vendor payment in the supplier's currency, at the rate
 * the bank gave, applied to the import supplier bill.
 */
async function pushImportPayment(payment) {
  const importRepository = require('../repositories/importRepository');
  const s = await importRepository.find(Number(payment.shipment_id));
  if (!s || !s.zoho_bill_id) throw new Error('The supplier bill of this import is not in Zoho Books yet.');
  assertVendorAllowed(String(s.supplier_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const vendorId = await namedContact(config, accessToken, String(s.supplier_name), 'vendor', String(s.currency_code));
  return sendDivisionPayment(config, accessToken, true, vendorId, 'bill', String(s.reference), String(s.zoho_bill_id),
    Number(payment.amount_fc), String(payment.paid_on).slice(0, 10), String(payment.bank_reference), String(payment.description), Number(payment.fx_rate));
}

/** Rule D-212: the customs duty payment's description, built from the facts. */
function importDutyPaymentDescription(s) {
  const G = require('./gstCalculator');
  const total = fixed2(Number(s.bcd_total) + Number(s.sws_total) + Number(s.igst_total));
  return `Customs duty and IGST paid to Indian Customs for import PO ${s.reference}, bill of entry ${s.boe_no} dated ${String(s.boe_date).slice(0, 10)} at ${s.port_code}: `
    + `basic customs duty ₹${G.inr(String(s.bcd_total))} + social welfare surcharge ₹${G.inr(String(s.sws_total))}`
    + ` + IGST ₹${G.inr(String(s.igst_total))} = ₹${G.inr(total)}, against customs duty bill BE-${s.boe_no}, challan / bank ref ${s.duty_bank_reference}`;
}

/** Import & Distribution: the customs duty and IGST payment as a Books vendor payment to Customs, applied to the customs duty bill. */
async function pushImportDutyPayment(shipmentId) {
  const importRepository = require('../repositories/importRepository');
  const s = await importRepository.find(shipmentId);
  if (!s || !s.zoho_duty_bill_id || !s.duty_paid_on) throw new Error('The customs duty bill or its payment is not recorded yet.');
  assertVendorAllowed(String(s.supplier_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const customsId = await namedContact(config, accessToken, zohoGuard.isProduction() ? 'Indian Customs' : `${zohoGuard.TEST_PREFIX}Indian Customs`, 'vendor', null);
  const amount = round(Number(s.bcd_total) + Number(s.sws_total) + Number(s.igst_total), 2);
  return sendDivisionPayment(config, accessToken, true, customsId, 'bill', `BE-${s.boe_no}`, String(s.zoho_duty_bill_id),
    amount, String(s.duty_paid_on).slice(0, 10), String(s.duty_bank_reference), importDutyPaymentDescription(s), null);
}

/** Import & Distribution: the journal taking the import IGST as input credit (once the credit is recorded). */
async function pushImportIgstJournal(shipmentId) {
  const importRepository = require('../repositories/importRepository');
  const s = await importRepository.find(shipmentId);
  if (!s) throw new Error(`Import #${shipmentId} not found.`);
  assertVendorAllowed(String(s.supplier_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const payload = await importIgstCreditJournalPayload(s, (name) => accountId(config, accessToken, name));
  if (!zohoGuard.isProduction()) payload.notes = `${zohoGuard.TEST_PREFIX} ${payload.notes}`;
  const response = await request('POST', `https://${config.apiDomain}/books/v3/journals?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(response.body);
  const id = decoded && decoded.journal && decoded.journal.journal_id;
  if (!response.ok || !id) throw new Error(`Zoho Books IGST credit journal for import ${s.reference} failed (HTTP ${response.status}): ${String(response.body).slice(0, 500)}`);
  return String(id);
}

/**
 * Merchant Trade: one payment leg — the buy leg as a Books vendor payment on
 * the supplier's bill, the sell leg as a customer payment on the sale
 * invoice — each in its own currency at the rate the bank gave.
 */
async function pushMerchantPayment(payment) {
  const merchantRepository = require('../repositories/merchantTradeRepository');
  const d = await merchantRepository.find(Number(payment.deal_id));
  const buy = String(payment.leg) === 'buy';
  if (!d || !d[buy ? 'zoho_bill_id' : 'zoho_invoice_id']) throw new Error(`The ${buy ? "supplier's bill" : 'sale invoice'} of this deal is not in Zoho Books yet.`);
  zohoGuard.assertCustomerAllowed(String(d.buyer_name));
  assertVendorAllowed(String(d.supplier_name));
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const contactId = buy
    ? await namedContact(config, accessToken, String(d.supplier_name), 'vendor', String(d.purchase_currency))
    : await namedContact(config, accessToken, String(d.buyer_name), 'customer', String(d.sale_currency));
  return sendDivisionPayment(config, accessToken, buy, contactId, buy ? 'bill' : 'invoice', String(buy ? d.po_no : d.sale_invoice_no),
    String(d[buy ? 'zoho_bill_id' : 'zoho_invoice_id']), Number(payment.amount_fc), String(payment.moved_on).slice(0, 10), String(payment.bank_reference), String(payment.description), Number(payment.fx_rate));
}

/**
 * The "App vs Zoho" check for a new division's record (reads only): the
 * invoice / bills and payments the app holds, beside what Books holds.
 */
async function matchRecords(records) {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const cache = {};
  const rows = [];
  for (const r of records) {
    const fmt = (v) => (v === null ? null : `${r.unit} ${money2(v)}`);
    if (!r.zoho_id) {
      rows.push({ group: r.group, item: r.item, app: fmt(r.amount), books: null, status: 'not_sent', note: 'Goes at the next sync (or see the Zoho Sync Log).' });
      continue;
    }
    let books = null;
    if (r.kind === 'invoice' || r.kind === 'bill') {
      const row = await documentRow(config, accessToken, r.kind, r.number, String(r.zoho_id));
      books = row ? Number(row.total || 0) : null;
    } else if (r.kind === 'journal') {
      if (!cache.journal) cache.journal = await totalsById('journal');
      books = cache.journal[String(r.zoho_id)] !== undefined ? cache.journal[String(r.zoho_id)] : null;
    } else {
      const key = `${r.vendor ? 'v' : 'c'}:${r.contact}`;
      if (!cache[key]) {
        cache[key] = {};
        if (r.contact !== null) {
          const recs = await contactRecords(String(r.contact), r.vendor);
          for (const p of recs[r.vendor ? 'vendorpayments' : 'customerpayments']) cache[key][String(p.payment_id)] = Number(p.amount || 0);
        }
      }
      books = cache[key][String(r.zoho_id)] !== undefined ? cache[key][String(r.zoho_id)] : null;
    }
    const status = books === null ? 'missing' : (Math.abs(books - r.amount) <= 0.01 ? 'match' : 'different');
    rows.push({ group: r.group, item: r.item, app: fmt(r.amount), books: fmt(books), status, note: status === 'missing' ? 'Sent by the app, but Books no longer has it.' : '' });
  }
  return rows;
}

/** The Books contact id of a named contact, if it exists (reads only; for the match check). */
async function contactIdByName(name, type) {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const rows = await getList(accessToken, `https://${config.apiDomain}/books/v3/contacts?organization_id=${encodeURIComponent(config.organizationId)}&contact_type=${type}&contact_name=${encodeURIComponent(name)}`, 'contacts');
  const found = rows.find((row) => String(row.contact_name || '') === name);
  return found ? String(found.contact_id) : null;
}

/** The import's clearance charges (₹), by line: freight, insurance, CHA, port and terminal, other. */
const IMPORT_CLEARANCE_CHARGES = {
  freight_inr: 'Freight',
  insurance_inr: 'Insurance',
  cha_inr: 'CHA charges',
  port_inr: 'Port and terminal charges',
  other_inr: 'Other clearance charges',
};

function importClearanceTotal(s) {
  let t = 0;
  for (const k of Object.keys(IMPORT_CLEARANCE_CHARGES)) t += Number(s[k] || 0);
  return round(t, 2);
}

/**
 * D-257 Zoho: the clearance charges of an import (pure: no network) as one
 * Books bill from the CHA, to stock (part of the landed cost). Rule D-212:
 * each line and the notes say what it is and for which import and bill of entry.
 */
async function importClearanceBillPayload(s, vendorId, accountIdByName) {
  const what = `import PO ${s.reference}, bill of entry ${s.boe_no} dated ${String(s.boe_date).slice(0, 10)}`;
  const lines = [];
  for (const [k, label] of Object.entries(IMPORT_CLEARANCE_CHARGES)) {
    if (Number(s[k] || 0) > 0) lines.push({ account_id: await accountIdByName(IMPORT_ACCOUNTS.goods), name: label, description: `${label} on ${what}`, quantity: 1, rate: round(Number(s[k]), 2) });
  }
  const payload = {
    vendor_id: vendorId,
    bill_number: `CLR-${s.boe_no}`,
    reference_number: String(s.reference),
    date: String(s.clearance_date || s.boe_date).slice(0, 10),
    line_items: lines,
    notes: `Clearance charges on ${what}, through ${s.cha_name} (part of the landed cost).`,
  };
  const tags = await divisionTags(Number(s.division_id));
  if (tags.length) payload.tags = tags;
  return payload;
}

module.exports = {
  associateBillPayload,
  associatePaymentPayload,
  // Owner 2 Nov 2026: exported for the bank-charges payload test (remittanceDifference.test.js).
  paymentPayload,
  BILL_ACCOUNTS, EXPENSE_ACCOUNTS, PAID_THROUGH_ACCOUNTS, COST_ACCOUNTS, BENEFIT_INCOME_ACCOUNT, BENEFIT_SCRIP_ACCOUNT, GST_REFUND_ACCOUNT, pushGstRefundJournal, payoutCostJournalPayload, pushPayoutCostJournal,
  isEnabled, syncStartDate, pushRevenuePayment, applyPaymentToInvoice, pushInvoice, pushBill, pushVendorPayment, applyVendorPayment, pushExpense,
  pushCostEntry, pushAssociateCommission, associateCommission, pushBenefitJournal, listTestJournals, contactRecords, readRecord, totalsById, invoiceByNumber,
  restoreVendorPayment, restoreRevenuePayment,
  testConnection, listExpenses, listTestContacts, listTestExpenses, deleteRecord, setTransport, forgetTokensInMemoryForTests,
  // Owner decision 2 Nov 2026 (D-238): clients and products kept in Books.
  pushCustomerContact, customerPayload, pushItem, itemPayload, markInactive,
  // D-257 Phase 2: Domestic Trade invoices and the Division tag.
  divisionTags, domesticInvoicePayload, pushDomesticInvoice,
  // D-257 Phase 3: imports (supplier bill, customs duty bill, IGST credit journal).
  IMPORT_ACCOUNTS, importBillPayload, customsDutyBillPayload, importIgstCreditJournalPayload, pushImport,
  // D-257 Phase 4: merchant trade (sale invoice to the foreign buyer, the foreign supplier's bill).
  MERCHANT_ACCOUNTS, merchantInvoicePayload, merchantBillPayload, pushMerchantDeal,
  // D-257 Zoho: the new divisions' payments, journals, clearance bill and the App vs Zoho check.
  divisionPaymentPayload, pushDomesticReceipt, pushImportPayment, importDutyPaymentDescription, pushImportDutyPayment, pushImportIgstJournal,
  pushMerchantPayment, matchRecords, contactIdByName, IMPORT_CLEARANCE_CHARGES, importClearanceTotal, importClearanceBillPayload,
};
