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

module.exports = { set, pull, setOld, pullOld };
