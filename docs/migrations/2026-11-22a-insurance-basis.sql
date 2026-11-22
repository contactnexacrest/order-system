-- 2026-11-22a — Owner decision D-405 (22 Nov 2026): the indicative insurance of a CIF order is recalculated whenever
-- the products or the freight change (until the actual premium is billed by the Freight Debit Note).
-- One new column orders.insurance_basis: what the insurance figure was worked out from (FOB value | freight |
-- product categories). Existing orders have none yet: the app records it the next time the order is opened, so
-- their saved insurance figures are kept until the products or the freight next change.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): a second run stops with "#1060 Duplicate column name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
ALTER TABLE orders ADD COLUMN insurance_basis VARCHAR(255) NULL;
