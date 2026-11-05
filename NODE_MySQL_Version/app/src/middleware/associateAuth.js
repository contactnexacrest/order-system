'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const associatePortalService = require('../services/associatePortalService');

/**
 * Port of App\Middleware\AssociateAuth. Owner decision 1 Nov 2026 (AS-1):
 * gates every /associate/* page. Separate from staff (sessionAuth) and
 * client (clientAuth) logins — a staff or buyer login never opens the
 * associate portal. A staff "view as" session is read-only: any form post
 * is refused.
 */
const LAST_ACTIVITY_KEY = '_associate_last_activity_ts';

function required() {
  return async function associateAuthRequired(req, res, next) {
    const a = await associatePortalService.current(req);
    if (!a) {
      res.redirect('/associate/login');
      return;
    }
    const timeout = parseInt((await companySettingsRepository.get('session_timeout_minutes')) ?? '30', 10);
    const last = req.session[LAST_ACTIVITY_KEY] ?? null;
    if (last !== null && (Date.now() - last) > timeout * 60000) {
      await associatePortalService.logout(req);
      res.redirect('/associate/login');
      return;
    }
    req.session[LAST_ACTIVITY_KEY] = Date.now();
    const viewAs = associatePortalService.viewAsStaffId(req) !== null;
    // QA ACC-10: a password changed or reset since this session logged in ends it.
    if (!viewAs && (Number(a.portal_enabled) !== 1 || ['ended', 'inactive'].includes(a.status) || !associatePortalService.sessionPasswordStillCurrent(req, a.password_hash))) {
      await associatePortalService.logout(req);
      res.redirect('/associate/login');
      return;
    }
    if (viewAs && req.method === 'POST') {
      res.status(403).send('Read-only: you are viewing this portal as staff.');
      return;
    }
    next();
  };
}

module.exports = { required };
