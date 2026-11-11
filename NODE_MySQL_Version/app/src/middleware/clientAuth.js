'use strict';

const clientPortalService = require('../services/clientPortalService');
const clientLoginRepository = require('../repositories/clientLoginRepository');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');

// Port of App\Middleware\ClientAuth. Gates every /client/* portal route.
// Structurally separate from sessionAuth (staff): reads a different
// session key, and never grants access based on a staff login or vice
// versa — a staff member browsing /client/* while logged in as staff is
// NOT authenticated here at all.

const LAST_ACTIVITY_KEY = '_client_last_activity_ts';

function required() {
  return async function clientAuthRequired(req, res, next) {
    const clientId = clientPortalService.currentClientId(req);
    if (!clientId) {
      res.redirect('/client/login');
      return;
    }

    // CP-12: the client portal never had an idle timeout at all — an
    // unattended browser (a shared office computer, a public kiosk) stayed
    // logged in to a buyer's own order/document history forever. Reuses
    // the same DB-driven setting sessionAuth enforces for staff.
    // D-283: the buyers' own idle timeout (Admin › Settings › Security, default 2 hours).
    const sessionTimeouts = require('../services/sessionTimeouts');
    const lastActivity = req.session[LAST_ACTIVITY_KEY] ?? null;
    if (await sessionTimeouts.idleExpired('client', lastActivity)) {
      await clientPortalService.logout(req);
      delete req.session[LAST_ACTIVITY_KEY];
      require('../helpers/flash').set(req, 'info', `You were signed out after ${sessionTimeouts.describeMinutes(await sessionTimeouts.idleMinutes('client'))} without activity. Please log in again.`);
      res.redirect('/client/login');
      return;
    }
    req.session[LAST_ACTIVITY_KEY] = Date.now();
    await sessionTimeouts.markPage(req, 'client');

    // docs/schema.sql Section AV: a staff-impersonated session has no
    // client_logins row to check at all — it's a separate channel, not a
    // stand-in for the client's own password login (that row may not even
    // exist yet, e.g. before Stage 3). Only the client record itself needs
    // to still be active.
    if (clientPortalService.isImpersonating(req)) {
      const client = await clientRepository.find(clientId);
      if (!client || !client.is_active) {
        await clientPortalService.endImpersonation(req);
        res.redirect('/client/login');
        return;
      }
      // QA ACC-9: the staff member behind it must still be an active user.
      const staff = await require('../repositories/userRepository').findById(clientPortalService.impersonatedByStaffId(req));
      if (!staff || !Number(staff.is_active)) {
        await clientPortalService.endImpersonation(req);
        res.redirect('/client/login');
        return;
      }
      // QA ACC-9: logging in as a client is for looking only — staff do not
      // acknowledge, upload, report payments, raise disputes or reorder as the buyer.
      if (req.method === 'POST') {
        const postPath = String(req.path || '').replace(/\/+$/, '');
        if (!['/client/end-impersonation', '/client/logout', '/client/keep-alive'].includes(postPath)) {
          require('../helpers/flash').set(req, 'error', 'You are logged in as this client to look only: nothing can be saved for the buyer. Use "End impersonation" first.');
          let back = '/client';
          try {
            const ref = new URL(req.get('Referer') || '');
            if (ref.host === req.get('host')) back = ref.pathname + ref.search;
          } catch (e) { /* no or foreign Referer */ }
          res.redirect(back);
          return;
        }
      }
      next();
      return;
    }

    // CP-06: a client (or their portal login specifically) deactivated
    // mid-session must lose access on their very next request — like
    // AUTH-10 for staff, is_active was otherwise only ever checked at
    // login time.
    const login = await clientLoginRepository.findById(clientId);
    // QA ACC-10: a password changed or reset since this session logged in ends it.
    if (!login || !login.is_active || !login.client_is_active || !clientPortalService.sessionPasswordStillCurrent(req, login.password_hash)) {
      await clientPortalService.logout(req);
      res.redirect('/client/login');
      return;
    }

    next();
  };
}

module.exports = { required };
