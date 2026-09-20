'use strict';

const db = require('../config/db');
const companySettingsRepository = require('./../repositories/companySettingsRepository');

// Port of App\Services\ReferenceNumberService. Formats are DB-driven
// (company_settings.master_tracking_ref_format, document_types.ref_format)
// — never hardcoded. {NNN} is a same-calendar-day sequence within scope.

function render(format, seq) {
  const now = new Date();
  const yyyy = String(now.getFullYear());
  const dd = String(now.getDate()).padStart(2, '0');
  const mm = String(now.getMonth() + 1).padStart(2, '0');
  const nnn = String(seq).padStart(3, '0');
  return format
    .replace(/\{YYYY\}/g, yyyy)
    .replace(/\{DDMM\}/g, dd + mm)
    .replace(/\{NNN\}/g, nnn);
}

async function generateClientUniqueNumber() {
  const format = (await companySettingsRepository.get('master_tracking_ref_format')) || 'NC/SC/{YYYY}/{DDMM}{NNN}';
  const row = await db.queryOne('SELECT COUNT(*) AS c FROM clients WHERE DATE(created_at) = CURDATE()');
  const seq = parseInt(row.c, 10) + 1;
  return render(format, seq);
}

async function generateDocumentRef(documentTypeId) {
  const row = await db.queryOne('SELECT ref_format FROM document_types WHERE id = :id', { id: documentTypeId });
  if (!row || !row.ref_format) return null;

  const countRow = await db.queryOne(
    'SELECT COUNT(*) AS c FROM documents WHERE document_type_id = :type_id AND DATE(generated_at) = CURDATE()',
    { type_id: documentTypeId }
  );
  const seq = parseInt(countRow.c, 10) + 1;
  return render(row.ref_format, seq);
}

/**
 * Minted at amendment REQUEST time, before any `documents` row exists for
 * it — counts same-day rows in `amendments` (the table that actually
 * enforces uniqueness on this value), not `documents`.
 */
async function generateAmendmentRef() {
  const row = await db.queryOne("SELECT ref_format FROM document_types WHERE code = 'AMD'");
  if (!row || !row.ref_format) return null;

  const countRow = await db.queryOne('SELECT COUNT(*) AS c FROM amendments WHERE DATE(created_at) = CURDATE()');
  const seq = parseInt(countRow.c, 10) + 1;
  return render(row.ref_format, seq);
}

module.exports = { generateClientUniqueNumber, generateDocumentRef, generateAmendmentRef };
