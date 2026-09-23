'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Every unhandled error in this app used to only go to console.error —
 * fine in a terminal you're watching, useless once the app runs under
 * PM2/systemd/a shared-hosting process manager where "check the console"
 * isn't a thing anyone can actually do. This gives every error a fixed,
 * discoverable home: storage/logs/error.log, one line per error, newest
 * at the bottom, so "check the server error log" (already said in a few
 * flash messages) points somewhere real.
 */

const LOG_DIR = path.join(__dirname, '..', '..', '..', 'storage', 'logs');
const ERROR_LOG_PATH = path.join(LOG_DIR, 'error.log');

function ensureLogDir() {
  try {
    fs.mkdirSync(LOG_DIR, { recursive: true });
  } catch (e) {
    // If this fails there's nowhere to write anyway — the console.error
    // below is still there as a fallback, so swallow rather than crash
    // the request over a logging problem.
  }
}

/**
 * @param {string} context short tag identifying where this came from, e.g. "UNHANDLED ERROR" or "dispatch_deferred_emails"
 * @param {Error|unknown} err
 * @param {object} [extra] optional structured context (request path, user id, ...)
 */
function error(context, err, extra) {
  console.error(`[${context}]`, err);
  ensureLogDir();
  const timestamp = new Date().toISOString();
  const stack = err && err.stack ? err.stack : String(err);
  const extraLine = extra ? ` | ${JSON.stringify(extra)}` : '';
  const line = `[${timestamp}] [${context}]${extraLine}\n${stack}\n\n`;
  try {
    fs.appendFileSync(ERROR_LOG_PATH, line);
  } catch (e) {
    // Same reasoning as above — never let a logging failure become the
    // actual failure the request/job reports back.
  }
}

module.exports = { error, ERROR_LOG_PATH };
