'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const departmentRepository = require('../repositories/departmentRepository');
const departmentTaskRepository = require('../repositories/departmentTaskRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const activeDepartmentService = require('../services/activeDepartmentService');
const orderAccessService = require('../services/orderAccessService');

// Port of App\Controllers\CoordinationController. Owner decision D-214
// (2026-10-31), phase 4: the system coordinates — "My tasks" (the
// department's queue), one owner per department per order, the management
// cockpit, and the task due days in Admin.

/** The department codes whose work this person sees (null = every department). */
async function departmentsFor(req, userId) {
  return departmentTaskRepository.scopeFor(await activeDepartmentService.current(req, userId));
}

/** Only tasks on orders this person can open. */
async function visible(req, tasks) {
  const mayOpen = await orderAccessService.orderLinkChecker(req.user.id, req.permissions);
  return tasks.filter((t) => mayOpen(t.order_id));
}

async function myTasks(req, res) {
  const mine = req.query.show === 'mine';
  let tasks = await visible(req, await departmentTaskRepository.open(await departmentsFor(req, req.user.id)));
  if (mine) {
    tasks = tasks.filter((t) => Number(t.owner_id || 0) === Number(req.user.id));
  }
  const m = await activeDepartmentService.current(req, req.user.id);
  const p = await activeDepartmentService.preview(req);
  res.renderView('coordination/my_tasks', {
    tasks,
    mine,
    acting: p ? `Preview: ${p.title} · ${p.department_name}` : (m ? activeDepartmentService.label(m) : null),
  }, 'layout/base');
}

async function cockpit(req, res) {
  const tasks = await visible(req, await departmentTaskRepository.open());
  const byDepartment = {};
  for (const t of tasks) {
    const key = String(t.department_name);
    byDepartment[key] = byDepartment[key] || { color: t.department_color, open: 0, overdue: 0, escalated: 0, unowned: 0 };
    byDepartment[key].open += 1;
    byDepartment[key].overdue += Number(t.overdue);
    byDepartment[key].escalated += Number(t.escalated);
    byDepartment[key].unowned += t.owner_id === null ? 1 : 0;
  }
  res.renderView('coordination/cockpit', {
    byDepartment,
    escalated: tasks.filter((t) => Number(t.escalated) === 1),
    supplierDelays: tasks.filter((t) => t.task_key === 'chase_supplier' && Number(t.overdue) === 1),
    ordersByStatus: await departmentTaskRepository.ordersByStatus(),
  }, 'layout/base');
}

async function taskTypes(req, res) {
  res.renderView('coordination/task_types', { types: await departmentTaskRepository.types() }, 'layout/base');
}

async function updateTaskTypes(req, res) {
  const standard = (req.body && req.body.standard_days) || {};
  const escalate = (req.body && req.body.escalate_after_days) || {};
  const changes = [];
  for (const t of await departmentTaskRepository.types()) {
    const key = String(t.task_key);
    const s = String(standard[key] ?? '').trim();
    const e = String(escalate[key] ?? '').trim();
    if (!/^\d+$/.test(s) || !/^\d+$/.test(e) || Number(s) > 90 || Number(e) > 90) {
      flash.set(req, 'error', `Enter whole days from 0 to 90 for "${t.title}".`);
      res.redirect('/admin/task-types');
      return;
    }
    if (Number(s) !== Number(t.standard_days) || Number(e) !== Number(t.escalate_after_days)) {
      changes.push([key, Number(s), Number(e), t]);
    }
  }
  for (const [key, s, e, t] of changes) {
    await departmentTaskRepository.updateType(key, s, e);
    await auditLogRepository.log(req.user.id, 'TASK_TYPE_DAYS_UPDATED', 'department_task_types', null, key,
      `${t.standard_days} days, escalate after ${t.escalate_after_days}`, `${s} days, escalate after ${e}`);
  }
  flash.set(req, 'success', changes.length === 0 ? 'Nothing changed.' : `Task days saved (${changes.length} changed).`);
  res.redirect('/admin/task-types');
}

/** A department's manager (or MD/ED, Admin, the Order Coordinator) chooses who owns the order for that department. */
async function setOwner(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const back = `/orders/${orderId}#department-owners`;
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const department = await departmentRepository.findDepartment(parseInt(req.body.department_id, 10) || 0);
  const owner = await userRepository.findById(parseInt(req.body.user_id, 10) || 0);
  const mayFor = await departmentsFor(req, req.user.id);
  const candidates = department ? (await departmentTaskRepository.candidates(Number(department.id))).map((c) => Number(c.id)) : [];
  let error = null;
  if (!department) error = 'Choose the department.';
  else if (mayFor !== null && !mayFor.includes(String(department.code))) error = `You assign owners for your own department only — not ${department.name}.`;
  else if (!owner || !candidates.includes(Number(owner.id))) error = `Choose a person who works in ${department.name}.`;
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect(back);
    return;
  }
  await departmentTaskRepository.setOwner(orderId, Number(department.id), Number(owner.id), req.user.id);
  await auditLogRepository.log(req.user.id, 'ORDER_DEPARTMENT_OWNER_SET', 'orders', orderId, department.code, null, String(owner.name));
  await notificationRepository.create(Number(owner.id), null, 'order_department_owner', orderId,
    `You now own order ${order.order_reference} for ${department.name} — its ${department.name} tasks are in My tasks.`);
  flash.set(req, 'success', `${owner.name} now owns this order for ${department.name}.`);
  res.redirect(back);
}

module.exports = { myTasks, cockpit, taskTypes, updateTaskTypes, setOwner };
