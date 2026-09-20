'use strict';

const flash = require('../helpers/flash');
const superAdminService = require('../services/superAdminService');
const userRepository = require('../repositories/userRepository');

// Port of App\Controllers\SuperAdminController.

async function index(req, res) {
  const [superAdmins, activeDelegations, history, allUsers] = await Promise.all([
    superAdminService.permanentSuperAdmins(),
    superAdminService.activeDelegations(),
    superAdminService.delegationHistory(),
    userRepository.listAllForAdmin(),
  ]);
  res.renderView('super_admin/index', {
    superAdmins, activeDelegations, history, allUsers,
    minReasonLength: superAdminService.MIN_REASON_LENGTH,
  }, 'layout/base');
}

async function grantDelegation(req, res) {
  const user = req.user;
  const delegateUserId = parseInt(req.body.delegate_user_id, 10) || 0;
  const reason = String(req.body.reason || '').trim();
  const expiresAt = String(req.body.expires_at || '').trim() || null;

  try {
    await superAdminService.grantDelegation(delegateUserId, user.id, reason, expiresAt);
    flash.set(req, 'success', 'Super Admin delegation granted.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect('/super-admin');
}

async function revokeDelegation(req, res) {
  const user = req.user;
  const delegationId = parseInt(req.params.id, 10) || 0;
  const reason = String(req.body.reason || '').trim();

  try {
    await superAdminService.revokeDelegation(delegationId, user.id, reason);
    flash.set(req, 'success', 'Delegation revoked.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect('/super-admin');
}

async function setPermanent(req, res) {
  const user = req.user;
  const targetUserId = parseInt(req.body.target_id, 10) || 0;
  const makeSuperAdmin = String(req.body.is_super_admin || '0') === '1';
  const reason = String(req.body.reason || '').trim();

  try {
    await superAdminService.setPermanentFlag(targetUserId, makeSuperAdmin, user.id, reason);
    flash.set(req, 'success', makeSuperAdmin ? 'User promoted to Super Admin.' : 'Super Admin status removed.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect('/super-admin');
}

module.exports = { index, grantDelegation, revokeDelegation, setPermanent };
