-- 2026-11-12j — One list of surface-finish names
-- Owner decision D-357 (12 Nov 2026, owner "confirm 1" and "confirm 2"; mockup approved).
--
-- Before: two separate lists. Settings › Lists › surface_finish (dropdown_options) fed the
-- order line's "More › finish" box, the Supplier PO and the buyer's quotation form; Pricing ›
-- Specifications › Surface finish (spec_options) fed "Pick from Products" with the price.
-- A rename in Settings never reached the picker or the order lines.
--
-- After: Settings › Lists › surface_finish is the ONE place to name a finish (name, sort
-- order, default, on/off); Pricing › Specifications keeps the price only. The link:
--   * spec_definitions.list_key = 'surface_finish' marks the Surface finish specification
--     (only that one — no other specification or list is linked);
--   * spec_options.dropdown_option_id = the Settings row the option is.
--
-- The one-time merge (mockup section 4):
--   * The base finish — Settings "Polished", or "Mirror Finish" (the owner renamed it on the
--     live site), or the row that is the default / sort order 1 when it is not one of the
--     other known names — becomes "Natural Polish", the default; the specification's base
--     option ("Polished") becomes "Natural Polish" too, still the base.
--   * Honed and Flamed are matched by name.
--   * Settings "Natural/Sandblasted" becomes "Sandblasted"; "Leather/Satin" becomes "Leather".
--   * Pricing "Brushed" is added to Settings.
--   * Settings "Rock-Pitched" and "Bush-Hammered" get an option with no price change in each
--     category that has the Surface finish specification.
--   * Any other Settings value (typed differently by the owner) is left as it is and stays
--     active; it also gets an option with no price change. Any other Pricing option is added
--     to Settings under its own name (with its price) so every option is linked.
--   * Order: Natural Polish 1, Honed 2, Flamed 3, Sandblasted 4, Leather 5, Brushed 6,
--     Rock-Pitched 7, Bush-Hammered 8; other values follow in their old order.
--   * Prices do not change. Each linked option is on/off as its Settings row is.
--   * Order lines: on orders whose first Quotation is NOT approved yet (orders.prices_fixed_at
--     IS NULL — the D-342 rule) a finish "Polished", "Mirror Finish", "Natural/Sandblasted" or
--     "Leather/Satin" becomes "Natural Polish", "Natural Polish", "Sandblasted", "Leather".
--     Orders with an approved Quotation or later, and every document already issued, keep
--     the old name.
--
-- Safe to run twice: columns and the index through information_schema + PREPARE (MySQL-safe,
-- no IF [NOT] EXISTS on columns or indexes); every update and insert finds nothing left to do
-- on the second run. A fresh install (schema.sql + seed.sql) already has the end state.
-- Run after 2026-11-12i, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12j-surface-finish-one-list.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- columns
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE spec_definitions ADD COLUMN list_key VARCHAR(100) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'spec_definitions' AND COLUMN_NAME = 'list_key');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE spec_options ADD COLUMN dropdown_option_id BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'spec_options' AND COLUMN_NAME = 'dropdown_option_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE spec_options ADD KEY idx_spec_options_dropdown_option (dropdown_option_id)',
  'SELECT 1') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'spec_options' AND INDEX_NAME = 'idx_spec_options_dropdown_option');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- the Surface finish specification
UPDATE spec_definitions SET list_key = 'surface_finish'
 WHERE product_id IS NULL AND list_key IS NULL
   AND (LOWER(TRIM(name)) LIKE '%surface%finish%' OR LOWER(TRIM(name)) = 'finish');

-- ---------------------------------------------------------------- Settings: the base finish
-- The base row: by name first ("Natural Polish", "Polished", "Mirror Finish"), else the default
-- row, else the row at sort order 1 — never a row that carries another known finish name.
SET @base_dd := (SELECT id FROM dropdown_options
  WHERE list_key = 'surface_finish' AND option_value IN ('Natural Polish', 'Polished', 'Mirror Finish')
  ORDER BY (option_value = 'Natural Polish') DESC, is_default DESC, sort_order, id LIMIT 1);
SET @base_dd := COALESCE(@base_dd, (SELECT id FROM dropdown_options
  WHERE list_key = 'surface_finish' AND (is_default = 1 OR sort_order = 1)
    AND option_value NOT IN ('Honed', 'Flamed', 'Sandblasted', 'Natural/Sandblasted', 'Leather', 'Leather/Satin',
                             'Brushed', 'Rock-Pitched', 'Bush-Hammered')
  ORDER BY is_default DESC, sort_order, id LIMIT 1));
-- No row at all: add "Natural Polish".
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'surface_finish', 'Natural Polish', 1, 1, 1 FROM DUAL
 WHERE @base_dd IS NULL AND NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Natural Polish');
SET @base_dd := COALESCE(@base_dd, (SELECT id FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Natural Polish' ORDER BY id LIMIT 1));

UPDATE dropdown_options SET option_value = 'Natural Polish' WHERE id = @base_dd AND BINARY option_value <> BINARY 'Natural Polish';
UPDATE dropdown_options SET is_default = 0 WHERE list_key = 'surface_finish' AND id <> @base_dd AND is_default <> 0;
UPDATE dropdown_options SET is_default = 1 WHERE id = @base_dd AND is_default <> 1;

-- ---------------------------------------------------------------- Settings: merged names
-- (When Settings already has the new name, the old row is left as it is.)
SET @has := (SELECT COUNT(*) FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Sandblasted');
UPDATE dropdown_options SET option_value = 'Sandblasted'
 WHERE list_key = 'surface_finish' AND option_value = 'Natural/Sandblasted' AND @has = 0;
SET @has := (SELECT COUNT(*) FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Leather');
UPDATE dropdown_options SET option_value = 'Leather'
 WHERE list_key = 'surface_finish' AND option_value = 'Leather/Satin' AND @has = 0;

-- Every Pricing option of the Surface finish specification that Settings does not have yet
-- (e.g. "Brushed") is added to Settings under its own name, on/off as the option is.
-- The base option maps to "Natural Polish" and is never added.
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'surface_finish', o.name, 100 + MIN(o.sort_order), 0, MAX(o.is_active)
  FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.list_key = 'surface_finish'
 WHERE o.product_id IS NULL AND o.is_base = 0 AND o.dropdown_option_id IS NULL
   AND o.name NOT IN ('Polished', 'Mirror Finish', 'Natural Polish')
   AND NOT EXISTS (SELECT 1 FROM dropdown_options x WHERE x.list_key = 'surface_finish' AND x.option_value = o.name)
 GROUP BY o.name;

-- The order of mockup section 4; any other value follows in its old order.
UPDATE dropdown_options SET sort_order = CASE option_value
    WHEN 'Natural Polish' THEN 1 WHEN 'Honed' THEN 2 WHEN 'Flamed' THEN 3 WHEN 'Sandblasted' THEN 4
    WHEN 'Leather' THEN 5 WHEN 'Brushed' THEN 6 WHEN 'Rock-Pitched' THEN 7 WHEN 'Bush-Hammered' THEN 8 END
 WHERE list_key = 'surface_finish'
   AND option_value IN ('Natural Polish', 'Honed', 'Flamed', 'Sandblasted', 'Leather', 'Brushed', 'Rock-Pitched', 'Bush-Hammered')
   AND sort_order <> CASE option_value
    WHEN 'Natural Polish' THEN 1 WHEN 'Honed' THEN 2 WHEN 'Flamed' THEN 3 WHEN 'Sandblasted' THEN 4
    WHEN 'Leather' THEN 5 WHEN 'Brushed' THEN 6 WHEN 'Rock-Pitched' THEN 7 WHEN 'Bush-Hammered' THEN 8 END;
UPDATE dropdown_options SET sort_order = 9 + sort_order
 WHERE list_key = 'surface_finish' AND sort_order <= 8
   AND option_value NOT IN ('Natural Polish', 'Honed', 'Flamed', 'Sandblasted', 'Leather', 'Brushed', 'Rock-Pitched', 'Bush-Hammered');

-- ---------------------------------------------------------------- link Pricing options to Settings
-- The base option of each linked specification is "Natural Polish".
UPDATE spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.list_key = 'surface_finish'
  LEFT JOIN spec_options y ON y.spec_id = o.spec_id AND y.product_id IS NULL AND y.dropdown_option_id = @base_dd
   SET o.dropdown_option_id = @base_dd
 WHERE o.product_id IS NULL AND o.dropdown_option_id IS NULL AND o.is_base = 1 AND y.id IS NULL;
-- The others by name (the base by its old names too); a product's own option the same way.
UPDATE spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.list_key = 'surface_finish'
  JOIN dropdown_options d ON d.list_key = 'surface_finish'
   AND d.option_value = CASE WHEN o.name IN ('Polished', 'Mirror Finish') THEN 'Natural Polish'
                             WHEN o.name = 'Natural/Sandblasted' THEN 'Sandblasted'
                             WHEN o.name = 'Leather/Satin' THEN 'Leather' ELSE o.name END
  LEFT JOIN spec_options y ON y.spec_id = o.spec_id AND y.product_id IS NULL AND y.dropdown_option_id = d.id
   SET o.dropdown_option_id = d.id
 WHERE o.dropdown_option_id IS NULL AND (o.product_id IS NOT NULL OR y.id IS NULL);

-- A Settings row with no option yet in a linked specification gets one with no price change
-- (e.g. "Rock-Pitched", "Bush-Hammered").
INSERT INTO spec_options (spec_id, product_id, name, is_base, change_type, value, thickness_mm, sort_order, is_active, dropdown_option_id)
SELECT s.id, NULL, LEFT(d.option_value, 80), 0, 'none', NULL, NULL, d.sort_order, d.is_active, d.id
  FROM spec_definitions s JOIN dropdown_options d ON d.list_key = 'surface_finish'
 WHERE s.list_key = 'surface_finish' AND s.product_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM spec_options x WHERE x.spec_id = s.id AND x.product_id IS NULL AND x.dropdown_option_id = d.id);

-- Names and order follow Settings; each category option is on/off as its Settings row.
UPDATE spec_options o JOIN dropdown_options d ON d.id = o.dropdown_option_id
   SET o.name = LEFT(d.option_value, 80), o.sort_order = d.sort_order
 WHERE BINARY o.name <> BINARY LEFT(d.option_value, 80) OR o.sort_order <> d.sort_order;
UPDATE spec_options o JOIN dropdown_options d ON d.id = o.dropdown_option_id
   SET o.is_active = d.is_active
 WHERE o.product_id IS NULL AND o.is_active <> d.is_active;

-- ---------------------------------------------------------------- order lines on draft orders
UPDATE order_products op JOIN orders o ON o.id = op.order_id
   SET op.finish = CASE WHEN op.finish IN ('Polished', 'Mirror Finish') THEN 'Natural Polish'
                        WHEN op.finish = 'Natural/Sandblasted' THEN 'Sandblasted'
                        ELSE 'Leather' END
 WHERE o.prices_fixed_at IS NULL
   AND op.finish IN ('Polished', 'Mirror Finish', 'Natural/Sandblasted', 'Leather/Satin');
