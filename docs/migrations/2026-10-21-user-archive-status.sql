-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql Section BN — users.is_archived/
-- archived_at/archived_by
-- Covers: Phase 3 item 10 — "User 3-way status filter (active/
-- inactive/archive) + delete-if-unreferenced (only a 2-way active/
-- inactive toggle exists)"
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Adds is_archived/archived_at/archived_by to users, mirroring the
-- pattern orders already uses. Archive is a third, distinct state from
-- a merely deactivated account — someone who has genuinely left,
-- filterable apart from a temporarily-suspended login.
--
-- is_archived defaults to 0 for every existing user (no data migrated
-- — no such value existed before). Idempotent (safe to re-run).
-- ================================================================


SET NAMES utf8mb4;
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_archived'
);
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE users
     ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
     ADD COLUMN archived_at TIMESTAMP NULL,
     ADD COLUMN archived_by BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_archived_by'
);
SET @fk_ddl = IF(@fk_exists = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_archived_by FOREIGN KEY (archived_by) REFERENCES users(id)',
  'SELECT 1');
PREPARE fk_stmt FROM @fk_ddl;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;
