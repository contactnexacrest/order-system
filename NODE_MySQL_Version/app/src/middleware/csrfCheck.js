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
      res.status(419).send('<h1>419 — Session expired</h1><p>Please go back, refresh the page, and try again.</p>');
      return;
    }
    next();
  };
}

module.exports = { verify };
