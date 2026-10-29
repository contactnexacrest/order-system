'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const clientRepository = require('../repositories/clientRepository');
const lookupRepository = require('../repositories/lookupRepository');
const zohoGuard = require('./zohoGuard');
const { todayIst } = require('../helpers/dbDateTime');

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

/** Tests only: answer every Zoho request with fn(method, url, payload, headers, formEncoded) instead of the network (null restores real HTTP). */
function setTransport(fn) {
  transport = fn;
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
 * @throws on any missing config, HTTP failure, or unexpected response
 * @return {Promise<string>} the Zoho-side customerpayment_id
 */
async function pushRevenuePayment(clientId, companyLegalName, currencyCode, foreignAmount, amountInr, date, referenceNumber) {
  zohoGuard.assertCustomerAllowed(companyLegalName);
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const contactId = await findOrCreateContact(config, accessToken, clientId, companyLegalName, currencyCode);

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

  const res = await request('POST', `https://${config.apiDomain}/books/v3/customerpayments?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const paymentId = decoded && decoded.payment && decoded.payment.payment_id;
  if (!res.ok || typeof paymentId !== 'string') {
    throw new Error(`Zoho Books customer payment failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return paymentId;
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
 * customer whose name starts with TEST-, with its payments.
 *
 * @return {Promise<Array<{contact_id: string, name: string, payments: Array<{payment_id: string, label: string}>}>>}
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
    contacts.push({ contact_id: String(row.contact_id), name: String(row.contact_name || ''), payments });
  }
  return contacts;
}

/**
 * Deletes one Books record made while testing — 'customerpayment' or
 * 'contact' (payments first: Books refuses to delete a contact that still
 * has transactions). A record already gone counts as deleted.
 *
 * @throws on any other failure
 */
async function deleteRecord(kind, id) {
  const paths = { customerpayment: 'customerpayments', contact: 'contacts' };
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
      gst_treatment: 'overseas',
      currency_id: String(currency.currency_id),
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

module.exports = { isEnabled, pushRevenuePayment, testConnection, listExpenses, listTestContacts, deleteRecord, setTransport };
