'use strict';

/**
 * Port of SecretSetting.php. Owner decision 1 Nov 2026 (C-14, O-14): the
 * Zoho client secrets and refresh tokens are never shown on Company
 * Settings, nor written to the audit log. The page shows only the last 4
 * characters; typing a new value replaces it, an empty field keeps it.
 */
const KEYS = ['zoho_client_secret', 'zoho_refresh_token', 'zoho_books_client_secret', 'zoho_books_refresh_token'];

const isSecret = (key) => KEYS.includes(String(key));
const hint = (value) => (value === null || value === undefined || value === '' ? 'not set' : `•••• stored, ends in ${String(value).slice(-4)}`);
const forAudit = (value) => (value === null || value === undefined || value === '' ? value : `(secret, ends in ${String(value).slice(-4)})`);

module.exports = { KEYS, isSecret, hint, forAudit };
