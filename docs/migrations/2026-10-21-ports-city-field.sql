-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql ports.city column + seed.sql backfill
-- (90-item backlog "ports city field")
-- Covers item: ports city field
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Adds a nullable `city` column to `ports`, distinct from the existing
-- `name` (the specific terminal/port name, e.g. "Nhava Sheva (JNPT)")
-- and `country`. Incoterms convention prints the city alone (e.g.
-- "FOB Mumbai"), never the formal terminal name — DocumentDataAssembler/
-- AmendmentService (and their Node equivalents) now read ports.city for
-- every Incoterms-label-style field, falling back to `name` for any
-- port row that never gets a city filled in, so this is backward-
-- compatible with every existing port and order.
--
-- Backfills the one seeded Chennai row's city to 'Chennai' — if your
-- database has more port rows than that (added via the live /ports
-- admin screen before this column existed), their `city` stays NULL
-- until an admin fills it in via that same screen; they keep rendering
-- exactly as before (falling back to `name`) until then.
--
-- Idempotent (safe to re-run) — ADD COLUMN is guarded, and the backfill
-- only touches rows where city is still NULL.
-- ================================================================


SET NAMES utf8mb4;
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ports' AND COLUMN_NAME = 'city'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE ports ADD COLUMN city VARCHAR(100) NULL AFTER name', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE ports SET city = 'Chennai' WHERE name = 'Chennai, India' AND city IS NULL;
