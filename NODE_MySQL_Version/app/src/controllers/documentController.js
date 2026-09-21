'use strict';

const fs = require('fs');
const flash = require('../helpers/flash');
const documentRepository = require('../repositories/documentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const documentGenerationService = require('../services/documentGenerationService');
const stageGateService = require('../services/stageGateService');

// Port of App\Controllers\DocumentController.

const ALLOWED_TYPES = ['QT', 'ANNEXA', 'PI', 'OC', 'BUYERPO', 'SUPPO', 'FDN', 'PL', 'BLI', 'CI', 'COOPREP'];

async function generate(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const type = String(req.body.document_type || '').toUpperCase();
  const user = req.user;

  if (!ALLOWED_TYPES.includes(type)) {
    flash.set(req, 'error', `Unknown or unsupported document type: ${type}`);
    res.redirect(`/orders/${orderId}`);
    return;
  }

  let result;
  try {
    result = await documentGenerationService.generate(orderId, type, user.id);
  } catch (e) {
    console.error(`[DOCUMENT GENERATION FAILED] order=${orderId} type=${type} —`, e);
    flash.set(req, 'error', 'Document generation failed. Check the server error log for details.');
    res.redirect(`/orders/${orderId}`);
    return;
  }

  // QT generation is Stage 1's gate — passing it here (rather than inside
  // documentGenerationService) keeps stage progression, a
  // workflow/orchestration concern, out of the pure rendering service.
  if (type === 'QT') {
    await stageGateService.passAndUnlockNext(orderId, 1, user.id);
  }

  flash.set(req, 'success', `${type} generated: ${result.document_reference} Rev.${result.revision_number}.`);

  // A revision (not a first-ever generation) of an earlier-stage document
  // while later-stage documents already exist means those later documents
  // were built from data that existed before whatever just changed — see
  // downstreamDocumentsAtRisk()'s docblock. Surfacing this only on
  // regeneration, not on normal forward progress through the stages.
  if (result.revision_number > 0) {
    const downstream = await documentGenerationService.downstreamDocumentsAtRisk(orderId, type);
    if (downstream.length > 0) {
      flash.set(
        req,
        'warning',
        `${type} was revised, but this order already has ${downstream.join(', ')} generated from data that existed before this change. Review whether ${downstream.length > 1 ? 'they need' : 'it needs'} to be regenerated too.`
      );
    }
  }

  res.redirect(`/orders/${orderId}`);
}

async function download(req, res) {
  const documentId = parseInt(req.params.documentId, 10);
  const format = String(req.query.format || 'pdf').toLowerCase();

  const document = await documentRepository.find(documentId);
  if (!document) {
    res.status(404).send('Document not found.');
    return;
  }

  const fileId = format === 'docx' ? document.docx_file_id : document.pdf_file_id;
  if (!fileId) {
    res.status(404).send('That format was not generated for this document.');
    return;
  }

  const file = await fileStoreRepository.find(fileId);
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('File is missing from storage.');
    return;
  }

  // internal_only files (the content-parity DOCX) are downloadable from
  // here for internal staff use, but are never emailed to the buyer — that
  // boundary is enforced wherever a future "send to buyer" action is
  // built, not here.
  // original_filename is a display name, not a path — a document
  // reference containing "/" (e.g. "SC/AMD/2026/1809001") must not be
  // treated as a path with a directory component, which would silently
  // truncate the header value. Strip slashes/controls instead so the
  // browser always gets the full intended name.
  let safeDownloadName = file.original_filename.replace(/[\\/]/g, '-');
  // eslint-disable-next-line no-control-regex
  safeDownloadName = safeDownloadName.replace(/[\x00-\x1F\x7F"]/g, '');

  res.setHeader('Content-Type', file.mime_type);
  res.setHeader('Content-Disposition', `attachment; filename="${safeDownloadName}"`);
  res.setHeader('Content-Length', String(file.file_size_bytes));
  fs.createReadStream(file.server_path).pipe(res);
}

module.exports = { generate, download };
