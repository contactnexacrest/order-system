'use strict';

/**
 * QA batch 4, ZOH-1: only one Zoho sync at a time. The hourly run, "Sync
 * now", "Sync this order" and a record's "Send to Zoho Books" all read the
 * same waiting records before marking them sent; two at once would post the
 * same payment, expense or journal twice. A database lock (per database, so
 * the PHP and Node stacks on one database share it), held on its own pool
 * connection for the whole run, is taken without waiting: a second run stops
 * at once with one Sync Log row. Port of App\Services\ZohoSyncLock.
 */
const db = require('../config/db');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');

const BUSY = 'A Zoho sync is already running — try again in a minute.';

async function run(fn, busy, triggeredBy, userId, orderId = null) {
  const conn = await db.pool.getConnection();
  let got = 0;
  try {
    const [rows] = await conn.query("SELECT GET_LOCK(CONCAT('nx_zoho_sync_', DATABASE()), 0) AS got");
    got = Number(rows[0].got);
  } catch (e) {
    conn.release();
    throw e;
  }
  if (got !== 1) {
    conn.release();
    await zohoSyncLogRepository.log('sync_run', orderId != null ? 'order' : null, orderId != null ? Number(orderId) : null, null, 'error', null, BUSY, triggeredBy, userId);
    return busy();
  }
  try {
    return await fn();
  } finally {
    try {
      await conn.query("SELECT RELEASE_LOCK(CONCAT('nx_zoho_sync_', DATABASE()))");
    } finally {
      conn.release();
    }
  }
}

module.exports = { BUSY, run };
