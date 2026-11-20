-- 2026-11-20a — Owner decision D-394 (20 Nov 2026): quick-quote prospects.
-- (1) Three settings in Settings › Quick-quote prospects: prospect_expiry_days (60), prospect_reminder_days (7),
--     prospect_auto_close (On). quotation_validity_days (30, printed on the Quotation) is not touched.
-- (2) The Sales task "Quotation expiring — no reply from the prospect" (My tasks).
-- (3) Five new client columns: is_prospect, prospect_expires_on, prospect_reminded_on, prospect_expired_on,
--     prospect_expired_reason. Existing clients become no prospect (0) — nothing about them changes.
-- Nothing is deleted, no document changes.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): phpMyAdmin on the shared host refuses the information_schema +
-- PREPARE guard. The INSERTs are guarded with WHERE NOT EXISTS; the one ALTER (all five columns in a single
-- statement) comes last — a second run only stops there with "#1060 Duplicate column name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'prospect_expiry_days', '60', 'number', 'prospects', 'Days after a prospect is added (or kept, or reactivated) before its quotation order moves to "Did not go ahead" and the client to Deactivated, unless an order goes ahead. 7 to 365; default 60. Nothing is deleted, and a client can be activated again. Separate from "Quotation valid for … days", which prints on the Quotation.', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'prospect_expiry_days');
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'prospect_reminder_days', '7', 'number', 'prospects', 'Days before that date the order''s owner gets one reminder: a notification, a line in My tasks and an e-mail. 1 to 30; default 7.', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'prospect_reminder_days');
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'prospect_auto_close', '1', 'boolean', 'prospects', 'On = on that date the daily job moves the order to "Did not go ahead" and the client to Deactivated. Off = only remind; nothing closes on its own.', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'prospect_auto_close');
INSERT INTO department_task_types (task_key, department_code, title, standard_days, escalate_after_days, sort_order)
SELECT 'prospect_quote_expiring', 'SALES', 'Quotation expiring — no reply from the prospect', 7, 3, 5
 WHERE NOT EXISTS (SELECT 1 FROM department_task_types WHERE task_key = 'prospect_quote_expiring');
-- Last: a second run stops here with the harmless #1060.
ALTER TABLE clients
  ADD COLUMN is_prospect TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN prospect_expires_on DATE NULL,
  ADD COLUMN prospect_reminded_on DATE NULL,
  ADD COLUMN prospect_expired_on DATE NULL,
  ADD COLUMN prospect_expired_reason VARCHAR(255) NULL;
