-- 2026-11-12h — Prices and products fixed by the approved Quotation; the
-- "Products & prices" amendment
-- Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"; mockup approved).
--
-- What changes:
--   * When an order's first Quotation is approved, the order keeps the exchange rate
--     its prices were built with (orders.pricing_fx_rate_at_qt), the time and the
--     Quotation that fixed them (orders.prices_fixed_at, prices_fixed_by_document_id),
--     and each line keeps the price the buyer accepted
--     (order_products.qt_fixed_unit_price). From then on product, unit, size, pieces,
--     quantity, unit price and the lines themselves change only through an amendment
--     of type "Products & prices" (MD-approved, signed by the buyer, then activated).
--   * amendments gains amendment_type ('payment_terms' = every amendment so far,
--     'products'), products_totals_json (old / new totals and the payment effect),
--     activated_at and supplier_po_revision_needed; amendment_product_lines keeps the
--     old and the new figures of every line of a products amendment.
--   * orders gains buyer_po_amended_by_amendment_id / buyer_po_amended_at /
--     buyer_po_amended_by: the tick "The signed amendment amends the Buyer PO".
--   * A Procurement task kind "Revise the Supplier PO (products amendment)".
--   * EXISTING ORDERS: an order that already has an approved Quotation is fixed now:
--     prices_fixed_at = the approval time of its first approved Quotation (the last
--     reviewer's approval; the Quotation's generation time when no review row exists),
--     the rate = the rate fixed at its Proforma Invoice (pricing_fx_rate_at_pi) when it
--     has one, else the pricing rate in use when this migration runs (Manual: the typed
--     rate; Automatic: the newest rate in use less the cushion, as the app works it
--     out), and each active line's qt_fixed_unit_price = its unit price now. Lines
--     keep their price check figures as they are (fixed at the PI where issued; else
--     the app now works them out at the order's fixed rate).
--
-- Safe to run twice: every change is guarded through information_schema + PREPARE
-- (MySQL-compatible, no IF [NOT] EXISTS on columns), CREATE TABLE IF NOT EXISTS,
-- INSERT IGNORE, and the backfill only touches orders not fixed yet.
-- Run after 2026-11-12g, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12h-price-lock-and-products-amendment.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- orders
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN prices_fixed_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'prices_fixed_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN prices_fixed_by_document_id BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'prices_fixed_by_document_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN pricing_fx_rate_at_qt DECIMAL(12,4) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pricing_fx_rate_at_qt');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN buyer_po_amended_by_amendment_id BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_po_amended_by_amendment_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN buyer_po_amended_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_po_amended_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN buyer_po_amended_by BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_po_amended_by');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- order_products
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN qt_fixed_unit_price DECIMAL(14,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'qt_fixed_unit_price');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- amendments
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE amendments ADD COLUMN amendment_type ENUM(''payment_terms'',''products'') NOT NULL DEFAULT ''payment_terms''',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'amendments' AND COLUMN_NAME = 'amendment_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE amendments ADD COLUMN products_totals_json TEXT NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'amendments' AND COLUMN_NAME = 'products_totals_json');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE amendments ADD COLUMN activated_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'amendments' AND COLUMN_NAME = 'activated_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE amendments ADD COLUMN supplier_po_revision_needed TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'amendments' AND COLUMN_NAME = 'supplier_po_revision_needed');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS amendment_product_lines (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  amendment_id        BIGINT UNSIGNED NOT NULL,
  line_no             INT NOT NULL,
  order_product_id    BIGINT UNSIGNED NULL,
  source_line_id      BIGINT UNSIGNED NULL,
  change_kind         ENUM('none','change','add','remove') NOT NULL,
  description         VARCHAR(500) NOT NULL,
  unit                VARCHAR(20) NULL,
  old_length_cm       DECIMAL(7,2) NULL,
  old_width_cm        DECIMAL(7,2) NULL,
  old_thickness_cm    DECIMAL(7,2) NULL,
  old_pieces          INT UNSIGNED NULL,
  old_quantity        DECIMAL(14,3) NULL,
  old_unit_price      DECIMAL(14,2) NULL,
  old_line_total      DECIMAL(14,2) NULL,
  new_length_cm       DECIMAL(7,2) NULL,
  new_width_cm        DECIMAL(7,2) NULL,
  new_thickness_cm    DECIMAL(7,2) NULL,
  new_pieces          INT UNSIGNED NULL,
  new_quantity        DECIMAL(14,3) NULL,
  new_unit_price      DECIMAL(14,2) NULL,
  new_line_total      DECIMAL(14,2) NULL,
  price_lowered       TINYINT(1) NOT NULL DEFAULT 0,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_amendment_product_lines_amendment (amendment_id, line_no),
  CONSTRAINT fk_amendment_product_lines_amendment FOREIGN KEY (amendment_id) REFERENCES amendments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Procurement task
INSERT IGNORE INTO department_task_types (task_key, department_code, title, standard_days, escalate_after_days, sort_order) VALUES
  ('revise_supplier_po', 'PROC', 'Revise the Supplier PO (products amendment)', 2, 3, 45);

-- ---------------------------------------------------------------- existing orders
-- The pricing rate in use now: Manual = the typed rate; Automatic = newest rate in use × (1 − cushion %).
SET @fx_mode := (SELECT setting_value FROM pricing_settings WHERE setting_key = 'fx_mode' LIMIT 1);
SET @fx_manual := (SELECT CAST(setting_value AS DECIMAL(12,4)) FROM pricing_settings WHERE setting_key = 'pricing_fx_rate' LIMIT 1);
SET @fx_cushion := (SELECT LEAST(GREATEST(CAST(setting_value AS DECIMAL(6,3)), 0), 5) FROM pricing_settings WHERE setting_key = 'fx_cushion_pct' LIMIT 1);
SET @fx_market := (SELECT market_rate FROM fx_rates WHERE status = 'in_use' AND market_rate IS NOT NULL ORDER BY rate_date DESC, id DESC LIMIT 1);
SET @fx_now := IF(COALESCE(@fx_mode, 'auto') = 'manual' OR @fx_market IS NULL,
  COALESCE(@fx_manual, 82.0000),
  ROUND(@fx_market * (1 - COALESCE(@fx_cushion, 2.0) / 100), 4));

UPDATE orders o
  JOIN (
    SELECT d.order_id, MIN(d.id) AS document_id
      FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
     WHERE dt.code = 'QT' AND d.status IN ('approved', 'sent', 'superseded') AND d.order_id IS NOT NULL
     GROUP BY d.order_id
  ) q ON q.order_id = o.id
  JOIN documents qd ON qd.id = q.document_id
   SET o.prices_fixed_at = COALESCE(
         (SELECT MAX(r.reviewed_at) FROM document_reviews r WHERE r.document_id = q.document_id AND r.status = 'approved'),
         qd.generated_at),
       o.prices_fixed_by_document_id = q.document_id,
       o.pricing_fx_rate_at_qt = o.pricing_fx_rate_at_pi
 WHERE o.prices_fixed_at IS NULL;

-- Owner, 12 Nov 2026: an order not yet past its PI takes the pricing rate in use on the day its
-- Quotation was approved (the daily rate history, minus the cushion); only when no rate was fetched
-- on or before that day (or the rate is Manual) does it take the rate in use now.
UPDATE orders o
   SET o.pricing_fx_rate_at_qt = COALESCE(
         IF(COALESCE(@fx_mode, 'auto') = 'manual', NULL,
            (SELECT ROUND(f.market_rate * (1 - COALESCE(@fx_cushion, 2.0) / 100), 4)
               FROM fx_rates f
              WHERE f.status = 'in_use' AND f.market_rate IS NOT NULL AND f.rate_date <= DATE(o.prices_fixed_at)
              ORDER BY f.rate_date DESC, f.id DESC LIMIT 1)),
         @fx_now)
 WHERE o.prices_fixed_at IS NOT NULL AND o.pricing_fx_rate_at_qt IS NULL;

UPDATE order_products op JOIN orders o ON o.id = op.order_id
   SET op.qt_fixed_unit_price = op.unit_price
 WHERE o.prices_fixed_at IS NOT NULL AND op.is_active = 1 AND op.qt_fixed_unit_price IS NULL AND op.unit_price IS NOT NULL;
