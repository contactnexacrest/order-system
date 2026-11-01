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
  for (let i = 0; i < 50; i++) {
    const body = `NXA-${part}-${ALPHABET[crypto.randomInt(0, ALPHABET.length)]}`;
    const code = body + checkChar(body);
    const taken = await db.queryOne('SELECT 1 AS yes FROM associates WHERE personal_code = :c UNION SELECT 1 FROM associate_codes WHERE code = :c2', { c: code, c2: code });
    if (!taken) {
      return code;
    }
  }
  throw new Error('Could not make a unique code — please try again.');
}

/**
 * Gives the associate a new personal code: the old one is retired (it no
 * longer brings new clients; clients it brought stay theirs) and no code
 * is ever given to anyone else.
 */
async function issue(associateId, readable) {
  const code = await generate(readable);
  await db.execute('UPDATE associate_codes SET retired_at = NOW() WHERE associate_id = :a AND retired_at IS NULL', { a: associateId });
  await db.execute('INSERT INTO associate_codes (code, associate_id) VALUES (:c, :a)', { c: code, a: associateId });
  await db.execute('UPDATE associates SET personal_code = :c WHERE id = :a', { c: code, a: associateId });
  return code;
}

/** Whether the code is well-formed and its check letter matches (typo protection). */
function isValid(code) {
  const c = normalise(code);
  const m = c.match(new RegExp(`^(NXA-[A-Z0-9]{3,12}-[${ALPHABET}])([${ALPHABET}])$`));
  if (!m) {
    return false;
  }
  return checkChar(m[1]) === m[2];
}

async function resolve(code) {
  if (!isValid(code)) {
    return null;
  }
  // Only the associate's current code brings new clients; a retired code resolves to nobody.
  return require('../repositories/associateRepository').findByCode(normalise(code));
}

module.exports = { ALPHABET, normalise, readablePart, checkChar, generate, issue, isValid, resolve };
