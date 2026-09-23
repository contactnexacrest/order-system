'use strict';

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');
const userRepository = require('../repositories/userRepository');

// Port of App\Services\SuperAdminService.

const MIN_REASON_LENGTH = 10;

async function isEffective(userId) {
  const row = await db.queryOne('SELECT is_super_admin FROM users WHERE id = :id', { id: userId });
  if (row && parseInt(row.is_super_admin, 10) === 1) {
    return true;
  }

  const delegation = await db.queryOne(
    `SELECT id FROM super_admin_delegations
     WHERE delegate_user_id = :id AND revoked_at IS NULL
       AND (expires_at IS NULL OR expires_at > NOW())
     LIMIT 1`,
    { id: userId }
  );
  return !!delegation;
}

async function permanentSuperAdmins() {
  return db.query('SELECT id, name, email, is_protected_account FROM users WHERE is_super_admin = 1 AND is_active = 1 ORDER BY name');
}

async function activeDelegations() {
  return db.query(
    `SELECT sad.*, u.name AS delegate_name, u.email AS delegate_email, g.name AS granted_by_name
     FROM super_admin_delegations sad
     JOIN users u ON u.id = sad.delegate_user_id
     JOIN users g ON g.id = sad.granted_by
     WHERE sad.revoked_at IS NULL AND (sad.expires_at IS NULL OR sad.expires_at > NOW())
     ORDER BY sad.granted_at DESC`
  );
}

async function delegationHistory(limit = 50) {
  return db.query(
    `SELECT sad.*, u.name AS delegate_name, g.name AS granted_by_name, r.name AS revoked_by_name
     FROM super_admin_delegations sad
     JOIN users u ON u.id = sad.delegate_user_id
     JOIN users g ON g.id = sad.granted_by
     LEFT JOIN users r ON r.id = sad.revoked_by
     WHERE sad.revoked_at IS NOT NULL OR (sad.expires_at IS NOT NULL AND sad.expires_at <= NOW())
     ORDER BY sad.granted_at DESC LIMIT ${parseInt(limit, 10)}`
  );
}

async function grantDelegation(delegateUserId, grantedBy, reason, expiresAt) {
  if ((reason || '').trim().length < MIN_REASON_LENGTH) {
    throw new Error(`Reason must be at least ${MIN_REASON_LENGTH} characters — describe why this delegation is needed.`);
  }
  if (await isEffective(delegateUserId)) {
    throw new Error('This user is already a Super Admin or already has an active delegation.');
  }

  const result = await db.execute(
    `INSERT INTO super_admin_delegations (delegate_user_id, granted_by, reason, expires_at)
     VALUES (:delegate, :granted_by, :reason, :expires_at)`,
    { delegate: delegateUserId, granted_by: grantedBy, reason: reason.trim(), expires_at: expiresAt || null }
  );

  await auditLogRepository.log(grantedBy, 'SUPER_ADMIN_DELEGATION_GRANTED', 'users', delegateUserId, 'is_super_admin', '0', '1 (delegated)', reason.trim());
  return result.insertId;
}

async function revokeDelegation(delegationId, revokedBy, reason) {
  if ((reason || '').trim().length < MIN_REASON_LENGTH) {
    throw new Error(`Reason must be at least ${MIN_REASON_LENGTH} characters.`);
  }

  const result = await db.execute(
    `UPDATE super_admin_delegations
     SET revoked_at = NOW(), revoked_by = :revoked_by, revoked_reason = :reason
     WHERE id = :id AND revoked_at IS NULL`,
    { revoked_by: revokedBy, reason: reason.trim(), id: delegationId }
  );
  if (result.affectedRows === 0) {
    throw new Error('Delegation not found, or already revoked/expired.');
  }

  const row = await db.queryOne('SELECT delegate_user_id FROM super_admin_delegations WHERE id = :id', { id: delegationId });
  await auditLogRepository.log(revokedBy, 'SUPER_ADMIN_DELEGATION_REVOKED', 'users', row.delegate_user_id, 'is_super_admin', '1 (delegated)', '0', reason.trim());
}

/**
 * Promotes/demotes the PERMANENT is_super_admin flag — a distinct, audited
 * action from delegation, and distinct from delete/deactivate (which the
 * DB trigger blocks unconditionally while the flag is 1). Only an existing
 * effective Super Admin may call this (enforced by the controller).
 */
async function setPermanentFlag(userId, isSuperAdminFlag, changedBy, reason) {
  if ((reason || '').trim().length < MIN_REASON_LENGTH) {
    throw new Error(`Reason must be at least ${MIN_REASON_LENGTH} characters.`);
  }
  if (!isSuperAdminFlag && (await permanentSuperAdmins()).length <= 1) {
    throw new Error('Cannot remove the last remaining Super Admin — promote someone else first.');
  }
  if (!isSuperAdminFlag) {
    const target = await userRepository.findById(userId);
    if (target && parseInt(target.is_protected_account, 10) === 1) {
      throw new Error('This is a protected founder account — Super Admin status can never be removed from it.');
    }
  }

  await db.execute('UPDATE users SET is_super_admin = :flag WHERE id = :id', { flag: isSuperAdminFlag ? 1 : 0, id: userId });

  await auditLogRepository.log(
    changedBy,
    isSuperAdminFlag ? 'SUPER_ADMIN_PROMOTED' : 'SUPER_ADMIN_DEMOTED',
    'users', userId, 'is_super_admin',
    isSuperAdminFlag ? '0' : '1', isSuperAdminFlag ? '1' : '0',
    reason.trim()
  );
}

module.exports = {
  MIN_REASON_LENGTH, isEffective, permanentSuperAdmins, activeDelegations,
  delegationHistory, grantDelegation, revokeDelegation, setPermanentFlag,
};
