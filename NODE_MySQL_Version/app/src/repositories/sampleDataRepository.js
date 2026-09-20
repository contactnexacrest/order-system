'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../config/db');
const env = require('../config/env');

/**
 * Phase E follow-up — Sample Data Playground (see sampleDataService for the
 * load side). This repository owns the ONE hard-delete routine in an
 * otherwise soft-delete-only codebase (see file_store.is_active and every
 * other table's convention). It is deliberately scoped to rows flagged
 * is_sample_data = 1 on clients/orders and everything that hangs off them —
 * it can never touch a real client or order, because it only ever selects
 * by that flag, never by name or id range.
 *
 * Deletion order matters: nothing here has ON DELETE CASCADE (checked —
 * see docs/schema.sql, no FK in the whole schema declares one), and two
 * pairs of tables reference each other in a genuine cycle
 * (documents <-> file_store, via docx_file_id/pdf_file_id and
 * linked_document_id). Those FK columns are nulled out first so both sides
 * can then be deleted in either order. Everything else is deleted
 * strictly children-before-parents. audit_log is deliberately left alone —
 * entity_id there is NOT a real foreign key (no constraint in the schema),
 * so historical log rows referencing a since-cleared sample client/order
 * remain valid, harmless history rather than orphaned references.
 */

async function isLoaded() {
  const row = await db.queryOne('SELECT COUNT(*) AS c FROM clients WHERE is_sample_data = 1');
  return parseInt(row.c, 10) > 0;
}

/** @returns {Promise<Array<object>>} sample clients with their orders, for the playground screen. */
async function summary() {
  const clients = await db.query('SELECT * FROM clients WHERE is_sample_data = 1 ORDER BY id');

  for (const client of clients) {
    client.orders = await db.query(
      `SELECT o.*, sm.stage_name AS current_stage_name
       FROM orders o
       LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
       WHERE o.client_id = :client_id AND o.is_sample_data = 1
       ORDER BY o.id`,
      { client_id: client.id }
    );
  }

  return clients;
}

/**
 * Hard-deletes every sample-flagged row and its generated files.
 * Returns a small report (counts) for the flash message. Safe to call
 * when nothing is loaded — every step is a no-op on empty id sets.
 */
async function clearAll() {
  const clientIds = await intColumn('SELECT id FROM clients WHERE is_sample_data = 1');
  const orderIds = await intColumn('SELECT id FROM orders WHERE is_sample_data = 1');

  if (!clientIds.length && !orderIds.length) {
    return { clients: 0, orders: 0, files: 0 };
  }

  // Captured BEFORE the transaction deletes these clients — the
  // storage directory is keyed by client_unique_number, which won't
  // be queryable once the row is gone.
  const storageDirs = await sampleClientStorageDirs(clientIds);

  const documentIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM documents WHERE order_id IN (%s)', orderIds)) : [];
  const amendmentIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM amendments WHERE order_id IN (%s)', orderIds)) : [];
  const disputeIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM disputes WHERE order_id IN (%s)', orderIds)) : [];
  const annexureProductIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM order_annexure_products WHERE order_id IN (%s)', orderIds)) : [];

  // file_store rows belong to the order and/or the client directly.
  let fileRows = [];
  if (orderIds.length || clientIds.length) {
    const clauses = [];
    if (orderIds.length) clauses.push(`order_id IN (${orderIds.join(',')})`);
    if (clientIds.length) clauses.push(`client_id IN (${clientIds.join(',')})`);
    fileRows = await db.query(`SELECT id, server_path FROM file_store WHERE ${clauses.join(' OR ')}`);
  }
  const fileIds = fileRows.map((r) => parseInt(r.id, 10));

  await db.transaction(async (conn) => {
    // --- Leaf/child rows first ---
    if (annexureProductIds.length) {
      await conn.execute(inQuery('DELETE FROM order_annexure_images WHERE order_annexure_product_id IN (%s)', annexureProductIds));
    }
    if (orderIds.length) {
      await conn.execute(inQuery('DELETE FROM order_annexure_products WHERE order_id IN (%s)', orderIds));
    }
    if (disputeIds.length) {
      await conn.execute(inQuery('DELETE FROM dispute_documents WHERE dispute_id IN (%s)', disputeIds));
    }
    if (orderIds.length) {
      await conn.execute(inQuery('DELETE FROM disputes WHERE order_id IN (%s)', orderIds));
    }
    if (documentIds.length) {
      await conn.execute(inQuery('DELETE FROM document_revisions WHERE document_id IN (%s)', documentIds));
      await conn.execute(inQuery('DELETE FROM document_reviews WHERE document_id IN (%s)', documentIds));
      await conn.execute(inQuery('DELETE FROM document_cross_verifications WHERE document_id IN (%s)', documentIds));
    }
    if (orderIds.length) {
      await conn.execute(inQuery('DELETE FROM email_log WHERE order_id IN (%s)', orderIds));
      await conn.execute(inQuery('DELETE FROM notifications WHERE related_order_id IN (%s)', orderIds));
    }

    // --- Break the documents <-> file_store cycle before deleting either ---
    if (documentIds.length) {
      await conn.execute(inQuery('UPDATE documents SET docx_file_id = NULL, pdf_file_id = NULL WHERE id IN (%s)', documentIds));
    }
    if (fileIds.length) {
      await conn.execute(inQuery('UPDATE file_store SET linked_document_id = NULL WHERE id IN (%s)', fileIds));
    }

    // --- Null every other file/document pointer held by rows we're about to delete ---
    if (amendmentIds.length) {
      await conn.execute(inQuery('UPDATE amendments SET signed_copy_file_id = NULL, document_id = NULL WHERE id IN (%s)', amendmentIds));
    }
    if (orderIds.length) {
      await conn.execute(inQuery('UPDATE orders SET active_amendment_id = NULL WHERE id IN (%s)', orderIds));
      await conn.execute(inQuery('UPDATE order_freight SET fdn_document_id = NULL WHERE order_id IN (%s)', orderIds));
      await conn.execute(inQuery('UPDATE order_packing SET fumigation_cert_file_id = NULL, buyer_approval_file_id = NULL WHERE order_id IN (%s)', orderIds));
      await conn.execute(inQuery('UPDATE order_shipping SET draft_bl_file_id = NULL WHERE order_id IN (%s)', orderIds));
    }

    // --- Now safe to delete documents and amendments ---
    if (documentIds.length) {
      await conn.execute(inQuery('DELETE FROM documents WHERE id IN (%s)', documentIds));
    }
    if (amendmentIds.length) {
      await conn.execute(inQuery('DELETE FROM amendments WHERE id IN (%s)', amendmentIds));
    }

    // --- file_store rows themselves (pointers all cleared above) ---
    if (fileIds.length) {
      await conn.execute(inQuery('DELETE FROM file_store WHERE id IN (%s)', fileIds));
    }

    // --- Remaining order-scoped 1:1 / 1:many tables ---
    if (orderIds.length) {
      const tables = [
        'order_stages', 'order_products', 'order_payment_status', 'order_production',
        'order_supplier_po', 'order_packing', 'order_crates', 'order_freight', 'order_shipping',
      ];
      for (const table of tables) {
        await conn.execute(inQuery(`DELETE FROM ${table} WHERE order_id IN (%s)`, orderIds));
      }
    }

    // --- Orders, then clients ---
    if (orderIds.length) {
      await conn.execute(inQuery('DELETE FROM orders WHERE id IN (%s)', orderIds));
    }
    if (clientIds.length) {
      await conn.execute(inQuery('DELETE FROM clients WHERE id IN (%s)', clientIds));
    }
  });

  // Physical files — deleted only after the DB transaction committed,
  // so a failed clear never leaves file_store rows pointing at
  // already-unlinked files.
  let deletedFiles = 0;
  for (const row of fileRows) {
    if (row.server_path && fileExists(row.server_path) && safeUnlink(row.server_path)) {
      deletedFiles++;
    }
  }

  // Best-effort cleanup of the per-client storage directories
  // (storage/clients/{client_unique_number}/...) captured above,
  // before the clients were deleted — never fatal if it can't,
  // since the DB side is already fully committed.
  for (const dir of storageDirs) {
    rrmdirIfEmpty(dir);
  }

  return { clients: clientIds.length, orders: orderIds.length, files: deletedFiles };
}

/** @param {Array<number>} ids */
function inQuery(template, ids) {
  return template.replace('%s', ids.join(','));
}

/** @returns {Promise<Array<number>>} */
async function intColumn(sql) {
  const rows = await db.query(sql);
  const key = rows.length ? Object.keys(rows[0])[0] : null;
  return rows.map((r) => parseInt(key ? r[key] : Object.values(r)[0], 10));
}

/** @param {Array<number>} clientIds @returns {Promise<Array<string>>} */
async function sampleClientStorageDirs(clientIds) {
  if (!clientIds.length) {
    return [];
  }
  const rows = await db.query(`SELECT client_unique_number FROM clients WHERE id IN (${clientIds.join(',')})`);
  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  return rows.map((row) => {
    const safe = String(row.client_unique_number ?? '').replace(/[^A-Za-z0-9_-]+/g, '-');
    return `${storageBase}/clients/${safe}`;
  });
}

function fileExists(p) {
  try {
    return fs.statSync(p).isFile();
  } catch {
    return false;
  }
}

function safeUnlink(p) {
  try {
    fs.unlinkSync(p);
    return true;
  } catch {
    return false;
  }
}

function rrmdirIfEmpty(dir) {
  let stat;
  try {
    stat = fs.statSync(dir);
  } catch {
    return;
  }
  if (!stat.isDirectory()) {
    return;
  }
  // Remove files/subfolders bottom-up (all sample-generated — safe),
  // then the directory itself.
  removeRecursiveChildFirst(dir);
  try {
    fs.rmdirSync(dir);
  } catch {
    // not fatal — best-effort cleanup only
  }
}

function removeRecursiveChildFirst(dir) {
  let entries;
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch {
    return;
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      removeRecursiveChildFirst(full);
      try {
        fs.rmdirSync(full);
      } catch {
        // ignore
      }
    } else {
      try {
        fs.unlinkSync(full);
      } catch {
        // ignore
      }
    }
  }
}

module.exports = { isLoaded, summary, clearAll };
