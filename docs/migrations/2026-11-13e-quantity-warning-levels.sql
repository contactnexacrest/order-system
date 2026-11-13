-- 2026-11-13e — Large-quantity levels per unit (owner decision item 10a, 13 Nov 2026)
-- "Go for the two-level version": one order line (export order create / add line / edit line,
-- and a Domestic Trade line) is refused above 1,000,000 in any unit (in the app, no table
-- change), and above about 20 containers of its unit it saves only with "I confirm this
-- quantity" ticked (logged as LARGE_QUANTITY_CONFIRMED). The six levels are settings that
-- Admin / MD change in Settings › Orders:
--   SQM 30,000 · SQFT 300,000 · CBM 500 · TON 600 · PCS 600,000 · RMT 60,000
-- (SQM also covers M2, TON also MT, PCS also NOS; other units have only the hard block).
--
-- Adds the six company_settings rows only when missing — a level already changed is kept.
-- No schema change; MySQL-safe (plain INSERT … SELECT … WHERE NOT EXISTS). Safe to run twice.
-- A fresh install (schema.sql + seed.sql) already has these rows. Run after 2026-11-13d,
-- after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13e-quantity-warning-levels.sql
SET NAMES utf8mb4;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin)
SELECT x.k, x.v, 'number', 'orders', x.d, 0, 0 FROM (
SELECT 'quantity_warning_level_sqm' AS k, '30000' AS v, 'One order line above this many SQM or M2 (about 20 containers) saves only with "I confirm this quantity" ticked; the confirmation is logged. Any line above 1,000,000 is refused in every unit. 1 to 1,000,000; default 30,000.' AS d
  UNION ALL SELECT 'quantity_warning_level_sqft', '300000', 'One order line above this many SQFT (about 20 containers) saves only with "I confirm this quantity" ticked; the confirmation is logged. Any line above 1,000,000 is refused in every unit. 1 to 1,000,000; default 300,000.'
  UNION ALL SELECT 'quantity_warning_level_cbm', '500', 'One order line above this many CBM (about 20 containers) saves only with "I confirm this quantity" ticked; the confirmation is logged. Any line above 1,000,000 is refused in every unit. 1 to 1,000,000; default 500.'
  UNION ALL SELECT 'quantity_warning_level_ton', '600', 'One order line above this many TON or MT (about 20 containers) saves only with "I confirm this quantity" ticked; the confirmation is logged. Any line above 1,000,000 is refused in every unit. 1 to 1,000,000; default 600.'
  UNION ALL SELECT 'quantity_warning_level_pcs', '600000', 'One order line above this many PCS or NOS (about 20 containers) saves only with "I confirm this quantity" ticked; the confirmation is logged. Any line above 1,000,000 is refused in every unit. 1 to 1,000,000; default 600,000.'
  UNION ALL SELECT 'quantity_warning_level_rmt', '60000', 'One order line above this many RMT (about 20 containers) saves only with "I confirm this quantity" ticked; the confirmation is logged. Any line above 1,000,000 is refused in every unit. 1 to 1,000,000; default 60,000.'
) x WHERE NOT EXISTS (SELECT 1 FROM company_settings c WHERE c.setting_key = x.k);

SELECT CONCAT('2026-11-13e done: ', COUNT(*), ' of 6 large-quantity levels present.') AS result
  FROM company_settings
 WHERE setting_key IN ('quantity_warning_level_sqm', 'quantity_warning_level_sqft', 'quantity_warning_level_cbm',
                       'quantity_warning_level_ton', 'quantity_warning_level_pcs', 'quantity_warning_level_rmt');
