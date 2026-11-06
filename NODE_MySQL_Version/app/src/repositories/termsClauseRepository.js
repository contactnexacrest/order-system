'use strict';

const db = require('../config/db');

/** @returns {Promise<Array<{title:string,text:string,is_locked:boolean}>>} ordered clauses for a document type code */
async function forDocumentTypeCode(documentTypeCode) {
  return forDocumentTypeCodeAndGroup(documentTypeCode, 'standard', null);
}

/**
 * Legal Terms & Definitions. Same table, same admin-edit story as every
 * other clause (status/text/clause_order are just ordinary row edits) —
 * `clause_group` only changes WHERE a clause renders (T&C body vs. the
 * Legal Terms box vs. the Definitions box), and `visibility_rule` is the
 * one new thing: a clause can be scoped to only the orders whose own
 * payment-preset balance trigger matches, evaluated here rather than
 * hardcoded in any template. $balanceTriggerOption is the order's own
 * payment_presets.balance_trigger_option (A_BEFORE_SHIPMENT /
 * B_AGAINST_BL, via orders.balance_trigger_option_override) — pass null
 * when no order context applies (never matches a conditional
 * visibility_rule, so only 'always' rows come back).
 *
 * @returns {Promise<Array<{title:string,text:string,is_locked:boolean}>>}
 */
async function liveRows(documentTypeCode, clauseGroup) {
  return db.query(
    `SELECT c.clause_title, c.clause_text, c.is_locked, c.visibility_rule, c.clause_set
     FROM tc_clauses c
     JOIN tc_clause_documents cd ON cd.clause_id = c.id
     JOIN document_types dt ON dt.id = cd.document_type_id
     WHERE dt.code = :code AND c.status = 'active' AND c.clause_group = :clause_group
     ORDER BY c.clause_order`,
    { code: documentTypeCode, clause_group: clauseGroup }
  );
}

/**
 * D-262: every document type's clauses in every group, exactly as the live query
 * returns them — what an order keeps when its PI is approved. Port of captureAll().
 */
async function captureAll() {
  const codes = (await db.query('SELECT code FROM document_types ORDER BY code')).map((r) => r.code);
  const groups = (await db.query('SELECT DISTINCT clause_group FROM tc_clauses ORDER BY clause_group')).map((r) => r.clause_group);
  const out = {};
  for (const code of codes) {
    for (const group of groups) {
      const rows = await liveRows(code, group);
      if (rows.length) {
        out[`${code}|${group}`] = rows.map((r) => ({
          clause_title: r.clause_title, clause_text: r.clause_text, is_locked: r.is_locked, visibility_rule: r.visibility_rule, clause_set: r.clause_set,
        }));
      }
    }
  }
  return out;
}

async function forDocumentTypeCodeAndGroup(documentTypeCode, clauseGroup, balanceTriggerOption, clauseSets = null) {
  // D-262: an order's frozen clauses while its documents are made (orderSnapshotService.within).
  const frozen = require('../helpers/orderSnapshotContext').current();
  const rows = frozen && frozen.terms
    ? (frozen.terms[`${documentTypeCode}|${clauseGroup}`] || [])
    : await liveRows(documentTypeCode, clauseGroup);

  // D-257 Phase 1: a clause with a clause set prints only when one of the order's
  // categories uses that set; an empty set = every order (today's clauses). No
  // order context = Natural Stone, as before.
  const sets = clauseSets ?? ['natural_stone'];
  const visible = rows.filter((row) => {
    if (!inClauseSets(row.clause_set, sets)) return false;
    if (row.visibility_rule === 'balance_trigger_before_shipment') {
      return balanceTriggerOption === 'A_BEFORE_SHIPMENT';
    }
    if (row.visibility_rule === 'balance_trigger_against_bl') {
      return balanceTriggerOption === 'B_AGAINST_BL';
    }
    return true; // 'always'
  });

  return visible.map((row) => ({ title: row.clause_title, text: row.clause_text, is_locked: !!row.is_locked }));
}

function inClauseSets(clauseSet, orderSets) {
  const own = String(clauseSet ?? '').split(',').map((v) => v.trim()).filter((v) => v !== '');
  return own.length === 0 || own.some((v) => orderSets.includes(v));
}

module.exports = { forDocumentTypeCode, forDocumentTypeCodeAndGroup, inClauseSets, captureAll };
