-- 2026-11-12i — "Monument (cut to size)" size option, and the owner's new defaults (owner, 12 Nov 2026)
--
-- Part 1 — Natural Stone › Size / format gets the option "Monument (cut to size)", price change 0 %
--   (the product's base price applies as typed). For thick pieces cut to an exact size (headstones,
--   bases, kerbs, 80–200 mm), sold per CBM. Added to the category's standard options only; a product
--   that uses its own option list adds it on its own Price options page.
--
-- Part 2 — the owner's defaults, the same as a fresh install now has (seed.sql):
--   * Settings › E-mail: Testing mode ON (e-mails to buyers and suppliers are held; staff, associate
--     and MUST e-mails still go out). IMPORTANT: switch it OFF on Settings › E-mail on go-live day.
--   * Team incentive program ON.
--   * Pricing: other costs to FOB 5.5 %, margin 15 %.
--   Part 2 OVERWRITES what is set on this database now. Skip it (delete those four statements)
--   if the live site already has the values it should have.
--
-- Safe to run twice.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------- Part 1
INSERT INTO spec_options (spec_id, name, is_base, change_type, value, thickness_mm, sort_order)
SELECT s.id, 'Monument (cut to size)', 0, 'none', NULL, NULL,
       COALESCE((SELECT MAX(o.sort_order) FROM spec_options o WHERE o.spec_id = s.id AND o.product_id IS NULL), 0) + 1
  FROM spec_definitions s
  JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1
 WHERE s.name = 'Size / format' AND s.product_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM spec_options x WHERE x.spec_id = s.id AND x.product_id IS NULL AND x.name = 'Monument (cut to size)');

-- ---------------------------------------------------------------- Part 2 (overwrites current values)
UPDATE company_settings  SET setting_value = '1'     WHERE setting_key = 'email_testing_mode';
UPDATE incentive_settings SET setting_value = '1'    WHERE setting_key = 'program_enabled';
UPDATE pricing_settings  SET setting_value = '5.50'  WHERE setting_key = 'other_cost_pct';
UPDATE pricing_settings  SET setting_value = '15.00' WHERE setting_key = 'margin_pct';
