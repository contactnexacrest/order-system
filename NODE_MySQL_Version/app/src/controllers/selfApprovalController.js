'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const roleRepository = require('../repositories/roleRepository');
const selfApprovalRules = require('../services/selfApprovalRules');

/**
 * Port of SelfApprovalController.php. Owner decision 1 Nov 2026 (D-221):
 * Admin → Self-approval rules. Super Admins choose, per rule, which roles
 * may approve their own work; every change is written to the audit log
 * with the role lists before and after.
 */
async function index(req, res) {
  const current = {};
  for (const rule of Object.keys(selfApprovalRules.RULES)) {
    current[rule] = await selfApprovalRules.roleIds(rule);
  }
  res.renderView('self_approval/index', { rules: selfApprovalRules.RULES, current, roles: await roleRepository.all() }, 'layout/base');
}

async function save(req, res) {
  const valid = new Set((await roleRepository.all()).map((r) => Number(r.id)));
  let changed = 0;
  for (const rule of Object.keys(selfApprovalRules.RULES)) {
    const raw = req.body[rule] ?? req.body[`${rule}[]`] ?? [];
    const posted = [...new Set((Array.isArray(raw) ? raw : [raw]).map(Number).filter((id) => valid.has(id)))].sort((a, b) => a - b);
    const before = await selfApprovalRules.roleIds(rule);
    if (posted.length === before.length && posted.every((id, i) => id === before[i])) {
      continue;
    }
    const oldNames = (await selfApprovalRules.roleNames(rule)).join(', ') || 'nobody';
    await selfApprovalRules.set(rule, posted);
    const newNames = (await selfApprovalRules.roleNames(rule)).join(', ') || 'nobody';
    await auditLogRepository.log(req.user.id, 'SELF_APPROVAL_RULE_CHANGED', 'self_approval_rules', null, rule, oldNames, newNames);
    changed += 1;
  }
  flash.set(req, 'success', changed === 0 ? 'Nothing changed.' : 'Self-approval rules saved. They apply from the next approval.');
  res.redirect('/admin/self-approval');
}

module.exports = { index, save };
