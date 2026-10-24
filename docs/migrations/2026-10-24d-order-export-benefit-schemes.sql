-- ================================================================
-- DB MIGRATION — 2026-10-24d
-- Government export-benefit scheme eligibility, always materialized per
-- order — the same "never a missing row standing in for not applicable"
-- fix as the Compliance Checklist (OrderComplianceTaskRepository), now
-- applied to RODTEP/Duty Drawback/RoSCTL/Other scheme eligibility.
--
-- `ca_export_benefits` itself is untouched — it correctly represents "a
-- real claim" (NOT NULL claimed_amount/claimed_at), and today a claim row
-- is only ever written when staff actually submit one. That leaves "never
-- eligible for this scheme" represented purely by the ABSENCE of a claim
-- row — indistinguishable from "forgotten" to any report run directly
-- against the table. order_export_benefit_schemes is a new, separate,
-- always-present per-order-per-scheme record of the eligibility decision
-- itself (pending / eligible / not_eligible), modeled exactly on
-- order_compliance_tasks: new orders get a row per seeded
-- export_benefit_scheme option going forward (see
-- OrderRepository::insertRow() / OrderExportBenefitSchemeRepository::
-- initializeForOrder()); this migration backfills every order that
-- existed before this fix, for the same reason the multi-assignee
-- migration backfilled existing orders' first assignee.
--
-- Run this ONCE against each production database this app uses (the PHP
-- stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run): CREATE TABLE is guarded by an
-- information_schema check, and the backfill INSERT only ever adds a
-- row that doesn't already exist (one per order+scheme pair).
-- ================================================================

SET NAMES utf8mb4;

-- 1. CREATE TABLE order_export_benefit_schemes — one row per order per
-- scheme name (matching dropdown_options('export_benefit_scheme')).
-- Composite primary key both enforces "no duplicate scheme row per
-- order" for free and makes the write a trivial single-row upsert, same
-- shape as order_compliance_tasks's own (order_id, task_type_id) pair.
SET @tbl_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_export_benefit_schemes'
);
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE order_export_benefit_schemes (
    order_id    BIGINT UNSIGNED NOT NULL,
    scheme_name VARCHAR(150) NOT NULL,
    status      ENUM(\'pending\',\'eligible\',\'not_eligible\') NOT NULL DEFAULT \'pending\',
    reason      VARCHAR(500) NULL,
    resolved_at TIMESTAMP NULL,
    resolved_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (order_id, scheme_name),
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
  ) ENGINE=InnoDB',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Backfill every order that existed before this fix with a 'pending'
-- row for every currently-active export_benefit_scheme option, so no
-- order already in play is left with the old "missing row" gap.
INSERT INTO order_export_benefit_schemes (order_id, scheme_name)
SELECT o.id, d.option_value
FROM orders o
JOIN dropdown_options d ON d.list_key = 'export_benefit_scheme' AND d.is_active = 1
WHERE NOT EXISTS (
  SELECT 1 FROM order_export_benefit_schemes oebs
  WHERE oebs.order_id = o.id AND oebs.scheme_name = d.option_value
);

-- 3. Same backfill for order_compliance_tasks — OrderRepository::
-- insertRow() now auto-creates these at order creation (schema already
-- supports it, no column change needed there), but any order created
-- before that fix still has the old "missing row" gap for its own
-- active task types.
INSERT INTO order_compliance_tasks (order_id, task_type_id, status)
SELECT o.id, t.id, 'not_started'
FROM orders o
JOIN compliance_task_types t ON t.is_active = 1
WHERE NOT EXISTS (
  SELECT 1 FROM order_compliance_tasks oct
  WHERE oct.order_id = o.id AND oct.task_type_id = t.id
);
