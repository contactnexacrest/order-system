-- ================================================================
-- DB MIGRATION — 2026-10-14
-- Corresponds to: schema.sql Section BH (90-item backlog #59)
-- Covers item: 59 (Port of Discharge/Port of Loading full CRUD)
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Already applied to both local dev databases (nexacrest,
-- nexacrest_node_test) during this session. NOT yet applied anywhere
-- else.
--
-- External QA report L-32: safe to re-run (idempotent) — both
-- INSERTs below use INSERT IGNORE against a unique key, so running
-- this again after it already applied is a clean no-op.
--
-- No ALTER TABLE here — the `ports` table itself already existed with
-- everything this feature needs (name, country, port_role, is_default,
-- is_active, sort_order). The defect was purely the missing admin CRUD
-- screen (no PortController/PortRepository existed, so staff had no way
-- to add a second port beyond the single seeded Chennai/loading row).
-- ================================================================


SET NAMES utf8mb4;
INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_ports', 'Manage ports', 'Add, edit, and deactivate the Port of Loading / Port of Discharge options offered on the New Order form. Same tier as manage_hs_codes/manage_logistics_partners: Admin/MD/ED and Super Admin only.', 'catalog');

-- Grants the new permission to the same roles that already hold every
-- other permission at this tier (Admin/Managing Director/Executive
-- Director). Super Admin bypasses permission checks entirely and needs
-- no row here. On a FRESH install, seed.sql's own blanket grant does
-- this automatically — this INSERT is only needed because an existing
-- database already ran that blanket grant once, against the
-- permissions table as it stood before this permission existed.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director')
  AND p.permission_key = 'manage_ports';
