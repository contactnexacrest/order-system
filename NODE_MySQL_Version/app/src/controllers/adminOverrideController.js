'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');

// Port of App\Controllers\AdminOverrideController — Spec Section 13, see
// AdminOverrideRepository's docblock for exact scope.
//
// Form field names below use bracket keys like `clause_text[f{id}]` — the
// leading "f" is deliberate, not decorative: Express's body parser (qs,
// extended:true) treats a PURELY numeric bracket key as an array index and
// silently collapses `clause_text[3]` down to a one-element array, losing
// the id entirely (PHP's native array parsing has no such behavior, so this
// never showed up in the PHP build). Prefixing with a non-numeric character
// keeps qs on the plain-object path, where every id survives as its own key.

function stripFieldPrefix(rawId) {
  return parseInt(String(rawId).replace(/^f/, ''), 10);
}

async function index(req, res) {
  const [documentTypes, tcClauses, paymentPresets] = await Promise.all([
    adminOverrideRepository.documentTypes(),
    adminOverrideRepository.tcClauses(),
    adminOverrideRepository.paymentPresets(),
  ]);
  const byGroup = { standard: [], legal_terms: [], definitions: [] };
  for (const c of tcClauses) {
    (byGroup[c.clause_group || 'standard'] || byGroup.standard).push(c);
  }
  res.renderView('admin_overrides/index', {
    documentTypes,
    // Legal Terms is a buyer-facing section (never shown on an internal-
    // only or procurement document like SUPPO/STAGEGATE), so the per-
    // document-type toggle only offers the document types it can
    // actually appear on.
    customerFacingDocumentTypes: documentTypes.filter((dt) => dt.category === 'customer_facing'),
    tcClausesStandard: byGroup.standard,
    tcClausesLegalTerms: byGroup.legal_terms,
    tcClausesDefinitions: byGroup.definitions,
    paymentPresets,
  }, 'layout/base');
}

async function updateDocumentTypes(req, res) {
  const user = req.user;
  const reason = String(req.body.reason || '').trim();
  const rows = await adminOverrideRepository.documentTypes();
  const byId = {};
  for (const r of rows) byId[r.id] = r;

  const refFormatInput = req.body.ref_format || {};
  const minReviewersInput = req.body.min_reviewers || {};

  const toApply = {};
  for (const [rawId, rawNewRefFormat] of Object.entries(refFormatInput)) {
    const id = stripFieldPrefix(rawId);
    if (!byId[id]) continue;
    const newRefFormat = String(rawNewRefFormat).trim() || null;
    const newMinReviewers = Math.max(0, parseInt(minReviewersInput[rawId] ?? byId[id].min_reviewers_default, 10) || 0);
    if (newRefFormat !== byId[id].ref_format || newMinReviewers !== byId[id].min_reviewers_default) {
      toApply[id] = {
        old_ref: byId[id].ref_format,
        new_ref: newRefFormat,
        old_min: byId[id].min_reviewers_default,
        new_min: newMinReviewers,
      };
    }
  }

  if (Object.keys(toApply).length === 0) {
    flash.set(req, 'success', 'No changes were made.');
    res.redirect('/admin/overrides');
    return;
  }
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/admin/overrides');
    return;
  }

  for (const [id, c] of Object.entries(toApply)) {
    await adminOverrideRepository.updateDocumentTypeRefFormat(id, c.new_ref, c.new_min);
    if (c.old_ref !== c.new_ref) {
      await auditLogRepository.log(user.id, 'FIELD_EDIT', 'document_types', id, 'ref_format', c.old_ref, c.new_ref, reason);
    }
    if (c.old_min !== c.new_min) {
      await auditLogRepository.log(user.id, 'FIELD_EDIT', 'document_types', id, 'min_reviewers_default', String(c.old_min), String(c.new_min), reason);
    }
  }
  flash.set(req, 'success', `${Object.keys(toApply).length} document type(s) updated.`);
  res.redirect('/admin/overrides');
}

async function updateTcClauses(req, res) {
  const user = req.user;
  const reason = String(req.body.reason || '').trim();
  const unlockedRaw = req.body.unlocked_clause || {}; // { ['f'+id]: '1' }
  const unlockedIds = new Set(Object.keys(unlockedRaw).filter((k) => unlockedRaw[k] === '1').map(stripFieldPrefix));
  const rows = await adminOverrideRepository.tcClauses();
  const byId = {};
  for (const r of rows) byId[r.id] = r;

  const clauseTextInput = req.body.clause_text || {};
  const clauseTitleInput = req.body.clause_title || {};

  const toApply = {};
  for (const [rawId, rawNewText] of Object.entries(clauseTextInput)) {
    const id = stripFieldPrefix(rawId);
    if (!byId[id]) continue;
    const newTitle = String(clauseTitleInput[rawId] ?? byId[id].clause_title).trim();
    const newText = String(rawNewText).trim();
    if (newTitle !== byId[id].clause_title || newText !== byId[id].clause_text) {
      toApply[id] = {
        old_title: byId[id].clause_title,
        new_title: newTitle,
        old_text: byId[id].clause_text,
        new_text: newText,
      };
    }
  }

  // 90-item backlog "per-doc-type Legal Terms toggle": which document
  // types a Legal Terms clause is linked to (tc_clause_documents) used
  // to be fixed at whatever seed.sql originally set, with no admin-
  // facing way to change it. Only clauses the form actually submitted a
  // document_types[] field for are touched here (today, only
  // clause_group='legal_terms' rows get that checkbox grid in the
  // view), and only against the customer-facing document types the view
  // ever offers, so a tampered POST can't link a clause to an internal-
  // only/procurement document type the Legal Terms box never even
  // renders on.
  const allDocumentTypes = await adminOverrideRepository.documentTypes();
  const allowedDocumentTypeIds = new Set(
    allDocumentTypes.filter((dt) => dt.category === 'customer_facing').map((dt) => dt.id)
  );
  const documentTypesInput = req.body.document_types || {};
  const docTypeChanges = {};
  for (const [rawId, rawNewIds] of Object.entries(documentTypesInput)) {
    const id = stripFieldPrefix(rawId);
    if (!byId[id]) continue;
    const submitted = Array.isArray(rawNewIds) ? rawNewIds : [rawNewIds];
    const newIds = [...new Set(submitted.map((v) => parseInt(v, 10)).filter((v) => allowedDocumentTypeIds.has(v)))].sort();
    const oldIds = [...byId[id].document_type_ids].sort();
    if (JSON.stringify(newIds) !== JSON.stringify(oldIds)) {
      docTypeChanges[id] = { old: byId[id].document_type_ids, new: newIds };
    }
  }

  if (Object.keys(toApply).length === 0 && Object.keys(docTypeChanges).length === 0) {
    flash.set(req, 'success', 'No changes were made.');
    res.redirect('/admin/overrides');
    return;
  }
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/admin/overrides');
    return;
  }

  const blockedProtected = [...new Set([...Object.keys(toApply), ...Object.keys(docTypeChanges)])]
    .filter((id) => byId[id].is_protected && !unlockedIds.has(parseInt(id, 10)));
  if (blockedProtected.length > 0) {
    flash.set(req, 'error', `Protected clause(s) must be unlocked before editing (id ${blockedProtected.join(', ')}) — nothing was saved.`);
    res.redirect('/admin/overrides');
    return;
  }

  if (Object.keys(docTypeChanges).length > 0) {
    const codeById = {};
    for (const dt of allDocumentTypes) codeById[dt.id] = dt.code;
    const codesOf = (ids) => ids.map((dtId) => codeById[dtId] ?? String(dtId)).join(',');
    for (const [id, c] of Object.entries(docTypeChanges)) {
      await adminOverrideRepository.setTcClauseDocumentTypes(id, c.new);
      await auditLogRepository.log(user.id, 'FIELD_EDIT', 'tc_clause_documents', id, 'document_type_ids', codesOf(c.old), codesOf(c.new), reason);
    }
  }

  for (const [id, c] of Object.entries(toApply)) {
    await adminOverrideRepository.updateTcClause(id, c.new_title, c.new_text, user.id);
    if (byId[id].is_protected) {
      await auditLogRepository.log(user.id, 'PROTECTED_FIELD_UNLOCKED', 'tc_clauses', id, 'clause_text', null, null, reason);
    }
    await auditLogRepository.log(user.id, 'FIELD_EDIT', 'tc_clauses', id, 'clause_text', c.old_text, c.new_text, reason);
    if (c.old_title !== c.new_title) {
      await auditLogRepository.log(user.id, 'FIELD_EDIT', 'tc_clauses', id, 'clause_title', c.old_title, c.new_title, reason);
    }
  }
  flash.set(req, 'success', `${new Set([...Object.keys(toApply), ...Object.keys(docTypeChanges)]).size} clause(s) updated.`);
  res.redirect('/admin/overrides');
}

async function updatePaymentPresets(req, res) {
  const user = req.user;
  const reason = String(req.body.reason || '').trim();
  const unlockedRaw = req.body.unlocked_preset || {}; // { ['f'+id]: '1' }
  const unlockedIds = new Set(Object.keys(unlockedRaw).filter((k) => unlockedRaw[k] === '1').map(stripFieldPrefix));
  const rows = await adminOverrideRepository.paymentPresets();
  const byId = {};
  for (const r of rows) byId[r.id] = r;

  const advancePctInput = req.body.advance_pct || {};
  const advanceTriggerTextInput = req.body.advance_trigger_text || {};
  const balancePctInput = req.body.balance_pct || {};
  const balanceTriggerOptionInput = req.body.balance_trigger_option || {};
  const balanceDaysInput = req.body.balance_days || {};

  const toApply = {};
  for (const [rawId, rawAdvancePct] of Object.entries(advancePctInput)) {
    const id = stripFieldPrefix(rawId);
    if (!byId[id]) continue;
    const submittedOption = balanceTriggerOptionInput[rawId];
    const newValues = {
      advance_pct: parseFloat(rawAdvancePct) || 0,
      advance_trigger_text: String(advanceTriggerTextInput[rawId] ?? '').trim(),
      balance_pct: parseFloat(balancePctInput[rawId] ?? 0) || 0,
      balance_trigger_option: ['A_BEFORE_SHIPMENT', 'B_AGAINST_BL'].includes(submittedOption)
        ? submittedOption : byId[id].balance_trigger_option,
      balance_days: parseInt(balanceDaysInput[rawId] ?? 0, 10) || 0,
    };
    const old = byId[id];
    const changedFields = {};
    for (const [field, value] of Object.entries(newValues)) {
      let oldValue = old[field];
      if (field === 'advance_pct' || field === 'balance_pct') {
        oldValue = parseFloat(oldValue);
      } else if (field === 'balance_days') {
        oldValue = parseInt(oldValue, 10);
      }
      if (oldValue !== value) {
        changedFields[field] = { old: oldValue, new: value };
      }
    }
    if (Object.keys(changedFields).length > 0) {
      toApply[id] = { new: newValues, changed: changedFields };
    }
  }

  if (Object.keys(toApply).length === 0) {
    flash.set(req, 'success', 'No changes were made.');
    res.redirect('/admin/overrides');
    return;
  }
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/admin/overrides');
    return;
  }

  const blockedProtected = Object.keys(toApply).filter((id) => byId[id].is_protected && !unlockedIds.has(parseInt(id, 10)));
  if (blockedProtected.length > 0) {
    flash.set(req, 'error', `Protected preset(s) must be unlocked before editing (id ${blockedProtected.join(', ')}) — nothing was saved.`);
    res.redirect('/admin/overrides');
    return;
  }

  for (const [id, c] of Object.entries(toApply)) {
    await adminOverrideRepository.updatePaymentPreset(
      id,
      c.new.advance_pct,
      c.new.advance_trigger_text,
      c.new.balance_pct,
      c.new.balance_trigger_option,
      c.new.balance_days
    );
    if (byId[id].is_protected) {
      await auditLogRepository.log(user.id, 'PROTECTED_FIELD_UNLOCKED', 'payment_presets', id, null, null, null, reason);
    }
    for (const [field, vals] of Object.entries(c.changed)) {
      await auditLogRepository.log(user.id, 'FIELD_EDIT', 'payment_presets', id, field, String(vals.old), String(vals.new), reason);
    }
  }
  flash.set(req, 'success', `${Object.keys(toApply).length} payment preset(s) updated.`);
  res.redirect('/admin/overrides');
}

module.exports = { index, updateDocumentTypes, updateTcClauses, updatePaymentPresets };
