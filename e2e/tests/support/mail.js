'use strict';

const fs = require('fs');

/**
 * Neither dev stack has a real SMTP server configured in this sandbox
 * (confirmed by reading EmailService.php / emailService.js: both degrade
 * to a logged line — "[EMAIL NOT SENT — no SMTP configured]" — whenever
 * SMTP_HOST/PHPMailer aren't available, which is this environment's only
 * state). That makes real delivery to an inbox impossible to verify here,
 * on either stack. What these helpers verify instead: the app actually
 * attempted to send, to the right recipient, with the right subject, and
 * — for document sends — the right attachment, by reading the exact log
 * line the real send path writes before falling back.
 *
 * PHP has a stable, app-defined sink: bootstrap.php does
 * `ini_set('error_log', storage/logs/error.log)`, independent of how the
 * dev server was started — this is the same file/mechanism the app's own
 * PHPUnit integration tests already rely on (AmendmentDocumentEmailGates
 * Test.php, EmailTemplateCompletenessTest.php use the identical ini_set
 * swap-and-read-back trick at the unit level).
 *
 * Node's email service only ever calls console.error — there's no
 * dedicated file sink for it in the app itself (src/helpers/logger.js is
 * a separate mechanism, for unhandled exceptions only) — so the Node dev
 * server MUST be started with its output redirected to a file for these
 * specific tests to work. See e2e/README.md's "Email functionality
 * tests" prerequisite; override the path with NODE_SERVER_LOG if you
 * started it somewhere else.
 */

function isNodeStack() {
  return (process.env.BASE_URL || '').includes(':3000');
}

function logPath() {
  if (isNodeStack()) {
    return process.env.NODE_SERVER_LOG || '/tmp/claude-0/node-server.log';
  }
  return process.env.PHP_ERROR_LOG || '/home/user/order-system/PHP_MySQL_Version/storage/logs/error.log';
}

/** Call before the action whose email attempt you want to verify. */
function mark() {
  const p = logPath();
  if (!fs.existsSync(p)) {
    throw new Error(
      `Email log file not found at ${p}. ` +
        (isNodeStack()
          ? 'Start the Node dev server with its output redirected to a file (see e2e/README.md), or set NODE_SERVER_LOG to point at it.'
          : "PHP's own storage/logs/error.log should exist once the app has handled any request — check STORAGE_BASE_PATH / file permissions.")
    );
  }
  return fs.statSync(p).size;
}

/** Call after the action — returns only the log content written since mark(). */
function sinceMark(markValue) {
  const p = logPath();
  let size = fs.statSync(p).size;
  if (size < markValue) {
    markValue = 0; // file rotated/truncated since mark() — read from the start instead of erroring
  }
  const length = size - markValue;
  if (length === 0) return '';
  const fd = fs.openSync(p, 'r');
  const buffer = Buffer.alloc(length);
  fs.readSync(fd, buffer, 0, length, markValue);
  fs.closeSync(fd);
  return buffer.toString('utf8');
}

module.exports = { mark, sinceMark };
