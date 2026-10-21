'use strict';

const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of TimestampsDisplayAndCompareInIstTest.php (the display half —
 * see that file's own docblock for the full write-up of user-reported
 * batch item 6, "the time stamp must be IST Indian -Kolkata time
 * stamp"). This stack keeps the DB in UTC deliberately (see
 * config/db.js and helpers/dbDateTime.js's own DEF-04 notes), so unlike
 * the PHP port's one-line Database::connection() fix, the fix here is
 * in documentDataAssembler.formatDate() itself: a time-bearing DB value
 * is now explicitly parsed as UTC (parseDbDateTime) before its calendar
 * date is read with LOCAL (IST) getters, instead of a bare `new
 * Date(str)` silently mislabeling the UTC digits as already-IST.
 */
describe('Timestamps display in IST (user-reported batch, item 6)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocument(orderId, typeCode) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      'INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:order_id, :type_id, :ref, :status)',
      { order_id: orderId, type_id: type.id, ref: `JEST-TSDOC-${Math.random().toString(16).slice(2, 10)}`, status: 'draft' }
    );
    return result.insertId;
  }

  it('formatDate shows the correct IST calendar date for a timestamp near UTC midnight', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const docId = await createTestDocument(orderId, 'QT');

    // A true UTC instant (2026-10-20 20:00:00 UTC = 2026-10-21 01:30:00
    // IST) written as a literal UTC wall-clock string — this stack's DB
    // session is always UTC, so no CONVERT_TZ trick is needed the way
    // the PHP port's test needs one.
    await db.execute("UPDATE documents SET generated_at = '2026-10-20 20:00:00' WHERE id = :id", { id: docId });

    const doc = await documentRepository.find(docId);

    expect(documentDataAssembler.formatDate(doc.generated_at)).toBe('21 October 2026');
  });

  it('still shows the right calendar date for a plain DATE column with no time component', async () => {
    expect(documentDataAssembler.formatDate('2026-10-21')).toBe('21 October 2026');
  });
});
