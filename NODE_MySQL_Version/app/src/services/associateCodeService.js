'use strict';

const crypto = require('crypto');
const db = require('../config/db');

/**
 * Port of AssociateCodeService.php. Owner decision 1 Nov 2026 (AS-5, part
 * 4.4-A2): an associate's permanent personal code, e.g. NXA-RAJESH-7K. Every
 * code belongs to one associate for ever (unique, never reused). It ends in
 * a check letter, so a mistyped code is refused instead of matching someone
 * else. Upper / lower case and the look-alikes 0/O and 1/I are read as the same.
 */

/** No 0, 1, I, L or O — nothing that can be misread. */
const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

/** Upper case, no spaces, 0→O, 1→I. */
function normalise(code) {
  return String(code || '').replace(/\s+/g, '').toUpperCase().replace(/0/g, 'O').replace(/1/g, 'I');
}

/** The readable middle part, from a name or the associate's own choice: letters and digits, 3–12. */
function readablePart(text) {
  return normalise(String(text || '').replace(/[^A-Za-z0-9]/g, '')).slice(0, 12);
}

function checkChar(body) {
  let sum = 0;
  [...String(body).replace(/-/g, '')].forEach((ch, i) => {
    sum += (ch.charCodeAt(0) * (i + 3)) % 97;
  });
  return ALPHABET[sum % ALPHABET.length];
}

async function generate(readable) {
  let part = readablePart(readable);
  if (part.length < 3) {
    part = part.padEnd(3, 'X');
  }
  // One random letter gives only 31 codes per name part (e.g. many associates called RAJESH, or many
  // past codes): after a few clashes the random part grows to 2, then 3 letters (961, 29,791 codes).
  // Old 1-letter codes stay valid; isValid() accepts 1 to 3 random letters before the check letter.
  for (let i = 0; i < 60; i++) {
    const len = i < 20 ? 1 : (i < 40 ? 2 : 3);
    let random = '';
    for (let k = 0; k < len; k++) {
      random += ALPHABET[crypto.randomInt(0, ALPHABET.length)];
    }
    const body = `NXA-${part}-${random}`;
    const code = body + checkChar(body);
    const taken = await db.queryOne('SELECT 1 AS yes FROM associates WHERE personal_code = :c UNION SELECT 1 FROM associate_codes WHERE code = :c2', { c: code, c2: code });
    if (!taken) {
      return code;
    }
  }
  throw new Error('Could not make a unique code — please try again.');
}

/**
 * Gives the associate a new personal code. Owner rule (4 Nov 2026, D-254):
 * the old code keeps bringing buyers to the same associate (links already
 * shared keep working) unless staff block it, e.g. after a leak. No code is
 * ever given to anyone else.
 */
async function issue(associateId, readable, blockOld = false, blockReason = null, code = null) {
  const c = code || await generate(readable);
  // The new code first: if someone took it in the meantime (unique), nothing else has changed.
  await db.execute('INSERT INTO associate_codes (code, associate_id) VALUES (:c, :a)', { c, a: associateId });
  if (blockOld) {
    await db.execute('UPDATE associate_codes SET retired_at = NOW(), blocked_at = NOW(), blocked_reason = :r WHERE associate_id = :a AND retired_at IS NULL AND code <> :c',
      { a: associateId, c, r: [...String(blockReason || '')].slice(0, 300).join('') });
  } else {
    await db.execute('UPDATE associate_codes SET retired_at = NOW() WHERE associate_id = :a AND retired_at IS NULL AND code <> :c', { a: associateId, c });
  }
  await db.execute('UPDATE associates SET personal_code = :c WHERE id = :a', { c, a: associateId });
  return c;
}

/** D-254: a proposed new code for the associate to look at (nothing saved) — "like a captcha refresh". */
async function propose(readable) {
  return generate(readable);
}

/** D-254: how many times the associate changed their own code in the last 365 days. */
async function changesInLastYear(associateId) {
  const row = await db.queryOne("SELECT COUNT(*) AS n FROM associate_events WHERE associate_id = :a AND event IN ('code_chosen', 'code_changed') AND created_at >= (NOW() - INTERVAL 365 DAY)", { a: associateId });
  return Number(row ? row.n : 0);
}

async function changesPerYear() {
  const v = parseInt((await require('../repositories/incentiveRepository').settings()).associate_code_changes_per_year ?? '3', 10);
  return Number.isNaN(v) || v < 0 ? 3 : v;
}

/** Every code the associate has had, newest first. */
async function codes(associateId) {
  return db.query('SELECT * FROM associate_codes WHERE associate_id = :a ORDER BY issued_at DESC, code', { a: associateId });
}

/** Whether the code is well-formed and its check letter matches (typo protection). */
function isValid(code) {
  const c = normalise(code);
  const m = c.match(new RegExp(`^(NXA-[A-Z0-9]{3,12}-[${ALPHABET}]{1,3})([${ALPHABET}])$`));
  if (!m) {
    return false;
  }
  return checkChar(m[1]) === m[2];
}

async function resolve(code) {
  if (!isValid(code)) {
    return null;
  }
  // Owner rule D-254: the current code and every old one (not blocked by staff) bring the buyer to the associate.
  return require('../repositories/associateRepository').findByAnyCode(normalise(code));
}

module.exports = { ALPHABET, normalise, readablePart, checkChar, generate, issue, propose, changesInLastYear, changesPerYear, codes, isValid, resolve };
