-- ================================================================
-- DB MIGRATION — 2026-10-24c
-- Order multi-assignee visibility: supersedes the single-owner
-- `orders.assigned_to` column (added by 2026-10-24b-order-owner-
-- assignment.sql) with a proper many-to-many `order_assignees` table.
-- Real-world need this fixes: an order can legitimately be worked by
-- more than one staff member at once (e.g. an Export Executive on
-- office/documentation work and a Logistics Executive on CHA/factory
-- visits/packing for the SAME order concurrently), which a single
-- owner column can never represent. Still visibility-only — never
-- consulted by the stage-gate or action-permission system.
--
-- New orders are still auto-assigned to their creator (now as the
-- order's first row in order_assignees — see
-- OrderRepository::insertRow()); only a holder of the existing
-- assign_order_owner permission (Admin/Managing Director/Executive
-- Director — unchanged, no new permission) can add or remove an
-- assignee afterward.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually
-- in production use; they use the same schema). Must run strictly
-- after 2026-10-24b has already been applied (this migration retires
-- the column that one added).
--
-- Idempotent (safe to re-run): CREATE TABLE/INDEX are guarded by
-- information_schema checks, the backfill INSERT only ever adds a row
-- that doesn't already exist, the DROP COLUMN/CONSTRAINT/INDEX steps
-- are guarded the same way, and the permission-text UPDATE is safe to
-- re-run by nature (an UPDATE to the same values is a no-op).
-- ================================================================

SET NAMES utf8mb4;

-- 1. CREATE TABLE order_assignees — composite primary key (order_id,
-- user_id) both enforces "no duplicate assignee" for free and makes
-- add/remove a trivial single-row operation, since this is a pure
-- membership table with no per-row status to track (unlike
-- document_reviews, which this is otherwise modeled on).
SET @tbl_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_assignees'
);
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE order_assignees (
    order_id    BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id, user_id),
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
  ) ENGINE=InnoDB',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_assignees' AND INDEX_NAME = 'idx_order_assignees_user'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_order_assignees_user ON order_assignees (user_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Backfill: every order's existing single owner becomes its first
-- assignee, so nothing already in play loses its current viewer. Only
-- runs while orders.assigned_to still exists (a re-run after step 3
-- below has already dropped it would otherwise error).
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'assigned_to'
);
SET @sql := IF(@col_exists > 0,
  'INSERT INTO order_assignees (order_id, user_id, assigned_by)
   SELECT o.id, o.assigned_to, o.assigned_to
   FROM orders o
   WHERE o.assigned_to IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM order_assignees oa WHERE oa.order_id = o.id AND oa.user_id = o.assigned_to)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Retire orders.assigned_to — drop the FK first (MySQL requires it
-- gone before the column), then the index, then the column itself.
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_assigned_to'
);
SET @sql := IF(@fk_exists > 0,
  'ALTER TABLE orders DROP FOREIGN KEY fk_orders_assigned_to',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_assigned_to'
);
SET @sql := IF(@idx_exists > 0,
  'DROP INDEX idx_orders_assigned_to ON orders',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'assigned_to'
);
SET @sql := IF(@col_exists > 0,
  'ALTER TABLE orders DROP COLUMN assigned_to',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Cosmetic: refresh assign_order_owner's display text for the
-- admin permissions list — the permission_key itself is unchanged
-- (every string literal in both codebases already references it), so
-- this never touches role_permissions or any grant.
UPDATE permissions
SET name = 'Manage order assignees',
    description = 'Add or remove which staff are assigned to an order (order_assignees). Visibility-only control — never affects stage-gate or payment/shipping permissions.'
WHERE permission_key = 'assign_order_owner';
