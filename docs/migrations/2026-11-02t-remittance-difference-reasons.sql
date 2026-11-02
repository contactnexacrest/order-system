-- Owner follow-up on point 18 (2 Nov 2026): "Why is it different?" becomes a
-- drop-down of the usual reasons, managed by Admin (Settings → Remittance
-- difference reasons), each mapped to how the difference is treated
-- (bank_charges / short / excess), plus "Other — type the reason".
-- Each receipt keeps the chosen reason (id) and a snapshot of its text.
-- Run after 2026-11-02q. MySQL-safe (information_schema guards), safe to run twice.
SET NAMES utf8mb4;

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS remittance_difference_reasons (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(150) NOT NULL,
  treatment ENUM('bank_charges','short','excess') NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rdr_treatment (treatment, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN difference_reason_id BIGINT UNSIGNED NULL AFTER difference_reason',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'difference_reason_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN difference_reason_text VARCHAR(255) NULL AFTER difference_reason_id',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'difference_reason_text');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN difference_reason_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER difference_reason_text',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'difference_reason_custom');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- The usual reasons (owner-editable). Only seeded once: a list the owner has
-- already edited is left alone.
INSERT INTO remittance_difference_reasons (label, treatment, sort_order)
SELECT v.label, v.treatment, v.sort_order FROM (
  SELECT 'Intermediary / correspondent bank charges deducted' AS label, 'bank_charges' AS treatment, 10 AS sort_order
  UNION ALL SELECT 'Buyer''s bank (remitting) charges deducted', 'bank_charges', 20
  UNION ALL SELECT 'Our bank deducted its charges from the amount credited', 'bank_charges', 30
  UNION ALL SELECT 'Currency conversion difference (small)', 'bank_charges', 40
  UNION ALL SELECT 'Buyer deducted an agreed discount', 'short', 50
  UNION ALL SELECT 'Buyer deducted a quality / quantity claim', 'short', 60
  UNION ALL SELECT 'Buyer paid in instalments — rest to follow', 'short', 70
  UNION ALL SELECT 'Buyer disputes part of the amount', 'short', 80
  UNION ALL SELECT 'Buyer paid extra — adjust against balance', 'excess', 90
  UNION ALL SELECT 'Buyer included next order''s advance', 'excess', 100
  UNION ALL SELECT 'Buyer rounded up the amount', 'excess', 110
) v
WHERE NOT EXISTS (SELECT 1 FROM remittance_difference_reasons);
