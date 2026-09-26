'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const orderSupplierPoDocumentRepository = require('../repositories/orderSupplierPoDocumentRepository');

/**
 * Port of App\Services\WetSignatureGuardService — the wet-signature-required
 * flag concept (docs/SOP/README.md "Still pending", task tracker item
 * #106). recordBuyerPo() (Stage 2) and confirmSupplierSigned() (Stage 5)
 * used to let staff pass their gate with a button click alone — the
 * upload-signed-copy endpoint next to each one is a separate, optional
 * action, never actually required to advance the stage. That was the
 * loophole: a stage could be confirmed "signed" with zero physical
 * evidence ever attached to the order. This service is the single place
 * both gates check before they're allowed to pass — each flag defaults on
 * (see seed.sql), but stays Admin-toggleable per document type via
 * company_settings for a workflow that genuinely doesn't need the
 * physical copy.
 */

async function buyerPoBlocked(orderId) {
  if ((await companySettingsRepository.get('wet_signature_required_buyer_po')) !== '1') {
    return false;
  }
  const docs = await orderBuyerPoDocumentRepository.forOrder(orderId);
  return docs.length === 0;
}

async function supplierPoBlocked(orderSupplierPoId) {
  if ((await companySettingsRepository.get('wet_signature_required_supplier_po')) !== '1') {
    return false;
  }
  const docs = await orderSupplierPoDocumentRepository.forSupplierPo(orderSupplierPoId);
  return docs.length === 0;
}

module.exports = { buyerPoBlocked, supplierPoBlocked };
