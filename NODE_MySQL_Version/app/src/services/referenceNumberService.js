'use strict';

const db = require('../config/db');
const companySettingsRepository = require('./../repositories/companySettingsRepository');
const testModeService = require('./testModeService');

// Port of App\Services\ReferenceNumberService. Formats are DB-driven
// (company_settings.master_tracking_ref_format, document_types.ref_format)
// — never hardcoded. {NNN} is a same-calendar-day sequence within scope.

/**
 * Next value in a persistent, monotonic per-scope counter (docs/schema.sql
 * Section AG) — atomic via INSERT ... ON DUPLICATE KEY UPDATE, so it's safe
 * under concurrent calls and, critically, immune to any same-day row being
 * deleted elsewhere. This replaced a `SELECT COUNT(*) ... WHERE
 * DATE(created_at) = CURDATE()` scheme that undercounted as soon as any
 * same-day row was deleted — e.g. loading the Sample Data Playground,
 * clearing it, then loading it again — and could then re-mint a number
 * still held by a surviving real row, raising a duplicate-key error on
 * client_unique_number or amendment_reference.
 *
 * QA-5 CONC-01/CONC-02: the increment and the read-back used to be two
 * separate statements (an UPSERT, then a plain SELECT). Under autocommit
 * — the default, no explicit transaction spans the two — the UPSERT's row
 * lock releases the instant it commits, which is BEFORE the follow-up
 * SELECT runs. A third concurrent caller's own increment could land in
 * that gap, and then two different callers' SELECTs could both read that
 * same newer value back — the exact same class of duplicate-number bug
 * this function was written to fix in the first place, just one layer
 * deeper. `LAST_INSERT_ID(expr)` makes MySQL report the value THIS
 * statement itself computed, in the same response as the UPSERT — no
 * separate read, so no gap for another caller's write to land in.
 */
async function nextSeq(scopeKey) {
  const result = await db.execute(
    `INSERT INTO reference_sequences (scope_key, last_seq) VALUES (:key, 1)
     ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)`,
    { key: scopeKey }
  );
  return parseInt(result.insertId, 10);
}

/**
 * QA-5 CONC-03: orders.sequence_no is a per-client counter, not a
 * same-day one like the scopes above, and existing clients already have
 * real order history — so the very first call for a client must seed the
 * counter from that client's current MAX(sequence_no), not blindly start
 * at 1 (which would immediately collide with an existing order_reference).
 * The INSERT ... SELECT computes that seed only on the row's first-ever
 * insert; once the reference_sequences row exists, ON DUPLICATE KEY UPDATE
 * increments it exactly like nextSeq() above and ignores the SELECT's
 * value. This was previously a `SELECT MAX(sequence_no)+1 ... FOR UPDATE`
 * inside a transaction, which is deadlock-prone (InnoDB gap locks on a
 * non-unique-indexed range) under real concurrent load — this UPSERT is a
 * single point-lock on reference_sequences' unique scope_key index instead.
 */
async function nextOrderSequenceForClient(clientId) {
  const result = await db.execute(
    `INSERT INTO reference_sequences (scope_key, last_seq)
     SELECT :key, COALESCE(MAX(o.sequence_no), 0) + 1 FROM orders o WHERE o.client_id = :client_id
     ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)`,
    { key: `order_seq:client:${clientId}`, client_id: clientId }
  );
  return parseInt(result.insertId, 10);
}

function pad2(n) {
  return String(n).padStart(2, '0');
}

/**
 * Single chokepoint for every minted reference number (client unique
 * number, every document reference, amendment reference) — the TEST-
 * prefix (docs/schema.sql Section V, requirement: test reference numbers
 * must be identifiable) is applied exactly once here rather than at each
 * of the three call sites below. `now` is passed in from the caller so
 * the {YYYY}/{DDMM} rendered here always matches the same clock instant
 * used to compute the counter's scope key.
 */
async function render(format, seq, now) {
  const yyyy = String(now.getFullYear());
  const dd = pad2(now.getDate());
  const mm = pad2(now.getMonth() + 1);
  const nnn = String(seq).padStart(3, '0');
  const rendered = format
    .replace(/\{YYYY\}/g, yyyy)
    .replace(/\{DDMM\}/g, dd + mm)
    .replace(/\{NNN\}/g, nnn);
  const testMode = await testModeService.isEnabled();
  return testModeService.applyReferencePrefix(rendered, testMode);
}

function ymd(now) {
  return String(now.getFullYear()) + pad2(now.getMonth() + 1) + pad2(now.getDate());
}

async function generateClientUniqueNumber() {
  const format = (await companySettingsRepository.get('master_tracking_ref_format')) || 'NC/SC/{YYYY}/{DDMM}{NNN}';
  const now = new Date();
  const seq = await nextSeq('client_unique:' + ymd(now));
  return render(format, seq, now);
}

async function generateDocumentRef(documentTypeId) {
  const row = await db.queryOne('SELECT ref_format FROM document_types WHERE id = :id', { id: documentTypeId });
  if (!row || !row.ref_format) return null;

  const now = new Date();
  const seq = await nextSeq('document:' + documentTypeId + ':' + ymd(now));
  return render(row.ref_format, seq, now);
}

/**
 * Minted at amendment REQUEST time, before any `documents` row exists for
 * it — uses its own scope (the table that actually enforces uniqueness on
 * this value), not the document-type scope above.
 */
async function generateAmendmentRef() {
  const row = await db.queryOne("SELECT ref_format FROM document_types WHERE code = 'AMD'");
  if (!row || !row.ref_format) return null;

  const now = new Date();
  const seq = await nextSeq('amendment:' + ymd(now));
  return render(row.ref_format, seq, now);
}

module.exports = { generateClientUniqueNumber, generateDocumentRef, generateAmendmentRef, nextOrderSequenceForClient };
