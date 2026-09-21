'use strict';

const fs = require('fs');
const fileStoreRepository = require('../repositories/fileStoreRepository');

/**
 * Real gap this closes: every "received" upload (dispute_document,
 * amendment_signed_copy, buyer_approval, and now buyer_po_copy/
 * supplier_po_ack) had a working upload path but no download path at
 * all anywhere in the app — staff could attach evidence but never
 * retrieve it again. One generic, permission-gated download route for
 * any file_store row, mirroring documentController.download()'s header
 * handling exactly.
 */
async function download(req, res) {
  const fileId = parseInt(req.params.id, 10);
  const file = await fileStoreRepository.find(fileId);
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('File is missing from storage.');
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

module.exports = { download };
