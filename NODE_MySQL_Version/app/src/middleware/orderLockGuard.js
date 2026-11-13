'use strict';

const orderRepository = require('../repositories/orderRepository');
const flash = require('../helpers/flash');

/**
 * Port of App\Middleware\OrderLockGuard — see its own docblock for the
 * full rationale (External QA report L-28) and the exact list of routes
 * deliberately left unguarded (override-status-lock, close/mark-lost,
 * archive/unarchive, duplicate, comments, disputes, and the document-
 * generate/send routes). The draft document delete route IS guarded (QA
 * HNT-002).
 */
function check() {
  return async function orderLockGuardCheck(req, res, next) {
    const orderId = parseInt(req.params.id, 10);
    if (!orderId) {
      next();
      return;
    }

    const order = await orderRepository.find(orderId);
    if (!order || !order.is_locked) {
      next();
      return;
    }

    if (req.permissions && req.permissions.edit_locked_data) {
      next();
      return;
    }

    flash.set(req, 'error', 'This order is locked (closed or marked lost) — this action now needs the "Override locked data" permission.');
    res.redirect(`/orders/${orderId}`);
  };
}

module.exports = { check };
