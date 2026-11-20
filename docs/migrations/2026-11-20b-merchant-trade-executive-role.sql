-- 2026-11-20b — Owner decision D-399 (20 Nov 2026): a ready-made role for a whole Merchant Trade deal.
-- "Merchant Trade Executive": the permissions of Sales/Export Manager (selling side, buyer payments, documents)
-- plus manage_purchasing and view_purchase_prices (the supplier side), its report access and SOP chapters, and
-- SOP chapter 31 (Merchant Trade). No other role changes; no user is moved; nothing is deleted.
-- Every INSERT is guarded with WHERE NOT EXISTS (no PREPARE, owner D-371): a second run changes nothing.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT INTO roles (name, description, is_system_role)
SELECT 'Merchant Trade Executive', 'Merchant Trade division — a whole deal: the buyer and the supplier side, purchase prices, payments (D-399).', 0
 WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Merchant Trade Executive');
INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT n.id, rp.permission_id, 1 FROM roles n
  JOIN roles s ON s.name = 'Sales/Export Manager'
  JOIN role_permissions rp ON rp.role_id = s.id AND rp.is_enabled = 1
 WHERE n.name = 'Merchant Trade Executive'
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = n.id AND x.permission_id = rp.permission_id);
INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT n.id, p.id, 1 FROM roles n JOIN permissions p ON p.permission_key IN ('manage_purchasing', 'view_purchase_prices')
 WHERE n.name = 'Merchant Trade Executive'
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = n.id AND x.permission_id = p.id);
INSERT INTO role_report_access (role_id, report_key, level)
SELECT n.id, a.report_key, a.level FROM roles n
  JOIN roles s ON s.name = 'Sales/Export Manager'
  JOIN role_report_access a ON a.role_id = s.id
 WHERE n.name = 'Merchant Trade Executive'
   AND NOT EXISTS (SELECT 1 FROM role_report_access x WHERE x.role_id = n.id AND x.report_key = a.report_key);
INSERT INTO sop_role_chapters (role_id, slug)
SELECT n.id, c.slug FROM roles n
  JOIN roles s ON s.name = 'Sales/Export Manager'
  JOIN sop_role_chapters c ON c.role_id = s.id
 WHERE n.name = 'Merchant Trade Executive'
   AND NOT EXISTS (SELECT 1 FROM sop_role_chapters x WHERE x.role_id = n.id AND x.slug = c.slug);
INSERT INTO sop_role_chapters (role_id, slug)
SELECT n.id, '31-merchant-trade' FROM roles n
 WHERE n.name = 'Merchant Trade Executive'
   AND NOT EXISTS (SELECT 1 FROM sop_role_chapters x WHERE x.role_id = n.id AND x.slug = '31-merchant-trade');
