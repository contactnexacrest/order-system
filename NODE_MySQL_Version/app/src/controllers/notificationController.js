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

  res.renderView('notifications/index', { notifications }, 'layout/base');
}

module.exports = { index };
