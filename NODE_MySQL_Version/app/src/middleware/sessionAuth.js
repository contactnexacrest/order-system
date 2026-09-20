'use strict';

const authService = require('../services/authService');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const userRepository = require('../repositories/userRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const permissionService = require('../services/permissionService');
const permissionRepository = require('../repositories/permissionRepository');
const superAdminService = require('../services/superAdminService');
const notificationRepository = require('../repositories/notificationRepository');

// Port of App\Middleware\SessionAuth. Redirects to /login if not
// authenticated; enforces the DB-driven idle session timeout
// (company_settings.session_timeout_minutes); enforces the forced-password-
// change gate; and — new in this port, since Express has no per-request
// global equivalent to PHP re-checking PermissionService::can() ad hoc in
// every view — also preloads req.user and req.permissions once per request
// so route guards and view templates both read the same resolved object.

const LAST_ACTIVITY_KEY = '_last_activity_ts';

function required() {
  return async function sessionAuthRequired(req, res, next) {
    const userId = authService.currentUserId(req);
    if (!userId) {
      res.redirect('/login');
      return;
    }

    const timeoutMinutes = parseInt((await companySettingsRepository.get('session_timeout_minutes')) ?? '30', 10);
    const lastActivity = req.session[LAST_ACTIVITY_KEY] ?? null;
    if (lastActivity !== null && (Date.now() - lastActivity) > timeoutMinutes * 60000) {
      await authService.logout(req);
      res.redirect('/login');
      return;
    }
    req.session[LAST_ACTIVITY_KEY] = Date.now();

    const user = await userRepository.findById(userId);
    if (user) {
      let mustChange = parseInt(user.force_password_change, 10) === 1;

      if (!mustChange && user.password_changed_at !== null) {
        const expiryDays = parseInt((await companySettingsRepository.get('password_expiry_days')) ?? '0', 10);
        if (expiryDays > 0) {
          const ageDays = (Date.now() - new Date(user.password_changed_at).getTime()) / 86400000;
          if (ageDays > expiryDays) {
            await userRepository.flagPasswordExpired(user.id);
            await auditLogRepository.log(
              user.id, 'PASSWORD_EXPIRED_FORCED_CHANGE', 'users', user.id, null, null, null,
              `Password last changed ${Math.round(ageDays)} days ago, exceeding the ${expiryDays}-day policy.`
            );
            mustChange = true;
          }
        }
      }

      if (mustChange) {
        const requestPath = req.path.replace(/\/+$/, '');
        if (requestPath !== '/force-password-change') {
          res.redirect('/force-password-change');
          return;
        }
      }

      req.user = user;
      req.permissions = await permissionService.load(user.id, user.role_id !== null ? user.role_id : null);

      // A Super Admin (permanent flag or an active delegation) is
      // unrestricted everywhere: every permission key resolves true, with
      // no per-key configuration needed. Every currently-registered
      // permission key is set true here (rather than a Proxy) so both
      // permissionCheck middleware's plain-object lookups and every view
      // template's `permissions.xxx` access see the same real object.
      req.isSuperAdmin = await superAdminService.isEffective(user.id);
      if (req.isSuperAdmin) {
        for (const key of await permissionRepository.allKeys()) {
          req.permissions[key] = true;
        }
      }

      req.unreadCount = await notificationRepository.unreadCountForUser(user.id);
    }

    next();
  };
}

module.exports = { required };
