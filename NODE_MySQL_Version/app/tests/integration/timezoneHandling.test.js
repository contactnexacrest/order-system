'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const { formatForDb } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report L-21 (supersedes L-02): Node never set the DB
 * session time zone (PHP's Database.php does: SET time_zone='+05:30'),
 * and still wrote some expiry/lockout DATETIME values via
 * `new Date(...).toISOString()` (always UTC) — on a DB server whose own
 * SYSTEM time zone is UTC (normal on shared hosting), that landed every
 * DB-generated time 5h30m behind IST, and these app-computed literals
 * 5h30m off in the opposite direction once the session was corrected
 * without also fixing the writes. Concretely reproduced: a Buyer Action
 * Link's expiry stored as 06:20 instead of 11:50.
 */
describe('DB session time zone + app-written expiry columns (External QA report L-21/L-02)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('pins every pooled connection session to +05:30', async () => {
    const row = await db.queryOne('SELECT @@session.time_zone AS tz');
    expect(row.tz).toBe('+05:30');
  });

  it('stores the Buyer Action Link expiry as IST wall-clock, matching the app clock', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const beforeCall = new Date();

    await orderRepository.generateBuyerActionToken(orderId, 90);

    const afterCall = new Date();
    const row = await db.queryOne('SELECT buyer_action_token_expires_at FROM orders WHERE id = :id', { id: orderId });

    // buyer_action_token_expires_at is a DATETIME (not TIMESTAMP) column —
    // MySQL never converts it on read/write, so whatever wall-clock string
    // the app wrote comes straight back. It must fall within the
    // [beforeCall, afterCall] + 90 days window, computed in IST exactly
    // like generateBuyerActionToken() itself does.
    const lowerBound = formatForDb(new Date(beforeCall.getTime() + 90 * 86400000));
    const upperBound = formatForDb(new Date(afterCall.getTime() + 90 * 86400000));

    expect(row.buyer_action_token_expires_at >= lowerBound).toBe(true);
    expect(row.buyer_action_token_expires_at <= upperBound).toBe(true);

    // The regression this guards: the pre-fix UTC-formatted write would
    // land 5h30m before the correct IST value — well outside the window
    // above (and still outside it even with the loose per-test timing
    // slack), so a reintroduced toISOString() would fail the assertions
    // above rather than this one merely restating them.
  });
});
