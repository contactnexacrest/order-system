'use strict';

const db = require('../../src/config/db');

/**
 * External QA report L-05: the buyer-facing T&C (QT/PI/OC) still said
 * "Grade A quality" in the Natural Stone Characteristics clause — the
 * owner asked for "quality is as agreed" instead. A content-only
 * regression guard: fails if "Grade A quality" is ever reintroduced into
 * the seeded clause text.
 */
describe('Natural Stone Characteristics clause wording (QA-5 L-05)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('no longer says "Grade A quality"', async () => {
    const row = await db.queryOne(
      "SELECT clause_text FROM tc_clauses WHERE clause_title = 'Natural Stone Characteristics'"
    );

    expect(row).toBeTruthy();
    expect(row.clause_text).not.toContain('Grade A quality');
    expect(row.clause_text).toContain('quality');
    expect(row.clause_text).toContain('as agreed');
  });
});
