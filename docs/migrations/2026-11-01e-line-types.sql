-- Owner decision D-215 (2026-11-01, confirmed twice): an order line is Goods,
-- Service or Charge. Goods lines work exactly as today. A service line has a
-- SAC code (the GST code for services) instead of an HS code and is invoiced
-- on its own Export Service Invoice; the goods FOB value — and with it the
-- advance, balance, Commercial Invoice and CAFIN — counts goods and charges
-- only. Every existing line is goods. Columns only; safe to run twice.

SET NAMES utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_products' AND column_name = 'line_type') = 0,
  "ALTER TABLE order_products ADD COLUMN line_type ENUM('goods','service','charge') NOT NULL DEFAULT 'goods' AFTER line_no", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_products' AND column_name = 'sac_code') = 0,
  'ALTER TABLE order_products ADD COLUMN sac_code VARCHAR(10) NULL AFTER hs_code', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
