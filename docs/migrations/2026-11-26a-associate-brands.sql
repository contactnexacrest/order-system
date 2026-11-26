-- 2026-11-26a — Owner decision D-431 (25–26 Nov 2026): Brands for divisions, associate side only.
--  - New tables associate_brands, associate_brand_settings, associate_brand_ladder_steps.
--  - New columns associates.brand_id and associate_registration_links.brand_id (empty = NexaCrest, exactly as before: nothing changes until a brand is added
--    in Admin › Brands and given to an associate).
--  - No data is added: there are no brands until the MD adds one.
-- PLAIN ALTER (owner D-371): the four ALTER statements come last; a second run stops there with
-- "Duplicate column name" / "Duplicate key name", which is harmless (CREATE TABLE IF NOT EXISTS before them).
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS associate_brands (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30) NOT NULL,                 -- in the brand's login address: /associate/login/{code}
  name        VARCHAR(80) NOT NULL,
  tagline     VARCHAR(200) NOT NULL,                -- e.g. "a division of NexaCrest International Private Limited"
  products    VARCHAR(200) NULL,                    -- what it covers, e.g. "Natural Stone" (shown to staff)
  website     VARCHAR(190) NULL,
  email       VARCHAR(190) NULL,                    -- shown to the brand's associates
  status      ENUM('active','planned','inactive') NOT NULL DEFAULT 'active',
  created_by  BIGINT UNSIGNED NULL,
  updated_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_associate_brands_code (code),
  UNIQUE KEY uq_associate_brands_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS associate_brand_settings (
  brand_id      BIGINT UNSIGNED NOT NULL,
  setting_key   VARCHAR(60) NOT NULL,
  setting_value VARCHAR(100) NOT NULL,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (brand_id, setting_key),
  CONSTRAINT fk_associate_brand_settings_brand FOREIGN KEY (brand_id) REFERENCES associate_brands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS associate_brand_ladder_steps (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  brand_id    BIGINT UNSIGNED NOT NULL,
  from_order  INT NOT NULL,
  to_order    INT NULL,                              -- NULL = the open-ended last step
  level_id    BIGINT UNSIGNED NOT NULL,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_associate_brand_ladder_from (brand_id, from_order),
  CONSTRAINT fk_associate_brand_ladder_brand FOREIGN KEY (brand_id) REFERENCES associate_brands(id),
  CONSTRAINT fk_associate_brand_ladder_level FOREIGN KEY (level_id) REFERENCES commission_levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE associates ADD COLUMN brand_id BIGINT UNSIGNED NULL;
ALTER TABLE associates ADD CONSTRAINT fk_associates_brand FOREIGN KEY (brand_id) REFERENCES associate_brands(id);
-- The brand an applicant joins through: chosen by the MD on the associate registration link.
ALTER TABLE associate_registration_links ADD COLUMN brand_id BIGINT UNSIGNED NULL;
ALTER TABLE associate_registration_links ADD CONSTRAINT fk_associate_registration_links_brand FOREIGN KEY (brand_id) REFERENCES associate_brands(id);
