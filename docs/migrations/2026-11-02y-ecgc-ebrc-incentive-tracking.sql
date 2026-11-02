-- Owner decision (2 Nov 2026, gap-list Q-6 "go with your recommendation"):
-- (a) eBRC / FIRC: never blocks Mark Fully Settled; a warning on 13h, a
--     monthly "Missing eBRC / FIRC" report and a monthly reminder instead.
-- (b) ECGC: company policy (company settings), the ECGC-approved credit limit
--     per buyer, and the monthly shipment declaration per order — with a
--     warning when a buyer's open exposure is above the limit and a reminder
--     until the shipment is declared (by the 10th of the month after the BL).
-- (c) Export incentives: sanctioned amount / date, claim deadline and a claim
--     status (filed / sanctioned / received / rejected) on every claim, with
--     reminders 30 and 7 days before the deadline. Deadline defaults per
--     scheme are company settings (to be confirmed with the CA).
-- MySQL-safe (information_schema guards, no IF NOT EXISTS on columns), safe
-- to run twice. Run after 02x (associate TDS); independent of it.
SET NAMES utf8mb4;
SET @db := DATABASE();

-- (b) buyer credit limit approved by ECGC
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE clients ADD COLUMN ecgc_limit_amount DECIMAL(14,2) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'ecgc_limit_amount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE clients ADD COLUMN ecgc_limit_currency VARCHAR(10) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'ecgc_limit_currency');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE clients ADD COLUMN ecgc_limit_approved_on DATE NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'ecgc_limit_approved_on');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE clients ADD COLUMN ecgc_limit_valid_to DATE NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'ecgc_limit_valid_to');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE clients ADD COLUMN ecgc_limit_ref VARCHAR(100) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'ecgc_limit_ref');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE clients ADD COLUMN ecgc_limit_file_id BIGINT UNSIGNED NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'ecgc_limit_file_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- (b) monthly shipment declaration to ECGC, per order
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE orders ADD COLUMN ecgc_declared_on DATE NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'ecgc_declared_on');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE orders ADD COLUMN ecgc_declaration_ref VARCHAR(100) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'ecgc_declaration_ref');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- (c) claim tracking
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN sanctioned_amount DECIMAL(14,2) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'sanctioned_amount');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN sanctioned_at DATE NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'sanctioned_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN claim_deadline DATE NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'claim_deadline');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN claim_status VARCHAR(20) NOT NULL DEFAULT ''filed''', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'claim_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN rejected_reason VARCHAR(500) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'rejected_reason');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- Claims already received count as 'received'.
UPDATE ca_export_benefits SET claim_status = 'received' WHERE received_amount IS NOT NULL AND claim_status = 'filed';

-- one reminder per thing, per threshold
CREATE TABLE IF NOT EXISTS compliance_alerts_sent (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alert_kind  VARCHAR(40)  NOT NULL,
  ref_key     VARCHAR(100) NOT NULL,
  threshold   VARCHAR(20)  NOT NULL,
  sent_on     DATE         NOT NULL,
  UNIQUE KEY uq_compliance_alert (alert_kind, ref_key, threshold)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company settings (shown on Admin → Company Settings under "compliance")
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description)
SELECT * FROM (
  SELECT 'ecgc_policy_no' AS k, '' AS v, 'string' AS t, 'compliance' AS c, 'ECGC policy number of the company (Shipments Comprehensive Risk policy). Leave empty if there is no ECGC policy.' AS d
  UNION ALL SELECT 'ecgc_policy_cover_pct', '90', 'number', 'compliance', 'ECGC cover percentage of the policy (shown on the buyer and order pages).'
  UNION ALL SELECT 'ecgc_policy_valid_to', '', 'date', 'compliance', 'ECGC policy valid until (YYYY-MM-DD). A reminder goes out 30 days before.'
  UNION ALL SELECT 'ecgc_declaration_day', '10', 'number', 'compliance', 'Day of the month by which last month''s shipments must be declared to ECGC.'
  UNION ALL SELECT 'benefit_deadline_days_rodtep', '365', 'number', 'compliance', 'RoDTEP: days after the shipping bill (LEO) date to file the claim — default 365 (1 year); 0 = no deadline. To be confirmed with the CA.'
  UNION ALL SELECT 'benefit_deadline_days_rosctl', '365', 'number', 'compliance', 'RoSCTL: days after the shipping bill (LEO) date to file the claim — default 365; 0 = no deadline. To be confirmed with the CA.'
  UNION ALL SELECT 'benefit_deadline_days_duty_drawback', '0', 'number', 'compliance', 'Duty Drawback: 0 = no deadline (paid automatically through the shipping bill). To be confirmed with the CA.'
  UNION ALL SELECT 'benefit_deadline_days_other', '0', 'number', 'compliance', 'Any other scheme: days after the shipping bill (LEO) date to file; 0 = no deadline.'
) x WHERE NOT EXISTS (SELECT 1 FROM company_settings s WHERE s.setting_key = x.k);

-- upload context for the ECGC approval letter
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'ecgc_limit_letter', 'pdf,jpg,jpeg,png', 10485760, 'ECGC credit-limit approval letter for a buyer (clients.ecgc_limit_file_id).'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'ecgc_limit_letter');
