'use strict';

const authService = require('../services/authService');
const { parseDbDateTime } = require('../helpers/dbDateTime');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const userRepository = require('../repositories/userRepository');
const departmentRepository = require('../repositories/departmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const permissionService = require('../services/permissionService');
const permissionRepository = require('../repositories/permissionRepository');
const superAdminService = require('../services/superAdminService');
const notificationRepository = require('../repositories/notificationRepository');
const clientIntakeRepository = require('../repositories/clientIntakeRepository');
const documentReviewRepository = require('../repositories/documentReviewRepository');
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

const orderAccessService = require('../services/orderAccessService');
const activeDepartmentService = require('../services/activeDepartmentService');
const requestContext = require('../helpers/requestContext');
const divisionService = require('../services/divisionService');
const db = require('../config/db');

const LAST_ACTIVITY_KEY = '_last_activity_ts';
const flash = require('../helpers/flash');

function required() {
  return async function sessionAuthRequired(req, res, next) {
    const userId = authService.currentUserId(req);
    if (!userId) {
      res.redirect('/login');
      return;
    }

    // Owner decision D-283 (11 Nov 2026): the staff idle timeout (Admin ›
    // Settings › Security, default 4 hours) and the 12-hour limit since
    // sign-in, however active.
    const sessionTimeouts = require('../services/sessionTimeouts');
    const lastActivity = req.session[LAST_ACTIVITY_KEY] ?? null;
    if (await sessionTimeouts.idleExpired('staff', lastActivity)) {
      await authService.logout(req);
      flash.set(req, 'info', `You were signed out after ${sessionTimeouts.describeMinutes(await sessionTimeouts.idleMinutes('staff'))} without activity. Please log in again.`);
      res.redirect('/login');
      return;
    }
    if (req.session[sessionTimeouts.STAFF_LOGIN_AT_KEY] === undefined || req.session[sessionTimeouts.STAFF_LOGIN_AT_KEY] === null) {
      req.session[sessionTimeouts.STAFF_LOGIN_AT_KEY] = Date.now(); // a session from before D-283 starts its 12 hours now
    }
    const loginAt = Number(req.session[sessionTimeouts.STAFF_LOGIN_AT_KEY]);
    if (await sessionTimeouts.absoluteExpired(loginAt)) {
      await authService.logout(req);
      delete req.session[sessionTimeouts.STAFF_LOGIN_AT_KEY];
      flash.set(req, 'info', `For security, staff log in again at least every ${await sessionTimeouts.absoluteHours()} hours. Please log in again.`);
      res.redirect('/login');
      return;
    }
    req.session[LAST_ACTIVITY_KEY] = Date.now();
    await sessionTimeouts.markPage(req, 'staff', await sessionTimeouts.staffAbsoluteLeft(loginAt));

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

    // Owner decision D-214: a person in several departments works as one
    // of them; until they choose, only the choice page (and logout) opens.
    if (!mustChange && await activeDepartmentService.needsChoice(req, user.id)) {
      const requestPath = req.path.replace(/\/+$/, '');
      if (!['/choose-department', '/logout'].includes(requestPath)) {
        res.redirect('/choose-department');
        return;
      }
    }
    // The role every check uses: the previewed designation's, else the
    // active membership's, else the person's own (kept as own_role_id).
    const acting = await activeDepartmentService.resolve(req, user.id, user.role_id !== null ? user.role_id : null);
    user.own_role_id = user.role_id;
    user.role_id = acting.effectiveRoleId;
    req.actingMembership = acting.membership;
    req.previewDesignation = acting.preview;
    req.myMemberships = acting.membership !== null ? await departmentRepository.activeMembershipsForUser(user.id) : [];

    req.user = user;
    req.permissions = await permissionService.load(user.id, acting.effectiveRoleId, acting.previewing);

    // QA ACC-12: staff placed in a division that is not active (planned, or
    // switched back) cannot create or change anything until it is activated.
    if (req.method === 'POST' && user.division_id !== null && user.division_id !== undefined && !req.permissions.see_all_divisions) {
      const ownDivision = await divisionService.find(Number(user.division_id));
      const postPath = String(req.path || '').replace(/\/+$/, '');
      if (ownDivision && ownDivision.status !== 'active' && !['/logout', '/preview/exit', '/account/password', '/keep-alive'].includes(postPath)
        && !(await superAdminService.isEffective(user.id))) {
        const escapeHtml = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        res.status(403).send(`Your division (${escapeHtml(ownDivision.name)}) is not active yet: nothing can be saved until the MD activates it in Admin › Divisions.`);
        return;
      }
    }

    // QA ACC-2: "Preview as" / "view as" is for looking only. While a
    // preview runs nothing can be saved, approved or granted with the
    // previewed designation's rights — only "Exit preview" and log out post.
    if (acting.previewing && req.method === 'POST') {
      const postPath = String(req.path || '').replace(/\/+$/, '');
      if (!['/preview/exit', '/logout', '/keep-alive'].includes(postPath)) {
        require('../helpers/flash').set(req, 'error', 'You are previewing the app: nothing can be saved during a preview. Use "Exit preview" at the top first.');
        let back = '/';
        try {
          const ref = new URL(req.get('Referer') || '');
          if (ref.host === req.get('host')) back = ref.pathname + ref.search;
        } catch (e) { /* no or foreign Referer: home */ }
        res.redirect(back);
        return;
      }
    }

    // A Super Admin (permanent flag or an active delegation) is
    // unrestricted everywhere: every permission key resolves true, with
    // no per-key configuration needed. Every currently-registered
    // permission key is set true here (rather than a Proxy) so both
    // permissionCheck middleware's plain-object lookups and every view
    // template's `permissions.xxx` access see the same real object.
    req.isSuperAdmin = await superAdminService.isEffective(user.id);
    // D-214: Procurement sees the order number only, not the client name (QA Round 7, R7-N02).
    // QA ACC-16: a preview shows exactly the designation, so a Super Admin previewing Procurement sees no client either (as on PHP).
    req.seesClient = (req.isSuperAdmin && !acting.previewing) || !(await require('../services/moneyVisibility').isProcurementRole(acting.effectiveRoleId));
    // A preview shows exactly that designation, so no Super Admin bypass during it.
    if (req.isSuperAdmin && !acting.previewing) {
      for (const key of await permissionRepository.allKeys()) {
        req.permissions[key] = true;
      }
    }

    // Owner decision D-257: the request's division scope, worked out once and
    // kept in the request context so every repository reads it without `req`.
    const divisionScope = await divisionService.resolveScope(user, req.permissions, req.session);
    const ctxStore = {
      userId: user.id, effectiveRoleId: acting.effectiveRoleId, previewing: acting.previewing, actingAs: acting.actingAs,
      divisionIds: divisionScope.ids, divisionSeesAll: divisionScope.seesAll, divisionUser: user,
    };
    await requestContext.run(ctxStore, async () => {
      const allDivisions = await divisionService.all();
      const activeDivisions = allDivisions.filter((d) => d.status === 'active');
      const scopeIds = divisionService.currentIds() || activeDivisions.map((d) => Number(d.id));
      const off = new Set((await db.query('SELECT division_id, module_key FROM division_modules WHERE is_enabled = 0')).map((r) => `${r.division_id}:${r.module_key}`));
      const disabledPatterns = [];
      const modules = {};
      for (const [key, [, patterns]] of Object.entries(divisionService.MODULES)) {
        // On for the scope when on in any of its divisions (a module with no row is on).
        modules[key] = scopeIds.some((id) => !off.has(`${id}:${key}`));
        if (!modules[key]) disabledPatterns.push(...patterns);
      }
      req.divisionUi = {
        multi: divisionScope.multi,
        showSwitcher: divisionScope.multi && divisionScope.seesAll,
        active: activeDivisions,
        activeCodes: activeDivisions.map((d) => d.code),
        all: allDivisions,
        currentId: divisionService.currentId(),
        modules,
        isDisabledPath: (path) => disabledPatterns.some((p) => p.test(path)),
      };
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

      // Same live-count badge on My Reviews: documents waiting on this user's own review.
      req.myReviewsPendingCount = await documentReviewRepository.pendingCountForReviewer(user.id);
      // Owner decision D-266: the SOP and Gallery menu links follow the SOP library switch.
      req.sopLibraryOpen = await require('../services/sopAccessService').libraryVisibleTo(user.id, req.permissions);

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

      // Owner decision 2 Nov 2026 (D-233): "Waiting for Zoho: N" next to Zoho Sync in the menu.
      req.zohoWaitingMenu = null;
      if (req.permissions.ca_module_manage && req.method === 'GET') {
        req.zohoWaitingMenu = await require('../services/zohoPendingService').menuCount(req.session);
        // Owner decision 2 Nov 2026 (D-239): the badge says so when the day's Zoho allowance is used.
        req.zohoPausedText = req.zohoWaitingMenu > 0 ? await require('../services/zohoPendingService').pausedText() : null;
      }

      // QA Round 5, R5-24: the archived-order and assignment rules apply to
      // every page and action of an order, not just its main page — checked
      // once here for every staff route.
      const denied = await orderAccessService.denialReasonForPath(req.path, user.id, req.permissions);
      if (denied !== null) {
        res.status(403).send(denied);
        return;
      }

      // D-257: a module switched off for the request's division(s) refuses its addresses.
      // (A request without a path, e.g. a bare internal call, is treated as the empty path.)
      const reqPath = req.path || String(req.originalUrl || '').split('?')[0] || '';
      if (req.divisionUi && req.divisionUi.isDisabledPath(reqPath)) {
        res.status(403).send('This part of the system is not in use here.');
        return;
      }
      // D-257: a client or an associate outside the request's division(s) is not available.
      const clientMatch = /^\/clients\/(\d+)(?:\/|$)/.exec(reqPath);
      if (clientMatch && !(await divisionService.allowsClient(parseInt(clientMatch[1], 10)))) {
        res.status(403).send('This client is not available to you.');
        return;
      }
      const associateMatch = /^\/associates\/(\d+)(?:\/|$)/.exec(reqPath);
      if (associateMatch) {
        const associateRow = await db.queryOne('SELECT division_id FROM associates WHERE id = :id', { id: parseInt(associateMatch[1], 10) });
        if (associateRow && !divisionService.allows(associateRow.division_id)) {
          res.status(403).send('This associate is not available to you.');
          return;
        }
      }
      // D-257: the report Division filter (company level, two or more active divisions).
      if (reqPath.startsWith('/reports') && req.query && req.query.division && req.divisionUi && req.divisionUi.showSwitcher) {
        const reportDivision = await divisionService.find(parseInt(req.query.division, 10));
        if (reportDivision && reportDivision.status === 'active' && divisionService.allows(reportDivision.id)) ctxStore.forcedDivision = Number(reportDivision.id);
      }
      // D-257: an order's pages work in the order's own division (its numbering and settings).
      const orderIdForPath = await orderAccessService.orderIdForPath(reqPath);
      if (orderIdForPath !== null) {
        const orderForPath = await db.queryOne('SELECT division_id FROM orders WHERE id = :id', { id: orderIdForPath });
        if (orderForPath) {
          ctxStore.forcedDivision = Number(orderForPath.division_id);
          // QA ACC-11: a module switched off in the ORDER's division refuses that order's addresses
          // (a company-level user on "All divisions" included).
          if (await divisionService.disabledModuleForPath(reqPath)) {
            res.status(403).send('This part of the system is not in use here.');
            return;
          }
        }
      }


      next();
    });
  };
}

module.exports = { required };
