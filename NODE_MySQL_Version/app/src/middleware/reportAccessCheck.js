'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const reportAccess = require('../services/reportAccess');
const refusalPage = require('../helpers/refusalPage');

/**
 * Owner decision D-379: the route gate of every report in reportAccess.REPORTS. It runs after
 * permissionCheck (the base permission stays on the route as the ceiling) and refuses — the
 * same 403 page as permissionCheck (helpers/refusalPage.js), for the page and its CSV alike — when the signed-in person's
 * effective level for the report is below min (default: the level that opens its page).
 *
 * PHP twin: PHP_MySQL_Version/app/src/Middleware/ReportAccessCheck.php
 */

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
    refusalPage.report(res, reportAccess.REFUSED); // D-379 cosmetic follow-up: 403 inside the app layout, back to Reports
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
    refusalPage.report(res, reportAccess.REFUSED, false); // no report opens: back to the dashboard
  };
}

module.exports = { requires, anyReport };
