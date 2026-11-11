'use strict';

const db = require('../config/db');
const associateDocumentRepository = require('../repositories/associateDocumentRepository');
const { short } = require('../helpers/dates');

/**
 * Port of AssociateKyc.php. Owner decisions 4 Nov 2026:
 * - D-251 (A4): every associate document carries its number / reference,
 *   checked per kind; for an Aadhaar card NexaCrest keeps ONLY the last
 *   4 digits ("XXXX XXXX 1234") — a full Aadhaar number is refused.
 * - D-252 (A3): the KYC checklist — the required documents, each Pending /
 *   Accepted / Rejected (reason), reviewed by staff. Commission cannot be
 *   marked paid until every required item is Accepted.
 * Owner decisions 11 Nov 2026 (D-290, points 6 and 7): the ID document's type
 * is chosen (Passport / National ID card / Residence permit / Driving licence),
 * its number is 5–30 letters, digits, dashes, slashes or spaces (PAN and
 * Aadhaar unchanged); ID proof and Address proof are separate required items
 * in India and abroad (the same document may be uploaded under each — two
 * records, app-set file names; no "use the same file" button); an associate
 * accepted before the change has until address_proof_due_on (60 days) before
 * payouts are held for a missing address proof.
 */

/** Per-kind reference rule: [HTML pattern, placeholder, title shown when wrong]. */
const REFERENCE_RULES = {
  pan: ['[A-Za-z]{5}[0-9]{4}[A-Za-z]', 'e.g. ABCDE1234F', 'PAN: 5 letters, 4 digits, 1 letter, e.g. ABCDE1234F'],
  aadhaar: ['[0-9]{4}', 'last 4 digits only, e.g. 1234', 'Aadhaar: enter only the LAST 4 digits — NexaCrest does not keep the full number'],
  identity: ['[A-Za-z0-9 \\/\\-]{5,30}', 'e.g. Z1234567 or AB-12/345', 'Document number: 5 to 30 letters, digits, dashes (-), slashes (/) or spaces'],
  gst: ['[0-9]{2}[A-Za-z0-9]{13}', 'e.g. 29ABCDE1234F1Z5', 'GSTIN: 15 characters, e.g. 29ABCDE1234F1Z5'],
};
const OTHER_RULE = ['.{0,50}', 'e.g. certificate or account no.', 'Up to 50 characters'];

/** D-290: the ID document's type, chosen by the associate (kind 'identity'). */
const ID_DOC_TYPES = { passport: 'Passport', national_id: 'National ID card', residence_permit: 'Residence permit', driving_licence: 'Driving licence' };
/** D-290: the kinds that are an ID proof; an address proof is kind 'address'. */
const ID_KINDS = ['aadhaar', 'identity'];
const ADDRESS_PROOF_GRACE_DAYS = 60;

/** D-290: "ID proof — <name> — 11 Nov 2026.pdf" / "Address proof — …" (null for the other kinds). */
function appFileName(kind, associateName, uploadedName, date = null) {
  const label = ID_KINDS.includes(kind) ? 'ID proof' : (kind === 'address' ? 'Address proof' : null);
  if (!label) return null;
  const m = String(uploadedName || '').match(/\.([^./\\]+)$/);
  const ext = m ? m[1].toLowerCase() : '';
  const name = String(associateName || '').replace(/[^\p{L}\p{N} .\-]+/gu, ' ').replace(/\s+/g, ' ').trim() || 'Associate';
  return `${label} — ${name} — ${short(date || new Date().toISOString().slice(0, 10))}${ext ? `.${ext}` : ''}`;
}

/** D-290: the ID document type posted with an identity document, or an error: [type, error]. */
function idDocType(kind, posted) {
  if (kind !== 'identity') return [null, null];
  return ID_DOC_TYPES[posted] ? [posted, null] : [null, 'Choose the type of ID document: Passport, National ID card, Residence permit or Driving licence.'];
}

/** D-251 + D-290: the checks of one uploaded document: [reference, ID document type, error]. */
function checkUpload(kind, reference, idType) {
  const [ref, error] = normaliseReference(kind, reference);
  if (error) return [null, null, error];
  const [type, typeError] = idDocType(kind, String(idType ?? '').trim());
  if (typeError) return [null, null, typeError];
  if (kind === 'identity' && ref === null) return [null, null, "Enter the ID document's number (5 to 30 letters, digits, dashes, slashes or spaces)."];
  return [ref, type, null];
}

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
    case 'identity': {
      // D-290: 5–30 letters, digits, dashes, slashes or spaces (a residence permit or driving licence may carry them).
      const v = r.replace(/\s+/g, ' ').trim().toUpperCase();
      return /^[A-Z0-9 /-]{5,30}$/.test(v) ? [v, null] : [null, 'The ID document number should be 5 to 30 letters, digits, dashes (-), slashes (/) or spaces.'];
    }
    case 'gst':
      return /^[0-9]{2}[A-Z0-9]{13}$/.test(upper) ? [upper, null] : [null, 'The GSTIN should be 15 characters, e.g. 29ABCDE1234F1Z5.'];
    default:
      return len(r) <= 50 ? [r, null] : [null, 'The document number / reference can be up to 50 characters.'];
  }
}

/**
 * The required KYC items: India — PAN, ID proof (Aadhaar card or an ID document), address proof, bank proof,
 * signed agreement, GST certificate with a GSTIN; abroad — ID proof, address proof, bank proof, signed agreement.
 * D-290: ID proof and address proof are separate items everywhere.
 */
function requiredItems(a) {
  const india = ((a && a.based_in) || 'india') === 'india';
  const items = india
    ? [{ key: 'pan', label: 'PAN card', kinds: ['pan'] }, { key: 'id', label: 'ID proof (Aadhaar card, passport or other ID document)', kinds: ['aadhaar', 'identity'] }]
    : [{ key: 'id', label: 'ID proof (passport, national ID card, residence permit or driving licence)', kinds: ['identity'] }];
  items.push({ key: 'address', label: 'Address proof', kinds: ['address'] });
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

/** D-290: what holds a payout on `on` — the missing items, except the address proof within address_proof_due_on. */
async function missingForPayout(a, on = null) {
  const day = String(on || new Date().toISOString()).slice(0, 10);
  const due = a.address_proof_due_on ? (a.address_proof_due_on instanceof Date ? a.address_proof_due_on.toISOString() : String(a.address_proof_due_on)).slice(0, 10) : null;
  const grace = due !== null && day <= due;
  return (await checklist(a)).filter((i) => i.status !== 'accepted' && !(grace && i.key === 'address')).map((i) => i.label);
}

/** D-290: "Address proof needed by … — after that, commission payouts wait until it is accepted." or null. */
async function addressGraceNote(a, today = null) {
  if (!a || !a.address_proof_due_on) return null;
  const day = String(today || new Date().toISOString()).slice(0, 10);
  const due = (a.address_proof_due_on instanceof Date ? a.address_proof_due_on.toISOString() : String(a.address_proof_due_on)).slice(0, 10);
  const item = (await checklist(a)).find((i) => i.key === 'address');
  if (!item || item.status === 'accepted') return null;
  return day <= due
    ? `Address proof needed by ${short(due)} — after that, commission payouts wait until it is accepted.`
    : `Address proof was due by ${short(due)} — commission payouts wait until it is accepted.`;
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

module.exports = {
  REFERENCE_RULES, OTHER_RULE, STATUS_LABELS, ID_DOC_TYPES, ID_KINDS, ADDRESS_PROOF_GRACE_DAYS,
  normaliseReference, requiredItems, checklist, missing, missingForPayout, addressGraceNote, appFileName, idDocType, checkUpload, review, notifyStaffOfUpload,
};
