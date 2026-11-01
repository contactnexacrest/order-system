'use strict';

const db = require('../config/db');

/** Port of AssociateDocumentRepository.php — an associate's KYC, tax papers, invoices and NexaCrest's certificates. */
const KINDS = {
  pan: 'PAN card', identity: 'Passport / ID', address: 'Address proof', gst: 'GST certificate',
  company: 'Company registration', tax_residency: 'Tax residency certificate', form_10f: 'Form 10F',
  no_pe: 'No-PE declaration', invoice: 'Commission invoice', form_16a: 'Form 16A (TDS certificate)',
  form_15ca: 'Form 15CA', form_15cb: 'Form 15CB', other: 'Other',
};
const ASSOCIATE_KINDS = ['pan', 'identity', 'address', 'gst', 'company', 'tax_residency', 'form_10f', 'no_pe', 'invoice', 'other'];

async function add(associateId, kind, fileId, payoutId, reference, byAssociate, userId) {
  const r = await db.execute(
    'INSERT INTO associate_documents (associate_id, kind, payout_id, file_id, reference, uploaded_by_associate, uploaded_by_user) VALUES (:a, :k, :p, :f, :r, :ba, :u)',
    { a: associateId, k: kind, p: payoutId ?? null, f: fileId, r: reference ?? null, ba: byAssociate ? 1 : 0, u: userId ?? null }
  );
  return r.insertId;
}

async function forAssociate(associateId) {
  return db.query(
    'SELECT d.*, f.original_filename FROM associate_documents d JOIN file_store f ON f.id = d.file_id WHERE d.associate_id = :a ORDER BY d.id DESC',
    { a: associateId }
  );
}

async function find(id) {
  return db.queryOne(
    'SELECT d.*, f.original_filename, f.server_path, f.mime_type FROM associate_documents d JOIN file_store f ON f.id = d.file_id WHERE d.id = :id',
    { id }
  );
}

async function invoiceForPayout(payoutId) {
  return db.queryOne("SELECT * FROM associate_documents WHERE payout_id = :p AND kind = 'invoice' ORDER BY id DESC LIMIT 1", { p: payoutId });
}

module.exports = { KINDS, ASSOCIATE_KINDS, add, forAssociate, find, invoiceForPayout };
