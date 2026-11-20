'use strict';

const notificationRepository = require('../repositories/notificationRepository');

// Port of App\Controllers\NotificationController.

async function index(req, res) {
  const user = req.user;
  const notifications = await notificationRepository.forUser(user.id, 50);
  await notificationRepository.markAllRead(user.id);
  // sessionAuth's requireAuth computes req.unreadCount once, before this
  // controller runs — markAllRead() above makes that value stale (it's
  // what renderView's common context reads for the sidebar badge), so
  // it's corrected here rather than left to report what's no longer true.
  req.unreadCount = 0;

  // Owner decision D-394: a prospect reminder offers "Keep for N more days" while its client is still an active prospect.
  const prospectService = require('../services/prospectService');
  const canManageOrders = !!(req.permissions && req.permissions.manage_orders);
  for (const n of notifications) {
    n.prospect_client = null;
    if (canManageOrders && n.type === prospectService.NOTIFICATION_TYPE && n.related_order_id) {
      const order = await require('../repositories/orderRepository').find(Number(n.related_order_id));
      const client = order ? await require('../repositories/clientRepository').find(Number(order.client_id)) : null;
      if (client && Number(client.is_prospect || 0) === 1 && Number(client.is_active) === 1) n.prospect_client = client;
    }
  }

  res.renderView('notifications/index', { notifications, prospectExpiryDays: await prospectService.expiryDays() }, 'layout/base');
}

module.exports = { index };
