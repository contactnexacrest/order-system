-- Associate system (owner decision 1 Nov 2026: "AS all as recommended", AS-1 … AS-17).
-- Outside associates get their own portal; personal codes and client invitation
-- links; buyer-confirmed attribution with a 12-month lock; bank details verified
-- by two people; a global commission ladder with optional personal rates.
-- MySQL-safe (no ADD COLUMN IF NOT EXISTS), safe to run twice.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- associates
-- Status life cycle: invited → active → suspended / ended ('inactive' kept for old rows).
ALTER TABLE associates MODIFY COLUMN status ENUM('invited','active','suspended','ended','inactive') NOT NULL DEFAULT 'invited';

-- AS-17: a personal rate per rung is optional; NULL (or 0) means "use the global ladder".
ALTER TABLE associates MODIFY COLUMN rate_1_5 DECIMAL(5,2) NULL DEFAULT NULL;
ALTER TABLE associates MODIFY COLUMN rate_6_10 DECIMAL(5,2) NULL DEFAULT NULL;
ALTER TABLE associates MODIFY COLUMN rate_11_20 DECIMAL(5,2) NULL DEFAULT NULL;
ALTER TABLE associates MODIFY COLUMN rate_21_40 DECIMAL(5,2) NULL DEFAULT NULL;
ALTER TABLE associates MODIFY COLUMN rate_41_plus DECIMAL(5,2) NULL DEFAULT NULL;
-- Existing associates carried a copy of the standard ladder: a rung equal to it now follows the global ladder.
UPDATE associates SET rate_1_5 = NULL WHERE rate_1_5 = 0.70;
UPDATE associates SET rate_6_10 = NULL WHERE rate_6_10 = 1.00;
UPDATE associates SET rate_11_20 = NULL WHERE rate_11_20 = 1.50;
UPDATE associates SET rate_21_40 = NULL WHERE rate_21_40 = 1.75;
UPDATE associates SET rate_41_plus = NULL WHERE rate_41_plus = 2.00;

SET @t := 'associates';
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'personal_code');
SET @s := IF(@c = 0, 'ALTER TABLE associates
  ADD COLUMN personal_code VARCHAR(24) NULL,
  ADD COLUMN no_commission TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN agreement_ends_on DATE NULL,
  ADD COLUMN relationship_manager_id BIGINT UNSIGNED NULL,
  ADD COLUMN pan VARCHAR(20) NULL,
  ADD COLUMN gstin VARCHAR(20) NULL,
  ADD COLUMN address TEXT NULL,
  ADD COLUMN password_hash VARCHAR(255) NULL,
  ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN failed_login_count INT NOT NULL DEFAULT 0,
  ADD COLUMN locked_until DATETIME NULL,
  ADD COLUMN last_login_at DATETIME NULL,
  ADD COLUMN zoho_contact_id VARCHAR(40) NULL,
  ADD COLUMN status_reason VARCHAR(500) NULL,
  ADD UNIQUE KEY uq_associates_personal_code (personal_code),
  ADD CONSTRAINT fk_associates_rm FOREIGN KEY (relationship_manager_id) REFERENCES users(id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- An associate's e-mail is their portal login: one associate per e-mail.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'associates' AND INDEX_NAME = 'uq_associates_email');
SET @s := IF(@c = 0, 'ALTER TABLE associates ADD UNIQUE KEY uq_associates_email (email)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- portal tokens (set password, login codes)
CREATE TABLE IF NOT EXISTS associate_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('set_password','login_code') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_associate_tokens_lookup (purpose, token_hash),
  CONSTRAINT fk_associate_tokens_associate FOREIGN KEY (associate_id) REFERENCES associates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- bank accounts (AS-7)
CREATE TABLE IF NOT EXISTS associate_bank_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','verified','rejected','superseded') NOT NULL DEFAULT 'pending',
  holder_name VARCHAR(150) NOT NULL,
  account_number_enc TEXT NOT NULL,
  account_last4 VARCHAR(4) NOT NULL,
  ifsc VARCHAR(11) NULL,
  swift VARCHAR(11) NULL,
  bank_name VARCHAR(150) NOT NULL,
  bank_branch_address VARCHAR(300) NULL,
  intermediary_bank VARCHAR(300) NULL,
  account_currency CHAR(3) NOT NULL DEFAULT 'INR',
  proof_file_id BIGINT UNSIGNED NULL,
  requested_by_associate TINYINT(1) NOT NULL DEFAULT 0,
  requested_by_user BIGINT UNSIGNED NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  check_method VARCHAR(200) NULL,
  first_checked_by BIGINT UNSIGNED NULL,
  first_checked_at DATETIME NULL,
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  usable_from DATE NULL,
  rejected_reason VARCHAR(300) NULL,
  KEY idx_associate_bank_associate (associate_id, status),
  CONSTRAINT fk_associate_bank_associate FOREIGN KEY (associate_id) REFERENCES associates(id),
  CONSTRAINT fk_associate_bank_proof FOREIGN KEY (proof_file_id) REFERENCES file_store(id),
  CONSTRAINT fk_associate_bank_first FOREIGN KEY (first_checked_by) REFERENCES users(id),
  CONSTRAINT fk_associate_bank_verified FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- KYC, invoices, tax papers
CREATE TABLE IF NOT EXISTS associate_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(30) NOT NULL,
  payout_id BIGINT UNSIGNED NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  reference VARCHAR(100) NULL,
  uploaded_by_associate TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_by_user BIGINT UNSIGNED NULL,
  status ENUM('received','accepted','rejected') NOT NULL DEFAULT 'received',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_associate_documents (associate_id, kind),
  CONSTRAINT fk_associate_documents_associate FOREIGN KEY (associate_id) REFERENCES associates(id),
  CONSTRAINT fk_associate_documents_file FOREIGN KEY (file_id) REFERENCES file_store(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- invitations (AS-5)
CREATE TABLE IF NOT EXISTS associate_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('client_link','personal_code') NOT NULL,
  token VARCHAR(64) NULL,
  company_name VARCHAR(255) NULL,
  country VARCHAR(100) NULL,
  status ENUM('created','opened','registered','accepted','not_accepted','expired','cancelled') NOT NULL DEFAULT 'created',
  expires_at DATETIME NULL,
  opened_at DATETIME NULL,
  submission_id BIGINT UNSIGNED NULL,
  client_id BIGINT UNSIGNED NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_associate_invitations_token (token),
  KEY idx_associate_invitations_associate (associate_id, status),
  CONSTRAINT fk_associate_invitations_associate FOREIGN KEY (associate_id) REFERENCES associates(id)
) ENGINE=InnoDB;

-- The buyer's own registration through an associate's link or code.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_intake_submissions' AND COLUMN_NAME = 'associate_id');
SET @s := IF(@c = 0, 'ALTER TABLE client_intake_submissions
  ADD COLUMN associate_id BIGINT UNSIGNED NULL,
  ADD COLUMN associate_invitation_id BIGINT UNSIGNED NULL,
  ADD COLUMN introduction_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN email_code_hash CHAR(64) NULL,
  ADD COLUMN email_code_expires_at DATETIME NULL,
  ADD COLUMN email_verified_at DATETIME NULL,
  ADD COLUMN website VARCHAR(200) NULL,
  ADD CONSTRAINT fk_intake_associate FOREIGN KEY (associate_id) REFERENCES associates(id)', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The client's lock to its associate.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'associate_locked_until');
SET @s := IF(@c = 0, 'ALTER TABLE clients
  ADD COLUMN associate_locked_until DATE NULL,
  ADD COLUMN associate_source VARCHAR(20) NULL,
  ADD COLUMN associate_invitation_id BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- history, notices, messages
CREATE TABLE IF NOT EXISTS associate_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NULL,
  invitation_id BIGINT UNSIGNED NULL,
  event VARCHAR(40) NOT NULL,
  message VARCHAR(600) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  is_notice TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_associate_events (associate_id, created_at),
  CONSTRAINT fk_associate_events_associate FOREIGN KEY (associate_id) REFERENCES associates(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS associate_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  from_associate TINYINT(1) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_associate_messages (associate_id, created_at),
  CONSTRAINT fk_associate_messages_associate FOREIGN KEY (associate_id) REFERENCES associates(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- orders: the rate fixed when the order is made (AS-17)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'associate_rate_pct');
SET @s := IF(@c = 0, 'ALTER TABLE orders
  ADD COLUMN associate_id BIGINT UNSIGNED NULL,
  ADD COLUMN associate_rate_pct DECIMAL(5,2) NULL,
  ADD COLUMN associate_rate_source VARCHAR(10) NULL,
  ADD COLUMN associate_client_number INT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- payouts: hold, claw-back, invoice
ALTER TABLE incentive_payouts MODIFY COLUMN status ENUM('due','paid','held') NOT NULL DEFAULT 'due';
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'hold_reason');
SET @s := IF(@c = 0, 'ALTER TABLE incentive_payouts
  ADD COLUMN hold_reason VARCHAR(300) NULL,
  ADD COLUMN clawback_of_payout_id BIGINT UNSIGNED NULL,
  ADD COLUMN note VARCHAR(300) NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- settings: the global ladder (AS-17), TDS, protection
INSERT INTO incentive_settings (setting_key, setting_value)
SELECT k, v FROM (
  SELECT 'associate_rate_1_5' AS k, '0.70' AS v UNION ALL SELECT 'associate_rate_6_10', '1.00' UNION ALL SELECT 'associate_rate_11_20', '1.50'
  UNION ALL SELECT 'associate_rate_21_40', '1.75' UNION ALL SELECT 'associate_rate_41_plus', '2.00'
  UNION ALL SELECT 'associate_tds_pct', '2.00' UNION ALL SELECT 'associate_protection_months', '12'
  UNION ALL SELECT 'associate_bank_wait_days', '7') d
WHERE NOT EXISTS (SELECT 1 FROM incentive_settings s WHERE s.setting_key = d.k);

-- ---------------------------------------------------------------- staff permissions (AS-11)
INSERT INTO permissions (permission_key, name, description, category)
SELECT k, n, d, 'Associates' FROM (
  SELECT 'manage_associates' AS k, 'Manage associates' AS n, 'Associate register, agreements, rates, invitations, client links and their changes, portal access' AS d
  UNION ALL SELECT 'view_associates', 'View associates', 'See which client was introduced by which associate (no amounts, bank details or agreements)'
  UNION ALL SELECT 'manage_associate_bank', 'Associate bank & tax details', 'See associates'' full bank details and verify bank changes (two people)') p
WHERE NOT EXISTS (SELECT 1 FROM permissions x WHERE x.permission_key = p.k);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE ((p.permission_key IN ('manage_associates', 'view_associates', 'manage_associate_bank') AND r.name IN ('Admin', 'Managing Director'))
     OR (p.permission_key IN ('manage_associates', 'view_associates') AND r.name = 'Executive Director')
     OR (p.permission_key = 'view_associates' AND r.name IN ('Export Executive', 'Sales/Export Manager', 'Sales Director'))
     OR (p.permission_key = 'manage_associate_bank' AND r.name IN ('Finance Manager', 'Finance Director')))
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- ---------------------------------------------------------------- upload context for associate documents (KYC, invoices, bank proof, 15CA/CB, TDS certificates)
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'associate_document', 'pdf,jpg,jpeg,png', 10485760, 'An associate''s own documents (KYC, tax papers, commission invoices, bank proof, 15CA/CB, TDS certificates) — internal; seen by the associate and by manage_associates / manage_associate_bank only.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'associate_document');

-- ---------------------------------------------------------------- every personal code ever issued (guarantee: a code belongs to one associate for ever, never reissued)
CREATE TABLE IF NOT EXISTS associate_codes (
  code VARCHAR(30) NOT NULL PRIMARY KEY,
  associate_id BIGINT UNSIGNED NOT NULL,
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at DATETIME NULL,
  KEY idx_associate_codes_associate (associate_id),
  CONSTRAINT fk_associate_codes_associate FOREIGN KEY (associate_id) REFERENCES associates(id)
) ENGINE=InnoDB;
INSERT INTO associate_codes (code, associate_id)
SELECT a.personal_code, a.id FROM associates a WHERE a.personal_code IS NOT NULL AND NOT EXISTS (SELECT 1 FROM associate_codes c WHERE c.code = a.personal_code);
