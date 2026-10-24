-- ================================================================
-- DB MIGRATION — 2026-10-24b
-- Order ownership / assignment visibility: adds orders.assigned_to, the
-- single staff "owner" of each order. This is a VISIBILITY-ONLY concept
-- — who sees an order in the Orders list, the Kanban board, and its
-- detail page — and is never consulted by the existing stage-gate or
-- action-permission system (manage_payments/clear_payments/
-- manage_shipping/etc.), which is completely unchanged by this
-- migration. New orders are auto-assigned to their creator
-- (OrderRepository::insertRow()); only a holder of the new
-- assign_order_owner permission (Admin/Managing Director/Executive
-- Director) can change it afterward. Two new permissions are added:
-- view_all_orders (oversight roles: Admin/MD/ED, Accounts Executive,
-- Viewer / Auditor) and assign_order_owner (Admin/MD/ED only). Admin/
-- MD/ED are granted both explicitly below rather than relying on
-- docs/seed.sql's own ROLE_PERMISSIONS cross-join — that cross-join
-- only runs once, at initial seed time, against whatever permissions
-- exist at that moment; it does not retroactively cover a permission
-- added later by a migration against an already-seeded database.
--
-- Run this ONCE against each production database this app uses (the PHP
-- stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run): ADD COLUMN/ADD CONSTRAINT/CREATE INDEX
-- are guarded by information_schema checks, the backfill UPDATE only
-- ever touches rows still NULL, and the permission/role_permissions
-- INSERTs are guarded by NOT EXISTS.
-- ================================================================

SET NAMES utf8mb4;

-- 1. ADD COLUMN assigned_to
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'assigned_to'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE orders ADD COLUMN assigned_to BIGINT UNSIGNED NULL AFTER created_by',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. ADD the FK constraint
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_assigned_to'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE orders ADD CONSTRAINT fk_orders_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Index, for the WHERE assigned_to = :user_id scoping filter
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_assigned_to'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_orders_assigned_to ON orders (assigned_to)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Backfill every pre-existing order to its own creator, so no order
-- already in play vanishes from its original handler's view the moment
-- this ships. A handful of very old rows with no recorded creator
-- (created_by also NULL) stay NULL — visible only to oversight roles,
-- deliberately, rather than guessed onto an arbitrary account.
UPDATE orders SET assigned_to = created_by WHERE assigned_to IS NULL AND created_by IS NOT NULL;

-- 5. New permissions
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'view_all_orders', 'View all orders regardless of assignment',
       'See every order in the Orders list, Kanban board, and order detail page, not just ones assigned to you. Oversight roles only.',
       'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'view_all_orders');

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'assign_order_owner', 'Assign/reassign order owner',
       'Set or change which single user owns an order (orders.assigned_to). Visibility-only control — never affects stage-gate or payment/shipping permissions.',
       'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'assign_order_owner');

-- 6. role_permissions grants, resolved by role/permission name (never a
-- hardcoded id).
INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r JOIN permissions p ON p.permission_key = 'view_all_orders'
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Accounts Executive', 'Viewer / Auditor')
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r JOIN permissions p ON p.permission_key = 'assign_order_owner'
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director')
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
