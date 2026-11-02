-- Owner decision D-226 (2 Nov 2026, "3 yes"): an Export Compliance
-- department for the 1–3 people who handle government and agency work
-- (ECGC cover, Certificate of Origin, phytosanitary / fumigation /
-- inspection certificates, export benefit claims, eBRC / FIRC follow-up).
-- Same pattern as Procurement / Logistics / Finance: one department, an
-- Executive → Manager → Director ladder, each designation with its own role.
--
-- What they get: the order's compliance checklist, the COO Prep Sheet
-- (generate), export benefit eligibility and claim filing, uploading
-- government / agency documents to the order, their own work queue.
-- What they do not get: purchase prices, logistics costs, buyer payment
-- amounts, profit, incentives, CAFIN. They do see selling (invoice)
-- values — the government forms need them (owner accepted).
--
-- Three narrow permissions are added so nobody else changes:
--   manage_compliance_checklist  — the order's compliance checklist; given
--                                  to every role that had it through
--                                  close_orders, so nothing changes for them;
--   manage_export_benefit_claims — answer scheme eligibility and file a claim
--                                  (recording the money received and
--                                  correcting / deleting stay with
--                                  "Manage order financials");
--   generate_compliance_documents — generate the COO Prep Sheet only;
--   upload_order_documents       — attach government / agency documents to
--                                  an order (Step 14), without "Manage orders".
-- New rows only; safe to run twice (INSERT IGNORE). MySQL and MariaDB.

SET NAMES utf8mb4;

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_compliance_checklist', 'Compliance checklist', 'Work the order''s compliance checklist (ECGC cover, inspection, fumigation, phytosanitary, due diligence …). D-226: Export Compliance, and everyone who could already do it through Close orders.', 'orders'),
  ('manage_export_benefit_claims', 'File export benefit claims', 'Answer which export benefit schemes an order is eligible for and file the claims (scheme, reference no., amount claimed, date). Recording the money received stays with Manage order financials. D-226: Export Compliance.', 'orders'),
  ('generate_compliance_documents', 'Generate compliance documents', 'Generate the COO Prep Sheet (for the Certificate of Origin application) without the general Generate documents permission. D-226: Export Compliance.', 'documents'),
  ('upload_order_documents', 'Attach documents to an order', 'Attach government and agency documents (ECGC policy, Certificate of Origin, phytosanitary / fumigation / inspection certificates, eBRC …) to an order in Step 14, without Manage orders. D-226: Export Compliance.', 'orders');

-- Nothing changes for the roles that worked the checklist through Close orders.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT rp.role_id, pn.id, rp.is_enabled
  FROM role_permissions rp
  JOIN permissions po ON po.id = rp.permission_id AND po.permission_key = 'close_orders'
  JOIN permissions pn ON pn.permission_key = 'manage_compliance_checklist';

-- Management holds every permission.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('manage_compliance_checklist', 'manage_export_benefit_claims', 'generate_compliance_documents', 'upload_order_documents')
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

-- Finance already records benefits through Manage order financials; filing a claim follows.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_export_benefit_claims'
  AND r.name IN ('Finance Manager', 'Finance Director');

-- The department and its ladder.
INSERT IGNORE INTO departments (code, name, color, sees_whole_company, sort_order, is_active) VALUES
  ('EXPORT_COMPLIANCE', 'Export Compliance', '#1d7f8c', 0, 45, 1);

INSERT IGNORE INTO roles (name, description, is_system_role) VALUES
  ('Export Compliance Executive', 'Export Compliance department — Executive', 0),
  ('Export Compliance Manager',   'Export Compliance department — Manager', 0),
  ('Export Compliance Director',  'Export Compliance department — Director', 0);

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r JOIN permissions p ON (
     (r.name IN ('Export Compliance Executive', 'Export Compliance Manager', 'Export Compliance Director')
      AND p.permission_key IN ('view_orders', 'download_pdf', 'view_selling_prices', 'view_own_incentives',
                               'manage_compliance_checklist', 'manage_export_benefit_claims',
                               'generate_compliance_documents', 'upload_order_documents'))
  OR (r.name IN ('Export Compliance Manager', 'Export Compliance Director')
      AND p.permission_key IN ('view_all_orders', 'assign_order_owner', 'assign_department_owner', 'approve_documents',
                               'manage_compliance_task_types'))
  OR (r.name = 'Export Compliance Director' AND p.permission_key IN ('view_cockpit'))
);

INSERT IGNORE INTO department_designations (department_id, title, level, role_id, sort_order)
SELECT d.id, x.title, x.level, r.id, x.sort_order FROM (
  SELECT 'Export Compliance Executive' AS title, 'executive' AS level, 1 AS sort_order
  UNION ALL SELECT 'Export Compliance Manager', 'manager', 2
  UNION ALL SELECT 'Export Compliance Director', 'director', 3
) x JOIN departments d ON d.code = 'EXPORT_COMPLIANCE' JOIN roles r ON r.name = x.title;

-- Their work queue (built from the order's own state, like the other departments').
INSERT IGNORE INTO department_task_types (task_key, department_code, title, standard_days, escalate_after_days, sort_order) VALUES
  ('compliance_checklist_item', 'EXPORT_COMPLIANCE', 'Compliance checklist item (ECGC, inspection, fumigation, phytosanitary …)', 5, 3, 90),
  ('apply_coo', 'EXPORT_COMPLIANCE', 'Apply for the Certificate of Origin (COO Prep Sheet)', 2, 2, 91),
  ('answer_benefit_eligibility', 'EXPORT_COMPLIANCE', 'Answer export benefit eligibility (BL issued)', 3, 3, 92),
  ('file_export_benefit_claim', 'EXPORT_COMPLIANCE', 'File the export benefit claim', 7, 7, 93),
  ('ebrc_followup', 'EXPORT_COMPLIANCE', 'Get the eBRC / FIRC from the bank (payment cleared)', 7, 7, 94);
