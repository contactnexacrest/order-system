'use strict';

const { ConnectSessionKnexStore } = require('connect-session-knex');

/**
 * QA Round 7, R7-N04: a short database problem (too many connections, a
 * restart, a network blip) must fail the requests it touches, not take the
 * whole Node process down.
 */

/** Error codes that mean "the database is unreachable right now", not a bug. */
const TRANSIENT_CODES = new Set([
  'ER_CON_COUNT_ERROR', 'ER_TOO_MANY_USER_CONNECTIONS', 'ECONNREFUSED', 'ECONNRESET', 'ETIMEDOUT', 'EPIPE',
  'PROTOCOL_CONNECTION_LOST', 'PROTOCOL_SEQUENCE_TIMEOUT', 'ER_SERVER_SHUTDOWN', 'ER_ACCESS_DENIED_ERROR_TRANSIENT',
]);

function isTransientDbError(err) {
  if (!err || typeof err !== 'object') return false;
  if (TRANSIENT_CODES.has(err.code)) return true;
  // knex: "Knex: Timeout acquiring a connection. The pool is probably full."
  return err.name === 'KnexTimeoutError';
}

const UNAVAILABLE_MESSAGE = 'The database is busy or briefly unavailable. Nothing was saved — please wait a moment and try again.';

/**
 * connect-session-knex both calls back with a database error and re-throws
 * it from a promise express-session never awaits — an unhandled rejection
 * that used to exit the process. Here the callback alone carries the error
 * (express-session turns it into a 503 for that request), and a failed
 * start-up check no longer leaves the store broken for good.
 */
class ResilientKnexStore extends ConnectSessionKnexStore {
  constructor(options, onError = () => {}) {
    super(options);
    this.ready = this.ready.catch((err) => { onError(err); });
    for (const method of ['get', 'set', 'touch', 'destroy', 'all', 'length', 'clear']) {
      const original = this[method].bind(this);
      this[method] = (...args) => {
        const result = original(...args);
        if (result && typeof result.catch === 'function') {
          result.catch((err) => { onError(err); });
        }
        return result;
      };
    }
  }
}

module.exports = { isTransientDbError, UNAVAILABLE_MESSAGE, ResilientKnexStore };
