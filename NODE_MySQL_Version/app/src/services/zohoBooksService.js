'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const clientRepository = require('../repositories/clientRepository');
const lookupRepository = require('../repositories/lookupRepository');
const zohoGuard = require('./zohoGuard');
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

/** Tests only: answer every Zoho request with fn(method, url, payload, headers, formEncoded) instead of the network (null restores real HTTP). */
function setTransport(fn) {
  transport = fn;
  tokens.clear();
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
  if (existing) return String(existing.bill_id);

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
      description: `${what} for order ${bill.buyer_inquiry_ref}: taxable ${money(bill.taxable_inr)} + GST ${money(bill.gst_inr)}${bill.note ? `. ${bill.note}` : ''}`,
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
  const url = `https://${config.apiDomain}/books/v3/journals${journalId !== null ? `/${encodeURIComponent(journalId)}` : ''}?organization_id=${encodeURIComponent(config.organizationId)}`;
  const res = await request(journalId !== null ? 'PUT' : 'POST', url, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const id = decoded && decoded.journal && decoded.journal.journal_id;
  if (!res.ok || id === undefined || id === null) {
    throw new Error(`Zoho Books journal for export benefit ${what} failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
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
  if (bill.vendor_kind === 'supplier') {
    const leg = payment.leg === 'balance' ? 'balance' : 'advance';
    return `Supplier ${leg} payment for order ${order}${bill.supplier_po_reference ? ` · Supplier PO ${bill.supplier_po_reference}` : ''} · against ${billLabel}`;
  }
  const service = LOGISTICS_SERVICES[bill.bill_for] || 'Logistics';
  const kind = bill.payment_kind === 'advance' ? 'advance' : 'final';
  return `${service} — ${kind} payment for order ${order} · against ${billLabel}`;
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
  // Zoho issues only a few access tokens per refresh token in ten minutes,
  // and a Stage 2 run makes many calls: one token (valid an hour) is
  // reused for 50 minutes.
  const cached = tokens.get(refreshToken);
  if (cached && cached.expires > Date.now()) {
    return cached.token;
  }
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
  tokens.set(refreshToken, { token: decoded.access_token, expires: Date.now() + 3000 * 1000 });
  return decoded.access_token;
}

async function request(method, url, payload, headers, formEncoded = false) {
  zohoGuard.assertRequestAllowed(url);
  if (transport) {
    const fake = await transport(method, url, payload, headers, formEncoded);
    return { ok: fake.status >= 200 && fake.status < 300, status: fake.status, body: fake.body };
  }
  let body;
  if (method !== 'GET' && method !== 'DELETE') body = formEncoded ? new URLSearchParams(payload).toString() : JSON.stringify(payload);
  const res = await fetch(url, { method, headers, body });
  return { ok: res.ok, status: res.status, body: await res.text() };
}

function safeJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

module.exports = {
  BILL_ACCOUNTS, EXPENSE_ACCOUNTS, PAID_THROUGH_ACCOUNTS, COST_ACCOUNTS, BENEFIT_INCOME_ACCOUNT, BENEFIT_SCRIP_ACCOUNT,
  isEnabled, syncStartDate, pushRevenuePayment, applyPaymentToInvoice, pushInvoice, pushBill, pushVendorPayment, applyVendorPayment, pushExpense,
  pushCostEntry, pushBenefitJournal, listTestJournals, contactRecords, readRecord, totalsById, invoiceByNumber,
  restoreVendorPayment, restoreRevenuePayment,
  testConnection, listExpenses, listTestContacts, listTestExpenses, deleteRecord, setTransport,
};
