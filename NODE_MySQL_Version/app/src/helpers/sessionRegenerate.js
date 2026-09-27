'use strict';

// QA-5 CP-13: express-session's regenerate() replaces req.session's entire
// contents with a brand-new, empty session object — unlike PHP's
// session_regenerate_id(), which only rotates the session ID and leaves
// $_SESSION's data completely untouched. Staff (_auth_user_id) and
// client-portal (_client_portal_client_id) logins are two independent,
// coexisting namespaces in the same session/cookie — a plain regenerate()
// call from either authService.js or clientPortalService.js silently wiped
// the other one out, so a staff member with the client portal open in
// another tab (or vice versa) got logged out of the OTHER surface just by
// logging in/out of the one they were using.
//
// This carries forward the other namespace's key(s) across the ID
// rotation, so logging in or out of one never touches the other's session.
// `applyFn` runs inside regenerate()'s callback — same discipline the
// original code already followed for the caller's own key: some session
// stores don't reliably reflect req.session's new contents until strictly
// inside that callback, not after the returned promise resolves.
function regeneratePreserving(req, keysToPreserve, applyFn) {
  return new Promise((resolve, reject) => {
    const preserved = {};
    for (const key of keysToPreserve) {
      if (req.session[key] !== undefined) {
        preserved[key] = req.session[key];
      }
    }
    req.session.regenerate((err) => {
      if (err) return reject(err);
      for (const key of Object.keys(preserved)) {
        req.session[key] = preserved[key];
      }
      if (applyFn) applyFn();
      resolve();
    });
  });
}

module.exports = { regeneratePreserving };
