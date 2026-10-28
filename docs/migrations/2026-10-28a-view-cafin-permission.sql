-- Owner decision (2026-10-28): who may SEE and DOWNLOAD the internal CA
-- Financial Annexure (CAFIN) is its own permission, editable per role and
-- per person (Admin -> Roles & Permissions) like every other permission.
-- Holders see the CAFIN box on the order page, CAFIN in the order's
-- document lists, and can download it; everyone else sees none of it and
-- every download path refuses them. Generating CAFIN stays with
-- ca_internal_doc_manage. Default holders: Admin, MD, ED, Accounts
-- Executive, CA / Chartered Accountant. Safe to run more than once.
SET NAMES utf8mb4;

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('view_cafin', 'View & download CAFIN', 'See and download the internal CA Financial Annexure (CAFIN): the CAFIN box on the order page and CAFIN in document lists. Never reaches the client. Grant or remove per role or per person (e.g. a CA during an audit).', 'ca');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_cafin'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Accounts Executive', 'CA / Chartered Accountant');
