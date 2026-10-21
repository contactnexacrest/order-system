-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql users.email_action_notifications_enabled
-- column + seed.sql company_settings addition (user-reported batch
-- item 20, "non-negotiable")
-- Covers item: system-wide action notifications
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- 1) Adds a nullable-equivalent (DEFAULT 1) TINYINT(1) column to
--    `users`: email_action_notifications_enabled — the per-staff-member
--    opt-in/out for receiving a copy of their own action-notification
--    emails (Account > Notifications). Every existing user defaults to
--    enabled (1), matching the column's own DEFAULT.
--
-- 2) Adds one new company_settings row, notification_email — the
--    catch-all address that receives an email for every action logged
--    anywhere in the system. Seeded BLANK (disabled) on an existing
--    database, same as a fresh install — an admin must explicitly set
--    it via Settings before any notification email goes out, so this
--    migration cannot suddenly start emailing an address nobody chose.
--
-- Idempotent (safe to re-run) — both changes are guarded.
-- ================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_action_notifications_enabled'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN email_action_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER two_fa_method', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'notification_email', '', 'email', 'notifications',
       'Catch-all address that receives an email for every action logged in the system (order/client/document/payment changes, approvals, etc.) — leave blank to disable. Each staff member can separately opt in/out of also receiving a copy of their own actions via Account > Notifications.',
       0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'notification_email');
