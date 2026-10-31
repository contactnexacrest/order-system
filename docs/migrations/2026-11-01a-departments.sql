-- Owner decision D-214 (2026-10-31), phase 1: departments → designations → roles → permissions.
-- A person is added to one or more departments with a designation; the designation's role gives the
-- permissions. A person in more than one department picks one at "Log in as". Users with no
-- department membership keep working exactly as before with their own role (users.role_id).
--
-- New tables only, plus two guarded columns (audit_log.acting_as, user_permissions.expires_at) and
-- new roles with default permissions. Existing users of the Export / Accounts / Logistics Executive,
-- Viewer, CA, Managing Director and Executive Director roles get the matching membership. Admin-role
-- accounts get none and keep their full Admin role until a System Administrator membership is given.
-- Safe to run more than once.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20) NOT NULL,
  name                VARCHAR(100) NOT NULL,
  color               CHAR(7) NOT NULL DEFAULT '#3a4140',
  sees_whole_company  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order          INT NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_departments_code (code),
  UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS department_designations (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id   BIGINT UNSIGNED NOT NULL,
  title           VARCHAR(100) NOT NULL,
  level           ENUM('executive','manager','director','other') NOT NULL DEFAULT 'executive',
  role_id         BIGINT UNSIGNED NOT NULL,
  approval_limit_inr DECIMAL(14,2) NULL,
  sort_order      INT NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_designation_title (department_id, title),
  FOREIGN KEY (department_id) REFERENCES departments(id),
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_department_memberships (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  designation_id  BIGINT UNSIGNED NOT NULL,
  starts_on       DATE NOT NULL,
  ends_on         DATE NULL,
  is_default      TINYINT(1) NOT NULL DEFAULT 0,
  created_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_by        BIGINT UNSIGNED NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (designation_id) REFERENCES department_designations(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (ended_by) REFERENCES users(id),
  INDEX idx_memberships_user (user_id)
) ENGINE=InnoDB;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'acting_as') = 0, 'ALTER TABLE audit_log ADD COLUMN acting_as VARCHAR(160) NULL AFTER user_id', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_permissions' AND COLUMN_NAME = 'expires_at') = 0, 'ALTER TABLE user_permissions ADD COLUMN expires_at DATE NULL AFTER reason', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

-- Departments
INSERT IGNORE INTO departments (code, name, color, sees_whole_company, sort_order, is_active) VALUES
  ('MGMT',  'Company Management',  '#3a4140', 1, 10, 1),
  ('SALES', 'Sales & Export',      '#2f6fb3', 0, 20, 1),
  ('PROC',  'Procurement',         '#a3591b', 0, 30, 1),
  ('LOG',   'Logistics',           '#6b4fa8', 0, 40, 1),
  ('FIN',   'Accounts & Finance',  '#2c7a4b', 1, 50, 1),
  ('AUDIT', 'Audit & Compliance',  '#6a736f', 1, 60, 1),
  ('ADMIN', 'Administration',      '#6a736f', 0, 70, 1),
  ('OPS',   'Order Desk',          '#9aa39f', 0, 80, 0);

-- New roles (one per new designation). Existing roles are reused for the designations that match them.
INSERT IGNORE INTO roles (name, description, is_system_role) VALUES
  ('Sales/Export Manager',  'Sales & Export department — Manager', 0),
  ('Sales Director',        'Sales & Export department — Director', 0),
  ('Procurement Executive', 'Procurement department — Executive', 0),
  ('Procurement Manager',   'Procurement department — Manager', 0),
  ('Procurement Director',  'Procurement department — Director', 0),
  ('Logistics Manager',     'Logistics department — Manager', 0),
  ('Logistics Director',    'Logistics department — Director', 0),
  ('Finance Manager',       'Accounts & Finance department — Manager', 0),
  ('Finance Director',      'Accounts & Finance department — Director', 0),
  ('System Administrator',  'Administration — users, settings and templates; no business money', 0),
  ('Order Coordinator',     'Order Desk — status of every order, no prices', 0),
  ('Operations Manager',    'Order Desk — Manager', 0);

-- Managers start from their Executive's permissions; Directors from their Manager's.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT n.id, rp.permission_id, rp.is_enabled FROM role_permissions rp JOIN roles o ON o.id = rp.role_id JOIN roles n
 WHERE (o.name = 'Export Executive' AND n.name IN ('Sales/Export Manager', 'Sales Director'))
    OR (o.name = 'Logistics Executive' AND n.name IN ('Logistics Manager', 'Logistics Director'))
    OR (o.name = 'Accounts Executive' AND n.name IN ('Finance Manager', 'Finance Director'));

-- Additional permissions per new role (keys that do not exist are simply skipped).
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r JOIN permissions p ON (
     (r.name IN ('Sales/Export Manager', 'Sales Director') AND p.permission_key IN ('approve_documents', 'approve_payment_terms', 'view_all_orders', 'assign_order_owner', 'manage_disputes', 'manage_complaints'))
  OR (r.name = 'Sales Director' AND p.permission_key IN ('approve_email_send', 'view_staff_reports'))
  OR (r.name IN ('Procurement Executive', 'Procurement Manager', 'Procurement Director') AND p.permission_key IN ('view_orders', 'view_product_catalog', 'browse_product_catalog', 'download_pdf', 'view_own_incentives'))
  OR (r.name IN ('Procurement Manager', 'Procurement Director') AND p.permission_key IN ('manage_suppliers', 'view_all_orders', 'assign_order_owner'))
  OR (r.name IN ('Logistics Manager', 'Logistics Director') AND p.permission_key IN ('view_all_orders', 'assign_order_owner', 'manage_logistics_partners'))
  OR (r.name IN ('Finance Manager', 'Finance Director') AND p.permission_key IN ('manage_order_financials', 'inr_actual_delete', 'view_cafin', 'ca_internal_doc_manage', 'pay_incentives', 'view_all_orders', 'assign_order_owner'))
  OR (r.name = 'Finance Director' AND p.permission_key IN ('view_reports', 'view_staff_reports', 'ca_fy_lock_override', 'approve_payment_terms'))
  OR (r.name = 'System Administrator' AND p.permission_key IN ('manage_users', 'manage_permissions', 'manage_company_settings', 'manage_assets', 'delete_assets', 'manage_email_templates', 'manage_dropdown_options', 'manage_ports', 'manage_hs_codes', 'manage_payment_presets', 'manage_compliance_task_types', 'manage_signatories', 'view_audit_log', 'manage_field_protection', 'data_export_run', 'manage_sample_data', 'manage_report_definitions'))
  OR (r.name IN ('Order Coordinator', 'Operations Manager') AND p.permission_key IN ('view_orders', 'view_all_orders'))
  OR (r.name = 'Operations Manager' AND p.permission_key IN ('assign_order_owner'))
);

-- Designations: each department's ladder, each tied to its own role.
INSERT IGNORE INTO department_designations (department_id, title, level, role_id, sort_order)
SELECT d.id, x.title, x.level, r.id, x.sort_order FROM (
  SELECT 'MGMT' AS dept, 'Managing Director' AS title, 'director' AS level, 'Managing Director' AS role, 1 AS sort_order
  UNION ALL SELECT 'MGMT', 'Executive Director', 'director', 'Executive Director', 2
  UNION ALL SELECT 'SALES', 'Sales/Export Executive', 'executive', 'Export Executive', 1
  UNION ALL SELECT 'SALES', 'Sales/Export Manager', 'manager', 'Sales/Export Manager', 2
  UNION ALL SELECT 'SALES', 'Sales Director', 'director', 'Sales Director', 3
  UNION ALL SELECT 'PROC', 'Procurement Executive', 'executive', 'Procurement Executive', 1
  UNION ALL SELECT 'PROC', 'Procurement Manager', 'manager', 'Procurement Manager', 2
  UNION ALL SELECT 'PROC', 'Procurement Director', 'director', 'Procurement Director', 3
  UNION ALL SELECT 'LOG', 'Logistics Executive', 'executive', 'Logistics Executive', 1
  UNION ALL SELECT 'LOG', 'Logistics Manager', 'manager', 'Logistics Manager', 2
  UNION ALL SELECT 'LOG', 'Logistics Director', 'director', 'Logistics Director', 3
  UNION ALL SELECT 'FIN', 'Accounts Executive', 'executive', 'Accounts Executive', 1
  UNION ALL SELECT 'FIN', 'Finance Manager', 'manager', 'Finance Manager', 2
  UNION ALL SELECT 'FIN', 'Finance Director', 'director', 'Finance Director', 3
  UNION ALL SELECT 'AUDIT', 'Internal Auditor', 'other', 'Viewer / Auditor', 1
  UNION ALL SELECT 'AUDIT', 'External CA', 'other', 'CA / Chartered Accountant', 2
  UNION ALL SELECT 'ADMIN', 'System Administrator', 'other', 'System Administrator', 1
  UNION ALL SELECT 'OPS', 'Order Coordinator', 'executive', 'Order Coordinator', 1
  UNION ALL SELECT 'OPS', 'Operations Manager', 'manager', 'Operations Manager', 2
) x JOIN departments d ON d.code = x.dept JOIN roles r ON r.name = x.role;

-- Existing people: one membership matching the role they hold today (not for Admin-role accounts).
INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
SELECT u.id, dd.id, CURDATE(), 1
  FROM users u
  JOIN roles r ON r.id = u.role_id
  JOIN department_designations dd ON dd.role_id = r.id
  JOIN departments d ON d.id = dd.department_id
 WHERE r.name IN ('Managing Director', 'Executive Director', 'Export Executive', 'Accounts Executive', 'Logistics Executive', 'Viewer / Auditor', 'CA / Chartered Accountant')
   AND NOT EXISTS (SELECT 1 FROM user_department_memberships m WHERE m.user_id = u.id);
