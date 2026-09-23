'use strict';

const crypto = require('crypto');
const db = require('../config/db');

// Port of App\Repositories\PiIntakeRepository. PI-stage intake — see
// schema.sql Section AA. A separate, second public form from
// clientIntakeRepository (the Quotation stage): staff generate a
// per-order link once the Quotation is out, the client confirms/fills
// their PI-stage details, and it lands in its own staff review queue.
// Never auto-applied to the client/order — see
// piIntakeReviewController.accept().

const TOKEN_TTL_DAYS = 30;

/** Generates a fresh link for this order and returns the RAW token (only its hash is stored). */
async function createLink(orderId, createdBy) {
  const rawToken = crypto.randomBytes(32).toString('hex');
  await db.execute(
    'INSERT INTO pi_intake_submissions (order_id, access_token_hash, access_token_expires_at, created_by) VALUES (:order_id, :hash, :expires, :created_by)',
    {
      order_id: orderId,
      hash: crypto.createHash('sha256').update(rawToken).digest('hex'),
      expires: new Date(Date.now() + TOKEN_TTL_DAYS * 86400000).toISOString().slice(0, 19).replace('T', ' '),
      created_by: createdBy,
    }
  );
  return rawToken;
}

async function find(id) {
  return db.queryOne('SELECT * FROM pi_intake_submissions WHERE id = :id', { id });
}

/** Only while awaiting the client's (first or corrected) submission — not once it's pending staff review or already applied. */
async function findValidByToken(rawToken) {
  const hash = crypto.createHash('sha256').update(rawToken).digest('hex');
  return db.queryOne(
    `SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name
     FROM pi_intake_submissions pis
     JOIN orders o ON o.id = pis.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE pis.access_token_hash = :hash AND pis.access_token_expires_at > NOW()
       AND pis.status IN ('awaiting_client', 'rejected')`,
    { hash }
  );
}

async function latestForOrder(orderId) {
  return db.queryOne(
    `SELECT pis.*, u.name AS reviewed_by_name
     FROM pi_intake_submissions pis
     LEFT JOIN users u ON u.id = pis.reviewed_by
     WHERE pis.order_id = :order_id ORDER BY pis.created_at DESC LIMIT 1`,
    { order_id: orderId }
  );
}

async function submit(id, data, ip) {
  await db.execute(
    `UPDATE pi_intake_submissions SET
        company_legal_name = :company_legal_name, billing_address = :billing_address,
        consignee_name = :consignee_name, consignee_address = :consignee_address,
        vat_eori_tax_no = :vat_eori_tax_no, contact_person = :contact_person, email = :email,
        phone = :phone, notify_party = :notify_party,
        port_of_discharge_text = :port_of_discharge_text, country_of_destination = :country_of_destination,
        incoterm_confirmed = :incoterm_confirmed, container_type_text = :container_type_text,
        payment_terms_confirmation = :payment_terms_confirmation,
        quotation_acceptance_reference = :quotation_acceptance_reference,
        coo_type = :coo_type, buyer_po_ref = :buyer_po_ref,
        changes_from_quotation = :changes_from_quotation,
        special_document_requirements = :special_document_requirements,
        status = 'pending_review', submitted_at = NOW(), submitted_ip = :ip,
        rejection_reason = NULL
     WHERE id = :id`,
    {
      company_legal_name: data.company_legal_name,
      billing_address: data.billing_address,
      consignee_name: data.consignee_name,
      consignee_address: data.consignee_address,
      vat_eori_tax_no: data.vat_eori_tax_no,
      contact_person: data.contact_person,
      email: data.email,
      phone: data.phone,
      notify_party: data.notify_party || null,
      port_of_discharge_text: data.port_of_discharge_text,
      country_of_destination: data.country_of_destination,
      incoterm_confirmed: data.incoterm_confirmed,
      container_type_text: data.container_type_text || null,
      payment_terms_confirmation: data.payment_terms_confirmation,
      quotation_acceptance_reference: data.quotation_acceptance_reference,
      coo_type: data.coo_type,
      buyer_po_ref: data.buyer_po_ref || null,
      changes_from_quotation: data.changes_from_quotation || null,
      special_document_requirements: data.special_document_requirements || null,
      ip,
      id,
    }
  );
}

async function pendingReview() {
  return db.query(
    `SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name
     FROM pi_intake_submissions pis
     JOIN orders o ON o.id = pis.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE pis.status = 'pending_review'
     ORDER BY pis.submitted_at ASC`
  );
}

async function recentResolved(limit = 30) {
  return db.query(
    `SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name, u.name AS reviewed_by_name
     FROM pi_intake_submissions pis
     JOIN orders o ON o.id = pis.order_id
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN users u ON u.id = pis.reviewed_by
     WHERE pis.status IN ('applied', 'rejected')
     ORDER BY pis.reviewed_at DESC LIMIT ${parseInt(limit, 10)}`
  );
}

async function markApplied(id, reviewedBy) {
  await db.execute(
    "UPDATE pi_intake_submissions SET status = 'applied', reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id",
    { reviewed_by: reviewedBy, id }
  );
}

async function markRejected(id, reviewedBy, reason) {
  await db.execute(
    "UPDATE pi_intake_submissions SET status = 'rejected', rejection_reason = :reason, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id",
    { reason, reviewed_by: reviewedBy, id }
  );
}

module.exports = {
  createLink, find, findValidByToken, latestForOrder, submit,
  pendingReview, recentResolved, markApplied, markRejected,
};
