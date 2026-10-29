'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const clientRepository = require('../repositories/clientRepository');
const lookupRepository = require('../repositories/lookupRepository');
const zohoGuard = require('./zohoGuard');
const { todayIst } = require('../helpers/dbDateTime');
const { KIND_LABELS } = require('./zohoInvoiceData');

/**
 * CA / Accounting module — the Zoho Books connection. Phase 3 pushes a
 * settled revenue leg as a Customer Payment (one-way, this system to
 * Zoho); Phase 4 imports expenses (one-way, Zoho to this system) — both
 * via the same OAuth self-client refresh-token flow (same shape as
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

/** Tests only: answer every Zoho request with fn(method, url, payload, headers, formEncoded) instead of the network (null restores real HTTP). */
function setTransport(fn) {
  transport = fn;
  tokens.clear();
  gstRegistered.clear();
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
async function pushRevenuePayment(clientId, companyLegalName, currencyCode, foreignAmount, amountInr, date, referenceNumber, invoiceNumber = null) {
  zohoGuard.assertCustomerAllowed(companyLegalName);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const contactId = await findOrCreateContact(config, accessToken, clientId, companyLegalName, currencyCode);
  const payload = paymentPayload(config, contactId, currencyCode, foreignAmount, amountInr, date, referenceNumber);
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
async function applyPaymentToInvoice(paymentId, clientId, companyLegalName, currencyCode, foreignAmount, amountInr, date, referenceNumber, invoiceNumber) {
  zohoGuard.assertCustomerAllowed(companyLegalName);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const contactId = await findOrCreateContact(config, accessToken, clientId, companyLegalName, currencyCode);
  const payload = paymentPayload(config, contactId, currencyCode, foreignAmount, amountInr, date, referenceNumber);
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
  // or update that did go through (test organisation, Z-029), so the
  // result is read back from the invoice list, never from the response.
  const res = existing
    ? await request('PUT', `https://${config.apiDomain}/books/v3/invoices/${encodeURIComponent(String(existing.invoice_id))}${query}`, payload, authHeaders(accessToken))
    : await request('POST', `https://${config.apiDomain}/books/v3/invoices${query}`, payload, authHeaders(accessToken));
  const saved = await findInvoiceByNumber(config, accessToken, String(invoice.invoice_number));
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
    contacts.push({ contact_id: String(row.contact_id), name: String(row.contact_name || ''), payments, invoices });
  }
  return contacts;
}

/**
 * Deletes one Books record made while testing — 'customerpayment',
 * 'invoice' or 'contact' (payments, then invoices, then the contact: Books
 * refuses to delete a contact that still has transactions). A record
 * already gone counts as deleted.
 *
 * @throws on any other failure
 */
async function deleteRecord(kind, id) {
  const paths = { customerpayment: 'customerpayments', invoice: 'invoices', contact: 'contacts' };
  if (!paths[kind]) {
    throw new Error(`Unknown Zoho Books record kind ${kind}.`);
  }
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);
  const res = await request('DELETE', `https://${config.apiDomain}/books/v3/${paths[kind]}/${encodeURIComponent(id)}?organization_id=${encodeURIComponent(config.organizationId)}`, null, authHeaders(accessToken));
  if (res.status === 404) {
    return;
  }
  if (!res.ok) {
    throw new Error(`Zoho Books ${kind} ${id} delete failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
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
function paymentPayload(config, contactId, currencyCode, foreignAmount, amountInr, date, referenceNumber) {
  const payload = {
    customer_id: contactId,
    payment_mode: 'banktransfer',
    amount: round(amountInr, 2),
    date,
    reference_number: referenceNumber,
    account_id: config.depositAccountId,
  };
  if (currencyCode !== 'INR') {
    if (foreignAmount === null || foreignAmount === undefined || foreignAmount <= 0) {
      throw new Error(`Payment ${referenceNumber} has no ${currencyCode} amount recorded, so its rate cannot be worked out.`);
    }
    payload.amount = round(foreignAmount, 2);
    payload.exchange_rate = round(amountInr / foreignAmount, 6);
  }
  return payload;
}

/** The "invoices" part of a payment: as much of amount as invoice invoiceNumber still owes. @return {Promise<[Array, number]>} */
async function invoiceApplication(config, accessToken, invoiceNumber, contactId, amount) {
  const invoice = await findInvoiceByNumber(config, accessToken, invoiceNumber);
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
  }
  return gstRegistered.get(config.organizationId);
}

function assertContactCurrency(companyLegalName, contactCurrency, currencyCode) {
  if (contactCurrency !== '' && contactCurrency !== currencyCode) {
    throw new Error(`${companyLegalName} is set up in Zoho Books in ${contactCurrency}, but this payment is in ${currencyCode}. A Zoho Books customer has one currency — ask the CA how to book it.`);
  }
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

module.exports = { isEnabled, pushRevenuePayment, applyPaymentToInvoice, pushInvoice, testConnection, listExpenses, listTestContacts, deleteRecord, setTransport };
