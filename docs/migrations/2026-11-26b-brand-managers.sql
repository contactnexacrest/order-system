-- 2026-11-26b — Owner decision D-432 (26 Nov 2026): brand managers.
--  - New column users.brand_id: a staff member who looks after one brand (e.g. a StoneCrest manager) sees and changes only that
--    brand's associates and rules. Empty = all brands, exactly as before (every existing user stays empty).
--  - No data is added: the MD / Admin gives a person a brand in Admin › Users.
-- PLAIN ALTER (owner D-371): a second run stops with "Duplicate column name" / "Duplicate key name", which is harmless.
-- Needs 2026-11-26a first (the associate_brands table).
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
ALTER TABLE users ADD COLUMN brand_id BIGINT UNSIGNED NULL;
ALTER TABLE users ADD CONSTRAINT fk_users_brand FOREIGN KEY (brand_id) REFERENCES associate_brands(id);
