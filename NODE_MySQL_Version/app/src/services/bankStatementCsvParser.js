'use strict';

// CA / Accounting module (Phase 5) — parses a bank-issued CSV export into
// plain transaction rows. Bank statement CSV layouts vary wildly bank to
// bank; this recognizes a handful of common header-name variants rather
// than one fixed format, and reports how many rows it couldn't parse
// rather than silently dropping them. If a real statement's headers don't
// match any alias here, that's a real gap to close by adding the alias,
// not a bug in the parsing logic itself.
//
// No CSV parsing library is already used in this codebase (csv-stringify
// only writes CSV) — this is a small, dependency-free RFC4180-ish reader,
// good enough for the plain comma-separated exports bank portals produce.

const DATE_ALIASES = ['date', 'txn date', 'transaction date', 'value date'];
const DESCRIPTION_ALIASES = ['description', 'narration', 'particulars', 'remarks'];
const REFERENCE_ALIASES = ['reference', 'reference no', 'ref no', 'cheque no', 'chq no', 'utr'];
const DEBIT_ALIASES = ['debit', 'withdrawal amt', 'withdrawal', 'debit amount'];
const CREDIT_ALIASES = ['credit', 'deposit amt', 'deposit', 'credit amount'];
const AMOUNT_ALIASES = ['amount'];
const TYPE_ALIASES = ['type', 'dr/cr', 'cr/dr'];

function splitCsvLine(line) {
  const fields = [];
  let current = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (inQuotes) {
      if (ch === '"') {
        if (line[i + 1] === '"') {
          current += '"';
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        current += ch;
      }
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === ',') {
      fields.push(current);
      current = '';
    } else {
      current += ch;
    }
  }
  fields.push(current);
  return fields;
}

function findColumn(normalizedHeader, aliases) {
  for (const alias of aliases) {
    const idx = normalizedHeader.indexOf(alias);
    if (idx !== -1) return idx;
  }
  return null;
}

function parseDate(raw) {
  if (!raw) return null;
  const isoMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (isoMatch) return raw;

  const dmy = raw.match(/^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$/);
  if (dmy) {
    const [, d, m, y] = dmy;
    // Ambiguous d/m vs m/d — assume day-first (most bank exports outside the US)
    return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  }

  const parsed = new Date(raw);
  if (!Number.isNaN(parsed.getTime())) {
    return parsed.toISOString().slice(0, 10);
  }
  return null;
}

function parseAmount(raw) {
  const s = String(raw ?? '').trim();
  if (s === '' || s === '-') return null;
  const clean = s.replace(/,/g, '').replace(/[^0-9.\-]/g, '');
  if (clean === '' || clean === '-' || clean === '.') return null;
  const value = parseFloat(clean);
  return value !== 0 ? Math.abs(value) : null;
}

/**
 * @param {string} content the raw CSV file content (already decoded to a string)
 * @returns {{rows: Array<{date:string, description:?string, reference:?string, credit:?number, debit:?number}>, skipped: number}}
 * @throws if no recognizable header is found
 */
function parse(content) {
  const lines = content.split(/\r\n|\r|\n/).filter((l) => l.length > 0);
  if (lines.length === 0) {
    throw new Error('The file appears to be empty.');
  }

  const header = splitCsvLine(lines[0]).map((h) => h.trim().toLowerCase());
  const dateIdx = findColumn(header, DATE_ALIASES);
  const descIdx = findColumn(header, DESCRIPTION_ALIASES);
  const refIdx = findColumn(header, REFERENCE_ALIASES);
  const debitIdx = findColumn(header, DEBIT_ALIASES);
  const creditIdx = findColumn(header, CREDIT_ALIASES);
  const amountIdx = findColumn(header, AMOUNT_ALIASES);
  const typeIdx = findColumn(header, TYPE_ALIASES);

  const hasCreditDebitPair = debitIdx !== null || creditIdx !== null;
  const hasAmountTypePair = amountIdx !== null && typeIdx !== null;
  if (dateIdx === null || (!hasCreditDebitPair && !hasAmountTypePair)) {
    throw new Error("Could not find recognizable Date and Credit/Debit (or Amount + Type) columns in the file's header row.");
  }

  const rows = [];
  let skipped = 0;
  for (let i = 1; i < lines.length; i++) {
    const fields = splitCsvLine(lines[i]);
    if (fields.every((f) => f.trim() === '')) continue;

    const date = parseDate((fields[dateIdx] || '').trim());
    if (!date) {
      skipped++;
      continue;
    }

    let credit = null;
    let debit = null;
    if (hasCreditDebitPair) {
      credit = creditIdx !== null ? parseAmount(fields[creditIdx]) : null;
      debit = debitIdx !== null ? parseAmount(fields[debitIdx]) : null;
    } else {
      const amount = parseAmount(fields[amountIdx]);
      const type = (fields[typeIdx] || '').trim().toLowerCase();
      if (amount !== null) {
        if (type.startsWith('cr')) credit = amount;
        else if (type.startsWith('dr')) debit = amount;
      }
    }

    if (credit === null && debit === null) {
      skipped++;
      continue;
    }

    rows.push({
      date,
      description: descIdx !== null ? (fields[descIdx] || '').trim() || null : null,
      reference: refIdx !== null ? (fields[refIdx] || '').trim() || null : null,
      credit,
      debit,
    });
  }

  return { rows, skipped };
}

module.exports = { parse };
