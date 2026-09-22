'use strict';

const flash = require('../helpers/flash');
const testModeService = require('../services/testModeService');

// Test Mode admin screen (docs/schema.sql Section V) — Super Admin only,
// wired via requireSuperAdmin in server.js, same as /super-admin itself.

async function index(req, res) {
  const [settings, counts] = await Promise.all([
    testModeService.getSettings(),
    testModeService.testDataCounts(),
  ]);
  const hasTestData = counts.clients > 0 || counts.orders > 0 || counts.suppliers > 0;
  res.renderView('test_mode/index', { settings, counts, hasTestData }, 'layout/base');
}

async function enable(req, res) {
  await testModeService.enable(req.user.id);
  flash.set(req, 'success', 'Test Mode enabled. Client access is suspended and outbound mail is now redirected.');
  res.redirect('/test-mode');
}

async function disable(req, res) {
  try {
    await testModeService.disable();
    flash.set(req, 'success', 'Test Mode disabled.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect('/test-mode');
}

async function updateTestEmail(req, res) {
  const email = String(req.body.test_email || '').trim();
  if (!email) {
    flash.set(req, 'error', 'Test email cannot be blank.');
    res.redirect('/test-mode');
    return;
  }
  await testModeService.setTestEmail(email);
  flash.set(req, 'success', 'Test email updated.');
  res.redirect('/test-mode');
}

async function deleteTestData(req, res) {
  const result = await testModeService.deleteAllTestData();
  flash.set(
    req,
    'success',
    `Test data deleted: ${result.clients} client(s), ${result.orders} order(s), ${result.files} file(s).`
  );
  res.redirect('/test-mode');
}

module.exports = { index, enable, disable, updateTestEmail, deleteTestData };
