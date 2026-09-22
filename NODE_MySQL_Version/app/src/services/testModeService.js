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
  testDataCounts,
  hasTestData,
  deleteAllTestData,
  applyReferencePrefix,
  isTestEntity,
};
