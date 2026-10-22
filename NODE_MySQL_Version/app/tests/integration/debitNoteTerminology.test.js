'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');

/**
 * External QA report L-07: "'FDN' still printed: CI 'Freight &
 * Insurance paid separately via FDN'; Debit Note 'Please quote FDN
 * No.'" document_types.name/ref_format were already renamed
 * "Freight Debit Note" -> "Debit Note" / "SC/FDN/" -> "SC/DN/" (per
 * NexaCrest_Change_Log.txt's authoritative "Tier1/06_DebitNote"
 * entry), but the PDF templates themselves still spelled "FDN" out
 * literally in several buyer-facing sentences. The document_type_code
 * itself ('FDN') is an internal identifier, never shown to the buyer,
 * and is deliberately left unchanged. Port of PHP's
 * DebitNoteTerminologyTest.
 */
describe('Debit Note terminology (External QA report L-07)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('the Debit Note template no longer spells out FDN', () => {
    const html = fs.readFileSync(path.join(__dirname, '../../templates/FDN/freight_debit_note.njk'), 'utf8');

    expect(html).not.toContain('FDN');
    expect(html).toContain('Please quote Debit Note No.');
  });

  it('the Commercial Invoice template no longer spells out FDN', () => {
    const html = fs.readFileSync(path.join(__dirname, '../../templates/CI/commercial_invoice.njk'), 'utf8');

    expect(html).not.toContain('FDN');
    expect(html).toContain('PAID VIA DEBIT NOTE');
    expect(html).toContain('Against Debit Note No.');
    expect(html).toContain('paid separately via Debit Note');
  });

  it('the document_type row itself is renamed', async () => {
    const row = await db.queryOne("SELECT name, ref_format FROM document_types WHERE code = 'FDN'");

    expect(row.name).toBe('Debit Note');
    expect(row.ref_format).toBe('SC/DN/{YYYY}/{DDMM}{NNN}');
  });
});
