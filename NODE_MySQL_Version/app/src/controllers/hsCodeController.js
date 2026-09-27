'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const hsCodeRepository = require('../repositories/hsCodeRepository');
const hsCodeProductGuideRepository = require('../repositories/hsCodeProductGuideRepository');

/**
 * Port of App\Controllers\HsCodeController — master list order creation's
 * HS code field picks from. Deliberately gated behind its own permission
 * (manage_hs_codes), separate from ordinary order-entry access, so a new
 * code always passes through a privileged person before it can ever
 * appear on an order.
 */

async function index(req, res) {
  res.renderView('hs_codes/index', {
    codes: await hsCodeRepository.all(),
    productGuide: await hsCodeProductGuideRepository.all(),
  }, 'layout/base');
}

async function create(req, res) {
  const code = String(req.body.code || '').trim();
  const description = String(req.body.description || '').trim();

  if (!/^\d{6}$|^\d{8}$/.test(code)) {
    flash.set(req, 'error', 'HS code must be exactly 6 or 8 digits, no dots or other characters.');
    res.redirect('/hs-codes');
    return;
  }
  if (description === '') {
    flash.set(req, 'error', 'A description is required.');
    res.redirect('/hs-codes');
    return;
  }
  if (await hsCodeRepository.findByCode(code)) {
    flash.set(req, 'error', `HS code ${code} already exists.`);
    res.redirect('/hs-codes');
    return;
  }

  const id = await hsCodeRepository.create(code, description, req.user.id);
  await auditLogRepository.log(req.user.id, 'HS_CODE_ADDED', 'hs_codes', id, null, null, `${code}: ${description}`);
  flash.set(req, 'success', `HS code ${code} added.`);
  res.redirect('/hs-codes');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const description = String(req.body.description || '').trim();
  const usageNote = String(req.body.usage_note || '').trim() || null;
  if (description === '') {
    flash.set(req, 'error', 'A description is required.');
    res.redirect('/hs-codes');
    return;
  }
  const existing = (await hsCodeRepository.all()).find((r) => r.id === id);
  if (!existing) {
    flash.set(req, 'error', 'HS code not found.');
    res.redirect('/hs-codes');
    return;
  }

  await hsCodeRepository.updateDescription(id, description, usageNote);
  await auditLogRepository.log(req.user.id, 'HS_CODE_UPDATED', 'hs_codes', id, 'description', existing.description, description);
  flash.set(req, 'success', 'HS code updated.');
  res.redirect('/hs-codes');
}

/**
 * Point 4 — bulk import from a pasted block (Excel's own tab-separated
 * copy/paste format works directly; comma-separated also accepted for
 * hand-typed lines).
 */
async function bulkImport(req, res) {
  const raw = String(req.body.bulk_codes || '');
  if (raw.trim() === '') {
    flash.set(req, 'error', 'Paste at least one line (Code, Description) to import.');
    res.redirect('/hs-codes');
    return;
  }

  const result = await hsCodeRepository.bulkImport(raw, req.user.id);

  if (result.inserted.length > 0) {
    await auditLogRepository.log(req.user.id, 'HS_CODE_BULK_IMPORTED', 'hs_codes', null, null, null, result.inserted.join(', '));
  }

  const message = `${result.inserted.length} HS code(s) added.`;
  if (result.skipped.length > 0) {
    flash.set(req, 'error', `${message} Skipped: ${result.skipped.join(' | ')}`);
  } else {
    flash.set(req, 'success', message);
  }
  res.redirect('/hs-codes');
}

/** Point 4 — bulk import for the Product Guide reference (Product, Code, optional Note). */
async function importProductGuide(req, res) {
  const raw = String(req.body.bulk_guide || '');
  if (raw.trim() === '') {
    flash.set(req, 'error', 'Paste at least one line (Product, Code, Note) to import.');
    res.redirect('/hs-codes');
    return;
  }

  const result = await hsCodeProductGuideRepository.bulkImport(raw, req.user.id);

  if (result.inserted > 0) {
    await auditLogRepository.log(req.user.id, 'HS_CODE_GUIDE_IMPORTED', 'hs_code_product_examples', null, null, null, String(result.inserted));
  }

  const message = `${result.inserted} product guide row(s) added.`;
  if (result.skipped.length > 0) {
    flash.set(req, 'error', `${message} Skipped: ${result.skipped.join(' | ')}`);
  } else {
    flash.set(req, 'success', message);
  }
  res.redirect('/hs-codes');
}

async function deleteProductGuideEntry(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  await hsCodeProductGuideRepository.remove(id);
  await auditLogRepository.log(req.user.id, 'HS_CODE_GUIDE_DELETED', 'hs_code_product_examples', id, null, null, null);
  flash.set(req, 'success', 'Product guide entry removed.');
  res.redirect('/hs-codes');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  await hsCodeRepository.toggleActive(id);
  await auditLogRepository.log(req.user.id, 'HS_CODE_TOGGLED', 'hs_codes', id, null, null, null);
  flash.set(req, 'success', 'Status updated.');
  res.redirect('/hs-codes');
}

async function remove(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const existing = (await hsCodeRepository.all()).find((r) => r.id === id);
  if (!existing) {
    flash.set(req, 'error', 'HS code not found.');
    res.redirect('/hs-codes');
    return;
  }
  if ((await hsCodeRepository.usageCount(existing.code)) > 0) {
    flash.set(req, 'error', 'This HS code is already used on at least one order — deactivate it instead of deleting.');
    res.redirect('/hs-codes');
    return;
  }

  await hsCodeRepository.remove(id);
  await auditLogRepository.log(req.user.id, 'HS_CODE_DELETED', 'hs_codes', id, null, existing.code, null);
  flash.set(req, 'success', 'HS code deleted.');
  res.redirect('/hs-codes');
}

module.exports = { index, create, update, toggleActive, remove, bulkImport, importProductGuide, deleteProductGuideEntry };
