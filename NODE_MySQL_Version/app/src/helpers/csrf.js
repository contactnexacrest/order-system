'use strict';

const crypto = require('crypto');

// Port of App\Helpers\Csrf — session-stored token, double-submit on POST.
// PHP kept the token in the global $_SESSION; Express has no session global,
// so every function here takes `req` explicitly instead.

function token(req) {
  if (!req.session._csrf_token) {
    req.session._csrf_token = crypto.randomBytes(32).toString('hex');
  }
  return req.session._csrf_token;
}

/** HTML for a hidden <input>, for use in server-rendered forms (Nunjucks templates call this via a global). */
function field(req) {
  return `<input type="hidden" name="_csrf" value="${token(req)}">`;
}

function verify(req, submitted) {
  const expected = req.session._csrf_token;
  if (!expected || !submitted) return false;
  const a = Buffer.from(String(expected));
  const b = Buffer.from(String(submitted));
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}

module.exports = { token, field, verify };
