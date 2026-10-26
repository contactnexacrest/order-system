'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../config/db');
const env = require('../config/env');
const orderStorage = require('../helpers/orderStorage');

/**
 * Port of App\Services\OrderFileReorganizer. Moves an order's existing
 * files into the step-named folders of helpers/orderStorage (owner request
 * 2026-10-26) and updates file_store.server_path — the one place every
 * order file's location is recorded (documents, revisions, uploads all
 * point at a file_store row by id, never at a path). Run from
 * src/jobs/reorganizeOrderFiles.js: a dry run by default, listing every
 * planned move; --apply performs them.
 *
 * Where a file belongs:
 *  - a generated document (or an earlier revision of one) goes to the
 *    folder of its own document type — fixing files that landed under
 *    whatever stage the order happened to be at when they were generated;
 *  - an uploaded file is placed by the folder it was uploaded to
 *    (buyer_po/, shipping/, ...), mapped to its step folder;
 *  - anything already in the new layout is left alone, and anything not
 *    recognised is reported and left where it is.
 */

// Old upload folder (first segment after the order folder) => [area, sub-folder].
const LEGACY_UPLOAD_FOLDERS = {
  buyer_po: ['buyer_po', 'received'],
  supplier_po: ['supplier_po', 'received'],
  shipping: ['packing_bl', 'received'],
  packing: ['packing_bl', 'received'],
  lc: ['lc', null],
  annexure: ['annexure', null],
  additional_documents: ['additional_documents', null],
  client_payment_reports: ['payments', null],
  comment_attachments: ['order_updates', null],
  'internal-ca': ['financials', 'generated'],
  'bl-endorsement': ['closure', 'generated'],
};

const NEW_LAYOUT_ROOTS = new Set(Object.values(orderStorage.AREAS).map((f) => f.split('/')[0]));

/**
 * An older render no longer referenced by its document (replaced by a later
 * regeneration) still says what it is: generated files are named
 * "<TYPE CODE> <reference> Rev.N.pdf".
 */
function typeFromGeneratedFilename(row) {
  if (!String(row.file_origin || '').startsWith('GENERATED')) {
    return null;
  }
  const code = String(row.original_filename || '').split(' ')[0];
  return orderStorage.DOCUMENT_TYPE_AREAS[code] ? code : null;
}

/** @returns {[string|null, string]} [target folder relative to storage, reason] — folder null = leave it */
function targetFolder(filePath, order, documentTypeCode) {
  if (documentTypeCode && documentTypeCode !== 'AMD') {
    return [orderStorage.forGeneratedDocument(order, documentTypeCode), `generated ${documentTypeCode}`];
  }
  const m = String(filePath).match(/\/clients\/[^/]+\/[^/]+\/(.+)\/[^/]+$/);
  if (!m) {
    return [null, 'not under an order folder — left in place'];
  }
  const relative = m[1];
  const parts = relative.split('/');
  const first = parts[0];

  if (first === 'amendments' && parts.length >= 3) {
    const sub = parts[2] === 'signed' ? 'received' : parts[2];
    return [orderStorage.folder(order, 'amendments', `${parts[1]}/${sub}`), 'amendment file'];
  }
  if (first === 'disputes' && parts.length >= 2) {
    return [orderStorage.folder(order, 'disputes', parts[1]), 'dispute file'];
  }
  if (LEGACY_UPLOAD_FOLDERS[first]) {
    const [area, sub] = LEGACY_UPLOAD_FOLDERS[first];
    return [orderStorage.folder(order, area, sub), `${first}/ upload`];
  }
  if (NEW_LAYOUT_ROOTS.has(first)) {
    // Already in the new layout (possibly under an old client/order folder
    // name) — keep its place within the order.
    return [`${orderStorage.orderRoot(order)}/${relative}`, 'new layout'];
  }
  return [null, `unrecognised folder '${relative}' — left in place`];
}

/** @returns {Promise<Array<{file_id:number, from:string, to:string|null, note:string}>>} */
async function plan() {
  const docTypeByFile = {};
  const typed = await db.query(
    `SELECT f.file_id, dt.code FROM (
        SELECT pdf_file_id AS file_id, document_type_id FROM documents WHERE pdf_file_id IS NOT NULL
        UNION ALL SELECT docx_file_id, document_type_id FROM documents WHERE docx_file_id IS NOT NULL
        UNION ALL SELECT r.pdf_file_id, d.document_type_id FROM document_revisions r JOIN documents d ON d.id = r.document_id WHERE r.pdf_file_id IS NOT NULL
        UNION ALL SELECT r.docx_file_id, d.document_type_id FROM document_revisions r JOIN documents d ON d.id = r.document_id WHERE r.docx_file_id IS NOT NULL
     ) f JOIN document_types dt ON dt.id = f.document_type_id`
  );
  for (const row of typed) {
    docTypeByFile[Number(row.file_id)] = row.code;
  }

  const base = (env.storageBasePath() || '').replace(/\/+$/, '');
  const rows = await db.query(
    `SELECT fs.id, fs.server_path, fs.file_origin, fs.original_filename, c.client_unique_number, o.order_reference
       FROM file_store fs
       JOIN orders o ON o.id = fs.order_id
       JOIN clients c ON c.id = o.client_id
      ORDER BY fs.id`
  );

  return rows.map((row) => {
    const fileId = Number(row.id);
    const from = String(row.server_path);
    const order = { client_unique_number: row.client_unique_number, order_reference: row.order_reference };
    const [folder, note] = targetFolder(from, order, docTypeByFile[fileId] || typeFromGeneratedFilename(row));
    if (folder === null) {
      return { file_id: fileId, from, to: null, note };
    }
    // Stays under the storage root it already lives in.
    const rootMatch = from.match(/^(.*)\/clients\//);
    const to = `${rootMatch ? rootMatch[1] : base}/${folder}/${path.basename(from)}`;
    return { file_id: fileId, from, to: to === from ? null : to, note: to === from ? 'already in place' : note };
  });
}

/** Tidies away the old folders a move left empty, stopping at the order folder. */
function removeEmptyParents(dir) {
  let current = dir;
  while (/\/clients\/[^/]+\/[^/]+\/.+/.test(current) && fs.existsSync(current) && fs.readdirSync(current).length === 0) {
    try {
      fs.rmdirSync(current);
    } catch (e) {
      return;
    }
    current = path.dirname(current);
  }
}

/**
 * Performs the planned moves. A file missing from disk is reported and its
 * path left unchanged; every successful move updates its row at once, so an
 * interrupted run can simply be run again.
 */
async function apply(planned) {
  const result = { moved: 0, missing: 0, failed: 0, errors: [] };
  for (const item of planned) {
    if (item.to === null) {
      continue;
    }
    if (!fs.existsSync(item.from)) {
      result.missing++;
      result.errors.push(`file #${item.file_id} missing on disk: ${item.from}`);
      continue;
    }
    try {
      fs.mkdirSync(path.dirname(item.to), { recursive: true });
      fs.renameSync(item.from, item.to);
    } catch (e) {
      result.failed++;
      result.errors.push(`file #${item.file_id}: cannot move to ${item.to} (${e.message})`);
      continue;
    }
    await db.execute('UPDATE file_store SET server_path = :path WHERE id = :id', { path: item.to, id: item.file_id });
    result.moved++;
    removeEmptyParents(path.dirname(item.from));
  }
  return result;
}

module.exports = { plan, apply, targetFolder };
