'use strict';

const fs = require('fs');
const fileStoreRepository = require('../repositories/fileStoreRepository');

/**
 * Streams one file_store file as a download, or a plain 404. Used by the
 * no-login Buyer/Supplier Action Link pages for the one document each may
 * download (owner request 2026-10-26).
 */
async function sendStoredFile(res, fileId) {
  const file = fileId ? await fileStoreRepository.find(fileId) : null;
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('Document not found.');
    return;
  }
  let safeDownloadName = file.original_filename.replace(/[\\/]/g, '-');
  // eslint-disable-next-line no-control-regex
  safeDownloadName = safeDownloadName.replace(/[\x00-\x1F\x7F"]/g, '');
  res.setHeader('Content-Type', file.mime_type);
  res.setHeader('Content-Disposition', `attachment; filename="${safeDownloadName}"`);
  res.setHeader('Content-Length', String(file.file_size_bytes));
  fs.createReadStream(file.server_path).pipe(res);
}

module.exports = { sendStoredFile };
