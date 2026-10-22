'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const reasonValidator = require('../helpers/reasonValidator');
const flash = require('../helpers/flash');

/**
 * Port of App\Services\OrderEditGuard — gate for editing order details or
 * product rows once an order has reached Order Confirmation (Stage 4).
 * Before that, editing works exactly as it always has (manage_orders
 * alone). From Stage 4 onward, the caller also needs
 * edit_order_post_confirmation (or is Super Admin — req.permissions
 * already carries every key as true for one, per sessionAuth) and must
 * supply a reason; every such edit is logged.
 *
 * 90-item backlog Phase 6 Item 4: a SECOND, stricter check sits in front
 * of the stage-4 one — once an order is_locked (closed via Stage 9, or
 * marked lost), editing it needs edit_locked_data specifically, the same
 * permission documentGenerationService.generate() already requires to
 * force a document through on a locked order. Checked first because
 * it's the stricter gate: a locked order is always past Stage 4 too,
 * but the reverse isn't true.
 */

function isPostConfirmation(currentStageNumber) {
  return currentStageNumber !== null && currentStageNumber !== undefined && currentStageNumber >= 4;
}

async function allow(req, currentStageNumber, isLocked, reason, entityType, entityId, field) {
  if (isLocked) {
    if (!req.permissions.edit_locked_data) {
      flash.set(req, 'error', 'This order is locked (closed or marked lost) — editing it now needs the "Override locked data" permission.');
      return false;
    }
    const lockError = reasonValidator.check(reason);
    if (lockError) {
      flash.set(req, 'error', lockError);
      return false;
    }

    await auditLogRepository.log(req.user.id, 'ORDER_EDITED_WHILE_LOCKED', entityType, entityId, field, null, null, reason);
    flash.set(req, 'warning', `Editing this LOCKED order — logged for audit (${field}).`);
    return true;
  }

  if (!isPostConfirmation(currentStageNumber)) {
    return true;
  }
  if (!req.permissions.edit_order_post_confirmation) {
    flash.set(req, 'error', 'This order has already reached Order Confirmation — editing it now needs the "Edit an order after confirmation" permission.');
    return false;
  }
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    return false;
  }

  await auditLogRepository.log(req.user.id, 'ORDER_EDITED_POST_CONFIRMATION', entityType, entityId, field, null, null, reason);
  flash.set(req, 'warning', `Editing this order after confirmation — logged for audit (${field}).`);
  return true;
}

function canOverride(req) {
  return !!req.permissions.edit_order_post_confirmation;
}

function canOverrideLock(req) {
  return !!req.permissions.edit_locked_data;
}

module.exports = { isPostConfirmation, allow, canOverride, canOverrideLock };
