'use strict';

const db = require('../../src/config/db');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/** Port of TestFixtureSequenceTest: each order of a client gets its own sequence number (uq_client_sequence). */
describe('test order fixture', () => {
  afterAll(async () => { await db.pool.end(); });

  it('one client can have several test orders', async () => {
    const client = await createTestClient();
    const first = await createTestOrder(client);
    const second = await createTestOrder(client);
    const rows = await db.query(`SELECT sequence_no FROM orders WHERE id IN (${Number(first)}, ${Number(second)}) ORDER BY id`);
    expect(Number(rows[1].sequence_no) - Number(rows[0].sequence_no)).toBe(1);
  });
});
