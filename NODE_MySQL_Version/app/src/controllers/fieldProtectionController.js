'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const fieldProtectionRepository = require('../repositories/fieldProtectionRepository');
const superAdminService = require('../services/superAdminService');

// Peer-approved lock/unlock governance for the is_protected flag — see
// Section L, schema.sql, and fieldProtectionRepository's docblock. This
// controller only ever creates/resolves *requests*; the flag itself is
// flipped exclusively inside fieldProtectionRepository.approve().

async function index(req, res) {
  const [protectable, pending, resolved] = await Promise.all([
    fieldProtectionRepository.listProtectable(),
    fieldProtectionRepository.pendingRequests(),
    fieldProtectionRepository.recentResolved(),
  ]);
  const isSuperAdmin = await superAdminService.isEffective(req.user.id);
  res.renderView('field_protection/index', { protectable, pending, resolved, currentUserId: req.user.id, isSuperAdmin }, 'layout/base');
}

async function createRequest(req, res) {
  const user = req.user;
  const tableName = String(req.body.table_name || '');
  const recordId = parseInt(req.body.record_id, 10);
  const recordLabel = String(req.body.record_label || '').trim();
  const action = String(req.body.action || '');
  const reason = String(req.body.reason || '').trim();

  if (!Object.prototype.hasOwnProperty.call(fieldProtectionRepository.PROTECTABLE_TABLES, tableName) || !recordId) {
    flash.set(req, 'error', 'Unrecognized field — nothing was submitted.');
    res.redirect('/admin/field-protection');
    return;
  }
  if (!['lock', 'unlock'].includes(action)) {
    flash.set(req, 'error', 'Invalid action.');
    res.redirect('/admin/field-protection');
    return;
  }
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/admin/field-protection');
    return;
  }

  const existing = await fieldProtectionRepository.findPendingForRecord(tableName, recordId);
  if (existing) {
    flash.set(req, 'error', `There is already a pending ${existing.requested_action} request for this field — resolve that one first.`);
    res.redirect('/admin/field-protection');
    return;
  }

  const requestId = await fieldProtectionRepository.createRequest({
    tableName, recordId, recordLabel, action, reason, requestedBy: user.id,
  });
  await auditLogRepository.log(user.id, 'PROTECTION_FLAG_REQUESTED', tableName, recordId, 'is_protected', null, action, reason);

  flash.set(req, 'success', `Request submitted (#${requestId}) — a different privileged user must approve it before anything changes.`);
  res.redirect('/admin/field-protection');
}

async function approve(req, res) {
  const user = req.user;
  const requestId = parseInt(req.params.requestId, 10);
  const resolvedReason = String(req.body.resolved_reason || '').trim();

  try {
    const result = await fieldProtectionRepository.approve(requestId, user.id, resolvedReason);
    await auditLogRepository.log(
      user.id, 'PROTECTION_FLAG_APPROVED', result.table_name, result.record_id, 'is_protected',
      result.requested_action === 'lock' ? '0' : '1', result.requested_action === 'lock' ? '1' : '0',
      `Approved request #${requestId} (originally requested by user #${result.requested_by}): ${result.reason}`
    );
    flash.set(req, 'success', `Request #${requestId} approved — the field is now ${result.requested_action === 'lock' ? 'protected' : 'unprotected'}.`);
  } catch (e) {
    flash.set(req, 'error', e.message || 'Could not approve this request.');
  }
  res.redirect('/admin/field-protection');
}

async function reject(req, res) {
  const user = req.user;
  const requestId = parseInt(req.params.requestId, 10);
  const resolvedReason = String(req.body.resolved_reason || '').trim();

  try {
    const result = await fieldProtectionRepository.reject(requestId, user.id, resolvedReason);
    await auditLogRepository.log(
      user.id, 'PROTECTION_FLAG_REJECTED', result.table_name, result.record_id, 'is_protected',
      null, null, `Rejected request #${requestId}: ${resolvedReason || '(no reason given)'}`
    );
    flash.set(req, 'success', `Request #${requestId} rejected.`);
  } catch (e) {
    flash.set(req, 'error', e.message || 'Could not reject this request.');
  }
  res.redirect('/admin/field-protection');
}

module.exports = { index, createRequest, approve, reject };
