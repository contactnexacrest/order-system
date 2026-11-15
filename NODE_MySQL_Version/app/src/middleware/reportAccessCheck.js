'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const reportAccess = require('../services/reportAccess');

/**
 * Owner decision D-379: the route gate of every report in reportAccess.REPORTS. It runs after
 * permissionCheck (the base permission stays on the route as the ceiling) and refuses — the
 * same 403 page as permissionCheck, for the page and its CSV alike — when the signed-in person's
 * effective level for the report is below min (default: the level that opens its page).
 *
 * PHP twin: PHP_MySQL_Version/app/src/Middleware/ReportAccessCheck.php
 */

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const REFUSED_PAGE = `<h1>403 — Not permitted</h1><p>${escapeHtml(reportAccess.REFUSED)}</p><p><a href="/">Back to dashboard</a></p>`;

function requires(reportKey, min = null) {
  return async function reportAccessCheckRequires(req, res, next) {
    const user = req.user;
    if (!user) {
      res.redirect('/login');
      return;
    }
    if (await reportAccess.can(reportKey, min || reportAccess.pageMin(reportKey))) {
      next();
      return;
    }
    await auditLogRepository.log(user.id, 'PERMISSION_DENIED', 'permissions', null, `report:${reportKey}`);
    res.status(403).send(REFUSED_PAGE);
  };
}

/** /reports itself: at least one report page opens for the person. */
function anyReport() {
  return async function reportAccessCheckAnyReport(req, res, next) {
    const user = req.user;
    if (!user) {
      res.redirect('/login');
      return;
    }
    if (await reportAccess.anyOpen()) {
      next();
      return;
    }
    await auditLogRepository.log(user.id, 'PERMISSION_DENIED', 'permissions', null, 'report:any');
    res.status(403).send(REFUSED_PAGE);
  };
}

module.exports = { requires, anyReport };
