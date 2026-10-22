'use strict';

// Port of App\Helpers\Flash — one-shot session-backed messages.

function set(req, type, message) {
  if (!req.session._flash) req.session._flash = [];
  req.session._flash.push({ type, message });
}

/** @returns {Array<{type:string,message:string}>} and clears the queue, same contract as the PHP pull(). */
function pull(req) {
  const messages = req.session._flash || [];
  delete req.session._flash;
  return messages;
}

/**
 * Batch 3 #4 — carries a form's submitted values across a redirect-on-validation-failure, so
 * the create/edit view can re-populate the form instead of the buyer/client having to retype
 * everything. Call right before the redirect on failure; never call on success.
 */
function setOld(req, data) {
  req.session._flash_old = data;
}

/** Returns and clears the old() data in one step — same read-once contract as pull(). */
function pullOld(req) {
  const old = req.session._flash_old || {};
  delete req.session._flash_old;
  return old;
}

/**
 * External QA report R-02: `catch (e) { flash.set(req, 'error', e.message); }`
 * is this app's standard way (~30 call sites) to surface a deliberately-
 * thrown business error's message (`throw new Error('friendly text here')`)
 * to the user — correct for that case. The bug is that the same broad
 * `catch (e)` also catches genuinely unexpected errors — most often a raw
 * mysql2 driver error (a too-long value, an impossible date, a foreign-key
 * violation — the exact things R-01/R-03/R-04 also had to handle) — and
 * shows ITS raw message the same way, leaking SQL internals. mysql2 always
 * stamps a driver-originated error with `.sqlState`/`.code`/`.errno`
 * (see node_modules/mysql2/lib/packets/packet.js's asError()) — something
 * a deliberately-thrown `new Error('...')` never has — so that property is
 * the reliable signal to tell the two apart, the same way PHP's fix relies
 * on PDOException vs. a deliberately-thrown RuntimeException.
 */
function describeException(e, fallback = 'Something went wrong. Please try again.') {
  if (e && e.sqlState) {
    console.error('[UNEXPECTED ERROR shown generically]', e);
    return fallback;
  }
  if (e && typeof e.message === 'string' && e.message !== '') {
    return e.message;
  }
  console.error('[UNEXPECTED ERROR shown generically]', e);
  return fallback;
}

function setErrorFromException(req, e, fallback = 'Something went wrong. Please try again.') {
  set(req, 'error', describeException(e, fallback));
}

module.exports = { set, pull, setOld, pullOld, describeException, setErrorFromException };
