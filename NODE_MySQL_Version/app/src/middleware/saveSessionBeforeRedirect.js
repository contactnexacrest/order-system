'use strict';

/**
 * QA Round 5, R5-15: confirmation messages intermittently missing on Node.
 *
 * express-session saves a changed session from inside res.end(), but when
 * the store is asynchronous (connect-session-knex) it sends the response
 * headers first — writetop() — and only finishes the response once the
 * save completes. For a redirect the headers are all the browser needs, so
 * it can request the next page before the session holding the new flash
 * message has been written: that page reads the old session, shows no
 * message, and its own save (the idle-timeout timestamp) then overwrites
 * the message for good. PHP never showed this because its file session
 * stays locked until the first request has finished.
 *
 * So every redirect first saves the session and only then sends the 302.
 * save() also marks the session clean, so express-session does not save
 * it a second time at res.end().
 */
function saveSessionBeforeRedirect(req, res, next) {
  const redirect = res.redirect;
  res.redirect = function redirectAfterSessionSave(...args) {
    if (!req.session) {
      redirect.apply(res, args);
      return;
    }
    req.session.save((err) => {
      if (err) console.error('[SESSION SAVE BEFORE REDIRECT FAILED]', err);
      redirect.apply(res, args);
    });
  };
  next();
}

module.exports = saveSessionBeforeRedirect;
