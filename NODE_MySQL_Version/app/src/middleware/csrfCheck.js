'use strict';

const csrf = require('../helpers/csrf');

// Port of App\Middleware\CsrfCheck — only POST requests are checked (this
// app never uses PUT/PATCH/DELETE, mirroring the PHP router's method set).

function verify() {
  return function csrfCheckVerify(req, res, next) {
    if (req.method !== 'POST') {
      next();
      return;
    }
    if (!csrf.verify(req, req.body ? req.body._csrf : undefined)) {
      // 90-item backlog #25: this used to send a bare, unstyled "419 —
      // Session expired" page with no navigation — a dead end whenever a
      // session genuinely timed out while a form sat open. Redirect to
      // the right login screen instead, same as every other
      // session-expiry path in this app.
      const loginPath = req.path.startsWith('/client/') ? '/client/login' : '/login';
      res.redirect(loginPath);
      return;
    }
    next();
  };
}

module.exports = { verify };
