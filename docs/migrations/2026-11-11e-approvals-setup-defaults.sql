-- 2026-11-11e — One Approvals page, Setup checklist, ready-to-use defaults
-- Owner decisions D-310 (point 27), D-312 (point 4 B) and D-313 (point 4, 11 Nov 2026).
--
-- What changes:
--   * D-310: new table short_payment_requests — a payment that came in short waits on
--     the Approvals page for a manager with "Approve short payment".
--   * D-312: new table setup_checklist_ticks — the ticks given on Settings › Setup checklist.
--   * D-313 defaults, applied ONLY on the first run of this file and only where the old
--     default is still set (a value the company chose is kept):
--       - document clean-up on: drafts 90 days after approval, never-sent superseded
--         revisions after 365 days (the SUPERSEDED copy is kept), CAFIN latest only;
--         CHECK Settings › Document clean-up after running if the company wants
--         something else — the nightly clean-up starts removing copies under these rules;
--       - password expiry off (90 → 0);
--     and, whenever still empty, the Test Mode e-mail = the company e-mail.
--   * D-313: Indian national holidays (Republic Day, Independence Day, Gandhi Jayanti)
--     for this year and next are added to the Holiday Calendar where the date is not
--     there yet. State and festival holidays are added by the company.
--
-- NOT in this file (on purpose): the development step that replaced the company's
-- identity, bank, GSTIN, IEC/PAN, LUT and RCMC values with placeholders. On the live
-- site those values are real and stay as they are. (A fresh install gets placeholders
-- from seed.sql and enters the real values via Settings › Setup checklist.)
--
-- Safe to run twice: CREATE TABLE IF NOT EXISTS, the default changes run only on the
-- run that created setup_checklist_ticks, INSERT … WHERE NOT EXISTS. No column DDL.
-- Run after 2026-11-11d, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-11e-approvals-setup-defaults.sql
SET NAMES utf8mb4;

-- First run? (setup_checklist_ticks is created below.)
SET @first_run := (SELECT COUNT(*) = 0 FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setup_checklist_ticks');

-- D-310: a payment that came in short waits for a manager with "Approve short payment".
CREATE TABLE IF NOT EXISTS short_payment_requests (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NOT NULL,
  leg           VARCHAR(20) NOT NULL,
  milestone_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  owed_amount   DECIMAL(14,2) NOT NULL,
  currency_code VARCHAR(3) NULL,
  requested_by  BIGINT UNSIGNED NOT NULL,
  requested_at  DATETIME NOT NULL,
  UNIQUE KEY uq_short_payment_requests_leg (order_id, leg, milestone_id),
  CONSTRAINT fk_short_payment_requests_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_short_payment_requests_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- D-312: the ticks given on Settings › Setup checklist.
CREATE TABLE IF NOT EXISTS setup_checklist_ticks (
  item_key   VARCHAR(60) NOT NULL PRIMARY KEY,
  ticked_by  BIGINT UNSIGNED NULL,
  ticked_at  DATETIME NOT NULL,
  note       VARCHAR(300) NULL,
  CONSTRAINT fk_setup_checklist_ticks_user FOREIGN KEY (ticked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- D-313: document clean-up on by default (first run only, only where still at the old "off" default).
UPDATE company_settings SET setting_value = '90'
 WHERE @first_run = 1 AND setting_key = 'doc_cleanup_draft_days' AND setting_value = '0';
UPDATE company_settings SET setting_value = '365'
 WHERE @first_run = 1 AND setting_key = 'doc_cleanup_superseded_days' AND setting_value = '0';
UPDATE company_settings SET setting_value = 'latest_only'
 WHERE @first_run = 1 AND setting_key = 'doc_cleanup_cafin_mode' AND setting_value = 'keep_all';

-- D-313: password expiry off by default (first run only, only where still at the old 90-day default).
UPDATE company_settings SET setting_value = '0',
       description = 'Days before a password must be changed. Enforced on every request (SessionAuth) — 0 disables the check (D-313: off by default; staff sign in with an e-mailed code).'
 WHERE @first_run = 1 AND setting_key = 'password_expiry_days' AND setting_value = '90';

-- D-313: the Test Mode e-mail defaults to the company e-mail (only where none is set).
UPDATE test_mode_settings
   SET test_email = (SELECT cs.setting_value FROM company_settings cs WHERE cs.setting_key = 'email')
 WHERE id = 1 AND (test_email IS NULL OR test_email = '');

-- D-313: Indian national holidays for this year and next (state and festival holidays are added by the company).
INSERT INTO company_holidays (holiday_date, description)
SELECT h.d, h.n FROM (
  SELECT CAST(CONCAT(YEAR(CURDATE()), '-01-26') AS DATE) AS d, 'Republic Day' AS n
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()), '-08-15') AS DATE), 'Independence Day'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()), '-10-02') AS DATE), 'Gandhi Jayanti'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()) + 1, '-01-26') AS DATE), 'Republic Day'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()) + 1, '-08-15') AS DATE), 'Independence Day'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()) + 1, '-10-02') AS DATE), 'Gandhi Jayanti'
) h WHERE NOT EXISTS (SELECT 1 FROM company_holidays c WHERE c.holiday_date = h.d);
