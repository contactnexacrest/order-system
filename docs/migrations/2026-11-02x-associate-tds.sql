-- Owner decision 2 Nov 2026 (D-235): TDS on associate commission, worked out
-- by the app. India: section 194H at 2% once the associate's commission in the
-- financial year passes ₹20,000 (catch-up on the crossing payment), 20%
-- (206AA) without a verified PAN. Abroad: no TDS by default, once the TRC,
-- Form 10F and no-PE declaration are on file; each payout needs Form 15CA
-- (and 15CB). All values are Admin settings (Settings -> Associate TDS).
-- Each paid payout keeps the rate, section and reason used, and the challan
-- Accounts deposited it with. MySQL-safe, safe to run twice. Run after 02v.
SET NAMES utf8mb4;

SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE incentive_payouts ADD COLUMN tds_rate DECIMAL(5,2) NULL AFTER tds_inr', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'tds_rate');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE incentive_payouts ADD COLUMN tds_section VARCHAR(20) NULL AFTER tds_rate', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'tds_section');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE incentive_payouts ADD COLUMN tds_reason VARCHAR(255) NULL AFTER tds_section', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'tds_reason');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE incentive_payouts ADD COLUMN tds_override_reason VARCHAR(255) NULL AFTER tds_reason', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'tds_override_reason');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE incentive_payouts ADD COLUMN tds_challan_no VARCHAR(40) NULL AFTER tds_override_reason', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'tds_challan_no');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE incentive_payouts ADD COLUMN tds_challan_date DATE NULL AFTER tds_challan_no', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'tds_challan_date');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO incentive_settings (setting_key, setting_value)
SELECT k, v FROM (
  SELECT 'associate_tds_section' AS k, '194H' AS v UNION ALL
  SELECT 'associate_tds_threshold_inr', '20000' UNION ALL
  SELECT 'associate_tds_no_pan_pct', '20.00' UNION ALL
  SELECT 'associate_tds_foreign', '0' UNION ALL
  SELECT 'associate_15cb_required', '1' UNION ALL
  SELECT 'zoho_tds_tax_id', ''
) d WHERE NOT EXISTS (SELECT 1 FROM incentive_settings s WHERE s.setting_key = d.k);
