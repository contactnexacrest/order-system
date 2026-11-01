'use strict';

const db = require('../config/db');

/**
 * Port of AssociateMatchService.php. Owner decision 1 Nov 2026 (AS-5,
 * guarantee 1): an associate's client is recognised by company identity —
 * legal name, VAT / registration number, e-mail domain, phone — however it
 * comes back, not by the link or code it first used.
 */
const FREE_MAIL = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'yandex.com', 'rediffmail.com'];

function normaliseName(name) {
  let n = String(name || '').trim().toLowerCase();
  n = n.replace(/[^a-z0-9 ]+/g, ' ');
  n = n.replace(/\b(ltd|limited|llc|inc|gmbh|bv|b v|srl|sarl|sa|s a|spa|pvt|private|co|company|corp|corporation|plc|ag|oy|ab|as|aps|kg|the)\b/g, ' ');
  return n.replace(/\s+/g, ' ').trim();
}

function emailDomain(email) {
  const e = String(email || '').trim().toLowerCase();
  const at = e.lastIndexOf('@');
  if (at === -1) return null;
  const domain = e.slice(at + 1);
  return FREE_MAIL.includes(domain) ? null : domain;
}

function isFreeMail(email) {
  const e = String(email || '').trim().toLowerCase();
  const at = e.lastIndexOf('@');
  return at !== -1 && FREE_MAIL.includes(e.slice(at + 1));
}

function digits(phone) {
  const d = String(phone || '').replace(/\D+/g, '');
  return d.length >= 8 ? d.slice(-9) : '';
}

function sameCompany(nameA, vatA, emailA, phoneA, nameB, vatB, emailB, phoneB) {
  const vA = String(vatA || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  const vB = String(vatB || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (vA !== '' && vA === vB) return true;
  const nA = normaliseName(nameA);
  if (nA !== '' && nA === normaliseName(nameB)) return true;
  const dA = emailDomain(emailA);
  if (dA !== null && dA === emailDomain(emailB)) return true;
  const pA = digits(phoneA);
  return pA !== '' && pA === digits(phoneB);
}

/** The client of an associate this company identity matches, if any (with associate_name). */
async function associatedClient(company, vat, email, phone) {
  const rows = await db.query(
    `SELECT c.id, c.company_legal_name, c.vat_eori_tax_no, c.email, c.phone, c.associate_id, a.name AS associate_name
       FROM clients c JOIN associates a ON a.id = c.associate_id WHERE c.associate_id IS NOT NULL`
  );
  for (const r of rows) {
    if (sameCompany(company, vat, email, phone, r.company_legal_name, r.vat_eori_tax_no, r.email, r.phone)) return r;
  }
  return null;
}

/** Any client at all (with or without an associate) this identity matches. */
async function anyClient(company, vat, email, phone, exceptId = null) {
  for (const r of await db.query('SELECT id, company_legal_name, vat_eori_tax_no, email, phone, associate_id FROM clients')) {
    if (exceptId !== null && Number(r.id) === Number(exceptId)) continue;
    if (sameCompany(company, vat, email, phone, r.company_legal_name, r.vat_eori_tax_no, r.email, r.phone)) return r;
  }
  return null;
}

/**
 * Warnings about contact details an associate-referred buyer gave (part 4.4):
 * the associate's own, a free-mail address, an e-mail not on the website's domain.
 */
function contactFlags(associate, email, phone, website) {
  const flags = [];
  if (email !== null && email !== undefined && String(email).trim().toLowerCase() === String(associate.email || '').trim().toLowerCase()) {
    flags.push('The buyer\'s e-mail is the associate\'s own e-mail.');
  }
  if (digits(phone) !== '' && digits(phone) === digits(associate.phone)) {
    flags.push('The buyer\'s phone is the associate\'s own phone.');
  }
  if (isFreeMail(email)) {
    flags.push('The buyer used a free-mail address (not a company domain).');
  }
  let site = '';
  const w = String(website || '');
  if (w !== '') {
    try {
      site = new URL(w.includes('://') ? w : `https://${w}`).hostname.toLowerCase();
    } catch (e) {
      site = '';
    }
  }
  site = site.replace(/^www\./, '');
  const domain = emailDomain(email);
  if (site !== '' && domain !== null && domain !== site && !domain.endsWith(`.${site}`)) {
    flags.push(`The buyer's e-mail domain (${domain}) is not the website's (${site}).`);
  }
  return flags;
}

module.exports = { FREE_MAIL, normaliseName, emailDomain, isFreeMail, digits, sameCompany, associatedClient, anyClient, contactFlags };
