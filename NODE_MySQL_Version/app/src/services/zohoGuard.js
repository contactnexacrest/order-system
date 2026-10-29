'use strict';

const env = require('../config/env');

/**
 * Port of App\Services\ZohoGuard. Owner decisions 2026-10-29 — the
 * server-level Zoho safety lock, read from .env (never from Admin ->
 * Settings, so no screen can change it). The three variables are always
 * written out in .env:
 *
 *   ZOHO_INTEGRATION_ENABLED=false   true = this server may talk to Zoho at all
 *   ZOHO_ENVIRONMENT=test            test | production
 *   ZOHO_EINVOICE_ENABLED=false      true = GST e-invoice / e-way bill / GST filing may be used
 *
 * A missing or unrecognised value is read as the safe choice (off / test /
 * off). Nothing from development or testing may ever reach the GST portal
 * or any other government portal: the government features are allowed only
 * when the integration is on, the environment is production and
 * ZOHO_EINVOICE_ENABLED=true — and even then only for real orders. In test,
 * only customers whose name starts with TEST- may be sent, so the clean-up
 * script always finds everything testing made.
 */
const VARIABLES = ['ZOHO_INTEGRATION_ENABLED', 'ZOHO_ENVIRONMENT', 'ZOHO_EINVOICE_ENABLED'];
const TEST_PREFIX = 'TEST-';

function integrationEnabled() {
  return String(env.get('ZOHO_INTEGRATION_ENABLED', 'false')).toLowerCase() === 'true';
}

/** 'production' only when .env says exactly that; anything else is 'test'. */
function environment() {
  return String(env.get('ZOHO_ENVIRONMENT', 'test')).toLowerCase() === 'production' ? 'production' : 'test';
}

function isProduction() {
  return environment() === 'production';
}

/** GST e-invoice / e-way bill / GST filing: only on, in production, with ZOHO_EINVOICE_ENABLED=true. */
function governmentFeaturesAllowed() {
  return integrationEnabled()
    && isProduction()
    && String(env.get('ZOHO_EINVOICE_ENABLED', 'false')).toLowerCase() === 'true';
}

/** The three variables that are not written out in .env. */
function missingVariables() {
  return VARIABLES.filter((name) => env.get(name) === null);
}

/** A Zoho URL for e-invoicing (IRN / IRP), e-way bills or GST filing. */
function isGovernmentUrl(url) {
  let path;
  try {
    path = decodeURIComponent(new URL(url).pathname).toLowerCase();
  } catch {
    path = String(url).toLowerCase();
  }
  return /e-?invoic|e-?way|ewb|(^|[/_-])(irn|irp|gstn|gstr\w*)([/_-]|$)|gst-?fil|filing/.test(path);
}

/**
 * Every Zoho request goes through here first.
 *
 * @throws when this server may not make the request
 */
function assertRequestAllowed(url) {
  if (!integrationEnabled()) {
    throw new Error('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED is not true in .env).');
  }
  if (isGovernmentUrl(url) && !governmentFeaturesAllowed()) {
    throw new Error('Blocked: GST e-invoice, e-way bill and GST filing requests are allowed only in production with ZOHO_EINVOICE_ENABLED=true in .env.');
  }
}

/**
 * In test, only TEST- customers may be sent to Zoho.
 *
 * @throws for any other name in test
 */
function assertCustomerAllowed(name) {
  if (!isProduction() && !String(name).startsWith(TEST_PREFIX)) {
    throw new Error(`Not sent: ZOHO_ENVIRONMENT is test, and only customers whose name starts with TEST- may go to Zoho (this one is "${name}").`);
  }
}

module.exports = {
  VARIABLES, TEST_PREFIX, integrationEnabled, environment, isProduction, governmentFeaturesAllowed,
  missingVariables, isGovernmentUrl, assertRequestAllowed, assertCustomerAllowed,
};
