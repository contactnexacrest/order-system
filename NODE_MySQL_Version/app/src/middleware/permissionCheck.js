'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const refusalPage = require('../helpers/refusalPage');

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
      refusalPage.permission(res, `You do not have the "${permissionKey}" permission.`); // D-379 cosmetic follow-up: inside the app layout
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
    refusalPage.permission(res, 'You do not have any of the required permissions.');
  };
}

module.exports = { requires, requiresAny };
