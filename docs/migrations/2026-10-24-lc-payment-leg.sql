-- ================================================================
-- DB MIGRATION — 2026-10-24
-- Owner's export-process sequence, Step 5: adds a real Letter-of-Credit
-- payment leg alongside the existing Cash Advance leg. When a payment
-- preset's payment_method = 'LC', the order-detail page's Step 5
-- replaces "Record Advance Remittance" / "Mark Advance Cleared" with
-- "Record LC Details" / "Confirm LC Usable" — the LC leg replaces the
-- cash advance entirely (the order's Balance leg afterward is
-- unaffected either way). This is distinct from the existing
-- order_lc_details tracking added in 2026-10-23f, which records
-- supplementary LC-financing paperwork/cost (bank loan interest) for
-- ANY order regardless of payment method — the new columns below are
-- this leg's own amount/charges/confirmation, parallel to
-- order_payment_status's advance_amount/advance_cleared_at.
--
-- Run this ONCE against each production database this app uses (the PHP
-- stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run): ADD COLUMN/INSERT are guarded by
-- information_schema/NOT EXISTS checks since, unlike a plain MODIFY
-- COLUMN, a bare re-run of either would otherwise error or duplicate.
-- ================================================================

SET NAMES utf8mb4;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_presets' AND COLUMN_NAME = 'payment_method'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE payment_presets ADD COLUMN payment_method ENUM(''CASH_ADVANCE'',''LC'') NOT NULL DEFAULT ''CASH_ADVANCE'' AFTER advance_trigger_text',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- "Standalone order" snapshot column (same pattern as advance_pct etc.
-- on orders — resolved once at creation, never re-read from
-- payment_presets, never touched by an Amendment).
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_method'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE orders ADD COLUMN payment_method ENUM(''CASH_ADVANCE'',''LC'') NOT NULL DEFAULT ''CASH_ADVANCE'' AFTER advance_trigger_text',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_bank_name'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE order_lc_details
     ADD COLUMN lc_bank_name VARCHAR(150) NULL AFTER lc_file_id,
     ADD COLUMN lc_amount DECIMAL(14,2) NULL AFTER lc_bank_name,
     ADD COLUMN lc_charges_amount DECIMAL(14,2) NULL AFTER lc_amount,
     ADD COLUMN lc_confirmed_at TIMESTAMP NULL AFTER lc_charges_amount,
     ADD COLUMN lc_confirmed_by BIGINT UNSIGNED NULL AFTER lc_confirmed_at,
     ADD CONSTRAINT fk_order_lc_details_confirmed_by FOREIGN KEY (lc_confirmed_by) REFERENCES users(id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The third real-world payment option the business offers (apart from
-- the two 30/70 cash presets already seeded, nothing else is offered):
-- opening a Letter of Credit instead of wiring a cash advance. Added
-- only if a preset with this exact name doesn't already exist, so a
-- re-run never creates duplicates and an admin who already renamed/
-- adjusted it isn't overwritten.
INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, is_active)
SELECT 'Letter of Credit — 30/70', 0, 30.00, 'against Letter of Credit opened before production commences', 'LC',
       70.00, 'B_AGAINST_BL', 7,
       'Payable against scanned copy of Bill of Lading, within {days} Calendar Days of the date NexaCrest emails the scanned BL copy.',
       c.id, 1, 1
FROM currencies c
WHERE c.code = 'USD'
  AND NOT EXISTS (SELECT 1 FROM payment_presets WHERE preset_name = 'Letter of Credit — 30/70');
