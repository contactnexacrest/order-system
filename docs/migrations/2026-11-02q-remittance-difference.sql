-- Owner decision (2 Nov 2026, "18 yes"): when a buyer remittance differs from
-- what is due, the person recording it says why — bank charges deducted by
-- the banks (the leg counts as fully paid, the charges become an order cost
-- and go to Zoho Books with the payment), the buyer paid less (short), or
-- the buyer paid more. Each receipt keeps its reason and the charges.
-- MySQL-safe (no ADD COLUMN IF NOT EXISTS), safe to run twice.
SET NAMES utf8mb4;

SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN difference_reason VARCHAR(20) NULL AFTER staff_note',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'difference_reason');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN bank_charges DECIMAL(14,2) NULL AFTER difference_reason',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'bank_charges');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN bank_charges_inr DECIMAL(14,2) NULL AFTER bank_charges',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'bank_charges_inr');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN bank_charges_cost_id BIGINT UNSIGNED NULL AFTER bank_charges_inr',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'bank_charges_cost_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The most bank charges one receipt may carry, as a % of what was due (owner-adjustable).
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'max_bank_charges_pct', '3', 'number', 'tolerances',
       'The most bank charges one buyer remittance may carry, as a % of the amount due. Above it the difference must be recorded as a short payment instead.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'max_bank_charges_pct');
