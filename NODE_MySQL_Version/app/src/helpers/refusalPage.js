'use strict';

/**
 * D-379 cosmetic follow-up (16 Nov 2026, owner-approved mockup): the 403 refusal page of the report gates
 * (middleware/reportAccessCheck.js) and of middleware/permissionCheck.js, drawn inside the normal app
 * layout (views/errors/refused.njk): a red "Not open to you" pill, a title, the same explanatory text as
 * before and a "← Back" button. The HTTP status stays 403 and the PERMISSION_DENIED audit row is written
 * by the caller, as before. If the layout cannot be drawn (no res.renderView, or an error while
 * rendering), the old plain page is sent instead, so a refusal always answers.
 *
 * PHP twin: PHP_MySQL_Version/app/src/Helpers/RefusalPage.php
 */

const PILL = 'Not open to you';
const REPORT_TITLE = 'This report is not open to your role';
const PAGE_TITLE = 'This page is not open to your role';

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** The page as it was before (no layout). */
function plain(message, backUrl = '/', backLabel = 'Back to dashboard') {
  return `<h1>403 — Not permitted</h1><p>${escapeHtml(message)}</p><p><a href="${escapeHtml(backUrl)}">${escapeHtml(backLabel)}</a></p>`;
}

function send(res, title, message, backUrl, backLabel) {
  res.status(403);
  if (typeof res.renderView === 'function') {
    try {
      res.renderView('errors/refused', { title, message, backUrl, backLabel }, 'layout/base');
      return;
    } catch (err) {
      if (res.headersSent) return;
    }
  }
  res.send(plain(message, backUrl, backLabel));
}

/** A report refusal: back to the Reports page (or the dashboard when no report opens at all). */
function report(res, message, toReports = true) {
  send(res, REPORT_TITLE, message, toReports ? '/reports' : '/', toReports ? 'Back to Reports' : 'Back to dashboard');
}

/** A permission refusal (permissionCheck): back to the dashboard. */
function permission(res, message) {
  send(res, PAGE_TITLE, message, '/', 'Back to dashboard');
}

module.exports = { PILL, REPORT_TITLE, PAGE_TITLE, plain, send, report, permission };
