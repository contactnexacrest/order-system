'use strict';

/**
 * Port of the PHP annexure/index.php view's $annexureConfirmAttr closure.
 * 90-item backlog #73/#74: once the Annexure document itself has been
 * approved/sent, editing the content below silently drifts from what the
 * buyer already saw unless the next regeneration+re-approval catches up.
 * Warn, don't block — same "tracking aid, not a lock" treatment as every
 * other staff-trust gate in this codebase (see Stage 9's Close Order hint).
 *
 * Builds an onsubmit="return confirm(...)" HTML attribute, prefixing the
 * Annexure-locked warning (if any) onto an optional existing confirm
 * message. Returns '' when there is nothing to confirm (not locked and no
 * extraMessage), matching the pre-existing forms that had no confirm at all.
 */
function escapeHtmlAttr(value) {
  return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function annexureConfirmAttr(annexaDocument, extraMessage = '') {
  const isLocked = !!annexaDocument && ['approved', 'sent'].includes(annexaDocument.status);
  const warnText = isLocked
    ? `This Annexure was already ${annexaDocument.status === 'sent' ? 'sent to the buyer' : 'approved'}. This change will not update what was already sent — regenerate and get it re-approved first.`
    : '';
  const message = `${warnText} ${extraMessage}`.trim() + (warnText !== '' ? ' Continue?' : '');
  if (message === '') {
    return '';
  }
  return `onsubmit="return confirm(${escapeHtmlAttr(JSON.stringify(message))});"`;
}

module.exports = { annexureConfirmAttr };
