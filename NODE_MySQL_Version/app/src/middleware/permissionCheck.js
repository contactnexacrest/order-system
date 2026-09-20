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

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

module.exports = { requires };
