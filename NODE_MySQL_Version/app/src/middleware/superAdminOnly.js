'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const superAdminService = require('../services/superAdminService');

// Port of App\Middleware\SuperAdminOnly. Gates the Super Admin management
// screen itself — deliberately NOT a `permissions` row, since Super Admin
// is the tier above the permission system, not a bundle within it.

function required() {
  return async function superAdminOnlyRequired(req, res, next) {
    const user = req.user;
    if (!user) {
      res.redirect('/login');
      return;
    }

    if (!(await superAdminService.isEffective(user.id))) {
      await auditLogRepository.log(user.id, 'PERMISSION_DENIED', 'super_admin', null, 'super_admin_only');
      res.status(403).send('<h1>403 — Not permitted</h1><p>Only a Super Admin can access this page.</p><p><a href="/">Back to dashboard</a></p>');
      return;
    }

    next();
  };
}

module.exports = { required };
