-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: seed.sql company_settings addition (90-item backlog
-- "default weight/volume values")
-- Covers item: default weight/volume values
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Pure DATA change — no ALTER TABLE, no new table, no new permission.
-- Adds three new company_settings rows (category 'order_defaults'):
--   default_estimated_total_cbm, default_estimated_gross_weight_kg,
--   default_estimated_net_weight_kg
-- These pre-fill the New Order form's Total CBM/Gross Weight/Net Weight
-- fields instead of starting blank; staff still edit the value per
-- order as always, and editable afterward like any other setting via
-- the existing Settings screen (/settings). Blanking a setting's value
-- there simply leaves that field blank on the form again, same as
-- before this migration.
--
-- Idempotent (safe to re-run).
-- ================================================================


SET NAMES utf8mb4;
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'default_estimated_total_cbm', '12.5', 'number', 'order_defaults', 'Pre-fills Total CBM (m³) on the New Order form — staff edit per order as always.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'default_estimated_total_cbm');

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'default_estimated_gross_weight_kg', '2400', 'number', 'order_defaults', 'Pre-fills Gross Weight (kg) on the New Order form — staff edit per order as always.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'default_estimated_gross_weight_kg');

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'default_estimated_net_weight_kg', '2100', 'number', 'order_defaults', 'Pre-fills Net Weight (kg) on the New Order form — staff edit per order as always.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'default_estimated_net_weight_kg');
