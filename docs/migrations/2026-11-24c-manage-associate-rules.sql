-- 2026-11-24c — Owner decision D-411 (24 Nov 2026): the new permission "Manage associate rules".
-- Until now only the Managing Director could change the associate commission rules. From now on the MD, the
-- Executive Director and anyone given this permission (Admin › Roles, or one person) can: commission levels and the
-- ladder, what the client's price includes, an associate's own rule, Associates › Settings, payment advice
-- corrections (Rev), a client's associate and the portal access levels. A reason is still needed and kept.
-- Plain INSERT … WHERE NOT EXISTS (owner D-371): safe to run twice, no PREPARE, no information_schema.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'manage_associate_rules', 'Manage associate rules', 'Change the associate commission rules: levels and ladder, what the client''s price includes, an associate''s own rule, Associates › Settings, payment advice corrections, a client''s associate, portal access levels (reason kept)', 'Associates'
WHERE NOT EXISTS (SELECT 1 FROM permissions x WHERE x.permission_key = 'manage_associate_rules');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_associate_rules'
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
-- The "See associate commission rules" description named the MD only; it now names the new permission.
UPDATE permissions SET description = 'See an associate''s commission rule (standard ladder, own ladder or fixed), the level and % of their next order, how many orders have counted and the ladder steps. Without it staff see "Set by the MD / ED". Changing it needs Manage associate rules.'
 WHERE permission_key = 'view_associate_commission_rules';
