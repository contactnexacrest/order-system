'use strict';

const permissionRepository = require('../repositories/permissionRepository');
const requestContext = require('../helpers/requestContext');

// Port of App\Services\PermissionService. The PHP version cached a static
// map for the lifetime of one share-nothing request; here sessionAuth
// middleware loads it once per request onto req.permissions (see
// middleware/sessionAuth.js), and both route guards and view templates read
// that same object — so this module is just the loader, not a cache holder.
//
// Owner decision D-214: the signed-in person's permissions come from the
// department they logged in as (or the designation they preview), as in
// PHP's PermissionService::can(); a preview leaves out their own exceptions.

async function load(userId, roleId, previewing = false) {
  const ctx = requestContext.get();
  if (ctx && Number(ctx.userId) === Number(userId)) {
    return permissionRepository.effectivePermissions(userId, ctx.effectiveRoleId, ctx.previewing);
  }
  return permissionRepository.effectivePermissions(userId, roleId, previewing);
}

module.exports = { load };
