-- ================================================================
-- DB MIGRATION — 2026-10-25
-- New default order estimates (owner request): Total CBM 8.2 m³,
-- Gross Weight 27000 kg, Net Weight 26000 kg — a typical full container
-- for this business, replacing 12.5 / 2400 / 2100. These pre-fill the
-- New Order form, and now also the Edit Order form for an order with no
-- estimate saved (instead of an "e.g." placeholder).
--
-- docs/seed.sql (both stacks) is updated separately so a fresh database
-- is seeded with the new values; this migration is only for a database
-- that already seeded the old ones.
--
-- Only rows still holding the OLD seeded default are changed — a value
-- an Admin has already customised in Settings is left exactly as it is.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run): once applied, no row matches the old
-- value any more, so every subsequent run is a no-op.
-- ================================================================

SET NAMES utf8mb4;

UPDATE company_settings SET setting_value = '8.2',
       description = 'Pre-fills Total CBM (m³) on the New Order form, and on Edit Order for an order with none saved — staff edit per order as always.'
WHERE setting_key = 'default_estimated_total_cbm' AND setting_value = '12.5';

UPDATE company_settings SET setting_value = '27000',
       description = 'Pre-fills Gross Weight (kg) on the New Order form, and on Edit Order for an order with none saved — staff edit per order as always.'
WHERE setting_key = 'default_estimated_gross_weight_kg' AND setting_value = '2400';

UPDATE company_settings SET setting_value = '26000',
       description = 'Pre-fills Net Weight (kg) on the New Order form, and on Edit Order for an order with none saved — staff edit per order as always.'
WHERE setting_key = 'default_estimated_net_weight_kg' AND setting_value = '2100';
