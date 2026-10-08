'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const dropdownOptionRepository = require('../repositories/dropdownOptionRepository');

/**
 * Port of App\Controllers\DropdownOptionController — admin screen for
 * dropdown_options (docs/schema.sql Section BD). Gated on
 * manage_dropdown_options, same tier as manage_hs_codes/
 * manage_logistics_partners. One bulk-save form per list_key (mirrors
 * Admin Overrides' per-section pattern) rather than per-row edit pages,
 * since each group is typically a handful of short values.
 */

async function index(req, res) {
  res.renderView('dropdown_options/index', {
    grouped: await dropdownOptionRepository.allGrouped(true),
  }, 'layout/base');
}

async function create(req, res) {
  const listKey = String(req.params.listKey || '');
  const value = String(req.body.option_value || '').trim();
  if (value === '') {
    flash.set(req, 'error', 'A value is required.');
    res.redirect('/admin/dropdown-options');
    return;
  }

  const existing = (await dropdownOptionRepository.allGrouped(true))[listKey] || [];
  const nextSortOrder = existing.length > 0 ? Math.max(...existing.map((o) => o.sort_order)) + 1 : 1;

  const id = await dropdownOptionRepository.create(listKey, value, nextSortOrder);
  await auditLogRepository.log(req.user.id, 'DROPDOWN_OPTION_ADDED', 'dropdown_options', id, null, null, `${listKey}: ${value}`);
  flash.set(req, 'success', `"${value}" added to ${listKey}.`);
  res.redirect('/admin/dropdown-options');
}

async function update(req, res) {
  const listKey = String(req.params.listKey || '');
  const rows = (await dropdownOptionRepository.allGrouped(true))[listKey] || [];
  if (rows.length === 0) {
    flash.set(req, 'error', 'Option list not found.');
    res.redirect('/admin/dropdown-options');
    return;
  }

  const valueInput = req.body.option_value || {};
  const sortOrderInput = req.body.sort_order || {};
  const activeInput = req.body.is_active || {};
  const defaultId = parseInt(req.body.default_id, 10) || 0;

  let changedCount = 0;
  for (const row of rows) {
    const id = row.id;
    const newValue = String(valueInput[id] ?? row.option_value).trim();
    const newSortOrder = parseInt(sortOrderInput[id] ?? row.sort_order, 10);
    const newActive = activeInput[id] !== undefined ? 1 : 0;

    if (newValue !== row.option_value || newSortOrder !== row.sort_order) {
      await dropdownOptionRepository.update(id, newValue, newSortOrder);
      await auditLogRepository.log(req.user.id, 'DROPDOWN_OPTION_UPDATED', 'dropdown_options', id, 'option_value', row.option_value, newValue);
      changedCount++;
    }
    if (newActive !== row.is_active) {
      await dropdownOptionRepository.toggleActive(id);
      await auditLogRepository.log(req.user.id, newActive ? 'DROPDOWN_OPTION_REACTIVATED' : 'DROPDOWN_OPTION_DEACTIVATED', 'dropdown_options', id, 'is_active', String(row.is_active), String(newActive));
      changedCount++;
    }
  }

  if (defaultId > 0) {
    const currentDefaultRow = rows.find((row) => !!row.is_default);
    const currentDefault = currentDefaultRow ? currentDefaultRow.id : null;
    if (currentDefault !== defaultId) {
      await dropdownOptionRepository.setDefault(defaultId, listKey);
      await auditLogRepository.log(req.user.id, 'DROPDOWN_OPTION_DEFAULT_CHANGED', 'dropdown_options', defaultId, 'is_default', String(currentDefault), String(defaultId));
      changedCount++;
    }
  }

  flash.set(req, 'success', changedCount > 0 ? `${listKey} updated.` : 'No changes were made.');
  res.redirect('/admin/dropdown-options');
}

module.exports = { index, create, update };
