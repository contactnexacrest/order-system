'use strict';

const sessionTimeouts = require('../services/sessionTimeouts');

/**
 * Port of App\Controllers\SessionController — owner decision D-283 (11 Nov 2026):
 * "Stay logged in" in the 2-minute warning popup (app.js) posts here. The area's
 * own auth middleware has already checked the session and reset its idle clock;
 * this only answers with the new times.
 */
async function answer(req, res, area) {
  res.set('Cache-Control', 'no-store');
  res.json({
    ok: true,
    idle_seconds: req.idleMeta ? req.idleMeta.idle : (await sessionTimeouts.idleMinutes(area)) * 60,
    absolute_left: req.idleMeta ? req.idleMeta.absoluteLeft : null,
  });
}

async function staffKeepAlive(req, res) {
  await answer(req, res, 'staff');
}

async function clientKeepAlive(req, res) {
  await answer(req, res, 'client');
}

async function associateKeepAlive(req, res) {
  await answer(req, res, 'associate');
}

module.exports = { staffKeepAlive, clientKeepAlive, associateKeepAlive };
