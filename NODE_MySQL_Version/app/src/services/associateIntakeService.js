'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const dates = require('../helpers/dates');
const associateEventRepository = require('../repositories/associateEventRepository');
const associateInvitationRepository = require('../repositories/associateInvitationRepository');
const associateRepository = require('../repositories/associateRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const associateCodeService = require('./associateCodeService');
const associateMatchService = require('./associateMatchService');
const associateService = require('./associateService');
const emailService = require('./emailService');

/**
 * Port of AssociateIntakeService.php. Owner decision 1 Nov 2026 (AS-5): how
 * a buyer an associate introduced registers. The buyer fills in the
 * quotation form themselves, with their own details, through the
 * associate's one-off link (/invite/{token}) or personal code (?code=NXA-…),
 * verifies their e-mail with a code and confirms "X introduced us". Staff
 * accept it; the first completed registration of a company wins; the
 * company then stays the associate's for the protection months, however it
 * comes back (guarantees 1–2).
 */
const SESSION_KEY = '_intake_verify_id';

const sha256 = (v) => crypto.createHash('sha256').update(String(v)).digest('hex');

/** Who introduced the buyer, from a link token or a personal code: {associate, invitation, error}. */
/**
 * Owner decision 4 Nov 2026 (A6): a buyer never sees an error caused by an
 * associate code or link. When the code or link cannot be applied —
 * unknown or mistyped, an old code staff blocked, a link that expired or was
 * used, an associate who is suspended or ended — the buyer
 * carries on as a direct buyer with no hint, and `note` tells staff on the
 * intake review what was given and why it was not applied. `error` is kept
 * for callers and is always null.
 */
async function introducer(code, token) {
  const c = String(code ?? '').trim();
  const t = String(token ?? '').trim();
  const none = (note) => ({ associate: null, invitation: null, error: null, note: [...note].slice(0, 500).join('') });
  if (t !== '') {
    const given = `Invitation link ${[...t].slice(0, 12).join('')}…`;
    const inv = await associateInvitationRepository.findByToken(t);
    if (!inv || inv.kind !== 'client_link') {
      return none(`${given} given but not applied: the link is not valid.`);
    }
    const a = await associateRepository.find(Number(inv.associate_id));
    const who = a ? ` (associate ${a.name})` : '';
    if (!associateInvitationRepository.isUsable(inv)) {
      return none(`${given}${who} given but not applied: the link expired or was already used.`);
    }
    const why = notActiveReason(a);
    if (why !== null) {
      return none(`${given}${who} given but not applied: ${why}.`);
    }
    return { associate: a, invitation: inv, error: null, note: null };
  }
  if (c !== '') {
    const given = `Associate code ${associateCodeService.normalise(c).slice(0, 40)}`;
    if (!associateCodeService.isValid(c)) {
      return none(`${given} given but not applied: the code is not valid (mistyped?).`);
    }
    const a = await associateCodeService.resolve(c);
    if (!a) {
      const old = await associateRepository.findByRetiredCode(associateCodeService.normalise(c));
      return none(old
        ? `${given} given but not applied: it is an old code of associate ${old.name} that NexaCrest blocked.`
        : `${given} given but not applied: no associate has this code.`);
    }
    const why = notActiveReason(a);
    if (why !== null) {
      return none(`${given} (associate ${a.name}) given but not applied: ${why}.`);
    }
    return { associate: a, invitation: null, error: null, note: null, code: associateCodeService.normalise(c) };
  }
  return { associate: null, invitation: null, error: null, note: null };
}

/** Why an associate cannot bring a new client right now, or null when they can. */
function notActiveReason(a) {
  if (!a) return 'the associate no longer exists';
  if (a.status !== 'active') return `the associate is ${String(a.status).replace(/_/g, ' ')}`;
  return null;
}

/** A6: keeps on the submission what code/link the buyer gave and why it was not applied. */
async function recordNote(submissionId, note) {
  if (!note) return;
  await db.execute('UPDATE client_intake_submissions SET associate_note = :n WHERE id = :id', { n: note, id: submissionId });
}

async function sendEmailCode(submissionId, email) {
  const code = String(crypto.randomInt(100000, 1000000));
  await db.execute('UPDATE client_intake_submissions SET email_code_hash = :h, email_code_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = :id', { h: sha256(code), id: submissionId });
  try {
    await emailService.sendPlainText(email, 'NexaCrest — your verification code',
      `Hello,\n\nYour code to confirm your quotation request with NexaCrest: ${code}\n\nIt is valid for 30 minutes.\n\nNexaCrest International Private Limited`, { isSecurityEmail: true, kind: 'quote_verification_code', audience: 'buyer' }); // QA batch 3 MAIL-13: a one-time code is security mail
  } catch (e) {
    // the buyer can submit the page again for a new code
  }
  return code;
}

/** Ties a new submission to the associate (not yet confirmed) and e-mails the buyer a 6-digit code. Returns the code. */
/** QA Round 8, R8-N04: the message when a one-off link was already used by another registration. */
const LINK_USED = 'This invitation link has expired or was already used. Ask the person who sent it for a new one, or send your request without it.';

async function attach(submissionId, associate, invitation, company, country, email, confirmed, website, code = null) {
  // A buyer registration link was already taken (claim); a personal code gets a row of its own, remembering the code used ("came via", D-254).
  const invId = invitation ? Number(invitation.id) : await associateInvitationRepository.createForCode(Number(associate.id), company, country, code);
  await db.execute(
    'UPDATE client_intake_submissions SET associate_id = :a, associate_invitation_id = :i, introduction_confirmed = :c, website = :w WHERE id = :id',
    { a: associate.id, i: invId, c: confirmed ? 1 : 0, w: website || null, id: submissionId }
  );
  // A one-off link was already taken for this registration (claim); a personal code gets a row of its own.
  if (!invitation) await associateInvitationRepository.setStatus(invId, 'opened', { submission_id: submissionId });
  return sendEmailCode(submissionId, email);
}

async function submission(id) {
  return db.queryOne('SELECT s.*, a.name AS associate_name FROM client_intake_submissions s LEFT JOIN associates a ON a.id = s.associate_id WHERE s.id = :id', { id });
}

/** The buyer's e-mail code and their answer to "X introduced us". Returns an error, or null when verified. */
async function verify(submissionId, code, confirmed) {
  const s = await submission(submissionId);
  if (!s || s.status !== 'pending' || !s.associate_id) {
    return 'This request cannot be confirmed any more.';
  }
  if (s.email_verified_at) {
    return null;
  }
  const nowIst = new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');
  if (!s.email_code_hash || String(s.email_code_expires_at) < nowIst) {
    return 'The code has expired — we sent you a new one.';
  }
  const given = sha256(String(code || '').trim());
  const stored = String(s.email_code_hash);
  if (stored.length !== given.length || !crypto.timingSafeEqual(Buffer.from(given), Buffer.from(stored))) {
    return 'That code is not correct.';
  }
  await db.execute('UPDATE client_intake_submissions SET email_verified_at = NOW(), email_code_hash = NULL, introduction_confirmed = :c WHERE id = :id', { c: confirmed ? 1 : 0, id: submissionId });
  const a = await associateRepository.find(Number(s.associate_id));
  if (s.associate_invitation_id) {
    await associateInvitationRepository.setStatus(Number(s.associate_invitation_id), 'registered');
  }
  if (a) {
    const inv = s.associate_invitation_id ? await associateInvitationRepository.find(Number(s.associate_invitation_id)) : null;
    const via = !s.associate_invitation_id || (inv && inv.kind === 'personal_code') ? 'personal code' : 'link';
    await associateEventRepository.add(Number(a.id), confirmed ? 'registered_client' : 'not_confirmed',
      confirmed ? `${s.company_legal_name} registered through your ${via} and confirmed you introduced them. NexaCrest will now check the registration.`
        : `${s.company_legal_name} registered through your link or code but did not confirm that you introduced them.`,
      null, null, s.associate_invitation_id ? Number(s.associate_invitation_id) : null, null, true);
  }
  await auditLogRepository.log(null, 'CLIENT_INTAKE_EMAIL_VERIFIED', 'client_intake_submissions', submissionId, 'introduction_confirmed', null, confirmed ? '1' : '0');
  return null;
}

/**
 * Guarantee 2: a buyer who comes back without the associate is still
 * recognised. Notifies the associate and staff; returns the matched client
 * (with associate) or null.
 */
async function directContactCheck(submissionId, data) {
  const match = await associateMatchService.associatedClient(data.company_legal_name ?? null, data.vat_eori_tax_no ?? null, data.email ?? null, data.phone ?? null);
  if (!match) return null;
  const a = await associateRepository.find(Number(match.associate_id));
  if (a) {
    await associateService.notify(a, 'direct_contact', `Your client ${match.company_legal_name} sent us a new enquiry directly. It stays your client; NexaCrest has noted it.`, Number(match.id));
  }
  await auditLogRepository.log(null, 'ASSOCIATE_DIRECT_CONTACT', 'client_intake_submissions', submissionId, 'associate_id', null, String(match.associate_id));
  return match;
}

/**
 * What staff see on the review queue for a submission: who introduced it,
 * whether the buyer verified and confirmed, warnings, and an associate
 * client it matches: {associate, verified, confirmed, flags, matched}.
 */
async function reviewInfo(s) {
  let flags = [];
  const a = s.associate_id ? await associateRepository.find(Number(s.associate_id)) : null;
  if (a) {
    flags = associateMatchService.contactFlags(a, s.email ?? null, s.phone ?? null, s.website ?? null);
    if (!s.email_verified_at) {
      flags.push('The buyer has not verified their e-mail yet.');
    } else if (Number(s.introduction_confirmed) !== 1) {
      flags.push('The buyer did not confirm that this associate introduced them — accepting does not link them.');
    }
  }
  // QA Round 8, R8-N04: a link made for one company, used by another.
  const inv = s.associate_invitation_id ? await associateInvitationRepository.find(Number(s.associate_invitation_id)) : null;
  if (inv && inv.kind === 'client_link' && associateMatchService.normaliseName(String(inv.company_name)) !== associateMatchService.normaliseName(String(s.company_legal_name || ''))) {
    flags.push(`The associate made this link for ${inv.company_name}, but the buyer registered as ${s.company_legal_name || ''}.`);
  }
  // A6: a code or link the buyer gave that was not applied — staff decide.
  if (s.associate_note) {
    flags.push(String(s.associate_note));
  }
  const matched = await associateMatchService.associatedClient(s.company_legal_name ?? null, s.vat_eori_tax_no ?? null, s.email ?? null, s.phone ?? null);
  if (matched) {
    flags.push(`Same company as ${matched.company_legal_name}, already the client of associate ${matched.associate_name}.`);
  }
  return { associate: a ? a.name : null, verified: !!s.email_verified_at, confirmed: Number(s.introduction_confirmed || 0) === 1, flags, matched };
}

/**
 * Before staff accept: an associate-referred submission must be verified,
 * and the earliest completed registration of the same company goes first.
 * Returns the reason it cannot be accepted yet, or null.
 */
async function acceptBlocker(s) {
  if (!s.associate_id) return null;
  if (!s.email_verified_at) {
    return 'The buyer has not yet verified their e-mail with the code we sent — it can be accepted once they have.';
  }
  const others = await db.query(
    `SELECT id, company_legal_name, vat_eori_tax_no, email, phone, associate_id FROM client_intake_submissions
      WHERE status = 'pending' AND id <> :id AND associate_id IS NOT NULL AND email_verified_at IS NOT NULL AND email_verified_at < :v`,
    { id: s.id, v: s.email_verified_at }
  );
  for (const o of others) {
    if (Number(o.associate_id) !== Number(s.associate_id) && associateMatchService.sameCompany(s.company_legal_name, s.vat_eori_tax_no, s.email, s.phone, o.company_legal_name, o.vat_eori_tax_no, o.email, o.phone)) {
      return `Another associate's registration of the same company was completed earlier (request no. ${o.id}) — the first completed registration counts, so decide that one first.`;
    }
  }
  return null;
}

/** After the client is created from the submission: links the associate (or tells them why not). Returns a note for staff. */
async function onAccepted(s, clientId, userId) {
  if (!s.associate_id) return null;
  const a = await associateRepository.find(Number(s.associate_id));
  if (!a) return null;
  const invId = s.associate_invitation_id ? Number(s.associate_invitation_id) : null;
  const inv = invId !== null ? await associateInvitationRepository.find(invId) : null;
  // QA Round 8, R8-N04: a link belongs to the one registration that took it.
  if (inv && Number(inv.submission_id || 0) !== Number(s.id)) {
    return 'Not linked to the associate: their invitation link was used by another registration.';
  }
  const already = await associateMatchService.associatedClient(s.company_legal_name, s.vat_eori_tax_no, s.email, s.phone);
  if (already && Number(already.id) !== Number(clientId) && Number(already.associate_id) !== Number(a.id)) {
    if (invId !== null) await associateInvitationRepository.setStatus(invId, 'not_accepted', { client_id: clientId });
    await associateService.notify(a, 'client_not_accepted', `${s.company_legal_name} was already registered as another associate's client, so it is not linked to you.`, null, null, invId, userId);
    return 'Not linked to the associate: the company is already another associate\'s client.';
  }
  if (Number(s.introduction_confirmed) !== 1) {
    if (invId !== null) await associateInvitationRepository.setStatus(invId, 'not_accepted', { client_id: clientId });
    await associateService.notify(a, 'client_not_accepted', `${s.company_legal_name} did not confirm that you introduced them, so it is not linked to you. Talk to your relationship manager if this is wrong.`, null, null, invId, userId);
    return 'Not linked to the associate: the buyer did not confirm the introduction.';
  }
  const source = (inv ? inv.kind : 'personal_code') === 'client_link' ? 'link' : 'code';
  await associateService.linkClient(clientId, Number(a.id), source, invId, associateService.todayIst(), userId);
  if (invId !== null) await associateInvitationRepository.setStatus(invId, 'accepted', { client_id: clientId });
  await auditLogRepository.log(userId, 'ASSOCIATE_CLIENT_LINKED', 'clients', clientId, 'associate_id', null, String(a.id));
  const until = associateService.addMonths(associateService.todayIst(), await associateService.protectionMonths());
  await associateService.notify(a, 'client_accepted', `${s.company_legal_name} is now registered as your client. It stays your client until ${dates.short(until)}`
    + '. Your registration certificate is in the portal (My clients).', clientId, null, invId, userId);
  return `Linked to associate ${a.name} — protected until ${dates.short(until)}.`;
}

async function onRejected(s, reason, userId) {
  if (!s.associate_id) return;
  const a = await associateRepository.find(Number(s.associate_id));
  if (s.associate_invitation_id) {
    await associateInvitationRepository.setStatus(Number(s.associate_invitation_id), 'not_accepted');
  }
  if (a) {
    await associateService.notify(a, 'client_not_accepted', `The registration of ${s.company_legal_name} was not accepted: ${reason}`, null, null, s.associate_invitation_id ? Number(s.associate_invitation_id) : null, userId);
  }
}

module.exports = { SESSION_KEY, LINK_USED, introducer, recordNote, sendEmailCode, attach, submission, verify, directContactCheck, reviewInfo, acceptBlocker, onAccepted, onRejected };
