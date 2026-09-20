'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const env = require('../config/env');
const db = require('../config/db');
const fileStoreRepository = require('../repositories/fileStoreRepository');

/**
 * Handles a real uploaded file (spec Section 3 / Section 14 "FILE UPLOADS":
 * type validation, size limits, UUID naming, outside web root). Validation
 * limits come from file_upload_contexts — never hardcoded per call site.
 *
 * Only two upload contexts are wired to an actual screen in this delivery:
 * amendment_signed_copy and dispute_document (Phase D scope). The others
 * seeded in file_upload_contexts (draft_bl, fumigation_cert, ...) are
 * reserved for a future phase's upload screens and this service works for
 * them unchanged whenever that's built — it has no idea what a "draft BL"
 * is, same judgment call as companySettingsRepository.
 *
 * req is the Express request; the actual bytes are expected already parsed
 * into memory by multer (upload.single(formFieldName)), matching the
 * memoryStorage pattern already used for company asset uploads.
 */

async function findContext(contextKey) {
  return db.queryOne('SELECT * FROM file_upload_contexts WHERE context_key = :key', { key: contextKey });
}

/**
 * @param {import('express').Request} req
 * @param {string} formFieldName e.g. 'signed_copy' — the <input type="file" name="...">
 * @throws on missing file, disallowed extension, or oversize
 */
async function handleUpload(
  req,
  formFieldName,
  contextKey,
  subPath,
  clientId,
  orderId,
  uploadedBy,
  originalFilenameOverride = null,
  receivedFrom = null,
  documentTypeLabel = null
) {
  const upload = req.file;
  if (!upload || !upload.buffer) {
    throw new Error('No file was uploaded, or the upload failed.');
  }

  const context = await findContext(contextKey);
  if (!context) {
    throw new Error(`Unknown upload context: ${contextKey}`);
  }

  const originalName = originalFilenameOverride || upload.originalname;
  const extension = path.extname(originalName).replace(/^\./, '').toLowerCase();
  const allowed = String(context.allowed_extensions).toLowerCase().split(',').map((s) => s.trim());
  if (!allowed.includes(extension)) {
    throw new Error(`File type .${extension} is not allowed for this upload (allowed: ${context.allowed_extensions}).`);
  }

  if (upload.size > parseInt(context.max_size_bytes, 10)) {
    const maxMb = Math.round((parseInt(context.max_size_bytes, 10) / 1048576) * 10) / 10;
    throw new Error(`File is too large — maximum is ${maxMb} MB for this upload.`);
  }

  const storageBase = (env.get('STORAGE_BASE_PATH', '') || '').replace(/\/+$/, '');
  const targetDir = path.join(storageBase, subPath);
  fs.mkdirSync(targetDir, { recursive: true });

  const uuidFilename = `${crypto.randomBytes(16).toString('hex')}.${extension}`;
  const targetPath = path.join(targetDir, uuidFilename);

  fs.writeFileSync(targetPath, upload.buffer);

  return fileStoreRepository.insertReceived(
    clientId,
    orderId,
    targetPath,
    uuidFilename,
    originalName,
    fs.statSync(targetPath).size,
    upload.mimetype || 'application/octet-stream',
    uploadedBy,
    receivedFrom,
    documentTypeLabel
  );
}

module.exports = { handleUpload };
