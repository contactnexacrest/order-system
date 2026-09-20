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

module.exports = { set, pull };
