-- 2026-11-14a — Owner decision D-374 (14 Nov 2026): each order line remembers the pricing rate (₹ per USD)
-- and the time its price was set, until the approved Quotation fixes the prices. Shown on the order page
-- under the price ("Priced at ₹92.37 per USD on 14 Nov 2026"), with "Use today's price" when today's
-- rate gives a different price. Existing lines start empty ("Rate not recorded").
-- PLAIN ALTER (owner D-371, 13 Nov 2026): phpMyAdmin on the shared host refuses the information_schema +
-- PREPARE guard. A second run only stops with "#1060 Duplicate column name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
ALTER TABLE order_products ADD COLUMN priced_fx_rate DECIMAL(12,4) NULL;
ALTER TABLE order_products ADD COLUMN priced_at DATETIME NULL;
