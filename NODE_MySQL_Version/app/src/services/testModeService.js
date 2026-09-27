'use strict';

const testModeRepository = require('../repositories/testModeRepository');

/**
 * Test Mode business rules (docs/schema.sql Section V). Thin over
 * testModeRepository — the repository owns SQL, this owns the two rules
 * that aren't just a query: you can't disable while test data exists, and
 * every test-mode reference number gets the same literal prefix so it's
 * identifiable and safely deletable (requirement: reference numbers must
 * contain "TEST-").
 */

const REFERENCE_PREFIX = 'TEST-';

async function isEnabled() {
  const settings = await testModeRepository.getSettings();
  return !!(settings && parseInt(settings.is_enabled, 10) === 1);
}

async function getSettings() {
  return testModeRepository.getSettings();
}

async function enable(userId) {
  await testModeRepository.setEnabled(true, userId);
}

/**
 * @throws {Error} if test data still exists — the caller (controller)
 * turns this into a flash message, never a silent no-op.
 */
async function disable() {
  if (await testModeRepository.hasTestData()) {
    throw new Error('Cannot disable Test Mode while test data still exists. Delete all test data first.');
  }
  await testModeRepository.setEnabled(false, null);
}

async function setTestEmail(email) {
  await testModeRepository.setTestEmail(email);
}

/**
 * QA-5 TM-07/TM-08: the single Test Mode email gate — every outbound
 * transport (Zoho Mail, SMTP) must call this before doing anything else,
 * so no choice of transport can bypass it. `isSecurityEmail` is the one
 * carve-out by design: staff's own 2FA codes and password-reset links must
 * keep going to the real address they belong to, or Test Mode would lock
 * staff out of their own accounts.
 *
 * Returns null when Test Mode is on and no test_email is configured — the
 * caller must treat that as "do not send this email at all", never fall
 * back to the real address (TM-07). docs/schema.sql Section V's guarantee
 * is "no real buyer is ever emailed while Test Mode is on", not "unless
 * nobody happened to configure a test address yet".
 */
async function resolveEmailRecipient(toEmail, isSecurityEmail, subject) {
  if (isSecurityEmail) return toEmail;
  const settings = await getSettings();
  if (!settings || parseInt(settings.is_enabled, 10) !== 1) return toEmail;
  if (!settings.test_email) {
    console.error(`[TEST MODE — no test_email configured, BLOCKING send that would otherwise reach the real address] To: ${toEmail} | Subject: ${subject}`);
    return null;
  }
  console.log(`[TEST MODE — email redirected] Original To: ${toEmail} -> Test: ${settings.test_email} | Subject: ${subject}`);
  return settings.test_email;
}

async function testDataCounts() {
  return testModeRepository.testDataCounts();
}

async function hasTestData() {
  return testModeRepository.hasTestData();
}

async function deleteAllTestData() {
  return testModeRepository.clearAllTestData();
}

/** Prefixes a freshly-generated reference/number when Test Mode is active; a no-op otherwise. */
function applyReferencePrefix(reference, testModeEnabled) {
  if (!testModeEnabled || !reference) return reference;
  return String(reference).startsWith(REFERENCE_PREFIX) ? reference : `${REFERENCE_PREFIX}${reference}`;
}

async function isTestEntity(entityType, entityId) {
  return testModeRepository.isTestEntity(entityType, entityId);
}

module.exports = {
  REFERENCE_PREFIX,
  isEnabled,
  getSettings,
  enable,
  disable,
  setTestEmail,
  resolveEmailRecipient,
  testDataCounts,
  hasTestData,
  deleteAllTestData,
  applyReferencePrefix,
  isTestEntity,
};
