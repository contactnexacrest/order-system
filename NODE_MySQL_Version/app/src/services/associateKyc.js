'use strict';

const db = require('../config/db');
const associateDocumentRepository = require('../repositories/associateDocumentRepository');

/**
 * Port of AssociateKyc.php. Owner decisions 4 Nov 2026:
 * - D-251 (A4): every associate document carries its number / reference,
 *   checked per kind; for an Aadhaar card NexaCrest keeps ONLY the last
 *   4 digits ("XXXX XXXX 1234") — a full Aadhaar number is refused.
 * - D-252 (A3): the KYC checklist — the required documents, each Pending /
 *   Accepted / Rejected (reason), reviewed by staff. Commission cannot be
 *   marked paid until every required item is Accepted.
 */

/** Per-kind reference rule: [HTML pattern, placeholder, title shown when wrong]. */
const REFERENCE_RULES = {
  pan: ['[A-Za-z]{5}[0-9]{4}[A-Za-z]', 'e.g. ABCDE1234F', 'PAN: 5 letters, 4 digits, 1 letter, e.g. ABCDE1234F'],
  aadhaar: ['[0-9]{4}', 'last 4 digits only, e.g. 1234', 'Aadhaar: enter only the LAST 4 digits — NexaCrest does not keep the full number'],
  identity: ['[A-Za-z0-9]{6,9}', 'e.g. Z1234567', 'Passport / ID number: 6 to 9 letters or digits'],
  gst: ['[0-9]{2}[A-Za-z0-9]{13}', 'e.g. 29ABCDE1234F1Z5', 'GSTIN: 15 characters, e.g. 29ABCDE1234F1Z5'],
};
const OTHER_RULE = ['.{0,50}', 'e.g. certificate or account no.', 'Up to 50 characters'];

const STATUS_LABELS = { accepted: 'Accepted', pending: 'Pending — being checked', rejected: 'Rejected', missing: 'Not uploaded yet' };

const len = (s) => [...String(s)].length;

/** The reference to store for a document kind, or an error: [value, error]. Empty stays empty. */
function normaliseReference(kind, reference) {
  const r = String(reference ?? '').trim();
  if (r === '') return [null, null];
  const upper = r.replace(/\s+/g, '').toUpperCase();
  switch (kind) {
    case 'pan':
      return /^[A-Z]{5}[0-9]{4}[A-Z]$/.test(upper) ? [upper, null] : [null, 'The PAN should look like ABCDE1234F (5 letters, 4 digits, 1 letter).'];
    case 'aadhaar': {
      const digits = r.replace(/\D/g, '');
      if (digits.length >= 12) {
        return [null, 'Enter only the last 4 digits of the Aadhaar number — NexaCrest does not keep the full number.'];
      }
      return digits.length === 4 && digits === r.replace(/[\sX]/gi, '') ? [`XXXX XXXX ${digits}`, null] : [null, 'Enter the last 4 digits of the Aadhaar number, e.g. 1234.'];
    }
    case 'identity':
      return /^[A-Z0-9]{6,9}$/.test(upper) ? [upper, null] : [null, 'The passport / ID number should be 6 to 9 letters or digits.'];
    case 'gst':
      return /^[0-9]{2}[A-Z0-9]{13}$/.test(upper) ? [upper, null] : [null, 'The GSTIN should be 15 characters, e.g. 29ABCDE1234F1Z5.'];
    default:
      return len(r) <= 50 ? [r, null] : [null, 'The document number / reference can be up to 50 characters.'];
  }
}

/** The required KYC items: India — PAN, Aadhaar or passport, bank proof, signed agreement, GST certificate with a GSTIN; abroad — passport / ID, bank proof, signed agreement. */
function requiredItems(a) {
  const india = ((a && a.based_in) || 'india') === 'india';
  const items = india
    ? [{ key: 'pan', label: 'PAN card', kinds: ['pan'] }, { key: 'id', label: 'Aadhaar card or passport', kinds: ['aadhaar', 'identity'] }]
    : [{ key: 'id', label: 'Passport / ID', kinds: ['identity'] }];
  items.push({ key: 'bank', label: 'Bank proof (cancelled cheque or bank statement)', kinds: ['bank_proof'] });
  items.push({ key: 'agreement', label: 'Signed agreement', kinds: ['agreement'] });
  if (india && String((a && a.gstin) || '').trim() !== '') {
    items.push({ key: 'gst', label: 'GST certificate', kinds: ['gst'] });
  }
  return items;
}

/** The checklist with each item's status (accepted / pending / rejected / missing). */
async function checklist(a, documents = null) {
  const docs = documents || await associateDocumentRepository.forAssociate(Number(a.id));
  return requiredItems(a).map((item) => {
    const mine = docs.filter((d) => item.kinds.includes(d.kind));
    const accepted = mine.filter((d) => d.status === 'accepted');
    const latest = mine[0] || null; // forAssociate is newest first
    let status;
    let doc = null;
    if (accepted.length) {
      status = 'accepted';
      doc = accepted[0];
    } else if (item.key === 'agreement' && a.agreement_file_id) {
      status = 'accepted';
    } else if (latest) {
      status = latest.status === 'rejected' ? 'rejected' : 'pending';
      doc = latest;
    } else {
      status = 'missing';
    }
    return { ...item, status, doc, by_nexacrest: item.key === 'agreement' && !doc && status === 'accepted' };
  });
}

/** The labels of the required items not yet accepted (empty = KYC complete). */
async function missing(a) {
  return (await checklist(a)).filter((i) => i.status !== 'accepted').map((i) => i.label);
}

/** Staff accept or reject one document. Returns an error or null. */
async function review(a, docId, decision, reason, userId) {
  const doc = await associateDocumentRepository.find(docId);
  if (!doc || Number(doc.associate_id) !== Number(a.id)) return 'Document not found.';
  if (!['accept', 'reject'].includes(decision)) return 'Choose Accept or Reject.';
  const why = String(reason ?? '').trim();
  if (decision === 'reject' && len(why) < 10) return 'Say why the document is rejected (at least 10 characters) — the associate sees it.';
  if (len(why) > 300) return 'The reason can be up to 300 characters.';
  const status = decision === 'accept' ? 'accepted' : 'rejected';
  await db.execute('UPDATE associate_documents SET status = :s, reviewed_by = :u, reviewed_at = NOW(), review_reason = :r WHERE id = :id',
    { s: status, u: userId, r: why !== '' ? why : null, id: docId });
  const label = associateDocumentRepository.KINDS[doc.kind] || doc.kind;
  await require('../repositories/auditLogRepository').log(userId, status === 'accepted' ? 'ASSOCIATE_DOCUMENT_ACCEPTED' : 'ASSOCIATE_DOCUMENT_REJECTED', 'associate_documents', Number(docId), 'status', String(doc.status), status);
  await require('./associateService').notify(a, status === 'accepted' ? 'document_accepted' : 'document_rejected',
    status === 'accepted' ? `Your ${label} was accepted.` : `Your ${label} was not accepted: ${why} Please upload it again in your portal (My profile).`, null, null, null, userId);
  return null;
}

/** Tells the staff who manage associates that a document arrived (in-app). */
async function notifyStaffOfUpload(a, kind) {
  const label = associateDocumentRepository.KINDS[kind] || kind;
  const notificationRepository = require('../repositories/notificationRepository');
  // D-281: in-app for everyone who manages associates; e-mailed to the associate's relationship manager.
  await notificationRepository.notifyRouted(await require('../repositories/permissionRepository').usersWithPermission('manage_associates'),
    await require('./staffRouting').forAssociate(a),
    'associate_document_uploaded', null, [...`Associate ${a.name} uploaded a ${label} — check it on their page (KYC).`].slice(0, 500).join(''));
}

module.exports = { REFERENCE_RULES, OTHER_RULE, STATUS_LABELS, normaliseReference, requiredItems, checklist, missing, review, notifyStaffOfUpload };
