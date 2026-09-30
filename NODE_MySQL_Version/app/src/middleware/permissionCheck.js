'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');

// Port of App\Middleware\PermissionCheck. Must run after sessionAuth.required()
// so req.user / req.permissions are already populated.

function requires(permissionKey) {
  return async function permissionCheckRequires(req, res, next) {
    const user = req.user;
    if (!user) {
      res.redirect('/login');
      return;
    }

    if (!req.permissions || !req.permissions[permissionKey]) {
      await auditLogRepository.log(user.id, 'PERMISSION_DENIED', 'permissions', null, permissionKey);
      res.status(403).send(
        `<h1>403 — Not permitted</h1><p>You do not have the "${escapeHtml(permissionKey)}" permission.</p><p><a href="/">Back to dashboard</a></p>`
      );
      return;
    }

    next();
  };
}

// Passes if the user holds ANY of the given permission keys — e.g. a
// dispute page must be reachable by someone who can only reply
// (respond_to_disputes) as well as someone who manages disputes broadly
// (manage_disputes).
function requiresAny(permissionKeys) {
  return async function permissionCheckRequiresAny(req, res, next) {
    const user = req.user;
    if (!user) {
      res.redirect('/login');
      return;
    }

    const permissions = req.permissions || {};
    if (permissionKeys.some((key) => permissions[key])) {
      next();
      return;
    }

    await auditLogRepository.log(user.id, 'PERMISSION_DENIED', 'permissions', null, permissionKeys.join('|'));
    res.status(403).send('<h1>403 — Not permitted</h1><p>You do not have any of the required permissions.</p><p><a href="/">Back to dashboard</a></p>');
  };
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

module.exports = { requires, requiresAny };
