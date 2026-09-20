'use strict';

const permissionRepository = require('../repositories/permissionRepository');

// Port of App\Services\PermissionService. The PHP version cached a static
// map for the lifetime of one share-nothing request; here sessionAuth
// middleware loads it once per request onto req.permissions (see
// middleware/sessionAuth.js), and both route guards and view templates read
// that same object — so this module is just the loader, not a cache holder.

async function load(userId, roleId) {
  return permissionRepository.effectivePermissions(userId, roleId);
}

module.exports = { load };
