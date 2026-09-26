'use strict';

const caRepository = require('../repositories/caRepository');
const userRepository = require('../repositories/userRepository');

/**
 * CA / Accounting module (Phase 1) — independent of the order-pipeline
 * system. The route is gated on ca_module_view (see server.js), never on
 * manage_orders, so a CA/Accounts-only user can reach this without any
 * order-management access.
 */
async function index(req, res) {
  const usersById = {};
  for (const u of await userRepository.listActive()) {
    usersById[u.id] = u.name;
  }

  res.renderView('ca/index', {
    settlements: await caRepository.settlementRegister(),
    usersById,
  }, 'layout/base');
}

module.exports = { index };
