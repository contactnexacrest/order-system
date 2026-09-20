'use strict';

const notificationRepository = require('../repositories/notificationRepository');

// Port of App\Controllers\NotificationController.

async function index(req, res) {
  const user = req.user;
  const notifications = await notificationRepository.forUser(user.id, 50);
  await notificationRepository.markAllRead(user.id);

  res.renderView('notifications/index', { notifications }, 'layout/base');
}

module.exports = { index };
