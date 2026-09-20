'use strict';

const clientPortalService = require('../services/clientPortalService');

// Port of App\Middleware\ClientAuth. Gates every /client/* portal route.
// Structurally separate from sessionAuth (staff): reads a different
// session key, and never grants access based on a staff login or vice
// versa — a staff member browsing /client/* while logged in as staff is
// NOT authenticated here at all.

function required() {
  return function clientAuthRequired(req, res, next) {
    if (!clientPortalService.currentClientId(req)) {
      res.redirect('/client/login');
      return;
    }
    next();
  };
}

module.exports = { required };
