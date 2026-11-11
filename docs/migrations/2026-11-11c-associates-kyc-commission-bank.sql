-- 2026-11-11c — Associates: KYC, commission rule, payout day, bank-details confirmation
-- Owner decisions D-290 to D-294 (11 Nov 2026, points 6, 7, 8, 9, 16, 10 and 18).
--
-- What changes:
--   * D-290 KYC: associate_documents.id_doc_type (Passport / National ID card /
--     Residence permit / Driving licence); associates.address_proof_due_on. Associates
--     already accepted (status active) get 60 days from the day this file is FIRST run
--     to add an address proof before their payouts are held. A second run does not give
--     anyone new 60 days.
--   * D-291 commission rule: associates.commission_mode gains 'own_ladder'; who changed
--     the rule, when and why (commission_rule_changed_at / _by / _reason); new table
--     associate_ladder_steps (an associate's own ladder).
--   * D-292 permission "See associate commission rules" (view_associate_commission_rules),
--     granted to Admin, Managing Director, Executive Director, Finance Director and
--     Finance Manager. Payout day: the default 5 becomes "last" (last day of the month)
--     ONLY where 5 is still set on the first run; a payout day on a Sunday or holiday moves
--     to the working day before (associate_payout_holiday_rule = before).
--   * D-293 bank details: associate_bank_accounts gets status 'awaiting_confirmation'
--     and the confirmation / "how I checked" columns; settings associate_bank_wait_first_days
--     (0), associate_bank_wait_change_days (15) and bank_details_check_people (one) REPLACE
--     associate_bank_wait_days; self-approval rule "Bank details" for MD and ED.
--   * D-294: new table vendor_bank_changes (the same rules for supplier and logistics
--     partner bank details).
--
-- Safe to run twice: columns, foreign keys and ENUM changes guarded through
-- information_schema + PREPARE (MySQL-compatible), CREATE TABLE IF NOT EXISTS,
-- INSERT … WHERE NOT EXISTS; the one-time data steps run only on the first run.
-- Run after 2026-11-11b, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-11c-associates-kyc-commission-bank.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- First run? (associate_bank_wait_days is removed at the end of this file.)
SET @first_run := (SELECT COUNT(*) FROM incentive_settings WHERE setting_key = 'associate_bank_wait_days');
SET @had_due_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'associates' AND COLUMN_NAME = 'address_proof_due_on');

-- ---------------------------------------------------------------- D-290 KYC
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE associate_documents ADD COLUMN id_doc_type VARCHAR(30) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'associate_documents' AND COLUMN_NAME = 'id_doc_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE associates ADD COLUMN address_proof_due_on DATE NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'associates' AND COLUMN_NAME = 'address_proof_due_on');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 60 days for associates already accepted — only on the run that added the column.
UPDATE associates SET address_proof_due_on = DATE_ADD(CURDATE(), INTERVAL 60 DAY)
 WHERE @had_due_col = 0 AND status = 'active' AND address_proof_due_on IS NULL;

-- ---------------------------------------------------------------- D-291 commission rule
SET @s := (SELECT IF(COUNT(*) > 0,
  "ALTER TABLE associates MODIFY COLUMN commission_mode ENUM('fixed','ladder','own_ladder') NOT NULL DEFAULT 'ladder'",
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'associates' AND COLUMN_NAME = 'commission_mode' AND COLUMN_TYPE NOT LIKE '%own_ladder%');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE associates ADD COLUMN commission_rule_changed_at DATETIME NULL, ADD COLUMN commission_rule_changed_by BIGINT UNSIGNED NULL, ADD COLUMN commission_rule_reason VARCHAR(300) NULL, ADD CONSTRAINT fk_associates_commission_rule_by FOREIGN KEY (commission_rule_changed_by) REFERENCES users(id)',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'associates' AND COLUMN_NAME = 'commission_rule_changed_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS associate_ladder_steps (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  from_order   INT NOT NULL,
  to_order     INT NULL,
  level_id     BIGINT UNSIGNED NOT NULL,
  updated_by   BIGINT UNSIGNED NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_associate_ladder_from (associate_id, from_order),
  CONSTRAINT fk_associate_ladder_associate FOREIGN KEY (associate_id) REFERENCES associates(id),
  CONSTRAINT fk_associate_ladder_level FOREIGN KEY (level_id) REFERENCES commission_levels(id),
  CONSTRAINT fk_associate_ladder_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- D-293 associate bank details
SET @s := (SELECT IF(COUNT(*) > 0,
  "ALTER TABLE associate_bank_accounts MODIFY COLUMN status ENUM('awaiting_confirmation','pending','verified','rejected','superseded','cancelled') NOT NULL DEFAULT 'pending'",
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'associate_bank_accounts' AND COLUMN_NAME = 'status' AND COLUMN_TYPE NOT LIKE '%awaiting_confirmation%');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE associate_bank_accounts
  ADD COLUMN confirm_by DATETIME NULL, ADD COLUMN confirm_code_hash CHAR(64) NULL, ADD COLUMN confirm_code_expires DATETIME NULL,
  ADD COLUMN confirm_code_attempts INT NOT NULL DEFAULT 0, ADD COLUMN confirmed_at DATETIME NULL, ADD COLUMN reminded_at DATETIME NULL,
  ADD COLUMN cancelled_at DATETIME NULL, ADD COLUMN cancelled_how VARCHAR(20) NULL, ADD COLUMN check_bank_proof TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN check_phone TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN check_test_payment TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN test_payment_ref VARCHAR(60) NULL,
  ADD COLUMN verify_note VARCHAR(300) NULL, ADD COLUMN self_verify_reason VARCHAR(300) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'associate_bank_accounts' AND COLUMN_NAME = 'confirm_by');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- Bank changes already waiting for staff ('pending') count as confirmed by the associate.

-- ---------------------------------------------------------------- D-294 supplier / logistics partner bank details
CREATE TABLE IF NOT EXISTS vendor_bank_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_kind ENUM('supplier','logistics_partner') NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  status ENUM('awaiting_confirmation','pending','verified','applied','rejected','cancelled') NOT NULL DEFAULT 'awaiting_confirmation',
  bank_account_name VARCHAR(255) NULL, bank_name VARCHAR(150) NULL, bank_branch VARCHAR(150) NULL, bank_account_number TEXT NULL, account_last4 VARCHAR(4) NULL,
  bank_ifsc VARCHAR(20) NULL, bank_account_type VARCHAR(30) NULL, bank_upi_id VARCHAR(100) NULL,
  entered_source ENUM('staff','vendor') NOT NULL, entered_by BIGINT UNSIGNED NULL, entered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_to_email VARCHAR(190) NULL, confirm_token_hash CHAR(64) NULL, confirm_by DATETIME NULL, confirmed_at DATETIME NULL, reminded_at DATETIME NULL,
  cancelled_at DATETIME NULL, cancelled_how VARCHAR(20) NULL, first_checked_by BIGINT UNSIGNED NULL, first_checked_at DATETIME NULL,
  check_bank_proof TINYINT(1) NOT NULL DEFAULT 0, check_phone TINYINT(1) NOT NULL DEFAULT 0, check_test_payment TINYINT(1) NOT NULL DEFAULT 0,
  test_payment_ref VARCHAR(60) NULL, verify_note VARCHAR(300) NULL, self_verify_reason VARCHAR(300) NULL,
  verified_by BIGINT UNSIGNED NULL, verified_at DATETIME NULL, usable_from DATE NULL, applied_at DATETIME NULL, rejected_reason VARCHAR(300) NULL,
  KEY idx_vendor_bank_changes_vendor (vendor_kind, vendor_id, status),
  KEY idx_vendor_bank_changes_token (confirm_token_hash),
  CONSTRAINT fk_vendor_bank_changes_entered FOREIGN KEY (entered_by) REFERENCES users(id),
  CONSTRAINT fk_vendor_bank_changes_first FOREIGN KEY (first_checked_by) REFERENCES users(id),
  CONSTRAINT fk_vendor_bank_changes_verified FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- data: permission, settings, self-approval rule
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'view_associate_commission_rules', 'See associate commission rules', 'See an associate''s commission rule (standard ladder, own ladder or fixed), the level and % of their next order, how many orders have counted and the ladder steps. Without it staff see "Set by the MD". Changing the rule needs Manage pricing (the MD).', 'Associates'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'view_associate_commission_rules');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'view_associate_commission_rules'
 WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Finance Director', 'Finance Manager')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
UPDATE permissions SET description = 'See associates'' full bank details and verify bank changes (once the associate has confirmed them; one or two people — Associates › Commission settings)'
 WHERE permission_key = 'manage_associate_bank';

INSERT INTO incentive_settings (setting_key, setting_value)
SELECT k, v FROM (
  SELECT 'associate_payout_holiday_rule' AS k, 'before' AS v
  UNION ALL SELECT 'associate_bank_wait_first_days', '0'
  UNION ALL SELECT 'associate_bank_wait_change_days', '15'
  UNION ALL SELECT 'bank_details_check_people', 'one') d
WHERE NOT EXISTS (SELECT 1 FROM incentive_settings s WHERE s.setting_key = d.k);

-- New default payout day "last day of the month" — first run only, and only where the old default 5 is
-- still set (a day the MD chose is kept).
UPDATE incentive_settings SET setting_value = 'last'
 WHERE @first_run > 0 AND setting_key = 'associate_payout_day' AND setting_value = '5';
DELETE FROM incentive_settings WHERE setting_key = 'associate_bank_wait_days';

INSERT INTO self_approval_rules (rule_key, role_id)
SELECT 'bank_details', r.id FROM roles r
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = 'bank_details');
