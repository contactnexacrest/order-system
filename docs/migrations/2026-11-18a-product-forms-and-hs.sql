-- 2026-11-18a — Owner decisions D-382 … D-385 and D-389 (18 Nov 2026, mockups approved, plan confirmed twice):
-- forms of stone and their units, the specification list that sets the HS code, the finishes each form
-- offers, a product's "Sold as" ticks, an order line's Sold as with its locked HS code, and Monument first
-- in Size / format.
--  * New tables product_forms (8 forms), hs_specifications (10 specifications, each with its HS code),
--    product_hs_specifications (a product's Sold as ticks — mapped once from today's catalog_products.forms:
--    block -> Raw block + Sawn block; slab -> Gangsaw slab + Cut-to-size slab + Monument slab; tile -> Tile;
--    kerb / step / cobble -> Kerbs, setts, cobbles; a product with no forms gets no ticks = every
--    specification offered, as "no forms = all forms" today) and surface_finish_forms (which finishes a
--    form offers; no rows for a form = every finish).
--    CREATE TABLE IF NOT EXISTS: a second run changes nothing.
--  * Settings › Lists › surface_finish gets "Roughly trimmed" and "Sawn", each with its linked Surface finish
--    option at no price change (the D-357 way). Roughly trimmed is offered for Raw block only, Sawn for Sawn
--    block only, every other finish for every form that is not a block.
--  * HS Code Master 68022310: "Granite blocks or tiles, polished" -> "Granite blocks or tiles".
--  * New permission "Change a line's HS code from its specification, with a reason" (override_line_hs_code)
--    for the MD, Founder & MD, ED and Founder & ED.
--  * Every category "Size / format" specification: "Monument (cut to size)" moves first (it to 0, the
--    others +1; only while it is not first yet, so a second run moves nothing).
--  * order_products.hs_code default '6802.93' -> '68029300' (same type and NULL rule; lines keep their codes);
--    new empty columns order_products.hs_spec_id and hs_override_reason. Lines already saved keep what they
--    have and print as today.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): phpMyAdmin on the shared host refuses the information_schema +
-- PREPARE guard. A second run only stops with "#1060 Duplicate column name", which is harmless — the
-- column ALTERs come last, after the tables, rows and updates.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS product_forms (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40) NOT NULL,
  name           VARCHAR(80) NOT NULL,
  units          VARCHAR(100) NOT NULL,
  default_unit   VARCHAR(10) NOT NULL,
  spec_group     VARCHAR(20) NOT NULL,
  default_finish VARCHAR(80) NULL,
  sort_order     INT NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  updated_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_forms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hs_specifications (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  form_id      BIGINT UNSIGNED NOT NULL,
  usual_finish VARCHAR(80) NULL,
  hs_code      VARCHAR(20) NOT NULL,       -- 6 or 8 digits, no dots (checked in the app); same length as every other hs_code column
  when_to_use  VARCHAR(255) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  updated_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hs_specifications_form (form_id),
  CONSTRAINT fk_hs_specifications_form FOREIGN KEY (form_id) REFERENCES product_forms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS product_hs_specifications (
  product_id BIGINT UNSIGNED NOT NULL,
  hs_spec_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, hs_spec_id),
  KEY idx_product_hs_specifications_spec (hs_spec_id),
  CONSTRAINT fk_product_hs_specifications_product FOREIGN KEY (product_id) REFERENCES catalog_products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_hs_specifications_spec FOREIGN KEY (hs_spec_id) REFERENCES hs_specifications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS surface_finish_forms (
  dropdown_option_id BIGINT UNSIGNED NOT NULL,
  form_id            BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (dropdown_option_id, form_id),
  KEY idx_surface_finish_forms_form (form_id),
  CONSTRAINT fk_surface_finish_forms_form FOREIGN KEY (form_id) REFERENCES product_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The forms of stone (name · units · starts on · group · default finish).
INSERT INTO product_forms (code, name, units, default_unit, spec_group, default_finish, sort_order, is_active)
SELECT f.code, f.name, f.units, f.default_unit, f.spec_group, f.default_finish, f.sort_order, 1 FROM (
  SELECT 'monument_slab' AS code, 'Monument slab' AS name, 'PCS,M2,SQFT,CBM' AS units, 'PCS' AS default_unit, 'slab' AS spec_group, 'Natural Polish' AS default_finish, 1 AS sort_order
  UNION ALL SELECT 'gangsaw_slab', 'Gangsaw slab', 'M2,SQFT,CBM', 'M2', 'slab', 'Natural Polish', 2
  UNION ALL SELECT 'cut_to_size_slab', 'Cut-to-size slab', 'M2,SQFT,CBM,PCS', 'M2', 'slab', 'Natural Polish', 3
  UNION ALL SELECT 'raw_block', 'Raw block', 'CBM,TON', 'CBM', 'block', 'Roughly trimmed', 4
  UNION ALL SELECT 'sawn_block', 'Sawn block', 'CBM,TON', 'CBM', 'block', 'Sawn', 5
  UNION ALL SELECT 'tile', 'Tile', 'M2,SQFT,PCS', 'M2', 'tile', 'Natural Polish', 6
  UNION ALL SELECT 'paving_stone', 'Paving stone', 'M2,SQFT,PCS', 'M2', 'slab', 'Flamed', 7
  UNION ALL SELECT 'kerbs_setts_cobbles', 'Kerbs, setts, cobbles', 'PCS,M2,RMT', 'PCS', 'kerb', 'Flamed', 8
) f WHERE NOT EXISTS (SELECT 1 FROM product_forms x WHERE x.code = f.code);

-- The specification list (name · form · usual finish · HS code · when to use).
INSERT INTO hs_specifications (name, form_id, usual_finish, hs_code, when_to_use, sort_order, is_active)
SELECT s.name, f.id, s.usual_finish, s.hs_code, s.when_to_use, s.sort_order, 1 FROM (
  SELECT 'Raw block' AS name, 'raw_block' AS form_code, 'Roughly trimmed' AS usual_finish, '25161100' AS hs_code, 'As it comes from the quarry' AS when_to_use, 1 AS sort_order
  UNION ALL SELECT 'Sawn block', 'sawn_block', 'Sawn', '25161200', 'Sawn / cut to size on all sides', 2
  UNION ALL SELECT 'Gangsaw slab', 'gangsaw_slab', 'Natural Polish', '68022310', NULL, 3
  UNION ALL SELECT 'Random slab — irregular', 'gangsaw_slab', 'Natural Polish', '68022390', NULL, 4
  UNION ALL SELECT 'Cut-to-size slab', 'cut_to_size_slab', 'Natural Polish', '68022310', NULL, 5
  UNION ALL SELECT 'Tile', 'tile', 'Natural Polish', '68022310', NULL, 6
  UNION ALL SELECT 'Monument slab', 'monument_slab', 'Natural Polish', '68029300', NULL, 7
  UNION ALL SELECT 'Monument — complete set / tombstone', 'monument_slab', 'Natural Polish', '68029300', NULL, 8
  UNION ALL SELECT 'Paving stone', 'paving_stone', 'Flamed', '68010000', NULL, 9
  UNION ALL SELECT 'Kerbs, setts, cobbles', 'kerbs_setts_cobbles', 'Flamed', '68010000', NULL, 10
) s JOIN product_forms f ON f.code = s.form_code
 WHERE NOT EXISTS (SELECT 1 FROM hs_specifications x WHERE x.name = s.name);

-- A product's Sold as ticks, once, from today's forms ticks (a product that already has ticks is left alone).
INSERT INTO product_hs_specifications (product_id, hs_spec_id)
SELECT p.id, s.id FROM catalog_products p
  JOIN hs_specifications s ON (
       (FIND_IN_SET('block', REPLACE(p.forms, ' ', '')) > 0 AND s.name IN ('Raw block', 'Sawn block'))
    OR (FIND_IN_SET('slab', REPLACE(p.forms, ' ', '')) > 0 AND s.name IN ('Gangsaw slab', 'Cut-to-size slab', 'Monument slab'))
    OR (FIND_IN_SET('tile', REPLACE(p.forms, ' ', '')) > 0 AND s.name = 'Tile')
    OR ((FIND_IN_SET('kerb', REPLACE(p.forms, ' ', '')) > 0 OR FIND_IN_SET('step', REPLACE(p.forms, ' ', '')) > 0
         OR FIND_IN_SET('cobble', REPLACE(p.forms, ' ', '')) > 0) AND s.name = 'Kerbs, setts, cobbles'))
 WHERE p.forms IS NOT NULL AND TRIM(p.forms) <> ''
   AND NOT EXISTS (SELECT 1 FROM product_hs_specifications x WHERE x.product_id = p.id);

-- Two new finishes in Settings › Lists › surface_finish, after the others.
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'surface_finish', n.v, n.o + COALESCE((SELECT MAX(d.sort_order) FROM dropdown_options d WHERE d.list_key = 'surface_finish'), 0), 0, 1 FROM (
  SELECT 'Roughly trimmed' AS v, 1 AS o UNION ALL SELECT 'Sawn', 2
) n WHERE NOT EXISTS (SELECT 1 FROM dropdown_options x WHERE x.list_key = 'surface_finish' AND x.option_value = n.v);
-- Their Surface finish options at no price change in every linked category specification (D-357).
INSERT INTO spec_options (spec_id, product_id, name, is_base, change_type, value, thickness_mm, sort_order, is_active, dropdown_option_id)
SELECT s.id, NULL, LEFT(d.option_value, 80), 0, 'none', NULL, NULL, d.sort_order, d.is_active, d.id
  FROM spec_definitions s JOIN dropdown_options d ON d.list_key = 'surface_finish'
 WHERE s.list_key = 'surface_finish' AND s.product_id IS NULL AND d.option_value IN ('Roughly trimmed', 'Sawn')
   AND NOT EXISTS (SELECT 1 FROM spec_options x WHERE x.spec_id = s.id AND x.product_id IS NULL AND x.dropdown_option_id = d.id);

-- Which finishes each form offers (only while the table is still empty, so the owner's later ticks stay).
SET @sff_empty := (SELECT COUNT(*) = 0 FROM surface_finish_forms);
INSERT INTO surface_finish_forms (dropdown_option_id, form_id)
SELECT d.id, f.id FROM dropdown_options d CROSS JOIN product_forms f
 WHERE @sff_empty = 1 AND d.list_key = 'surface_finish'
   AND ((d.option_value = 'Roughly trimmed' AND f.code = 'raw_block')
     OR (d.option_value = 'Sawn' AND f.code = 'sawn_block')
     OR (d.option_value NOT IN ('Roughly trimmed', 'Sawn') AND f.spec_group <> 'block'));

-- HS Code Master: 68022310 also covers unpolished tiles and blocks.
UPDATE hs_codes SET description = 'Granite blocks or tiles' WHERE code = '68022310' AND description = 'Granite blocks or tiles, polished';

-- The permission to change a line's HS code from its specification (MD and ED).
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'override_line_hs_code', 'Change a line''s HS code from its specification, with a reason', 'On an order line with a Sold as, type a different HS code from the HS Code Master instead of the one the specification sets, with a reason; audited (D-385).', 'catalog'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'override_line_hs_code');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'override_line_hs_code'
 WHERE r.name IN ('Managing Director', 'Founder & Managing Director', 'Executive Director', 'Founder & Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- D-389: "Monument (cut to size)" first in every category "Size / format" specification (only while it is not first).
UPDATE spec_options o
  JOIN spec_definitions s ON s.id = o.spec_id AND s.product_id IS NULL AND s.name = 'Size / format'
  JOIN spec_options m ON m.spec_id = s.id AND m.product_id IS NULL AND m.name = 'Monument (cut to size)' AND m.sort_order <> 0
   SET o.sort_order = o.sort_order + 1
 WHERE o.product_id IS NULL AND o.id <> m.id;
UPDATE spec_options o
  JOIN spec_definitions s ON s.id = o.spec_id AND s.product_id IS NULL AND s.name = 'Size / format'
   SET o.sort_order = 0
 WHERE o.product_id IS NULL AND o.name = 'Monument (cut to size)' AND o.sort_order <> 0;

-- order_products: the new default HS code (a MODIFY can run twice), then the two new columns.
ALTER TABLE order_products MODIFY hs_code VARCHAR(20) NOT NULL DEFAULT '68029300';
ALTER TABLE order_products ADD COLUMN hs_spec_id BIGINT UNSIGNED NULL;
ALTER TABLE order_products ADD COLUMN hs_override_reason VARCHAR(500) NULL;
