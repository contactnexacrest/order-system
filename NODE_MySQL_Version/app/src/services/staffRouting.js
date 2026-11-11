'use strict';

const db = require('../config/db');
const permissionRepository = require('../repositories/permissionRepository');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * Port of App\Services\StaffRouting — owner decision D-281 (11 Nov 2026, point 3):
 * who is e-mailed about a staff event.
 *
 * Order events: each event type belongs to a department (buyer → Sales & Export,
 * supplier → Procurement, payments → Accounts & Finance, shipping / BL /
 * inspection → Logistics, eBRC / ECGC / benefits → Export Compliance). The
 * e-mail goes only to that department's people on that order — its department
 * owner and the order's assignees who are members of it. With nobody from the
 * department on the order: the department's manager (director when there is no
 * manager), with an "assign someone" note; with no manager either: the MD.
 * The Managing Director, Executive Director and Order Coordinator are not
 * e-mailed for these routine events (they keep the in-app notice).
 *
 * Company-level compliance alerts: the responsible person and the backup named
 * in Admin › E-mail settings; fallback the department's manager, then the MD.
 */

const DEPARTMENT_FOR_TYPE = {
  buyer_oc_acknowledged: 'SALES', buyer_po_uploaded_by_client: 'SALES', order_comment_from_client: 'SALES',
  dispute_raised: 'SALES', order_feedback_submitted: 'SALES', reorder_request_submitted: 'SALES',
  pi_intake_submitted: 'SALES', dispute_overdue: 'SALES', quotation_expired: 'SALES', pi_expired: 'SALES',
  complaint_raised: 'SALES',
  supplier_po_ack_uploaded_by_supplier: 'PROC',
  client_payment_reported: 'FIN', fdn_overdue: 'FIN', balance_overdue: 'FIN',
  lc_expiry_soon: 'FIN', lc_last_shipment_soon: 'FIN', lc_presentation_soon: 'FIN',
  inspection_failed: 'LOG', pre_shipment_inspection_reminder: 'LOG',
  ecgc_declaration_due: 'EXPORT_COMPLIANCE', ecgc_declaration_overdue: 'EXPORT_COMPLIANCE',
  benefit_claim_deadline: 'EXPORT_COMPLIANCE',
};

const ROUTINE_IN_APP_ONLY_ROLES = ['Managing Director', 'Executive Director', 'Order Coordinator'];

const COMPANY_ITEMS = {
  lut: ['LUT (Letter of Undertaking) renewal', 'EXPORT_COMPLIANCE'],
  rcmc: ['RCMC certificate renewal', 'EXPORT_COMPLIANCE'],
  agreements: ['Agreements and registrations ending (IEC, client / associate / supplier agreements)', 'EXPORT_COMPLIANCE'],
  tds: ['TDS on associate commission — deposit by the 7th, Form 26Q each quarter', 'FIN'],
  merchant_trade_rbi: ['Merchant trade — RBI time limits', 'FIN'],
  ecgc_policy: ['ECGC policy renewal', 'FIN'],
  ebrc_monthly: ['Missing eBRC / FIRC — monthly list', 'FIN'],
};

function departmentFor(type) {
  return DEPARTMENT_FOR_TYPE[type] || 'SALES';
}

async function departmentName(code) {
  const row = await db.queryOne('SELECT name FROM departments WHERE code = :c', { c: code });
  return row ? String(row.name) : code;
}

/** Whether a person holds (by own role or a current membership) the MD, ED or Order Coordinator role. */
async function isRoutineInAppOnly(userId) {
  const row = await db.queryOne(
    `SELECT 1 AS x FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :u1 AND r.name IN ('Managing Director', 'Executive Director', 'Order Coordinator')
     UNION ALL
     SELECT 1 AS x FROM user_department_memberships m JOIN department_designations dd ON dd.id = m.designation_id JOIN roles r ON r.id = dd.role_id
      WHERE m.user_id = :u2 AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
        AND r.name IN ('Managing Director', 'Executive Director', 'Order Coordinator')
     LIMIT 1`,
    { u1: userId, u2: userId }
  );
  return !!row;
}

async function withoutInAppOnly(ids) {
  const out = [];
  for (const id of [...new Set(ids.map(Number))]) {
    if (!(await isRoutineInAppOnly(id))) out.push(id);
  }
  return out;
}

/** @returns {Promise<number[]>} the department's owner on the order and the order's assignees who are members of it (active users) */
async function departmentPeopleOnOrder(orderId, deptCode) {
  const rows = await db.query(
    `SELECT odo.user_id FROM order_department_owners odo JOIN departments d ON d.id = odo.department_id JOIN users u ON u.id = odo.user_id
      WHERE odo.order_id = :o1 AND d.code = :c1 AND u.is_active = 1
     UNION
     SELECT oa.user_id FROM order_assignees oa
       JOIN users u ON u.id = oa.user_id
       JOIN user_department_memberships m ON m.user_id = oa.user_id
       JOIN department_designations dd ON dd.id = m.designation_id
       JOIN departments d ON d.id = dd.department_id
      WHERE oa.order_id = :o2 AND d.code = :c2 AND u.is_active = 1
        AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())`,
    { o1: orderId, c1: deptCode, o2: orderId, c2: deptCode }
  );
  return [...new Set(rows.map((r) => Number(r.user_id)))];
}

/** @returns {Promise<number[]>} the department's managers today; its directors when it has no manager */
async function managers(deptCode) {
  for (const level of ['manager', 'director']) {
    const rows = await db.query(
      `SELECT DISTINCT m.user_id FROM user_department_memberships m
         JOIN department_designations dd ON dd.id = m.designation_id
         JOIN departments d ON d.id = dd.department_id
         JOIN users u ON u.id = m.user_id
        WHERE d.code = :c AND dd.level = :l AND dd.is_active = 1 AND u.is_active = 1
          AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
        ORDER BY m.user_id`,
      { c: deptCode, l: level }
    );
    const ids = await withoutInAppOnly(rows.map((r) => r.user_id));
    if (ids.length) return ids;
  }
  return [];
}

/** @returns {Promise<number[]>} the active Managing Director(s), by own role or membership */
async function managingDirectors() {
  const rows = await db.query(
    `SELECT u.id AS id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Managing Director' AND u.is_active = 1
     UNION
     SELECT m.user_id AS id FROM user_department_memberships m JOIN department_designations dd ON dd.id = m.designation_id
       JOIN roles r ON r.id = dd.role_id JOIN users u ON u.id = m.user_id
      WHERE r.name = 'Managing Director' AND u.is_active = 1 AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())`
  );
  return [...new Set(rows.map((r) => Number(r.id)))];
}

/**
 * Who is e-mailed about an order event of this department.
 * @returns {Promise<{email: number[], level: string, note: string|null}>}
 */
async function forOrder(orderId, deptCode) {
  const onOrder = await withoutInAppOnly(await departmentPeopleOnOrder(orderId, deptCode));
  if (onOrder.length) return { email: onOrder, level: 'order', note: null };
  const note = `Nobody from ${await departmentName(deptCode)} is assigned to this order — please assign someone.`;
  const m = await managers(deptCode);
  if (m.length) return { email: m, level: 'manager', note };
  return { email: await managingDirectors(), level: 'md', note };
}

/**
 * Associate events: the relationship manager; without one, the people who manage
 * associates (not MD / ED / Coordinator); else the MD. Bank changes also go to
 * Accounts & Finance's verifiers.
 */
async function forAssociate(associate, bankChange = false) {
  let ids = [];
  if (associate && associate.relationship_manager_id) {
    ids.push(Number(associate.relationship_manager_id));
  } else {
    ids = await withoutInAppOnly(await permissionRepository.usersWithPermission('manage_associates'));
  }
  if (bankChange) {
    ids = ids.concat((await permissionRepository.usersWithPermission('manage_associate_bank')).map(Number));
  }
  ids = [...new Set(ids.map(Number))];
  return ids.length ? ids : managingDirectors();
}

/** @returns {Promise<object>} item => {responsible_user_id, backup_user_id} */
async function companyOwners() {
  const out = {};
  for (const k of Object.keys(COMPANY_ITEMS)) out[k] = { responsible_user_id: null, backup_user_id: null };
  try {
    for (const r of await db.query('SELECT item_key, responsible_user_id, backup_user_id FROM compliance_alert_owners')) {
      if (out[r.item_key]) {
        out[r.item_key] = {
          responsible_user_id: r.responsible_user_id !== null ? Number(r.responsible_user_id) : null,
          backup_user_id: r.backup_user_id !== null ? Number(r.backup_user_id) : null,
        };
      }
    }
  } catch (e) {
    // A database from before D-281: everyone falls back to the department manager.
  }
  return out;
}

/** @returns {Promise<number[]>} who is e-mailed about a company-level item */
async function companyItem(itemKey) {
  const owners = (await companyOwners())[itemKey] || { responsible_user_id: null, backup_user_id: null };
  const ids = [];
  for (const id of [owners.responsible_user_id, owners.backup_user_id]) {
    if (id !== null) {
      const u = await db.queryOne('SELECT is_active FROM users WHERE id = :id', { id });
      if (u && Number(u.is_active) === 1) ids.push(id);
    }
  }
  if (ids.length) return [...new Set(ids)];
  const m = await managers((COMPANY_ITEMS[itemKey] || [null, 'FIN'])[1]);
  return m.length ? m : managingDirectors();
}

/** @returns {Promise<string[]>} what changed */
async function saveCompanyOwners(input, userId, reason) {
  const current = await companyOwners();
  const changes = [];
  for (const [key, [label]] of Object.entries(COMPANY_ITEMS)) {
    const r = Number((input && input[key] && input[key].responsible) || 0) || null;
    let b = Number((input && input[key] && input[key].backup) || 0) || null;
    if (r !== null && r === b) b = null; // the backup is a different person
    if (r === current[key].responsible_user_id && b === current[key].backup_user_id) continue;
    await db.execute(
      `INSERT INTO compliance_alert_owners (item_key, responsible_user_id, backup_user_id, updated_by) VALUES (:k, :r, :b, :u)
       ON DUPLICATE KEY UPDATE responsible_user_id = VALUES(responsible_user_id), backup_user_id = VALUES(backup_user_id), updated_by = VALUES(updated_by)`,
      { k: key, r, b, u: userId }
    );
    await auditLogRepository.log(userId, 'COMPLIANCE_ALERT_OWNER_CHANGED', 'compliance_alert_owners', null, key,
      `${current[key].responsible_user_id ?? ''}/${current[key].backup_user_id ?? ''}`, `${r ?? ''}/${b ?? ''}`, reason);
    changes.push(label);
  }
  return changes;
}

module.exports = {
  DEPARTMENT_FOR_TYPE, ROUTINE_IN_APP_ONLY_ROLES, COMPANY_ITEMS,
  departmentFor, departmentName, isRoutineInAppOnly, departmentPeopleOnOrder, managers, managingDirectors,
  forOrder, forAssociate, companyOwners, companyItem, saveCompanyOwners,
};
