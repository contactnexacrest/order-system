'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../config/db');
const env = require('../config/env');

/**
 * Test Mode — a global, system-wide switch distinct from the Sample Data
 * Playground (sampleDataRepository). See docs/schema.sql Section V for the
 * full rationale. This repository owns the settings row, the "any test
 * data present" check that gates the Disable/Delete buttons, and the one
 * hard-delete routine — modeled directly on sampleDataRepository.clearAll()
 * (same FK-safe ordering, same cycle-breaking technique for
 * documents <-> file_store), extended to cover the extra order/client-
 * linked tables that didn't exist yet when that routine was written
 * (order_buyer_po_documents, order_supplier_po_documents, client_logins,
 * client_password_reset_tokens, client_intake_submissions).
 */

async function getSettings() {
  return db.queryOne('SELECT * FROM test_mode_settings WHERE id = 1');
}

async function setEnabled(enabled, userId) {
  if (enabled) {
    await db.execute(
      `UPDATE test_mode_settings
       SET is_enabled = 1, enabled_at = CURRENT_TIMESTAMP, enabled_by = :user_id, disabled_at = NULL
       WHERE id = 1`,
      { user_id: userId }
    );
  } else {
    await db.execute(
      `UPDATE test_mode_settings SET is_enabled = 0, disabled_at = CURRENT_TIMESTAMP WHERE id = 1`
    );
  }
}

async function setTestEmail(email) {
  await db.execute('UPDATE test_mode_settings SET test_email = :email WHERE id = 1', { email });
}

/** @returns {Promise<{clients: number, orders: number, suppliers: number}>} */
async function testDataCounts() {
  const [clients, orders, suppliers] = await Promise.all([
    db.queryOne('SELECT COUNT(*) AS c FROM clients WHERE is_test_data = 1'),
    db.queryOne('SELECT COUNT(*) AS c FROM orders WHERE is_test_data = 1'),
    db.queryOne('SELECT COUNT(*) AS c FROM suppliers WHERE is_test_data = 1'),
  ]);
  return {
    clients: parseInt(clients.c, 10),
    orders: parseInt(orders.c, 10),
    suppliers: parseInt(suppliers.c, 10),
  };
}

async function hasTestData() {
  const counts = await testDataCounts();
  return counts.clients > 0 || counts.orders > 0 || counts.suppliers > 0;
}

/**
 * Hard-deletes every test-flagged row and its generated files. Returns a
 * small report (counts) for the flash message. Safe to call when nothing
 * is loaded — every step is a no-op on empty id sets. See
 * sampleDataRepository.clearAll()'s docblock for why deletion order
 * matters (no ON DELETE CASCADE anywhere in this schema, and a genuine
 * documents <-> file_store reference cycle).
 */
async function clearAllTestData() {
  const clientIds = await intColumn('SELECT id FROM clients WHERE is_test_data = 1');
  const orderIds = await intColumn('SELECT id FROM orders WHERE is_test_data = 1');

  if (!clientIds.length && !orderIds.length) {
    await db.execute('DELETE FROM suppliers WHERE is_test_data = 1');
    return { clients: 0, orders: 0, files: 0 };
  }

  // Captured BEFORE the transaction deletes these clients — the storage
  // directory is keyed by client_unique_number, unqueryable once gone.
  const storageDirs = await testClientStorageDirs(clientIds);

  const documentIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM documents WHERE order_id IN (%s)', orderIds)) : [];
  const amendmentIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM amendments WHERE order_id IN (%s)', orderIds)) : [];
  const disputeIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM disputes WHERE order_id IN (%s)', orderIds)) : [];
  const annexureProductIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM order_annexure_products WHERE order_id IN (%s)', orderIds)) : [];
  const supplierPoIds = orderIds.length ? await intColumn(inQuery('SELECT id FROM order_supplier_po WHERE order_id IN (%s)', orderIds)) : [];
  const clientLoginIds = clientIds.length ? await intColumn(inQuery('SELECT id FROM client_logins WHERE client_id IN (%s)', clientIds)) : [];

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
      await conn.execute(inQuery('DELETE FROM order_buyer_po_documents WHERE order_id IN (%s)', orderIds));
    }
    if (supplierPoIds.length) {
      await conn.execute(inQuery('DELETE FROM order_supplier_po_documents WHERE order_supplier_po_id IN (%s)', supplierPoIds));
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
    if (clientIds.length) {
      await conn.execute(inQuery('DELETE FROM client_password_reset_tokens WHERE client_id IN (%s)', clientIds));
      await conn.execute(inQuery('UPDATE client_intake_submissions SET converted_client_id = NULL WHERE converted_client_id IN (%s)', clientIds));
    }
    if (clientLoginIds.length) {
      await conn.execute(inQuery('DELETE FROM client_logins WHERE id IN (%s)', clientLoginIds));
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

    // --- Test suppliers (order_supplier_po rows pointing at them are
    // already gone via the order-scoped delete loop above) ---
    await conn.execute('DELETE FROM suppliers WHERE is_test_data = 1');
  });

  // Physical files — deleted only after the DB transaction committed, so a
  // failed clear never leaves file_store rows pointing at already-unlinked
  // files.
  let deletedFiles = 0;
  for (const row of fileRows) {
    if (row.server_path && fileExists(row.server_path) && safeUnlink(row.server_path)) {
      deletedFiles++;
    }
  }

  // Best-effort cleanup of the per-client storage directories
  // (storage/clients/{client_unique_number}/...) captured above, before
  // the clients were deleted — never fatal if it can't, since the DB side
  // is already fully committed.
  for (const dir of storageDirs) {
    rrmdirIfEmpty(dir);
  }

  return { clients: clientIds.length, orders: orderIds.length, files: deletedFiles };
}

function inQuery(template, ids) {
  return template.replace('%s', ids.join(','));
}

async function intColumn(sql) {
  const rows = await db.query(sql);
  const key = rows.length ? Object.keys(rows[0])[0] : null;
  return rows.map((r) => parseInt(key ? r[key] : Object.values(r)[0], 10));
}

async function testClientStorageDirs(clientIds) {
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

/** Used by the audit-log writer to skip test-data actions (docs/schema.sql Section V). */
async function isTestEntity(entityType, entityId) {
  if (!entityId) return false;
  switch (entityType) {
    case 'clients':
      return boolRow('SELECT is_test_data AS v FROM clients WHERE id = :id', { id: entityId });
    case 'orders':
      return boolRow('SELECT is_test_data AS v FROM orders WHERE id = :id', { id: entityId });
    case 'documents':
      return boolRow(
        'SELECT o.is_test_data AS v FROM documents d JOIN orders o ON o.id = d.order_id WHERE d.id = :id',
        { id: entityId }
      );
    case 'amendments':
      return boolRow(
        'SELECT o.is_test_data AS v FROM amendments a JOIN orders o ON o.id = a.order_id WHERE a.id = :id',
        { id: entityId }
      );
    case 'disputes':
      return boolRow(
        'SELECT o.is_test_data AS v FROM disputes di JOIN orders o ON o.id = di.order_id WHERE di.id = :id',
        { id: entityId }
      );
    case 'email_log':
      return boolRow(
        'SELECT o.is_test_data AS v FROM email_log e JOIN orders o ON o.id = e.order_id WHERE e.id = :id',
        { id: entityId }
      );
    default:
      return false;
  }
}

async function boolRow(sql, params) {
  const row = await db.queryOne(sql, params);
  return !!(row && parseInt(row.v, 10) === 1);
}

module.exports = {
  getSettings,
  setEnabled,
  setTestEmail,
  testDataCounts,
  hasTestData,
  clearAllTestData,
  isTestEntity,
};
