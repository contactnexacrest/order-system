-- Phase 3 item 16: lead-time days-vs-weeks display setting. Adds one new
-- company_settings row; no schema (column/table) change. Apply to both
-- the PHP and Node MySQL databases — same schema.


SET NAMES utf8mb4;
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin)
SELECT 'lead_time_display_unit', 'days', 'string', 'orders',
       '90-item backlog item 16: whether the Production/Sailing Lead Time ranges on the Order Detail page show in days (default) or weeks. Display only — the stored figures and every input field stay in days regardless.',
       0, 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'lead_time_display_unit');
