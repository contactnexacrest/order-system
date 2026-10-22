'use strict';

const mysql = require('mysql2/promise');
const env = require('./env');
const logger = require('../helpers/logger');

/**
 * Connection pool (not one-per-request like the PHP PDO wrapper — Node is a
 * long-running process, not share-nothing-per-request, so a pool is the
 * correct equivalent here, not a behavior change to the app). namedPlaceholders
 * lets repository code use the same ":name" style as the PHP PDO queries it
 * was ported from, so a side-by-side diff of a repository file against its
 * PHP source stays easy to review.
 */
const pool = mysql.createPool({
  host: env.get('DB_HOST', '127.0.0.1'),
  port: env.getInt('DB_PORT', 3306),
  database: env.get('DB_DATABASE'),
  user: env.get('DB_USERNAME'),
  password: env.get('DB_PASSWORD', ''),
  charset: env.get('DB_CHARSET', 'utf8mb4').toUpperCase().includes('UTF8MB4') ? 'UTF8MB4_GENERAL_CI' : undefined,
  namedPlaceholders: true,
  waitForConnections: true,
  connectionLimit: env.getInt('DB_POOL_SIZE', 10),
  maxIdle: env.getInt('DB_POOL_SIZE', 10),
  idleTimeout: 60000,
  dateStrings: true, // keep DATETIME/TIMESTAMP as 'YYYY-MM-DD HH:MM:SS' strings, matching PDO's default fetch behavior the PHP code relies on
});

/**
 * External QA report L-21 (supersedes L-02): mirrors
 * Database.php's own `SET time_zone = '+05:30'` on connect — without it,
 * this session defaults to the DB server's SYSTEM time zone (UTC on most
 * hosting, including the shared hosting this app targets), so NOW()/
 * CURRENT_TIMESTAMP write UTC wall-clock into every TIMESTAMP column while
 * config/env.js's process.env.TZ = 'Asia/Kolkata' means every app-computed
 * date is already IST — a split-timezone app. Sets it on every new pooled
 * connection (not once at startup) since the pool opens connections
 * lazily/as-needed for its whole lifetime.
 */
pool.on('connection', (connection) => {
  // The pool's 'connection' event hands back the underlying callback-style
  // connection (mysql2/promise only wraps query results returned to
  // application code, not this event) — callback form, not .then()/.catch().
  connection.query("SET time_zone = '+05:30'", (err) => {
    if (err) {
      logger.error('DB_SESSION_TIMEZONE', err);
    }
  });
});

/** @returns {Promise<Array<object>>} rows only (no field metadata), mirrors PDO fetchAll() */
async function query(sql, params = {}) {
  const [rows] = await pool.execute(sql, params);
  return rows;
}

/** @returns {Promise<object|null>} first row or null, mirrors PDO fetch() */
async function queryOne(sql, params = {}) {
  const rows = await query(sql, params);
  return rows.length ? rows[0] : null;
}

/** INSERT/UPDATE/DELETE — returns the raw mysql2 result (insertId, affectedRows, ...) */
async function execute(sql, params = {}) {
  const [result] = await pool.execute(sql, params);
  return result;
}

/** Runs `fn(conn)` inside a transaction; conn exposes the same query/queryOne/execute shape, bound to one connection. */
async function transaction(fn) {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const scoped = {
      query: async (sql, params = {}) => (await conn.execute(sql, params))[0],
      queryOne: async (sql, params = {}) => {
        const [rows] = await conn.execute(sql, params);
        return rows.length ? rows[0] : null;
      },
      execute: async (sql, params = {}) => (await conn.execute(sql, params))[0],
    };
    const result = await fn(scoped);
    await conn.commit();
    return result;
  } catch (e) {
    await conn.rollback();
    throw e;
  } finally {
    conn.release();
  }
}

module.exports = { pool, query, queryOne, execute, transaction };
