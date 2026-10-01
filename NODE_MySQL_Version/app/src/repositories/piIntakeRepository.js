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
    'INSERT INTO pi_intake_submissions (order_id, access_token_hash, access_token_plain, access_token_expires_at, created_by) VALUES (:order_id, :hash, :plain, :expires, :created_by)',
    {
      order_id: orderId,
      hash: crypto.createHash('sha256').update(rawToken).digest('hex'),
      plain: rawToken,
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
  const row = await db.queryOne(
    `SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name,
            c.billing_address AS client_billing_address,
            c.consignee_name AS client_consignee_name,
            c.consignee_address AS client_consignee_address,
            c.vat_eori_tax_no AS client_vat_eori_tax_no,
            c.contact_person AS client_contact_person,
            c.email AS client_email,
            c.phone AS client_phone,
            c.notify_party AS client_notify_party,
            c.country_of_destination AS client_country_of_destination,
            c.coo_type AS client_coo_type,
            COALESCE(p.name, o.port_of_discharge_text) AS order_port_of_discharge,
            i.code AS order_incoterm_code,
            o.container_type AS order_container_type
     FROM pi_intake_submissions pis
     JOIN orders o ON o.id = pis.order_id
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN ports p ON p.id = o.port_of_discharge_id
     LEFT JOIN incoterms i ON i.id = o.incoterm_id
     WHERE pis.access_token_hash = :hash AND pis.access_token_expires_at > NOW()
       AND pis.status IN ('awaiting_client', 'rejected')`,
    { hash }
  );
  if (!row) {
    return null;
  }

  // Prefill: a client who already provided these exact contact details
  // at the Quotation-stage form shouldn't have to retype them here. Only
  // fills in fields the client hasn't already answered themselves — on a
  // rejected-and-resubmitted link, the client's own prior answer always
  // wins over the client/order default, since they may have deliberately
  // corrected it.
  const prefillMap = {
    company_legal_name: 'client_company_legal_name',
    billing_address: 'client_billing_address',
    consignee_name: 'client_consignee_name',
    consignee_address: 'client_consignee_address',
    vat_eori_tax_no: 'client_vat_eori_tax_no',
    contact_person: 'client_contact_person',
    email: 'client_email',
    phone: 'client_phone',
    notify_party: 'client_notify_party',
    country_of_destination: 'client_country_of_destination',
    coo_type: 'client_coo_type',
    port_of_discharge_text: 'order_port_of_discharge',
    incoterm_confirmed: 'order_incoterm_code',
    container_type_text: 'order_container_type',
  };
  for (const [field, defaultKey] of Object.entries(prefillMap)) {
    if (row[field] === null || row[field] === '') {
      row[field] = row[defaultKey] || '';
    }
  }

  return row;
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
