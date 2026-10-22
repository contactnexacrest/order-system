'use strict';

const db = require('../../src/config/db');
const termsClauseRepository = require('../../src/repositories/termsClauseRepository');

/**
 * Port of QuotationValidityLateAcceptanceClauseTest.php. External QA
 * report L-09: "QT lacks the agreed clause: advance received after
 * 'Valid Until' is not acceptance / NexaCrest issues a revised QT at
 * same/updated prices. App says only 'subject to revision'." Extends
 * the existing 'Quotation Validity' clause (tc_clauses, linked to QT via
 * tc_clause_documents) with that missing sentence.
 */
describe('Quotation Validity late-acceptance clause (External QA report L-09)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('the Quotation Validity clause warns that late acceptance is not acceptance', async () => {
    const clauses = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'standard', null);
    const validityClause = clauses.find((c) => c.title === 'Quotation Validity');

    expect(validityClause).toBeTruthy();
    expect(validityClause.text).toContain('does not constitute acceptance');
    expect(validityClause.text).toContain('revised Quotation');
  });
});
