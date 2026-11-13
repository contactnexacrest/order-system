'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const dropdownOptionRepository = require('../repositories/dropdownOptionRepository');
const surfaceFinishList = require('../services/surfaceFinishList');

/**
 * Port of App\Controllers\DropdownOptionController — admin screen for
 * dropdown_options (docs/schema.sql Section BD). Gated on
 * manage_dropdown_options, same tier as manage_hs_codes/
 * manage_logistics_partners. One bulk-save form per list_key (mirrors
 * Admin Overrides' per-section pattern) rather than per-row edit pages,
 * since each group is typically a handful of short values.
 */

async function index(req, res) {
  // D-357: the surface_finish list is the one list of finish names — its price column and
  // the box shown after a save that renamed a finish.
  const finishSaved = req.session ? req.session._finishSaved : null;
  if (req.session) delete req.session._finishSaved;
  res.renderView('dropdown_options/index', {
    grouped: await dropdownOptionRepository.allGrouped(true),
    finishPrices: await surfaceFinishList.priceColumn(),
    finishSaved: finishSaved && typeof finishSaved === 'object' ? finishSaved : null,
    finishListKey: surfaceFinishList.LIST_KEY,
    finishMaxName: surfaceFinishList.MAX_NAME,
  }, 'layout/base');
}

/**
 * D-357: a finish name is also an option name in Pricing › Specifications — up to 80
 * characters, and each name once in the list (any letter case).
 */
function finishNameError(names, others) {
  const seen = new Set();
  for (const raw of [...others, ...names]) {
    const n = String(raw).trim();
    if ([...n].length > surfaceFinishList.MAX_NAME) {
      return `A surface finish name can be at most ${surfaceFinishList.MAX_NAME} characters ("${[...n].slice(0, 30).join('')}…").`;
    }
    const k = n.toLowerCase();
    if (seen.has(k)) return `"${n}" is in the surface_finish list twice — each finish name can be used once.`;
    seen.add(k);
  }
  return null;
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
  // D-357: a finish name is also the option name in Pricing › Specifications (up to 80 characters, once).
  if (listKey === surfaceFinishList.LIST_KEY) {
    const error = finishNameError([value], existing.map((o) => o.option_value));
    if (error !== null) {
      flash.set(req, 'error', error);
      res.redirect('/admin/dropdown-options');
      return;
    }
  }
  const nextSortOrder = existing.length > 0 ? Math.max(...existing.map((o) => o.sort_order)) + 1 : 1;

  const id = await dropdownOptionRepository.create(listKey, value, nextSortOrder);
  await auditLogRepository.log(req.user.id, 'DROPDOWN_OPTION_ADDED', 'dropdown_options', id, null, null, `${listKey}: ${value}`);
  if (listKey === surfaceFinishList.LIST_KEY) {
    await surfaceFinishList.sync([], req.user.id);
    flash.set(req, 'success', `"${value}" added to ${listKey} — it is in Pick from Products and Pricing › Specifications now, with no price change; set its price in Pricing › Specifications.`);
  } else {
    flash.set(req, 'success', `"${value}" added to ${listKey}.`);
  }
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

  // QA Round 5, R5-30 / R5-28: a cleared name used to save an empty
  // option, and text in a sort order was saved as a number. Check every
  // row first; a problem saves nothing.
  for (const row of rows) {
    const id = row.id;
    if (Array.isArray(valueInput[id]) || Array.isArray(sortOrderInput[id])
      || String(valueInput[id] ?? row.option_value).trim() === ''
      || !/^\d+$/.test(String(sortOrderInput[id] ?? row.sort_order).trim())) {
      flash.set(req, 'error', 'Every option needs a name and a sort order that is a whole number (0 or more) — nothing was saved.');
      res.redirect('/admin/dropdown-options');
      return;
    }
  }
  if (listKey === surfaceFinishList.LIST_KEY) {
    const error = finishNameError(rows.map((row) => String(valueInput[row.id] ?? row.option_value).trim()), []);
    if (error !== null) {
      flash.set(req, 'error', `${error} Nothing was saved.`);
      res.redirect('/admin/dropdown-options');
      return;
    }
  }

  let changedCount = 0;
  const renames = [];
  const activeChanged = [];
  for (const row of rows) {
    const id = row.id;
    const newValue = String(valueInput[id] ?? row.option_value).trim();
    const newSortOrder = parseInt(sortOrderInput[id] ?? row.sort_order, 10);
    const newActive = activeInput[id] !== undefined ? 1 : 0;

    if (newValue !== row.option_value || newSortOrder !== row.sort_order) {
      await dropdownOptionRepository.update(id, newValue, newSortOrder);
      await auditLogRepository.log(req.user.id, 'DROPDOWN_OPTION_UPDATED', 'dropdown_options', id, 'option_value', row.option_value, newValue);
      if (newValue !== row.option_value) renames.push([id, String(row.option_value), newValue]);
      changedCount++;
    }
    if (newActive !== row.is_active) {
      activeChanged.push(id);
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

  // D-357: the Surface finish options in Pricing › Specifications follow; a rename reaches
  // the order lines of orders whose quotation is not approved yet.
  if (listKey === surfaceFinishList.LIST_KEY && changedCount > 0) {
    await surfaceFinishList.sync(activeChanged, req.user.id);
    const box = { messages: [], renamed: {} };
    for (const [id, old, neu] of renames) {
      const r = await surfaceFinishList.renameOnDraftOrders(old, neu, req.user.id);
      await auditLogRepository.log(req.user.id, 'SURFACE_FINISH_RENAMED', 'dropdown_options', id, 'option_value', old, neu,
        `D-357: renamed everywhere — Pick from Products, Pricing › Specifications, ${r.lines} line(s) on ${r.orders} draft order(s); `
        + `${r.kept_orders} order(s) with an approved quotation or later and every issued document keep the old name.`);
      box.messages.push(surfaceFinishList.renameMessage(old, neu, r));
      box.renamed[id] = old;
    }
    if (box.messages.length) {
      req.session._finishSaved = box;
      res.redirect(`/admin/dropdown-options#list-${listKey}`);
      return;
    }
  }

  flash.set(req, 'success', changedCount > 0 ? `${listKey} updated.` : 'No changes were made.');
  res.redirect('/admin/dropdown-options');
}

module.exports = { index, create, update };
