'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const clientRepository = require('../repositories/clientRepository');

/**
 * CA / Accounting module (Phase 3) — pushes a settled revenue leg to Zoho
 * Books as a Customer Payment, via the OAuth self-client refresh-token
 * flow (same shape as zohoMailService, a separate credential set since
 * Zoho Mail and Zoho Books are different API scopes). Every public
 * function here either returns a result or throws — caSyncService is the
 * one place that catches those throws, logs them to zoho_sync_log, and
 * moves on to the next leg, so this module is free to fail loudly and
 * correctly on any bad response.
 *
 * NOTE: written against Zoho Books API v3's documented shape
 * (accounts.zoho.com OAuth token endpoint; www.zohoapis.com/books/v3/...
 * for contacts and customer payments). This account has no live Zoho
 * Books credentials yet to verify the exact request/response shape
 * against — re-check field names here against Zoho's current API docs
 * the first time real credentials are configured, using the "Sync Now"
 * button on /ca/zoho-sync (exactly the caveat zohoMailService already
 * carries for the same reason).
 */

async function isEnabled() {
  return (await companySettingsRepository.get('zoho_books_enabled')) === '1';
}

/**
 * Pushes one settlement leg's INR-actual amount as a Zoho Books Customer
 * Payment against the order's client (creating the Zoho contact on first
 * use, cached on clients.zoho_contact_id after).
 *
 * @throws on any missing config, HTTP failure, or unexpected response
 * @return {Promise<string>} the Zoho-side customerpayment_id
 */
async function pushRevenuePayment(clientId, companyLegalName, amountInr, date, referenceNumber) {
  const config = await loadConfig();
  const accessToken = await getAccessToken(config.accountsDomain, config.clientId, config.clientSecret, config.refreshToken);

  const contactId = await findOrCreateContact(config, accessToken, clientId, companyLegalName);

  const payload = {
    customer_id: contactId,
    payment_mode: 'banktransfer',
    amount: amountInr,
    date,
    reference_number: referenceNumber,
    account_id: config.depositAccountId,
  };

  const res = await request('POST', `https://${config.apiDomain}/books/v3/customerpayments?organization_id=${encodeURIComponent(config.organizationId)}`, payload, authHeaders(accessToken));
  const decoded = safeJson(res.body);
  const paymentId = decoded && decoded.payment && decoded.payment.payment_id;
  if (!res.ok || typeof paymentId !== 'string') {
    throw new Error(`Zoho Books customer payment failed (HTTP ${res.status}): ${res.body.slice(0, 500)}`);
  }
  return paymentId;
}

async function loadConfig() {
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
    accountsDomain: (await companySettingsRepository.get('zoho_books_accounts_domain')) || 'accounts.zoho.com',
    apiDomain: (await companySettingsRepository.get('zoho_books_api_domain')) || 'www.zohoapis.com',
  };
}

async function findOrCreateContact(config, accessToken, clientId, companyLegalName) {
  const client = await clientRepository.find(clientId);
  if (client && client.zoho_contact_id) {
    return client.zoho_contact_id;
  }

  const searchRes = await request(
    'GET',
    `https://${config.apiDomain}/books/v3/contacts?organization_id=${encodeURIComponent(config.organizationId)}&contact_name=${encodeURIComponent(companyLegalName)}`,
    null,
    authHeaders(accessToken)
  );
  const searchDecoded = safeJson(searchRes.body);
  const existing = searchDecoded && searchDecoded.contacts && searchDecoded.contacts[0] && searchDecoded.contacts[0].contact_id;
  if (typeof existing === 'string' && existing !== '') {
    await clientRepository.setZohoContactId(clientId, existing);
    return existing;
  }

  const createRes = await request(
    'POST',
    `https://${config.apiDomain}/books/v3/contacts?organization_id=${encodeURIComponent(config.organizationId)}`,
    { contact_name: companyLegalName, contact_type: 'customer' },
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

function authHeaders(accessToken) {
  return { Authorization: `Zoho-oauthtoken ${accessToken}`, 'Content-Type': 'application/json' };
}

async function getAccessToken(accountsDomain, clientId, clientSecret, refreshToken) {
  const params = new URLSearchParams({
    refresh_token: refreshToken,
    client_id: clientId,
    client_secret: clientSecret,
    grant_type: 'refresh_token',
  });
  const res = await fetch(`https://${accountsDomain}/oauth/v2/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString(),
  });
  const text = await res.text();
  const decoded = safeJson(text);
  if (!res.ok || !decoded || !decoded.access_token) {
    throw new Error(`Zoho Books OAuth token refresh failed (HTTP ${res.status}): ${text.slice(0, 500)}`);
  }
  return decoded.access_token;
}

async function request(method, url, payload, headers) {
  const res = await fetch(url, {
    method,
    headers,
    body: method === 'GET' ? undefined : JSON.stringify(payload),
  });
  const body = await res.text();
  return { ok: res.ok, status: res.status, body };
}

function safeJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

module.exports = { isEnabled, pushRevenuePayment };
