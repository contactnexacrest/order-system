-- 2026-11-21a — Owner decision D-402 (21 Nov 2026, approved "approve A"): calculated marine insurance on CIF orders.
-- (1) Two new product-category columns: insurance_rate_pct (the category's premium rate, % of the sum insured,
--     incl. the breakage clause) and insurance_cover_wording (how the cover is named on the Quotation / PI).
-- (2) The system category (Natural Stone) gets 0.30 % and "ICC (A) incl. breakage"; every other category
--     stays empty and uses the default rate in Admin › Global pricing settings › Marine insurance (0.20 %).
-- (3) Six settings in Admin › Global pricing settings › Marine Insurance (pricing_settings, INSERT IGNORE): cover 110 %,
--     default rate 0.20 %, default cover wording "ICC (A) all risks", no minimum premium, rounding up, GST not added.
-- Existing orders keep their saved insurance figures.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): the settings INSERT (IGNORE, safe to repeat) comes first, then the ALTER, then the
-- UPDATE that needs the new columns; a second run stops at the ALTER with "#1060 Duplicate column name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT IGNORE INTO pricing_settings (setting_key, setting_value) VALUES
  ('insurance_cover_pct', '110.00'),
  ('insurance_rate_pct', '0.2000'),
  ('insurance_cover_wording', 'ICC (A) all risks'),
  ('insurance_min_premium', ''),
  ('insurance_rounding', 'up'),
  ('insurance_add_gst', '0');
ALTER TABLE product_categories
  ADD COLUMN insurance_rate_pct DECIMAL(7,4) NULL,
  ADD COLUMN insurance_cover_wording VARCHAR(80) NULL;
UPDATE product_categories
   SET insurance_rate_pct = 0.3000, insurance_cover_wording = 'ICC (A) incl. breakage'
 WHERE is_system = 1 AND insurance_rate_pct IS NULL;
