'use strict';

const db = require('../../src/config/db');
const referenceNumberService = require('../../src/services/referenceNumberService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA Round 5, R5-08: on MariaDB with ONLY_FULL_GROUP_BY, reserving an
 * order sequence or a document revision number failed with error 1140
 * ("Mixing of GROUP columns ... with no GROUP BY clause") — the outer
 * SELECT of the INSERT ... SELECT held both a bound parameter and MAX().
 * Both now compute MAX() in a scalar subquery; run them here on a
 * connection with that strict mode switched on.
 */
describe('Reference sequences under ONLY_FULL_GROUP_BY (QA Round 5, R5-08)', () => {
  let conn;
  let executor;

  beforeAll(async () => {
    conn = await db.pool.getConnection();
    await conn.query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    executor = { execute: async (sql, params) => (await conn.execute(sql, params))[0] };
  });

  afterAll(async () => {
    await conn.query('SET SESSION sql_mode = DEFAULT');
    conn.release();
    await db.pool.end();
  });

  it('reserves the next order sequence for a client', async () => {
    const clientId = await createTestClient();
    expect(await referenceNumberService.nextOrderSequenceForClient(clientId, executor)).toBe(1);
    expect(await referenceNumberService.nextOrderSequenceForClient(clientId, executor)).toBe(2);
  });

  it('seeds the order sequence from the orders a client already has', async () => {
    const clientId = await createTestClient();
    await createTestOrder(clientId); // sequence_no 1, created outside reference_sequences
    expect(await referenceNumberService.nextOrderSequenceForClient(clientId, executor)).toBe(2);
  });

  it('reserves the next document revision number', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const qt = await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'");
    expect(await referenceNumberService.nextDocumentRevisionNumber(orderId, qt.id, executor)).toBe(0);
    expect(await referenceNumberService.nextDocumentRevisionNumber(orderId, qt.id, executor)).toBe(1);
  });
});
