'use strict';

const fs = require('fs');
const companySettingsRepository = require('../repositories/companySettingsRepository');

/**
 * docs/schema.sql Section AI — sends through the Zoho Mail API (OAuth
 * self-client refresh-token flow). Every public function here either
 * returns a result or throws — mailSenderService is the one place that
 * catches those throws and falls back to SMTP, so this module is free to
 * fail loudly and correctly on any bad response.
 *
 * NOTE: written against Zoho Mail API v1's documented shape
 * (accounts.zoho.com OAuth token endpoint; mail.zoho.com/api/accounts/
 * {accountId}/messages to send, .../messages/attachments to pre-upload an
 * attachment). This account has no live Zoho credentials yet to verify
 * the exact request/response shape against — re-check field names here
 * against Zoho's current API docs the first time real credentials are
 * configured, using the "Send Test Email" button on /settings.
 */

async function isEnabled() {
  return (await companySettingsRepository.get('zoho_mail_enabled')) === '1';
}

/**
 * @param {Array<{path: string, name: string}>} [attachments]
 * @throws on any missing config, HTTP failure, or unexpected response
 */
async function send(to, subject, body, attachments = []) {
  const clientId = (await companySettingsRepository.get('zoho_client_id')) || '';
  const clientSecret = (await companySettingsRepository.get('zoho_client_secret')) || '';
  const refreshToken = (await companySettingsRepository.get('zoho_refresh_token')) || '';
  const accountId = (await companySettingsRepository.get('zoho_account_id')) || '';
  const fromAddress = (await companySettingsRepository.get('zoho_from_address')) || '';

  if (!clientId || !clientSecret || !refreshToken || !accountId || !fromAddress) {
    throw new Error('Zoho Mail is enabled but not fully configured — fill in every zoho_* setting.');
  }

  const accountsDomain = (await companySettingsRepository.get('zoho_accounts_domain')) || 'accounts.zoho.com';
  const apiDomain = (await companySettingsRepository.get('zoho_api_domain')) || 'mail.zoho.com';

  const accessToken = await getAccessToken(accountsDomain, clientId, clientSecret, refreshToken);

  const uploaded = [];
  for (const att of attachments) {
    if (!fs.existsSync(att.path)) continue;
    uploaded.push(await uploadAttachment(apiDomain, accountId, accessToken, att.path, att.name));
  }

  const payload = {
    fromAddress,
    toAddress: to,
    subject,
    content: body,
    mailFormat: 'plaintext',
  };
  if (uploaded.length) {
    payload.attachments = uploaded;
  }

  const res = await fetch(`https://${apiDomain}/api/accounts/${accountId}/messages`, {
    method: 'POST',
    headers: { Authorization: `Zoho-oauthtoken ${accessToken}`, 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const text = await res.text();
  let decoded = null;
  try { decoded = JSON.parse(text); } catch { /* fall through to the generic error below */ }

  if (!res.ok || !decoded || ((decoded.status || {}).code !== 200 && decoded.data === undefined)) {
    throw new Error(`Zoho Mail send failed (HTTP ${res.status}): ${text.slice(0, 500)}`);
  }

  return true;
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
  let decoded = null;
  try { decoded = JSON.parse(text); } catch { /* fall through to the generic error below */ }

  if (!res.ok || !decoded || !decoded.access_token) {
    throw new Error(`Zoho OAuth token refresh failed (HTTP ${res.status}): ${text.slice(0, 500)}`);
  }
  return decoded.access_token;
}

/** @return {Promise<{storeName: ?string, attachmentPath: ?string, attachmentName: string}>} */
async function uploadAttachment(apiDomain, accountId, accessToken, filePath, name) {
  const bytes = fs.readFileSync(filePath);
  const res = await fetch(`https://${apiDomain}/api/accounts/${accountId}/messages/attachments?fileName=${encodeURIComponent(name)}`, {
    method: 'POST',
    headers: { Authorization: `Zoho-oauthtoken ${accessToken}`, 'Content-Type': 'application/octet-stream' },
    body: bytes,
  });
  const text = await res.text();
  let decoded = null;
  try { decoded = JSON.parse(text); } catch { /* fall through to the generic error below */ }
  const data = decoded && decoded.data;

  if (!res.ok || !data) {
    throw new Error(`Zoho attachment upload failed (HTTP ${res.status}): ${text.slice(0, 500)}`);
  }

  return {
    storeName: data.storeName || null,
    attachmentPath: data.attachmentPath || null,
    attachmentName: data.attachmentName || name,
  };
}

module.exports = { isEnabled, send };
