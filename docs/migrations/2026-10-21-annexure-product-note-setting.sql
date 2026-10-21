-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: seed.sql company_settings addition (90-item backlog
-- "Annexure note configurability")
-- Covers item: Annexure note configurability (half of the bundled
-- "Annexure note configurability + order_reference display + ..." item
-- — order_reference display needed no DB change, only code/template)
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Pure DATA change — no ALTER TABLE, no new table, no new permission.
-- Adds one new company_settings row, annexure_product_note, holding
-- the exact wording that was previously hardcoded straight into the
-- Annexure A template's "Note:" banner (above the Product Specification
-- table). Fully editable afterward via the existing Settings screen
-- (/settings), same as bl_type_instruction/bl_consignee_instruction.
--
-- Idempotent (safe to re-run).
-- ================================================================

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'annexure_product_note',
       'Product images and drawings are for reference only and may show optional accessories or decorative elements not included in the quoted price. All binding specifications are as stated in each product section below. Actual product appearance may vary slightly due to the natural characteristics of granite.',
       'string', 'documents', 'Printed in the amber "Note:" banner above every Annexure A''s Product Specification section.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'annexure_product_note');
