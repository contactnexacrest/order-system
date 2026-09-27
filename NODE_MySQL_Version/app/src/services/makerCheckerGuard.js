'use strict';

const userRepository = require('../repositories/userRepository');
const superAdminService = require('./superAdminService');
const permissionService = require('./permissionService');

// Port of App\Services\MakerCheckerGuard. QA-5 maker-checker: the single
// choke point every self-approval check (document review assignment,
// email-send approval, amendment MD-approval) delegates to. Owner
// decision: the person who created/requested an item must not also be
// the one who approves it — EXCEPT a Super Admin or anyone holding
// manage_permissions, since that tier can already grant itself any
// approval role through the permission system, so enforcing separation
// on them is not a real control.

async function selfApprovalAllowed(userId) {
  if (await superAdminService.isEffective(userId)) {
    return true;
  }
  const user = await userRepository.findById(userId);
  if (!user) {
    return false;
  }
  const permissions = await permissionService.load(userId, user.role_id !== null ? user.role_id : null);
  return Boolean(permissions.manage_permissions);
}

module.exports = { selfApprovalAllowed };
