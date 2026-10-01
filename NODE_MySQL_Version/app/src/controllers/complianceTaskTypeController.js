'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const complianceTaskTypeRepository = require('../repositories/complianceTaskTypeRepository');

/**
 * Port of App\Controllers\ComplianceTaskTypeController (docs/schema.sql
 * Section AR). Gated on manage_compliance_task_types, same tier as
 * manage_hs_codes/manage_logistics_partners — Admin/MD/ED and Super Admin
 * only by default. Does not itself grant access to the checklist on an
 * order's own page — that uses the existing close_orders permission.
 */

async function index(req, res) {
  res.renderView('compliance_task_types/index', {
    taskTypes: await complianceTaskTypeRepository.all(true),
  }, 'layout/base');
}

async function createForm(req, res) {
  res.renderView('compliance_task_types/create', {}, 'layout/base');
}

async function create(req, res) {
  const name = String(req.body.name || '').trim();
  if (name === '') {
    flash.set(req, 'error', 'Task name is required.');
    res.redirect('/compliance-task-types/create');
    return;
  }

  const id = await complianceTaskTypeRepository.create(name, req.user.id);
  await auditLogRepository.log(req.user.id, 'COMPLIANCE_TASK_TYPE_ADDED', 'compliance_task_types', id, null, null, name);
  flash.set(req, 'success', `"${name}" added to the compliance checklist — it now appears on every order's checklist.`);
  res.redirect('/compliance-task-types');
}

async function editForm(req, res) {
  const id = parseInt(req.params.id, 10);
  const taskType = await complianceTaskTypeRepository.find(id);
  if (!taskType) {
    res.status(404).send('Compliance task type not found.');
    return;
  }
  res.renderView('compliance_task_types/edit', { taskType }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10);
  const taskType = await complianceTaskTypeRepository.find(id);
  if (!taskType) {
    res.status(404).send('Compliance task type not found.');
    return;
  }

  const name = String(req.body.name || '').trim();
  if (name === '') {
    flash.set(req, 'error', 'Task name is required.');
    res.redirect(`/compliance-task-types/${id}/edit`);
    return;
  }

  await complianceTaskTypeRepository.update(id, name);
  await auditLogRepository.log(req.user.id, 'COMPLIANCE_TASK_TYPE_UPDATED', 'compliance_task_types', id, 'name', taskType.name, name);
  flash.set(req, 'success', `"${name}" updated.`);
  res.redirect('/compliance-task-types');
}

async function toggleActive(req, res) {
  const id = parseInt(req.params.id, 10);
  const taskType = await complianceTaskTypeRepository.find(id);
  if (!taskType) {
    flash.set(req, 'error', 'Compliance task type not found.');
    res.redirect('/compliance-task-types');
    return;
  }

  await complianceTaskTypeRepository.toggleActive(id);
  const nowActive = !taskType.is_active;
  await auditLogRepository.log(
    req.user.id,
    nowActive ? 'COMPLIANCE_TASK_TYPE_REACTIVATED' : 'COMPLIANCE_TASK_TYPE_DEACTIVATED',
    'compliance_task_types', id, 'is_active', String(Number(!!taskType.is_active)), String(Number(nowActive))
  );
  flash.set(req, 'success', `"${taskType.name}" ${nowActive ? 'reactivated' : 'deactivated'}.`);
  res.redirect('/compliance-task-types');
}

async function remove(req, res) {
  const id = parseInt(req.params.id, 10);
  const taskType = await complianceTaskTypeRepository.find(id);
  if (!taskType) {
    flash.set(req, 'error', 'Compliance task type not found.');
    res.redirect('/compliance-task-types');
    return;
  }

  try {
    await complianceTaskTypeRepository.remove(id);
  } catch (err) {
    if (err && err.code === 'ER_ROW_IS_REFERENCED_2') {
      flash.set(req, 'error', `"${taskType.name}" is already recorded against at least one order and cannot be deleted — deactivate it instead.`);
      res.redirect('/compliance-task-types');
      return;
    }
    throw err;
  }
  await auditLogRepository.log(req.user.id, 'COMPLIANCE_TASK_TYPE_DELETED', 'compliance_task_types', id, 'name', taskType.name, null);
  flash.set(req, 'success', `"${taskType.name}" removed from the task-type list.`);
  res.redirect('/compliance-task-types');
}

module.exports = { index, createForm, create, editForm, update, toggleActive, remove };
