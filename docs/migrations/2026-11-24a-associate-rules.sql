-- 2026-11-24a — Owner decision D-410 (24 Nov 2026, confirm 1 + confirm 2): new associate commission rules.
--  - Every order of the associate's client earns while the agreement is active, when its FOB is at least the minimum
--    (incentive_settings.associate_counting_threshold_inr, ₹5,00,000 as today); the client's first order always earns.
--  - Orders of the same client within N days are one ladder step: incentive_settings.associate_group_days = 15.
--  - The new rules apply to orders whose Proforma Invoice is issued from today on: incentive_settings.associate_rules_from
--    = the day this file runs. Orders with an earlier PI keep the old rule (owner choice 2A).
--  - What the client's price includes: pricing_settings.associate_price_rule = 'fixed', associate_price_fixed_pct = 5.00;
--    per-associate choice: associates.price_rule_override / price_rule_fixed_pct; the % fixed on an order at its PI:
--    orders.associate_included_pct.
-- Staff prices and prices for clients without an associate do not change.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): the settings INSERTs (IGNORE, safe to repeat) come first, then the ALTERs;
-- a second run stops at the first ALTER with "#1060 Duplicate column name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT IGNORE INTO pricing_settings (setting_key, setting_value) VALUES
  ('associate_price_rule', 'fixed'),
  ('associate_price_fixed_pct', '5.00');
INSERT IGNORE INTO incentive_settings (setting_key, setting_value) VALUES
  ('associate_group_days', '15'),
  ('associate_rules_from', DATE_FORMAT(CURDATE(), '%Y-%m-%d'));
ALTER TABLE associates ADD COLUMN price_rule_override VARCHAR(10) NULL;
ALTER TABLE associates ADD COLUMN price_rule_fixed_pct DECIMAL(5,2) NULL;
ALTER TABLE orders ADD COLUMN associate_included_pct DECIMAL(5,2) NULL;
