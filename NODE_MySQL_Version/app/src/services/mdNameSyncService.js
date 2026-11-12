'use strict';

/**
 * D-349 (owner, 12 Nov 2026, confirm 1 + confirm 2): the Managing Director's name
 * and title kept in sync on open orders and draft documents.
 *
 * When md_name / md_title is changed in Settings › Company, the person saving
 * chooses "Save and apply" or "Save setting only". Apply changes:
 *   - open orders (status not complete / lost / cancelled) whose kept company
 *     details (frozen_snapshot_json.company) still show the old value;
 *   - documents still in Draft / In review whose kept signatory is the fallback
 *     (signatory_user_id IS NULL) and whose snapshot equals the old value.
 * On those documents the kept company block and the stored render context (what the FINAL
 * copy is re-rendered from on approval) take the new value too.
 * Approved and sent documents, and closed / lost / cancelled orders, never change.
 * A protected founder login's own name is never changed (a DB trigger refuses
 * it on purpose): the panel only says so.
 *
 * userRenamed(): Admin › Users — a non-protected signatory-eligible user's new
 * name goes onto that user's documents still in Draft / In review.
 *
 * Port of App\Services\MdNameSyncService.
 */

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');

const CLOSED_ORDER_STATUSES = ['complete', 'lost', 'cancelled'];
const OPEN_DOCUMENT_STATUSES = ['draft', 'in_review'];
const COLUMNS = { md_name: 'signatory_name_snapshot', md_title: 'signatory_designation_snapshot' };

/** The md_name / md_title entries of a settings change set, as plain strings. */
function onlyMdKeys(changes) {
  const out = {};
  for (const key of ['md_name', 'md_title']) {
    const c = changes && changes[key];
    if (c && String(c.old ?? '') !== String(c.new ?? '')) {
      out[key] = { old: String(c.old ?? ''), new: String(c.new ?? '') };
    }
  }
  return out;
}

function parseJson(raw) {
  if (raw && typeof raw === 'object') return raw;
  if (typeof raw !== 'string' || raw === '') return null;
  try {
    return JSON.parse(raw);
  } catch (e) {
    return null;
  }
}

/** Info line when the default signatory is a protected founder login whose name differs from the new md_name. */
async function founderNote(newMdName) {
  const row = await db.queryOne(
    'SELECT u.name, u.is_protected_account FROM company_default_signatory cds JOIN users u ON u.id = cds.user_id WHERE cds.id = 1'
  );
  if (!row || parseInt(row.is_protected_account, 10) !== 1 || String(row.name) === newMdName) {
    return null;
  }
  return 'The name printed under the signature comes from the founder login, which the app cannot change on purpose. '
    + 'Change it with the founder SQL block in the README (First-time setup).';
}

async function fallbackDocuments(changes) {
  const byId = new Map();
  for (const [key, c] of Object.entries(changes)) {
    const col = COLUMNS[key];
    const rows = await db.query(
      `SELECT id, document_reference, status FROM documents
        WHERE signatory_user_id IS NULL AND ${col} = :old AND status IN ('draft', 'in_review', 'approved', 'sent')
        ORDER BY id`,
      { old: c.old }
    );
    for (const row of rows) {
      const id = Number(row.id);
      if (!byId.has(id)) byId.set(id, { id, document_reference: row.document_reference, status: String(row.status), keys: [] });
      byId.get(id).keys.push(key);
    }
  }
  return [...byId.values()].sort((a, b) => a.id - b.id);
}

/**
 * @param {Object<string,{old:string,new:string}>} changes keyed md_name / md_title
 */
async function preview(changes) {
  changes = onlyMdKeys(changes);
  const orders = [];
  let closedOrders = 0;
  if (Object.keys(changes).length > 0) {
    const rows = await db.query(
      'SELECT id, order_reference, status, frozen_snapshot_json FROM orders WHERE frozen_snapshot_json IS NOT NULL ORDER BY id'
    );
    for (const row of rows) {
      const snap = parseJson(row.frozen_snapshot_json);
      const company = snap && snap.company && typeof snap.company === 'object' ? snap.company : null;
      if (!company) continue;
      const keys = Object.keys(changes).filter((key) => Object.prototype.hasOwnProperty.call(company, key)
        && String(company[key] ?? '') === changes[key].old);
      if (keys.length > 0 && CLOSED_ORDER_STATUSES.includes(String(row.status))) {
        closedOrders += 1;
      } else if (keys.length > 0) {
        orders.push({ id: Number(row.id), ref: String(row.order_reference), keys });
      }
    }
  }

  const documents = [];
  let notChanged = 0;
  for (const doc of await fallbackDocuments(changes)) {
    if (OPEN_DOCUMENT_STATUSES.includes(doc.status)) {
      documents.push({ id: doc.id, ref: String(doc.document_reference ?? `#${doc.id}`), keys: doc.keys });
    } else if (['approved', 'sent'].includes(doc.status)) {
      notChanged += 1;
    }
  }

  return {
    orders,
    documents,
    not_changed_documents: notChanged,
    not_changed_orders: closedOrders,
    founder_note: changes.md_name ? await founderNote(changes.md_name.new) : null,
  };
}

/**
 * The stored render context of a Draft / In-review document (documents.render_context_json,
 * what the FINAL copy is re-rendered from on approval): the given text fields, where they
 * still show the old value. Images stay as their stored references.
 * @param edits Array<[[block, field], old, new]>
 */
async function syncRenderContext(conn, documentId, edits) {
  const row = await conn.queryOne("SELECT render_context_json FROM documents WHERE id = :id AND status IN ('draft', 'in_review')", { id: documentId });
  const context = parseJson(row && row.render_context_json);
  if (!context || typeof context !== 'object') return;
  let changed = false;
  for (const [[block, field], oldValue, newValue] of edits) {
    const b = context[block];
    if (b && typeof b === 'object' && Object.prototype.hasOwnProperty.call(b, field) && String(b[field] ?? '') === oldValue) {
      b[field] = newValue;
      changed = true;
    }
  }
  if (changed) {
    await conn.execute('UPDATE documents SET render_context_json = :c WHERE id = :id', { c: JSON.stringify(context), id: documentId });
  }
}

function auditReason(reason, where) {
  return `D-349 apply from ${where}${reason ? `: ${reason}` : ''}`.slice(0, 500);
}

/**
 * Applies the preview: order snapshots and draft / in-review fallback signatory snapshots.
 * Every change is written to the audit log (old -> new, which order, which document).
 */
async function apply(changes, userId, reason, applyOrders = true, applyDocuments = true) {
  changes = onlyMdKeys(changes);
  const plan = await preview(changes);
  const orders = applyOrders ? plan.orders : [];
  const documents = applyDocuments ? plan.documents : [];

  await db.transaction(async (tx) => {
    for (const o of orders) {
      const row = await tx.queryOne('SELECT frozen_snapshot_json FROM orders WHERE id = :id', { id: o.id });
      const snap = parseJson(row && row.frozen_snapshot_json);
      if (!snap) continue;
      for (const key of o.keys) snap.company[key] = changes[key].new;
      await tx.execute('UPDATE orders SET frozen_snapshot_json = :j WHERE id = :id', { j: JSON.stringify(snap), id: o.id });
    }
    for (const d of documents) {
      for (const key of d.keys) {
        const col = COLUMNS[key];
        await tx.execute(
          `UPDATE documents SET ${col} = :new
            WHERE id = :id AND signatory_user_id IS NULL AND status IN ('draft', 'in_review') AND ${col} = :old`,
          { new: changes[key].new, old: changes[key].old, id: d.id }
        );
      }
      // The same document's own kept company block (company_snapshot_json), where it still shows the old value.
      const row = await tx.queryOne('SELECT company_snapshot_json FROM documents WHERE id = :id', { id: d.id });
      const company = parseJson(row && row.company_snapshot_json);
      if (company && typeof company === 'object') {
        let changed = false;
        for (const key of d.keys) {
          if (Object.prototype.hasOwnProperty.call(company, key) && String(company[key] ?? '') === changes[key].old) {
            company[key] = changes[key].new;
            changed = true;
          }
        }
        if (changed) {
          await tx.execute('UPDATE documents SET company_snapshot_json = :j WHERE id = :id', { j: JSON.stringify(company), id: d.id });
        }
      }
      const edits = [];
      for (const key of d.keys) {
        edits.push([['company', key], changes[key].old, changes[key].new]);
        edits.push([['signatory', key === 'md_name' ? 'name' : 'designation'], changes[key].old, changes[key].new]);
      }
      await syncRenderContext(tx, d.id, edits);
    }
  });

  for (const o of orders) {
    for (const key of o.keys) {
      await auditLogRepository.log(userId, 'ORDER_MD_DETAILS_SYNCED', 'orders', o.id, `frozen_snapshot_json.company.${key}`,
        changes[key].old, changes[key].new, auditReason(reason, 'Settings › Company'));
    }
  }
  for (const d of documents) {
    for (const key of d.keys) {
      await auditLogRepository.log(userId, 'DOCUMENT_SIGNATORY_SNAPSHOT_SYNCED', 'documents', d.id, COLUMNS[key],
        changes[key].old, changes[key].new, auditReason(reason, 'Settings › Company'));
    }
  }
  return { orders: orders.length, documents: documents.length };
}

/**
 * Admin › Users: a signatory-eligible (non-protected) user's name changed — their
 * documents still in Draft / In review take the new name. Approved and sent never change.
 */
async function userRenamed(targetUserId, oldName, newName, actorId) {
  if (oldName === newName) return 0;
  const u = await db.queryOne('SELECT is_signatory_eligible, is_protected_account FROM users WHERE id = :id', { id: targetUserId });
  if (!u || parseInt(u.is_protected_account, 10) === 1 || parseInt(u.is_signatory_eligible, 10) !== 1) {
    return 0;
  }
  const docs = await db.query(
    `SELECT id, signatory_name_snapshot FROM documents
      WHERE signatory_user_id = :u AND status IN ('draft', 'in_review')
        AND (signatory_name_snapshot IS NULL OR signatory_name_snapshot <> :new)
      ORDER BY id`,
    { u: targetUserId, new: newName }
  );
  let n = 0;
  for (const d of docs) {
    await db.execute("UPDATE documents SET signatory_name_snapshot = :new WHERE id = :id AND status IN ('draft', 'in_review')", { new: newName, id: d.id });
    await syncRenderContext(db, Number(d.id), [[['signatory', 'name'], oldName, newName]]);
    await auditLogRepository.log(actorId, 'DOCUMENT_SIGNATORY_SNAPSHOT_SYNCED', 'documents', Number(d.id), 'signatory_name_snapshot',
      d.signatory_name_snapshot !== null && d.signatory_name_snapshot !== undefined ? String(d.signatory_name_snapshot) : null, newName,
      `Signatory renamed in Admin › Users (user #${targetUserId})`);
    n += 1;
  }
  return n;
}

module.exports = {
  CLOSED_ORDER_STATUSES,
  OPEN_DOCUMENT_STATUSES,
  onlyMdKeys,
  founderNote,
  preview,
  apply,
  userRenamed,
};
