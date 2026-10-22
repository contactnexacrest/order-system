'use strict';

const authService = require('../services/authService');
const { parseDbDateTime } = require('../helpers/dbDateTime');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const userRepository = require('../repositories/userRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const permissionService = require('../services/permissionService');
const permissionRepository = require('../repositories/permissionRepository');
const superAdminService = require('../services/superAdminService');
const notificationRepository = require('../repositories/notificationRepository');
const clientIntakeRepository = require('../repositories/clientIntakeRepository');
const piIntakeRepository = require('../repositories/piIntakeRepository');
const emailLogRepository = require('../repositories/emailLogRepository');
const dashboardRepository = require('../repositories/dashboardRepository');

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

    // AUTH-10: a user deactivated mid-session (staff let go, a wrongdoer
    // suspended) must lose access on their very next request, not just be
    // blocked from a future login — is_active is only ever checked at
    // login time otherwise, so an already logged-in session would keep
    // working indefinitely.
    if (!user || !user.is_active) {
      await authService.logout(req);
      res.redirect('/login');
      return;
    }

    // External QA report L-26: a password change bumps
    // users.session_version — any OTHER session (a stolen one
    // included) still carries the OLD value it snapshotted at its own
    // login time, so it's logged out right here on its very next
    // request, the same immediate-effect guarantee the is_active check
    // above already gives for deactivation.
    if (Number(user.session_version) !== Number(authService.sessionVersion(req) ?? 0)) {
      await authService.logout(req);
      res.redirect('/login');
      return;
    }

    let mustChange = parseInt(user.force_password_change, 10) === 1;

    if (!mustChange && user.password_changed_at !== null) {
      const expiryDays = parseInt((await companySettingsRepository.get('password_expiry_days')) ?? '0', 10);
      if (expiryDays > 0) {
        const ageDays = (Date.now() - parseDbDateTime(user.password_changed_at).getTime()) / 86400000;
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

    // Standing-request nav badges — live, not cached, so the sidebar
    // always reflects reality: a submission appearing the moment it's
    // filed, the number dropping the moment it's accepted or rejected.
    // Both review screens are gated on manage_orders, so only query for
    // staff who could actually act on one.
    if (req.permissions.manage_orders) {
      req.clientIntakePendingCount = await clientIntakeRepository.pendingCount();
      req.piIntakePendingCount = await piIntakeRepository.pendingReviewCount();
    } else {
      req.clientIntakePendingCount = 0;
      req.piIntakePendingCount = 0;
    }

    // User-reported batch item 16: same live-COUNT badge pattern as the
    // Quotation/PI Intake Review links above — only queried for staff
    // who could actually act on it.
    req.emailApprovalsPendingCount = req.permissions.approve_email_send
      ? await emailLogRepository.awaitingDispatchCount()
      : 0;

    // 90-item backlog item #11: a per-field lock badge + save-time
    // warning already existed on the Settings page itself
    // (dashboardRepository.complianceExpiryWarnings() already computed
    // this, shown only on the Dashboard body) — but nothing surfaced it
    // from every other page the way the other standing-request badges
    // above do. Reuses the exact same computation, just counted and
    // attached to the Settings link instead of rendered inline.
    if (req.permissions.manage_company_settings) {
      const warnings = await dashboardRepository.complianceExpiryWarnings();
      req.settingsExpiryWarningCount = (warnings.lut ? 1 : 0) + (warnings.rcmc ? 1 : 0);
    } else {
      req.settingsExpiryWarningCount = 0;
    }

    next();
  };
}

module.exports = { required };
