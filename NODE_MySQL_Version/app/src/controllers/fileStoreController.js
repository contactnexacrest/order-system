'use strict';

const moneyVisibility = require('../services/moneyVisibility');

const fs = require('fs');
const associateRepository = require('../repositories/associateRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderRepository = require('../repositories/orderRepository');
const orderAccessService = require('../services/orderAccessService');

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

  // QA Round 5, R5-21: this route used to serve any file by its id.
  // A removed file stays removed, an order's file needs the same
  // access as the order page (assignment / view_all_orders, archived
  // rule), and CAFIN needs view_cafin — the same gates as everywhere
  // else these files are shown.
  if (Number(file.is_active ?? 1) !== 1) {
    res.status(404).send('This file has been removed.');
    return;
  }
  const permissions = req.permissions || {};
  if (file.order_id) {
    const order = await orderRepository.find(Number(file.order_id));
    const denied = order ? await orderAccessService.denialReason(order, req.user.id, permissions) : null;
    if (denied !== null) {
      res.status(403).send(denied);
      return;
    }
  }
  if ((await fileStoreRepository.documentTypeCodeFor(fileId)) === 'CAFIN'
    && (!permissions.view_cafin || !file.order_id || !(await orderAccessService.orderLinkChecker(req.user.id, permissions))(file.order_id))) {
    res.status(403).send('You do not have permission to view this document.');
    return;
  }
  // Owner decision D-214, phase 2: a bill scan, signed PO, payment
  // screenshot or sales document only for whoever may see that money.
  if (!(await moneyVisibility.canSeeFile(permissions, fileId))) {
    res.status(403).send('You do not have permission to view this document.');
    return;
  }
  if ((await associateRepository.isAgreementFile(fileId)) && !permissions.manage_incentives) {
    res.status(403).send('You do not have permission to view this document.');
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
