-- 2026-11-11g — Order chat: why a staff post was or was not e-mailed
-- Owner decision D-268 (8 Nov 2026, four staff-screen fixes found while recording B01;
-- shipped with the 11 Nov 2026 build).
--
-- What changes:
--   * order_comments.email_status VARCHAR(20) NULL, right after email_sent: sent /
--     testing_mode / switched_off (D-280) / no_email / failed — the true reason a staff
--     post on the order chat was or was not e-mailed to the buyer (shown under the post
--     instead of a plain "not e-mailed"). NULL for buyer posts and for posts made before
--     this change.
--
-- Safe to run twice: the column is guarded through information_schema + PREPARE
-- (MySQL-compatible, no ADD COLUMN IF NOT EXISTS).
-- Run after 2026-11-11f, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-11g-order-comment-email-status.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_comments ADD COLUMN email_status VARCHAR(20) NULL AFTER email_sent',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_comments' AND COLUMN_NAME = 'email_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
