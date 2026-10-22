'use strict';

const db = require('../../src/config/db');
const referenceNumberService = require('../../src/services/referenceNumberService');
const { createTestClient } = require('../support/fixtures');

/**
 * QA-5 (CONC-01/CONC-02 — external QA report cross-verification): the
 * increment and the read-back inside nextSeq() used to be two separate
 * statements. Under autocommit, the UPSERT's row lock releases the instant
 * it commits — before the follow-up SELECT ever runs — so a third
 * concurrent caller's own increment could land in that gap, and two
 * different callers' SELECTs could then both read that same newer value
 * back. Fixed with LAST_INSERT_ID(expr), which reports the value THIS
 * statement itself computed, in the same round trip as the UPSERT.
 */
describe('Reference-number counters under concurrent calls (QA-5 CONC-01/CONC-02)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('never hands out the same client_unique_number twice under concurrent generation', async () => {
    const CONCURRENT_CALLS = 20;
    const numbers = await Promise.all(
      Array.from({ length: CONCURRENT_CALLS }, () => referenceNumberService.generateClientUniqueNumber())
    );

    expect(new Set(numbers).size).toBe(CONCURRENT_CALLS);
  });

  it('never hands out the same order sequence number twice for one client under concurrent generation', async () => {
    const clientId = await createTestClient();

    const CONCURRENT_CALLS = 20;
    const seqs = await Promise.all(
      Array.from({ length: CONCURRENT_CALLS }, () => referenceNumberService.nextOrderSequenceForClient(clientId))
    );

    expect(new Set(seqs).size).toBe(CONCURRENT_CALLS);
  });

  /**
   * External QA report L-01: reference_sequences has no AUTO_INCREMENT
   * column (scope_key is the PK), so the very first INSERT ... ON
   * DUPLICATE KEY UPDATE upsert for a brand-new scope used to hit the
   * plain INSERT branch, which never set the session's LAST_INSERT_ID()
   * at all — the driver's insertId then came back 0 (or stale), minting
   * "000" as the first reference of a new client/day/type instead of
   * "001". All three counters share the same nextSeq()-style shape, so a
   * single representative check (the per-client order sequence, via a
   * guaranteed-fresh client id) covers the fix for all of them.
   */
  it('reports 1, not 0, for the first-ever sequence number of a brand-new client', async () => {
    const clientId = await createTestClient();

    const first = await referenceNumberService.nextOrderSequenceForClient(clientId);

    expect(first).toBe(1);
  });
});
